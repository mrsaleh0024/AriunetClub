<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Wallet;

use AriunetClub\Core\Settings;

/**
 * The "ariunetclub_wallet" payment method. It only becomes available when the
 * wallet switch already covers the whole order (the amount due is then
 * zero), so a full-coverage order completes without a bank gateway and
 * records ariunetclub_wallet as its payment method.
 */
class WalletGateway extends \WC_Payment_Gateway {

	const GATEWAY_ID = 'ariunetclub_wallet';

	/** @var Settings */
	private $club_settings;

	/**
	 * @param Settings $settings Club settings.
	 */
	public function __construct( Settings $settings ) {
		$this->club_settings = $settings;

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'اعتبار باشگاه مشتریان', 'ariunetclub' );
		$this->method_description = __( 'پرداخت کامل مبلغ سفارش از موجودی اعتبار باشگاه؛ فقط وقتی سوییچ اعتبار باشگاه کل سبد را پوشش داده باشد در دسترس قرار می‌گیرد.', 'ariunetclub' );
		$this->title              = __( 'اعتبار باشگاه', 'ariunetclub' );
		$this->has_fields         = false;
		$this->enabled            = 'yes';

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'فعال‌سازی', 'ariunetclub' ),
				'type'    => 'checkbox',
				'label'   => __( 'فعال باشد', 'ariunetclub' ),
				'default' => 'yes',
			),
		);
		$this->init_settings();
		$enabled_option = $this->get_option( 'enabled', 'yes' );
		$this->enabled  = is_string( $enabled_option ) ? $enabled_option : 'yes';

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
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return false; // the pay page pays for a stored order, never for the current cart.
		}
		$config = $this->club_settings->get();
		if ( empty( $config['wallet_full_coverage'] ) ) {
			return false;
		}
		if ( true !== $wc->session->get( CheckoutController::SESSION_FLAG ) ) {
			return false;
		}

		$cart = $wc->cart;
		if ( 0 === $cart->get_cart_contents_count() ) {
			return false;
		}

		$due = max( 0.0, (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() );
		foreach ( $cart->get_fees() as $fee_row ) {
			$due += is_object( $fee_row ) && isset( $fee_row->amount ) && is_numeric( $fee_row->amount ) ? (float) $fee_row->amount : 0.0;
		}

		// The wallet gateway may only complete an order that owes nothing more.
		return max( 0.0, $due ) <= 0.001;
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
			wc_add_notice( __( 'سفارش برای پرداخت با اعتبار باشگاه پیدا نشد.', 'ariunetclub' ), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}

		$total  = max( 0.0, (float) $order->get_total() );
		$amount = OrderMeta::amount( $order );
		if ( $amount + 0.001 < $total ) {
			wc_add_notice( __( 'اعتبار باشگاه فقط سفارشی را کامل می‌پردازد که کل مبلغش را پوشش داده باشد؛ برای ادامه درگاه دیگری انتخاب کنید.', 'ariunetclub' ), 'error' );
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
