<?php
/**
 * @package AriunetClub
 *
 * Phase 6 unit tests: the lot lifecycle rules of LotService against an
 * in-memory lot store (the SQL paths live in tools/integration-tests.php and
 * the property-based sequences in tools/credit-functional-tests.php).
 */

namespace AriunetClub\Tests;

use AriunetClub\Core\Settings;
use AriunetClub\Modules\Credit\LotRepository;
use AriunetClub\Modules\Credit\LotService;
use AriunetClub\Modules\Ledger\InsufficientBalanceException;
use PHPUnit\Framework\TestCase;

final class UnitLotStore extends LotRepository {
        public $lots = array();
        public $allocations = array();
        private $next_lot = 1;
        private $next_alloc = 1;

        public function __construct() { parent::__construct( new UnitWpdb() ); }

        public function sums_for_user( int $user_id ): array {
                $remaining = 0.0;
                $held      = 0.0;
                foreach ( $this->lots as $lot ) {
                        if ( (int) $lot['user_id'] === $user_id && 'active' === (string) $lot['status'] ) {
                                $remaining += (float) $lot['remaining'];
                                $held      += (float) $lot['held'];
                        }
                }
                return array( 'remaining' => $remaining, 'held' => $held );
        }
        public function lock_allocatable_lots( int $user_id, string $now_gmt ): array { return $this->filter( $user_id, $now_gmt ); }
        public function lock_consumable_lots( int $user_id ): array { return $this->filter( $user_id, null ); }
        public function lock_order_lots( int $user_id, int $order_id ): array {
                $out = array();
                foreach ( $this->filter( $user_id, null ) as $lot ) {
                        if ( 'order' === (string) $lot['ref_type'] && (int) $lot['ref_id'] === $order_id ) {
                                $out[] = $lot;
                        }
                }
                return $out;
        }
        private function filter( int $user_id, ?string $now_gmt ): array {
                $out = array();
                foreach ( $this->lots as $lot ) {
                        if ( (int) $lot['user_id'] !== $user_id || 'active' !== (string) $lot['status'] ) { continue; }
                        if ( (float) $lot['remaining'] <= (float) $lot['held'] + 0.0000001 ) { continue; }
                        if ( null !== $now_gmt ) {
                                $expires = (string) $lot['expires_at'];
                                if ( '' !== $expires && $expires <= $now_gmt ) { continue; }
                        }
                        $out[] = $lot;
                }
                usort( $out, static function ( array $a, array $b ): int {
                        $an = '' === (string) $a['expires_at'] ? 1 : 0;
                        $bn = '' === (string) $b['expires_at'] ? 1 : 0;
                        if ( $an !== $bn ) { return $an - $bn; }
                        if ( '' !== (string) $a['expires_at'] && (string) $a['expires_at'] !== (string) $b['expires_at'] ) { return strcmp( (string) $a['expires_at'], (string) $b['expires_at'] ); }
                        return (int) $a['id'] - (int) $b['id'];
                } );
                return $out;
        }
        public function lock_lot( int $lot_id ): ?array {
                foreach ( $this->lots as $lot ) {
                        if ( (int) $lot['id'] === $lot_id ) { return $lot; }
                }
                return null;
        }
        public function lock_held_allocations( int $order_id, int $user_id ): array {
                $out = array();
                foreach ( $this->allocations as $allocation ) {
                        if ( (int) $allocation['order_id'] === $order_id && (int) $allocation['user_id'] === $user_id && 'held' === (string) $allocation['state'] ) {
                                $out[] = $allocation;
                        }
                }
                return $out;
        }
        public function insert_lot( array $lot ): int {
                $lot['id']         = $this->next_lot++;
                $lot['expires_at'] = $lot['expires_at'] ?? null;
                $lot['reason']     = $lot['reason'] ?? null;
                $lot['ref_type']   = $lot['ref_type'] ?? null;
                $lot['ref_id']     = $lot['ref_id'] ?? null;
                $this->lots[ (int) $lot['id'] ] = $lot;
                return (int) $lot['id'];
        }
        public function update_lot( int $lot_id, float $remaining, float $held, string $status ): void {
                if ( $remaining <= 0.0000001 && $held <= 0.0000001 && 'active' === $status ) { $status = 'exhausted'; }
                foreach ( $this->lots as $index => $lot ) {
                        if ( (int) $lot['id'] === $lot_id ) {
                                $this->lots[ $index ]['remaining'] = $remaining;
                                $this->lots[ $index ]['held']      = $held;
                                $this->lots[ $index ]['status']    = $status;
                                return;
                        }
                }
                throw new \RuntimeException( 'lot not found' );
        }
        public function insert_allocation( int $lot_id, int $user_id, int $order_id, float $amount ): int {
                $this->allocations[ $this->next_alloc ] = array(
                        'id' => $this->next_alloc, 'lot_id' => $lot_id, 'user_id' => $user_id,
                        'order_id' => $order_id, 'amount' => $amount, 'state' => 'held',
                );
                return $this->next_alloc++;
        }
        public function set_allocation_state( int $allocation_id, string $state ): void {
                $this->allocations[ $allocation_id ]['state'] = $state;
        }
}

