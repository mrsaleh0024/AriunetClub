<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events;

/**
 * Turns WordPress/WooCommerce hooks into unique, idempotent queue events.
 * Modules publish through the bus; they never talk to each other directly.
 */
class EventBus {
	/** @var EventGateway */
	private $gateway;

	/** @var bool */
	private $schedule_on_record;

	public function __construct( EventGateway $gateway, bool $schedule_on_record = true ) {
		$this->gateway            = $gateway;
		$this->schedule_on_record = $schedule_on_record;
	}

	/**
	 * Records an event once. A repeated idempotency key is silently ignored.
	 *
	 * @param string   $type        Event type, e.g. order.status.completed.
	 * @param int|null $user_id     Related user or null for guests.
	 * @param string   $object_type Object family: order, user, product_review.
	 * @param int      $object_id   Related object id.
	 * @param string   $key         Unique idempotency key, e.g. order:12:status:completed.
	 * @param array<string,mixed> $payload Structured event data (JSON encoded).
	 * @return array{recorded:bool,event:array<string,mixed>}
	 */
	public function record( string $type, ?int $user_id, string $object_type, int $object_id, string $key, array $payload = array() ): array {
		if ( '' === $type || '' === $key || '' === $object_type ) {
			return array(
				'recorded' => false,
				'event'    => array(),
			);
		}

		$known = $this->gateway->find_by_key( $key );
		if ( null !== $known ) {
			return array(
				'recorded' => false,
				'event'    => $known,
			);
		}

		$event = array(
			'type'            => $type,
			'user_id'         => max( 0, (int) $user_id ),
			'object_type'     => $object_type,
			'object_id'       => max( 0, (int) $object_id ),
			'payload'         => (string) wp_json_encode( $payload ),
			'idempotency_key' => $key,
			'status'          => 'pending',
		);

		$stored = $this->gateway->insert( $event );
		if ( empty( $stored ) ) {
			return array(
				'recorded' => false,
				'event'    => array(),
			);
		}

		if ( $this->schedule_on_record ) {
			$this->schedule_processing();
		}
		return array(
			'recorded' => true,
			'event'    => $stored,
		);
	}

	/**
	 * Queues one processing tick. Action Scheduler is primary, WP-Cron is the fallback.
	 */
	private function schedule_processing(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'ariunetclub_process_events', array(), 'ariunetclub' );
			return;
		}
		if ( ! wp_next_scheduled( 'ariunetclub_process_events' ) ) {
			wp_schedule_single_event( time() + 5, 'ariunetclub_process_events' );
		}
	}
}
