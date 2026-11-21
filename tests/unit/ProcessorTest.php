<?php

namespace AriunetClub\Tests;

use AriunetClub\Modules\Events\Processor;
use AriunetClub\Modules\Events\SkipEventException;
use PHPUnit\Framework\TestCase;

class ProcessorTest extends TestCase {
	private function make_event( string $key = 'order:1:status:completed' ): array {
		return array(
			'type'            => 'order.status.completed',
			'user_id'         => 7,
			'object_type'     => 'order',
			'object_id'       => 1,
			'payload'         => '{"from":"processing","to":"completed"}',
			'idempotency_key' => $key,
			'status'          => 'pending',
		);
	}

	public function test_pending_events_are_dispatched_and_marked_done(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$dispatched = array();
		$processor = new Processor( $gateway, static function ( array $event ) use ( &$dispatched ): void {
			$dispatched[] = $event;
		} );

		$count = $processor->process_pending();

		$this->assertSame( 1, $count );
		$this->assertCount( 1, $dispatched );
		$this->assertSame( 'done', $gateway->rows[0]['status'] );
		$this->assertNull( $gateway->rows[0]['error'] );
	}

	public function test_skip_exceptions_mark_the_event_skipped(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$processor = new Processor( $gateway, static function (): void {
			throw SkipEventException::because( 'guest_order' );
		} );

		$processor->process_pending();

		$this->assertSame( 'skipped', $gateway->rows[0]['status'] );
		$this->assertSame( 'سفارش مهمان کیف پول باشگاه ندارد.', $gateway->rows[0]['error'] );
	}

	public function test_failures_flip_to_failed_after_three_attempts(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$processor = new Processor( $gateway, static function (): void {
			throw new \RuntimeException( 'boom' );
		} );

		$processor->process_pending();
		$this->assertSame( 'pending', $gateway->rows[0]['status'] );
		$this->assertSame( 1, (int) $gateway->rows[0]['attempts'] );

		$processor->process_pending();
		$processor->process_pending();
		$this->assertSame( 'failed', $gateway->rows[0]['status'] );
		$this->assertSame( 3, (int) $gateway->rows[0]['attempts'] );
		$this->assertSame( 'boom', $gateway->rows[0]['error'] );

		$this->assertSame( 0, $processor->process_pending() );
	}

	public function test_requeue_resets_attempts_and_reprocesses(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$throwing = true;
		$processor = new Processor( $gateway, static function () use ( &$throwing ): void {
			if ( $throwing ) {
				throw new \RuntimeException( 'boom' );
			}
		} );
		$processor->process_pending();
		$processor->process_pending();
		$processor->process_pending();
		$this->assertSame( 'failed', $gateway->rows[0]['status'] );

		$gateway->requeue( (int) $gateway->rows[0]['id'] );
		$throwing = false;
		$processor->process_pending();

		$this->assertSame( 'done', $gateway->rows[0]['status'] );
		$this->assertSame( 1, (int) $gateway->rows[0]['attempts'] );
	}

	public function test_processing_is_skipped_while_another_processor_holds_the_lock(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$GLOBALS['ariunetclub_test_settings'][ Processor::LOCK_OPTION ] = (string) time();
		$processor = new Processor( $gateway, static function (): void {
			throw new \RuntimeException( 'must not run while locked' );
		} );

		$this->assertSame( 0, $processor->process_pending() );
		$this->assertSame( 'pending', $gateway->rows[0]['status'] );
		$this->assertNotEmpty( $GLOBALS['ariunetclub_test_settings'][ Processor::LOCK_OPTION ] );

		unset( $GLOBALS['ariunetclub_test_settings'][ Processor::LOCK_OPTION ] );
	}

	public function test_the_lock_is_released_after_the_batch(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$processor = new Processor( $gateway );

		$this->assertSame( 1, $processor->process_pending() );
		$this->assertArrayNotHasKey( Processor::LOCK_OPTION, $GLOBALS['ariunetclub_test_settings'] ?? array() );
	}

	public function test_a_stale_lock_from_a_crashed_processor_is_recovered(): void {
		$gateway = new InMemoryEventGateway();
		$gateway->insert( $this->make_event() );
		$GLOBALS['ariunetclub_test_settings'][ Processor::LOCK_OPTION ] = (string) ( time() - 500 );
		$processor = new Processor( $gateway );

		$this->assertSame( 1, $processor->process_pending() );
		$this->assertSame( 'done', $gateway->rows[0]['status'] );
		$this->assertArrayNotHasKey( Processor::LOCK_OPTION, $GLOBALS['ariunetclub_test_settings'] ?? array() );
	}
}
