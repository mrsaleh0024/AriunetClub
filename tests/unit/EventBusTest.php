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
}
