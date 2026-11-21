<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Wallet;

use LClub\Core\Logger;
use LClub\Core\Money;
use LClub\Core\Settings;
use LClub\Modules\Ledger\InsufficientBalanceException;
use LClub\Modules\Ledger\LedgerService;

/**
 * Phase 3 front end: the wallet switch and the negative fee at classic
 * checkout.
 *
 * The switch is a nonce-protected AJAX endpoint for logged-in customers;
 * the applied amount is always recomputed server-side from the ledger and
 * never taken from the client. The fee is not taxable and carries a
 * customizable title, exactly like the phase brief demands.
 */
class CheckoutController {

	const SESSION_FLAG  = 'lclub_use_wallet';
	const SESSION_SPEND = 'lclub_wallet_spend';
	const NONCE         = 'lclub_wallet_toggle';
	const THROTTLE      = 'lclub_toggle_lock_';

	/** @var LedgerService */
	private $ledger;

	/** @var SpendCalculator */
	private $calculator;

	/** @var Settings */
	private $settings;

	/** @var HoldManager */
	private $holds;

	/** @var Logger */
	private $logger;

	public function __construct( LedgerService $ledger, SpendCalculator $calculator, Settings $settings, HoldManager $holds, Logger $logger ) {
		$this->ledger     = $ledger;
		$this->calculator = $calculator;
		$this->settings   = $settings;
		$this->holds      = $holds;
		$this->logger     = $logger;
	}

	public function register(): void {
		add_action( 'wp_ajax_lclub_toggle_wallet', array( $this, 'toggle_wallet' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_fee' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'place_hold' ), 10, 3 );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_toggle' ) );
	}