final class UnitWpdb extends \wpdb {
        public $prefix = 'wp_';
}

final class CreditLotServiceTest extends TestCase {

        private $lots;
        private $service;

        protected function setUp(): void {
                $this->lots    = new UnitLotStore();
                $this->service = new LotService( $this->lots, new Settings(), null );
        }

        private function pending( string $type, int $user_id, float $amount, string $key, array $context = array() ): array {
                $entry = array_merge( array(
                        'user_id' => $user_id, 'asset' => 'cashback', 'entry_type' => $type, 'amount' => $amount,
                        'idempotency_key' => $key, 'ref_type' => null, 'ref_id' => null, 'rule_id' => null, 'note' => null,
                        'id' => 1, '_original_amount' => $amount,
                ), $context );
                return $entry;
        }

        private function apply( string $type, int $user_id, float $amount, string $key, array $context, array $before, array $after ): array {
                // Mirror the repository's delta rewriting: debit/capture/expire store a
                // negative delta, hold/release store zero.
                $delta = $amount;
                if ( in_array( $type, array( 'debit', 'capture', 'expire' ), true ) ) {
                        $delta = -1 * abs( $amount );
                } elseif ( 'hold' === $type || 'release' === $type ) {
                        $delta = 0.0;
                }
                $entry = $this->pending( $type, $user_id, $delta, $key, $context );
                $entry['_original_amount'] = abs( $amount );
                $this->service->on_entry_pending( $entry, $before, $after );
                return $entry;
        }

