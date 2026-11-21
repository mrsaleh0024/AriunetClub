<?php

namespace AriunetClub\Tests;

use AriunetClub\Modules\Rules\WcOrderHistory;
use PHPUnit\Framework\TestCase;

class WcOrderHistoryTest extends TestCase {
	public function test_first_order_lookup_uses_a_utc_timestamp_boundary(): void {
		$GLOBALS['ariunetclub_test_wc_get_orders_args'] = array();
		$history = new WcOrderHistory();

		$this->assertFalse( $history->has_paid_order_before( 0, '2026-01-01 00:00:00' ) );
		$this->assertSame( array(), $GLOBALS['ariunetclub_test_wc_get_orders_args'] );

		$this->assertFalse( $history->has_paid_order_before( 7, '2026-01-01 00:00:00' ) );
		$args = $GLOBALS['ariunetclub_test_wc_get_orders_args'][0];
		$this->assertSame( '<' . gmmktime( 0, 0, 0, 1, 1, 2026 ), $args['date_created'] );
		$this->assertSame( 7, $args['customer_id'] );
		$this->assertSame( array( 'wc-processing', 'wc-completed' ), $args['status'] );
		$this->assertSame( 1, $args['limit'] );
		$this->assertSame( 'ids', $args['return'] );

		$this->assertFalse( $history->has_paid_order_before( 7, 'not a date' ) );
		$this->assertCount( 1, $GLOBALS['ariunetclub_test_wc_get_orders_args'] );

		$GLOBALS['ariunetclub_test_wc_get_orders_args'] = array();
	}
}