	/**
	 * Nonce-protected toggle for logged-in customers. Persists the intent in
	 * the WooCommerce session and answers with fresh numbers; the fee itself
	 * is applied on the next cart recalculation.
	 */
	public function toggle_wallet(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! is_user_logged_in() || null === $wc || null === $wc->session || null === $wc->cart ) {
			wp_send_json_error( array( 'message' => __( 'برای استفاده از کیف پول باید وارد حساب خود شوید.', 'lclub' ) ), 403 );
		}
		$user_id = get_current_user_id();
		if ( get_transient( self::THROTTLE . $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'درخواست‌های پی‌در‌پی؛ یک لحظه بعد دوباره تلاش کنید.', 'lclub' ) ), 429 );
		}
		set_transient( self::THROTTLE . $user_id, 1, 1 );

		$use       = isset( $_POST['use'] ) && '1' === sanitize_key( wp_unslash( $_POST['use'] ) );
		$available = $this->available();
		$base      = $this->items_base( $wc->cart );
		$due       = $this->due_total( $wc->cart );
		$spend     = $this->calculator->max_spend( $available, $base, $due );

		if ( $use && $spend <= 0.0 ) {
			$wc->session->set( self::SESSION_FLAG, false );
			$wc->session->set( self::SESSION_SPEND, 0.0 );
			wp_send_json_error( array( 'message' => __( 'با توجه به تنظیمات، در حال حاضر امکان استفاده از کیف پول برای این سبد نیست.', 'lclub' ) ), 409 );
		}

		$wc->session->set( self::SESSION_FLAG, $use );
		$wc->session->set( self::SESSION_SPEND, $use ? $spend : 0.0 );

		wp_send_json_success(
			array(
				'used'               => $use,
				'spend'              => $use ? $spend : 0.0,
				'spendFormatted'     => $use ? Money::format( $spend ) : Money::format( 0.0 ),
				'availableFormatted' => Money::format( $available ),
			)
		);
	}

	/**
	 * Applies the negative, non-taxable fee while the wallet is switched on.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_fee( \WC_Cart $cart ): void {
		if ( ! is_user_logged_in() || ! $this->flag() ) {
			return;
		}
		$available = $this->available();
		$base      = $this->items_base( $cart );
		$due       = $this->due_total( $cart );
		$spend     = $this->calculator->max_spend( $available, $base, $due );

		if ( $spend <= 0.0 ) {
			$wc = $this->wc();
			if ( null !== $wc && null !== $wc->session ) {
				$wc->session->set( self::SESSION_SPEND, 0.0 );
			}
			return;
		}

		$cart->add_fee( $this->fee_title(), -1 * $spend, false );
		$wc = $this->wc();
		if ( null !== $wc && null !== $wc->session ) {
			$wc->session->set( self::SESSION_SPEND, $spend );
		}
	}

	/**
	 * Validates the wallet slice before the order is created.
	 *
	 * @param array<string,mixed> $data   Posted checkout data.
	 * @param \WP_Error           $errors Checkout error bag.
	 */
	public function validate_checkout( array $data, \WP_Error $errors ): void {
		if ( ! is_user_logged_in() || ! $this->flag() ) {
			return;
		}
		$available = $this->available();
		$base      = $this->items_base( null );
		$due       = $this->due_total( null );
		$spend     = $this->calculator->max_spend( $available, $base, $due );

		if ( $spend <= 0.0 ) {
			$errors->add( 'lclub_wallet', __( 'با توجه به شرایط فعلی، استفاده از کیف پول برای این سبد ممکن نیست.', 'lclub' ) );
			return;
		}
		if ( $available + 0.001 < $spend ) {
			$errors->add( 'lclub_wallet', __( 'موجودی کیف پول برای این خرید کافی نیست.', 'lclub' ) );
			return;
		}
		if ( $spend + 0.001 < $due ) {
			$method = isset( $data['payment_method'] ) ? (string) $data['payment_method'] : '';
			if ( ! $this->gateway_allowed( $method ) ) {
				$errors->add( 'lclub_wallet', __( 'برای درگاه انتخاب‌شده امکان استفاده از کیف پول وجود ندارد.', 'lclub' ) );
			}
		}
	}

	/**
	 * Stamps wallet meta on the fresh order before it is saved. At this point
	 * the fee lines and the order total (which already includes the fee) exist.
	 *
	 * @param \WC_Order           $order Order object.
	 * @param array<string,mixed> $data  Posted checkout data.
	 */
	public function stamp_order( \WC_Order $order, array $data ): void {
		if ( ! is_user_logged_in() || ! $this->flag() ) {
			return;
		}
		$spend = $this->session_spend();
		if ( $spend <= 0.0 ) {
			return;
		}

		$total   = max( 0.0, (float) $order->get_total() );
		$pre_fee = max( 0.0, $total + $spend );
		$spend   = min( $spend, $pre_fee );

		$order->update_meta_data( OrderMeta::AMOUNT, Money::round( $spend ) );
		$order->update_meta_data( OrderMeta::ORDER_TOTAL, Money::round( $total ) );

		if ( $total <= 0.001 ) {
			$order->set_payment_method( WalletGateway::GATEWAY_ID );
			$order->set_payment_method_title( $this->fee_title() );
		}
	}

	/**
	 * Places the hold the moment the order exists (the phase 3 reservation
	 * model). A failed hold never blocks the order silently: the order is
	 * marked failed with a Persian notice for the customer.
	 *
	 * @param int|string         $order_id   Order id.
	 * @param array<string,mixed> $posted_data Posted checkout data.
	 * @param \WC_Order          $order      Order object.
	 */
	public function place_hold( $order_id, array $posted_data, \WC_Order $order ): void {
		$this->clear_session();
		$order_id = (int) $order_id;
		$amount   = OrderMeta::amount( $order );
		if ( $amount <= 0.0 || '' !== OrderMeta::state( $order ) ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		try {
			$this->ledger->hold(
				$user_id,
				$amount,
				HoldManager::hold_key( $order_id ),
				array(
					'ref_type' => 'order',
					'ref_id'   => $order_id,
					'note'     => __( 'رزرو کیف پول برای سفارش', 'lclub' ),
				)
			);
			OrderMeta::remember_hold( $order, $amount, max( 0.0, OrderMeta::order_total( $order ) ) );
			$this->holds->schedule_expiry( $order_id );
			$order->add_order_note( __( 'رزرو کیف پول ثبت شد: ', 'lclub' ) . Money::format( $amount ) );
			$this->logger->info( 'Wallet hold placed.', array( 'order_id' => $order_id, 'amount' => $amount ) );
		} catch ( InsufficientBalanceException $exception ) {
			$order->update_status( 'failed', __( 'رزرو کیف پول به‌دلیل کافی نبودن موجودی انجام نشد.', 'lclub' ) );
			$this->logger->error( 'Wallet hold failed: insufficient balance.', array( 'order_id' => $order_id, 'user_id' => $user_id ) );
			throw new \Exception( __( 'موجودی کیف پول کافی نیست؛ سفارش ثبت نشد.', 'lclub' ) );
		} catch ( \Throwable $error ) {
			$order->update_status( 'failed', __( 'خطا در ثبت رزرو کیف پول؛ سفارش ناموفق شد.', 'lclub' ) );
			$this->logger->error( 'Wallet hold failed.', array( 'order_id' => $order_id, 'error' => $error->getMessage() ) );
			throw new \Exception( __( 'خطا در ثبت رزرو کیف پول؛ سفارش ناموفق شد.', 'lclub' ) );
		}
	}

	/**
	 * Renders the wallet switch above the place-order button.
	 */
	public function render_toggle(): void {
		if ( ! is_user_logged_in() || function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return;
		}
		$available = $this->available();
		$base       = $this->items_base( null );
		$due        = $this->due_total( null );
		$spend      = $this->calculator->max_spend( $available, $base, $due );
		if ( $available <= 0.0 || $spend <= 0.0 ) {
			return;
		}
		?>
		<div class="lclub-scope lclub-wallet-toggle" data-lclub-wallet-box>
			<div class="lclub-wallet-toggle__panel">
				<label class="lclub-wallet-toggle__label" for="lclub-use-wallet">
					<input type="checkbox" id="lclub-use-wallet" name="lclub_use_wallet" value="1" data-lclub-wallet-toggle <?php checked( $this->flag() ); ?> />
					<span><?php echo esc_html( $this->fee_title() ); ?></span>
				</label>
				<p class="lclub-wallet-toggle__meta">
					<span><?php esc_html_e( 'موجودی قابل استفاده:', 'lclub' ); ?> <strong data-lclub-wallet-available><?php echo esc_html( Money::format( $available ) ); ?></strong></span>
					<span><?php esc_html_e( 'کسر تقریبی از این سفارش:', 'lclub' ); ?> <strong data-lclub-wallet-spend><?php echo esc_html( Money::format( $spend ) ); ?></strong></span>
				</p>
				<p class="lclub-wallet-toggle__note" data-lclub-wallet-note aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * @return \WC|null The global WooCommerce instance when usable.
	 */
	private function wc() {
		return function_exists( 'WC' ) ? WC() : null;
	}

	/**
	 * @return bool True when the wallet switch is currently on.
	 */
	private function flag(): bool {
		$wc = $this->wc();
		return null !== $wc && null !== $wc->session && true === $wc->session->get( self::SESSION_FLAG );
	}

	/**
	 * @return float Wallet spend computed for the current request.
	 */
	private function session_spend(): float {
		$wc    = $this->wc();
		$value = null !== $wc && null !== $wc->session ? $wc->session->get( self::SESSION_SPEND ) : null;
		return is_scalar( $value ) ? (float) $value : 0.0;
	}

	private function clear_session(): void {
		$wc = $this->wc();
		if ( null === $wc || null === $wc->session ) {
			return;
		}
		$wc->session->set( self::SESSION_FLAG, false );
		$wc->session->set( self::SESSION_SPEND, 0.0 );
	}

	/**
	 * @return float Available wallet balance of the current customer.
	 */
	private function available(): float {
		if ( ! is_user_logged_in() ) {
			return 0.0;
		}
		$balance = $this->ledger->balance( get_current_user_id() );
		return isset( $balance['available'] ) ? (float) $balance['available'] : 0.0;
	}

	/**
	 * @param \WC_Cart|null $cart Cart object when supplied.
	 * @return float Items total plus tax (shipping excluded).
	 */
	private function items_base( $cart ): float {
		$cart = $cart instanceof \WC_Cart ? $cart : $this->cart();
		if ( null === $cart ) {
			return 0.0;
		}
		return max( 0.0, (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() );
	}

	/**
	 * @param \WC_Cart|null $cart Cart object when supplied.
	 * @return float Amount due for the cart (items + shipping; other fee rows
	 *                are intentionally ignored, which only makes the cap safer).
	 */
	private function due_total( $cart ): float {
		$cart = $cart instanceof \WC_Cart ? $cart : $this->cart();
		if ( null === $cart ) {
			return 0.0;
		}
		$due = $this->items_base( $cart ) + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		return max( 0.0, $due );
	}

	/**
	 * @return \WC_Cart|null The global cart when usable.
	 */
	private function cart() {
		$wc = $this->wc();
		return null !== $wc ? $wc->cart : null;
	}

	/**
	 * @return string Fee row title from the wallet settings.
	 */
	private function fee_title(): string {
		$config = $this->settings->get();
		$title  = isset( $config['wallet_fee_title'] ) ? (string) $config['wallet_fee_title'] : '';
		return '' !== $title ? $title : __( 'استفاده از کیف پول', 'lclub' );
	}

	/**
	 * @param string $method Payment gateway id.
	 * @return bool True when the wallet may be used alongside the gateway.
	 */
	private function gateway_allowed( string $method ): bool {
		if ( '' === $method ) {
			return true;
		}
		$config  = $this->settings->get();
		$allowed = isset( $config['wallet_allowed_gateways'] ) && is_array( $config['wallet_allowed_gateways'] ) ? $config['wallet_allowed_gateways'] : array();
		if ( empty( $allowed ) ) {
			return 'cod' !== $method;
		}
		return in_array( $method, $allowed, true );
	}
}