        public function test_earn_creates_one_lot_with_the_order_reason(): void {
                $this->apply( 'credit', 7, 50000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 123, 'rule_id' => 2, 'note' => 'کش‌بک ۵٪' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 50000.0, 'held' => 0.0 ) );
                $this->assertCount( 1, $this->lots->lots );
                $lot = reset( $this->lots->lots );
                $this->assertSame( 50000.0, (float) $lot['remaining'] );
                $this->assertSame( 'rule', $lot['source_type'] );
                $this->assertStringContainsString( '123', (string) $lot['reason'] );
                $this->assertStringContainsString( 'کش‌بک ۵٪', (string) $lot['reason'] );
                $this->assertNull( $lot['expires_at'] ); // expiry off by default.
        }

        public function test_fefo_consumes_the_soonest_expiry_first(): void {
                $this->apply( 'credit', 7, 10000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 10000.0, 'held' => 0.0 ) );
                $this->apply( 'credit', 7, 5000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 2, 'note' => 'r', '_lot_expiry_days' => 30 ), array( 'available' => 10000.0, 'held' => 0.0 ), array( 'available' => 15000.0, 'held' => 0.0 ) );

                $this->apply( 'debit', 7, 7000, 'k3', array( 'ref_type' => 'order', 'ref_id' => 9 ), array( 'available' => 15000.0, 'held' => 0.0 ), array( 'available' => 8000.0, 'held' => 0.0 ) );

                $sums = $this->lots->sums_for_user( 7 );
                $this->assertSame( 8000.0, $sums['remaining'] );
                $dated = null;
                foreach ( $this->lots->lots as $lot ) {
                        if ( null !== $lot['expires_at'] ) { $dated = $lot; }
                }
                $this->assertNotNull( $dated );
                $this->assertSame( 0.0, (float) $dated['remaining'] ); // the dated lot was spent first.
        }

        public function test_reversal_prefers_the_orders_own_lot(): void {
                $this->apply( 'credit', 7, 10000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 11, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 10000.0, 'held' => 0.0 ) );
                $this->apply( 'credit', 7, 10000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 12, 'note' => 'r' ), array( 'available' => 10000.0, 'held' => 0.0 ), array( 'available' => 20000.0, 'held' => 0.0 ) );

                $this->apply( 'adjust', 7, -4000, 'k3', array( 'ref_type' => 'order', 'ref_id' => 11 ), array( 'available' => 20000.0, 'held' => 0.0 ), array( 'available' => 16000.0, 'held' => 0.0 ) );

                foreach ( $this->lots->lots as $lot ) {
                        if ( 11 === (int) $lot['ref_id'] ) {
                                $this->assertSame( 6000.0, (float) $lot['remaining'] );
                        } else {
                                $this->assertSame( 10000.0, (float) $lot['remaining'] );
                        }
                }
        }

        public function test_oversized_reversal_exhausts_lots_and_leaves_debt(): void {
                $this->apply( 'credit', 7, 3000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 3000.0, 'held' => 0.0 ) );
                $this->apply( 'adjust', 7, -9000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 1 ), array( 'available' => 3000.0, 'held' => 0.0 ), array( 'available' => -6000.0, 'held' => 0.0 ) );

                $this->assertSame( 0.0, $this->lots->sums_for_user( 7 )['remaining'] );
        }

        public function test_earn_while_in_debt_pays_the_debt_before_creating_a_lot(): void {
                $this->apply( 'credit', 7, 5000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 5000.0, 'held' => 0.0 ) );
                $this->apply( 'adjust', 7, -8000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 1 ), array( 'available' => 5000.0, 'held' => 0.0 ), array( 'available' => -3000.0, 'held' => 0.0 ) );

                // Debt is 3,000: a 2,000 grant repays part of it, the rest comes from
                // the user's unheld lots (none left here) — no lot may be created.
                $this->apply( 'credit', 7, 2000, 'k3', array( 'ref_type' => 'order', 'ref_id' => 2, 'note' => 'r' ), array( 'available' => -3000.0, 'held' => 0.0 ), array( 'available' => -1000.0, 'held' => 0.0 ) );
                $this->assertSame( 0.0, $this->lots->sums_for_user( 7 )['remaining'] );

                // A 4,000 grant clears the residual 1,000 debt and lots the remaining 3,000.
                $this->apply( 'credit', 7, 4000, 'k4', array( 'ref_type' => 'order', 'ref_id' => 3, 'note' => 'r' ), array( 'available' => -1000.0, 'held' => 0.0 ), array( 'available' => 3000.0, 'held' => 0.0 ) );
                $this->assertSame( 3000.0, $this->lots->sums_for_user( 7 )['remaining'] );
        }

        public function test_residual_debt_is_repaid_from_unheld_lots(): void {
                // Lot A (held by an open order) + debt from a reversal of another order.
                $this->apply( 'credit', 7, 10000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 10000.0, 'held' => 0.0 ) );
                $this->apply( 'hold', 7, 6000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 50 ), array( 'available' => 10000.0, 'held' => 0.0 ), array( 'available' => 4000.0, 'held' => 6000.0 ) );
                $this->apply( 'adjust', 7, -9000, 'k3', array( 'ref_type' => 'order', 'ref_id' => 1 ), array( 'available' => 4000.0, 'held' => 6000.0 ), array( 'available' => -5000.0, 'held' => 6000.0 ) );

                // The 4,000 unheld part was consumed by the reversal; 5,000 debt remains
                // while 6,000 stay held. A small 2,000 grant cannot clear 5,000 of debt,
                // so it repays 2,000 — from unheld lots (none) → no new lot.
                $this->apply( 'credit', 7, 2000, 'k4', array( 'ref_type' => 'order', 'ref_id' => 2, 'note' => 'r' ), array( 'available' => -5000.0, 'held' => 6000.0 ), array( 'available' => -3000.0, 'held' => 6000.0 ) );
                $this->assertSame( 6000.0, $this->lots->sums_for_user( 7 )['remaining'] );
                $this->assertSame( 6000.0, $this->lots->sums_for_user( 7 )['held'] );
        }

        public function test_hold_allocates_fefo_and_capture_consumes_the_allocations(): void {
                $this->apply( 'credit', 7, 10000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 10000.0, 'held' => 0.0 ) );
                $this->apply( 'credit', 7, 4000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 2, 'note' => 'r', '_lot_expiry_days' => 14 ), array( 'available' => 10000.0, 'held' => 0.0 ), array( 'available' => 14000.0, 'held' => 0.0 ) );

                $this->apply( 'hold', 7, 6000, 'k3', array( 'ref_type' => 'order', 'ref_id' => 77 ), array( 'available' => 14000.0, 'held' => 0.0 ), array( 'available' => 8000.0, 'held' => 6000.0 ) );
                $sums = $this->lots->sums_for_user( 7 );
                $this->assertSame( 14000.0, $sums['remaining'] );
                $this->assertSame( 6000.0, $sums['held'] );
                $this->assertCount( 2, $this->lots->allocations ); // 4,000 (dated) + 2,000.

                $this->apply( 'capture', 7, 6000, 'k4', array( 'ref_type' => 'order', 'ref_id' => 77 ), array( 'available' => 8000.0, 'held' => 6000.0 ), array( 'available' => 8000.0, 'held' => 0.0 ) );
                $sums = $this->lots->sums_for_user( 7 );
                $this->assertSame( 8000.0, $sums['remaining'] );
                $this->assertSame( 0.0, $sums['held'] );
        }

        public function test_expired_lot_is_never_allocated_again(): void {
                $this->apply( 'credit', 7, 5000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r', '_lot_expiry_days' => 7 ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 5000.0, 'held' => 0.0 ) );
                $lot = reset( $this->lots->lots );
                $lot['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
                $this->lots->lots[ (int) $lot['id'] ] = $lot;

                $this->expectException( InsufficientBalanceException::class );
                $this->apply( 'hold', 7, 1000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 2 ), array( 'available' => 5000.0, 'held' => 0.0 ), array( 'available' => 4000.0, 'held' => 1000.0 ) );
        }

        public function test_expire_entry_reduces_only_the_unheld_part(): void {
                $this->apply( 'credit', 7, 5000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 5000.0, 'held' => 0.0 ) );
                $this->apply( 'hold', 7, 2000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 3 ), array( 'available' => 5000.0, 'held' => 0.0 ), array( 'available' => 3000.0, 'held' => 2000.0 ) );
                $lot = reset( $this->lots->lots );

                $this->apply( 'expire', 7, 3000, 'k3', array( '_lot_id' => (int) $lot['id'] ), array( 'available' => 3000.0, 'held' => 2000.0 ), array( 'available' => 0.0, 'held' => 2000.0 ) );

                $lot = $this->lots->lots[ (int) $lot['id'] ];
                $this->assertSame( 2000.0, (float) $lot['remaining'] );
                $this->assertSame( 2000.0, (float) $lot['held'] );
                $this->assertSame( 'active', (string) $lot['status'] ); // the held part survives.
        }

        public function test_expire_entry_mismatching_the_lot_fails_closed(): void {
                $this->apply( 'credit', 7, 5000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 5000.0, 'held' => 0.0 ) );
                $lot = reset( $this->lots->lots );

                $this->expectException( \RuntimeException::class );
                $this->apply( 'expire', 9, 100, 'k2', array( '_lot_id' => (int) $lot['id'] ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => -100.0, 'held' => 0.0 ) );
        }

        public function test_release_in_debt_repays_the_debt_from_the_freed_credit(): void {
                // 10,000 credit, 6,000 held on an order, then a 9,000 reversal consumes
                // the 4,000 unheld part and books 5,000 debt.
                $this->apply( 'credit', 7, 10000, 'k1', array( 'ref_type' => 'order', 'ref_id' => 1, 'note' => 'r' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 10000.0, 'held' => 0.0 ) );
                $this->apply( 'hold', 7, 6000, 'k2', array( 'ref_type' => 'order', 'ref_id' => 50 ), array( 'available' => 10000.0, 'held' => 0.0 ), array( 'available' => 4000.0, 'held' => 6000.0 ) );
                $this->apply( 'adjust', 7, -9000, 'k3', array( 'ref_type' => 'order', 'ref_id' => 1 ), array( 'available' => 4000.0, 'held' => 6000.0 ), array( 'available' => -5000.0, 'held' => 6000.0 ) );

                // The order is cancelled: the freed 6,000 first repays the 5,000 debt,
                // leaving 1,000 of live credit — never a positive balance with debt.
                $this->apply( 'release', 7, 6000, 'k4', array( 'ref_type' => 'order', 'ref_id' => 50 ), array( 'available' => -5000.0, 'held' => 6000.0 ), array( 'available' => 1000.0, 'held' => 0.0 ) );

                $sums = $this->lots->sums_for_user( 7 );
                $this->assertSame( 1000.0, $sums['remaining'] );
                $this->assertSame( 0.0, $sums['held'] );
                $this->assertSame( 1000.0, $sums['remaining'] - 0.0 );
        }

        public function test_reason_is_sanitized_and_capped(): void {
                $entry = $this->pending( 'grant', 7, 1000, 'k', array( 'note' => str_repeat( '<b>×</b>', 60 ) ) );
                $this->service->on_entry_pending( array_merge( $entry, array( 'entry_type' => 'credit' ) ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 1000.0, 'held' => 0.0 ) );
                $lot = reset( $this->lots->lots );
                $this->assertStringNotContainsString( '<b>', (string) $lot['reason'] );
                $this->assertLessThanOrEqual( 190, mb_strlen( (string) $lot['reason'], 'UTF-8' ) );
        }

        public function test_non_order_entries_get_a_plain_reason(): void {
                $this->apply( 'credit', 7, 500, 'k1', array( 'ref_type' => 'user', 'ref_id' => 7, 'note' => 'خوش‌آمدگویی' ), array( 'available' => 0.0, 'held' => 0.0 ), array( 'available' => 500.0, 'held' => 0.0 ) );
                $lot = reset( $this->lots->lots );
                $this->assertStringContainsString( 'خوش‌آمدگویی', (string) $lot['reason'] );
                $this->assertStringNotContainsString( 'سفارش', (string) $lot['reason'] );
        }
}
