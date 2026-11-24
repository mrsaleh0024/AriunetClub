<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Wallet;

use AriunetClub\Core\Money;
use AriunetClub\Modules\Ledger\LedgerGateway;

/**
 * Phase 3 admin metabox on the order edit screen: wallet amount consumed,
 * wallet refunds already returned and the cashback earned for the order.
 * Read-only, capability-guarded and registered for both legacy and HPOS screens.
 */
class WalletOrderMetabox {

	/** @var LedgerGateway */
	private $gateway;

	public function __construct( LedgerGateway $gateway ) {
		$this->gateway = $gateway;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_boxes(): void {
		add_meta_box(
			'ariunetclub_wallet',
			__( 'اعتبار باشگاه', 'ariunetclub' ),
			array( $this, 'render' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'default'
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}
		$is_legacy_order = 'post' === $screen->base && 'shop_order' === $screen->post_type;
		if ( ! $is_legacy_order && false === strpos( (string) $hook . ' ' . $screen->id, 'wc-orders' ) ) {
			return;
		}
		wp_enqueue_style( 'ariunetclub-order-metabox', ARIUNETCLUB_URL . 'assets/css/ariunetclub-order-metabox.css', array(), ARIUNETCLUB_VERSION );
	}

	/**
	 * Renders the metabox. Legacy storage passes a WP_Post, HPOS passes the order.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Metabox context object.
	 */
	public function render( $post_or_order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order = null;
		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof \WP_Post ) {
			$found = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $post_or_order->ID ) : null;
			$order = $found instanceof \WC_Order ? $found : null;
		}
		if ( null === $order || '' === OrderMeta::state( $order ) ) {
			return;
		}

		$amount   = OrderMeta::amount( $order );
		$returned = $this->returned_for( (int) $order->get_id() );
		$cashback = $this->cashback_for( (int) $order->get_id() );
		$history  = add_query_arg(
			array(
				'page'    => 'ariunetclub-wallet-history',
				'user_id' => (int) $order->get_customer_id(),
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ariunetclub-scope ariunetclub-metabox" dir="rtl">
			<dl>
				<div><dt><?php esc_html_e( 'مبلغ مصرف‌شده', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::format( $amount ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'وضعیت', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( $this->state_label( OrderMeta::state( $order ) ) ); ?></dd></div>
				<?php if ( $returned > 0.0 ) : ?>
					<div><dt><?php esc_html_e( 'برگشت مستردشده', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::format( $returned ) ); ?></dd></div>
				<?php endif; ?>
				<div><dt><?php esc_html_e( 'کش‌بک کسب‌شده', 'ariunetclub' ); ?></dt><dd><?php echo esc_html( Money::format( $cashback ) ); ?></dd></div>
			</dl>
			<a class="ariunetclub-metabox__link" href="<?php echo esc_url( $history ); ?>"><?php esc_html_e( 'تاریخچهٔ اعتبار مشتری', 'ariunetclub' ); ?></a>
		</div>
		<?php
	}

	/**
	 * @return string Persian label for a wallet state.
	 */
	private function state_label( string $state ): string {
		$labels = array(
			OrderMeta::STATE_HELD     => __( 'رزرو شده', 'ariunetclub' ),
			OrderMeta::STATE_CAPTURED => __( 'مصرف شده', 'ariunetclub' ),
			OrderMeta::STATE_RELEASED => __( 'آزاد شده', 'ariunetclub' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/**
	 * @return float Wallet refund entries returned for the order.
	 */
	private function returned_for( int $order_id ): float {
		$entries = $this->gateway->entries_for_reference( 'order', $order_id, array( 'adjust' ) );
		$prefix  = HoldManager::REFUND_PREFIX . $order_id . ':refund:';
		$sum     = 0.0;
		foreach ( $entries as $entry ) {
			$key = isset( $entry['idempotency_key'] ) && is_string( $entry['idempotency_key'] ) ? $entry['idempotency_key'] : '';
			if ( 0 === strpos( $key, $prefix ) ) {
				$entry_amount = isset( $entry['amount'] ) ? $entry['amount'] : null;
				$sum         += is_numeric( $entry_amount ) ? max( 0.0, (float) $entry_amount ) : 0.0;
			}
		}
		return $sum;
	}

	/**
	 * @return float Cashback credits earned for the order.
	 */
	private function cashback_for( int $order_id ): float {
		$entries = $this->gateway->entries_for_reference( 'order', $order_id, array( 'credit' ) );
		$sum     = 0.0;
		foreach ( $entries as $entry ) {
			$amount = isset( $entry['amount'] ) ? $entry['amount'] : null;
			$sum   += is_numeric( $amount ) ? max( 0.0, (float) $amount ) : 0.0;
		}
		return $sum;
	}
}
