<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events\Sources;

use AriunetClub\Modules\Events\EventBus;

/**
 * Listens to WooCommerce order hooks and records earning-relevant events.
 * Only key statuses create events to keep the queue lean.
 */
class OrderSource {
	/** @var string[] */
	public const KEY_STATUSES = array( 'processing', 'completed', 'cancelled', 'refunded', 'failed' );

	/** @var EventBus */
	private $bus;

	public function __construct( EventBus $bus ) {
		$this->bus = $bus;
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
	}

	/**
	 * @param int|string $order_id Order id.
	 * @param string     $from     Previous status.
	 * @param string     $to       New status.
	 */
	public function on_status_changed( $order_id, string $from, string $to ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! in_array( $to, self::KEY_STATUSES, true ) ) {
			return;
		}
		$user_id = $this->order_user( $order_id );
		$this->bus->record(
			'order.status.' . $to,
			$user_id,
			'order',
			$order_id,
			'order:' . $order_id . ':status:' . $to,
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
	}

	/**
	 * @param int|string $order_id  Order id.
	 * @param int|string $refund_id Refund (WC_Order_Refund) id.
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		$order_id  = (int) $order_id;
		$refund_id = (int) $refund_id;
		if ( $order_id <= 0 || $refund_id <= 0 ) {
			return;
		}
		$amount = $this->refund_amount( $refund_id );
		$this->bus->record(
			'order.refunded',
			$this->order_user( $order_id ),
			'order',
			$order_id,
			'order:' . $order_id . ':refund:' . $refund_id,
			array(
				'refund_id'     => $refund_id,
				'refund_amount' => $amount,
			)
		);
	}

	/**
	 * @return int|null
	 */
	private function order_user( int $order_id ): ?int {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$customer = (int) $order->get_customer_id();
		return $customer > 0 ? $customer : null;
	}

	/**
	 * Snapshot of the refund amount taken at event time.
	 *
	 * @return float
	 */
	private function refund_amount( int $refund_id ): float {
		$refund = wc_get_order( $refund_id );
		if ( $refund instanceof \WC_Order_Refund && method_exists( $refund, 'get_amount' ) ) {
			return (float) $refund->get_amount();
		}
		return 0.0;
	}
}
