<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Wallet;

use AriunetClub\Core\Logger;
use AriunetClub\Core\Module;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Ledger\LedgerRepository;
use AriunetClub\Modules\Ledger\LedgerService;

/**
 * Phase 3 module: wallet spend at classic checkout with the reservation
 * model (hold on order creation, capture on payment, release on cancel or
 * expiry). Wires the checkout controller, the hold lifecycle, the order
 * metabox, the ariunetclub_wallet gateway and the front-end assets together.
 */
class WalletModule implements Module {

	public function id(): string {
		return 'wallet';
	}

	/**
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'ledger' );
	}

	public function boot(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return; // no database handle, nothing safe to wire.
		}

		$ledger_service = isset( $GLOBALS['ariunetclub_ledger_service'] ) && $GLOBALS['ariunetclub_ledger_service'] instanceof LedgerService
			? $GLOBALS['ariunetclub_ledger_service']
			: new LedgerService( new LedgerRepository( $wpdb ) );
		$ledger_gateway = new LedgerRepository( $wpdb );
		$settings       = new Settings();
		$calculator     = new SpendCalculator( $settings );
		$logger         = new Logger();

		$holds = new HoldManager( $ledger_service, $ledger_gateway, $settings, $logger );
		$holds->register();

		( new CheckoutController( $ledger_service, $calculator, $settings, $holds, $logger ) )->register();
		( new WalletOrderMetabox( $ledger_gateway ) )->register();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );

		$GLOBALS['ariunetclub_wallet_calculator'] = $calculator;
	}

	/**
	 * Registers the ariunetclub_wallet payment gateway with the WooCommerce filter.
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways Registered gateways.
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function register_gateway( array $gateways ): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return $gateways;
		}

		$ledger_service = isset( $GLOBALS['ariunetclub_ledger_service'] ) && $GLOBALS['ariunetclub_ledger_service'] instanceof LedgerService
			? $GLOBALS['ariunetclub_ledger_service']
			: new LedgerService( new LedgerRepository( $wpdb ) );
		$settings       = new Settings();

		$gateways[ WalletGateway::GATEWAY_ID ] = new WalletGateway( $ledger_service, new SpendCalculator( $settings ), $settings );
		return $gateways;
	}

	/**
	 * Loads the wallet switch assets on the checkout page only.
	 */
	public function enqueue_checkout_assets(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		$on_checkout = is_checkout() || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() );
		if ( ! $on_checkout ) {
			return;
		}

		wp_enqueue_style( 'ariunetclub-checkout', ARIUNETCLUB_URL . 'assets/css/ariunetclub-checkout.css', array(), ARIUNETCLUB_VERSION );
		wp_enqueue_script( 'ariunetclub-checkout', ARIUNETCLUB_URL . 'assets/js/ariunetclub-checkout.js', array(), ARIUNETCLUB_VERSION, true );

		$available = 0.0;
		if ( isset( $GLOBALS['ariunetclub_ledger_service'] ) && $GLOBALS['ariunetclub_ledger_service'] instanceof LedgerService ) {
			$balance   = $GLOBALS['ariunetclub_ledger_service']->balance( get_current_user_id() );
			$available = isset( $balance['available'] ) && is_numeric( $balance['available'] ) ? (float) $balance['available'] : 0.0;
		}

		wp_localize_script(
			'ariunetclub-checkout',
			'ariunetclubCheckout',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( CheckoutController::NONCE ),
				'available' => $available,
				'strings'   => array(
					'error'   => __( 'خطا در به‌روزرسانی کیف پول؛ دوباره تلاش کنید.', 'ariunetclub' ),
					'blocked' => __( 'برای درگاه انتخاب‌شده امکان استفاده از کیف پول وجود ندارد.', 'ariunetclub' ),
				),
			)
		);
	}
}
