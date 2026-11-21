<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Wallet;

use LClub\Core\Settings;
use LClub\Modules\Ledger\LedgerService;

/**
 * The "lclub_wallet" payment method. It only becomes available when the
 * wallet switch already covers the whole order (the amount due is then
 * zero), so a full-coverage order completes without a bank gateway and
 * records lclub_wallet as its payment method.
 */
class WalletGateway extends \WC_Payment_Gateway {

	const GATEWAY_ID = 'lclub_wallet';

	/** @var LedgerService */
	private $ledger;

	/** @var SpendCalculator */
	private $calculator;

	/** @var Settings */
	private $settings;

	/**
	 * @param LedgerService   $ledger     Ledger service.
	 * @param SpendCalculator $calculator Wallet spend calculator.
	 * @param Settings        $settings   Club settings.
	 */
	public function __construct( LedgerService $ledger, SpendCalculator $calculator, Settings $settings ) {
		$this->ledger     = $ledger;
		$this->calculator = $calculator;
		$this->settings   = $settings;

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'کیف پول باشگاه مشتریان', 'lclub' );
		$this->method_description = __( 'پرداخت کامل مبلغ سفارش از موجودی کیف پول؛ فقط وقتی سوییچ کیف پول کل سبد را پوشش داده باشد در دسترس قرار می‌گیرد.', 'lclub' );
		$this->title              = __( 'کیف پول', 'lclub' );
		$this->has_fields         = false;
		$this->enabled            = 'yes';

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'فعال‌سازی', 'lclub' ),
				'type'    => 'checkbox',
				'label'   => __( 'فعال باشد', 'lclub' ),
				'default' => 'yes',
			),
		);
		$this->init_settings();
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Available only for a logged-in customer whose wallet switch already
	 * covers the whole cart (full coverage enabled).
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! is_user_logged_in() || null === $wc || null === $wc->cart || null === $wc->session ) {
			return false;
		}
		$config = $this->settings->get();
		if ( empty( $config['wallet_full_coverage'] ) ) {
			return false;
		}
		if ( true !== $wc->session->get( CheckoutController::SESSION_FLAG ) ) {
			return false;
		}

		$balance   = $this->ledger->balance( get_current_user_id() );
		$available = isset( $balance['available'] ) ? (float) $balance['available'] : 0.0;
		$base      = max( 0.0, (float) $wc->cart->get_subtotal() + (float) $wc->cart->get_subtotal_tax() );
		$due       = $base + (float) $wc->cart->get_shipping_total() + (float) $wc->cart->get_shipping_tax();
		$spend     = $this->calculator->max_spend( $available, $base, $due );

		return $due > 0.0 && $spend + 0.001 >= $due;
	}

	/**
	 * Marks the order as paid through the wallet; the status transition then
	 * triggers the hold capture in HoldManager.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,mixed>
	 */
	public function process_payment( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wc_add_notice( __( 'سفارش برای پرداخت با کیف پول پیدا نشد.', 'lclub' ), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}

		$order->payment_complete();

		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( null !== $wc && null !== $wc->cart ) {
			$wc->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
