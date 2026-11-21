<?php

namespace AriunetClub\Tests;

use AriunetClub\Modules\Events\EventBus;
use PHPUnit\Framework\TestCase;

class EventBusTest extends TestCase {
	public function test_same_idempotency_key_is_recorded_once(): void {
		$gateway = new InMemoryEventGateway();
		$bus = new EventBus( $gateway, false );

		$first = $bus->record( 'order.status.completed', 7, 'order', 12, 'order:12:status:completed', array( 'from' => 'processing', 'to' => 'completed' ) );
		$second = $bus->record( 'order.status.completed', 7, 'order', 12, 'order:12:status:completed', array( 'from' => 'processing', 'to' => 'completed' ) );

		$this->assertTrue( $first['recorded'] );
		$this->assertFalse( $second['recorded'] );
		$this->assertCount( 1, $gateway->rows );
		$this->assertSame( $first['event']['id'], $second['event']['id'] );
	}

	public function test_invalid_record_is_rejected_silently(): void {
		$gateway = new InMemoryEventGateway();
		$bus = new EventBus( $gateway, false );

		$this->assertFalse( $bus->record( '', 7, 'order', 1, 'order:1:status:completed' )['recorded'] );
		$this->assertFalse( $bus->record( 'order.status.completed', 7, 'order', 1, '' )['recorded'] );
		$this->assertCount( 0, $gateway->rows );
	}

	public function test_guest_event_is_stored_with_zero_user(): void {
		$gateway = new InMemoryEventGateway();
		$bus = new EventBus( $gateway, false );

		$result = $bus->record( 'order.status.completed', null, 'order', 5, 'order:5:status:completed', array() );

		$this->assertTrue( $result['recorded'] );
		$this->assertSame( 0, (int) $result['event']['user_id'] );
	}

	public function test_a_burst_of_events_enqueues_a_single_unique_queue_kick(): void {
		$gateway = new InMemoryEventGateway();
		$GLOBALS['ariunetclub_test_async_actions'] = array();
		$bus = new EventBus( $gateway, true );

		$bus->record( 'order.status.completed', 7, 'order', 12, 'order:12:status:completed', array() );
		$bus->record( 'user.registered', 7, 'user', 7, 'user:7:registered', array() );
		$bus->record( 'order.status.completed', 8, 'order', 13, 'order:13:status:completed', array() );

		$kicks = $GLOBALS['ariunetclub_test_async_actions'];
		$this->assertCount( 1, $kicks );
		$this->assertSame( 'ariunetclub_process_events', $kicks[0]['hook'] );
		$this->assertSame( 'ariunetclub', $kicks[0]['group'] );
		$this->assertTrue( $kicks[0]['unique'] );
		$GLOBALS['ariunetclub_test_async_actions'] = array();
	}
}
