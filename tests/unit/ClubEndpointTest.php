<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Money;
use AriunetClub\Modules\Account\ClubEndpoint;
use AriunetClub\Modules\Identity\IdentityService;
use AriunetClub\Modules\Identity\PhoneNormalizer;
use AriunetClub\Modules\Ledger\LedgerService;
use PHPUnit\Framework\TestCase;

class ClubEndpointTest extends TestCase {

        private function endpoint(): array {
                $ledger   = new InMemoryRulesLedger();
                $rules    = new FakeRuleGateway();
                $identity = new IdentityService( new PhoneNormalizer() );
                return array( new ClubEndpoint( $ledger, $rules, $identity ), $ledger, $rules, new LedgerService( $ledger ) );
        }

        private function seed_history( LedgerService $service, int $user_id, int $count ): void {
                for ( $i = 1; $i <= $count; $i++ ) {
                        $service->credit( $user_id, 1000.0, "seed-{$user_id}-{$i}", array(
                                'created_at' => gmdate( 'Y-m-d H:i:s', time() - $i * 60 ),
                                'note'       => "تراکنش {$i} کاربر {$user_id}",
                        ) );
                }
        }

        public function test_view_contains_only_own_entries(): void {
                list( $endpoint, $ledger, , $service ) = $this->endpoint();
                $this->seed_history( $service, 2, 3 );
                $this->seed_history( $service, 3, 5 );

                $view = $endpoint->build_view( 2, 1 );

                $this->assertSame( 3, $view['total'] );
                foreach ( $view['entries'] as $entry ) {
                        $this->assertStringContainsString( 'کاربر 2', $entry['note'] );
                }
        }

        public function test_pagination_is_twenty_rows_and_clamps(): void {
                list( $endpoint, $ledger, , $service ) = $this->endpoint();
                $this->seed_history( $service, 7, 45 );

                $view = $endpoint->build_view( 7, 1 );
                $this->assertSame( 45, $view['total'] );
                $this->assertSame( 3, $view['pages'] );
                $this->assertCount( 20, $view['entries'] );

                $view = $endpoint->build_view( 7, 2 );
                $this->assertCount( 20, $view['entries'] );
                $this->assertSame( 2, $view['page'] );

                $view = $endpoint->build_view( 7, 99 );
                $this->assertSame( 3, $view['page'], 'out-of-range page must clamp to the last page' );
                $this->assertCount( 5, $view['entries'] );
        }

        public function test_page_number_parsing(): void {
                list( $endpoint ) = $this->endpoint();
                $GLOBALS['ariunetclub_test_query_vars']['club'] = '';
                $this->assertSame( 1, $endpoint->current_page() );
                $GLOBALS['ariunetclub_test_query_vars']['club'] = 'page/2';
                $this->assertSame( 2, $endpoint->current_page() );
                $GLOBALS['ariunetclub_test_query_vars']['club'] = '3';
                $this->assertSame( 3, $endpoint->current_page() );
                $GLOBALS['ariunetclub_test_query_vars']['club'] = 'page/0';
                $this->assertSame( 1, $endpoint->current_page() );
                $GLOBALS['ariunetclub_test_query_vars']['club'] = 'page/abc';
                $this->assertSame( 1, $endpoint->current_page() );
                $GLOBALS['ariunetclub_test_query_vars']['club'] = "../../etc/passwd";
                $this->assertSame( 1, $endpoint->current_page() );
                $GLOBALS['ariunetclub_test_query_vars']['club'] = 'page/99999999999999999999';
                $this->assertSame( 1000000, $endpoint->current_page(), 'overflowing page numbers cap at a safe ceiling' );
        }

        public function test_missing_phone_flags_guide_state(): void {
                list( $endpoint ) = $this->endpoint();
                $view = $endpoint->build_view( 2, 1 );
                $this->assertFalse( $view['logged_in'] === false );
                $this->assertFalse( $view['has_phone'] );

                $GLOBALS['ariunetclub_test_user_meta'][2]['billing_phone'] = '09121234567';
                $view = $endpoint->build_view( 2, 1 );
                $this->assertTrue( $view['has_phone'] );
                $this->assertSame( '۰۹۱۲***۵۶۷', $view['phone_masked'] );
                $this->assertStringNotContainsString( '09121234567', $view['phone_masked'], 'full phone must never be exposed' );
        }

        public function test_logged_out_view_has_no_user_data(): void {
                list( $endpoint, $ledger, , $service ) = $this->endpoint();
                $this->seed_history( $service, 5, 4 );

                $view = $endpoint->build_view( 0, 1 );

                $this->assertFalse( $view['logged_in'] );
                $this->assertSame( array(), $view['entries'] );
                $this->assertSame( 0, $view['total'] );
        }

        public function test_entry_formatting_labels_signs_and_jalali_dates(): void {
                list( $endpoint, $ledger, , $service ) = $this->endpoint();
                $service->credit( 2, 5000.0, 'c1', array( 'created_at' => '2026-11-21 10:00:00', 'note' => 'کسب کش‌بک', 'ref_type' => 'order', 'ref_id' => 120 ) );
                $service->hold( 2, 2000.0, 'h1', array( 'created_at' => '2026-11-21 11:00:00' ) );
                $service->capture( 2, 2000.0, 'cp1', array( 'created_at' => '2026-11-21 12:00:00' ) );
                $service->adjust( 2, -1000.0, 'a1', array( 'created_at' => '2026-11-21 13:00:00' ) );

                $view = $endpoint->build_view( 2, 1 );

                $this->assertCount( 4, $view['entries'] );
                // Newest first.
                $this->assertSame( 'اصلاح', $view['entries'][0]['label'] );
                $this->assertSame( 'neutral', $view['entries'][0]['family'] );
                $this->assertSame( '−۱,۰۰۰', $view['entries'][0]['amount_display'] );

                $this->assertSame( 'ثبت خرج', $view['entries'][1]['label'] );
                $this->assertSame( 'danger', $view['entries'][1]['family'] );
                $this->assertSame( '−۲,۰۰۰', $view['entries'][1]['amount_display'] );

                $this->assertSame( 'رزرو', $view['entries'][2]['label'] );
                $this->assertSame( 'warning', $view['entries'][2]['family'] );
                $this->assertSame( '—', $view['entries'][2]['amount_display'] );

                $this->assertSame( 'کسب', $view['entries'][3]['label'] );
                $this->assertSame( 'success', $view['entries'][3]['family'] );
                $this->assertSame( '+۵,۰۰۰', $view['entries'][3]['amount_display'] );
                $this->assertSame( 120, $view['entries'][3]['order_id'] );

                $expected_date = Money::to_persian_digits( JalaliDate::format( '2026-11-21 10:00:00' ) );
                $this->assertSame( $expected_date, $view['entries'][3]['date'] );
                $this->assertStringContainsString( '۱۴۰۵', $view['entries'][3]['date'] );
        }

        public function test_rules_filter_and_customer_safe_summary(): void {
                list( $endpoint, $ledger, $rules ) = $this->endpoint();

                $rules->seed( array(
                        'id'         => 1,
                        'event_type' => 'order.status.completed',
                        'status'     => 'active',
                        'priority'   => 5,
                        'title'      => 'کش‌بک سفارش‌های تکمیل‌شده',
                        'reward'     => wp_json_encode( array( 'type' => 'percent', 'value' => 2, 'max_cap' => 50000 ) ),
                        'starts_at'  => null,
                        'ends_at'    => null,
                ) );
                $rules->seed( array(
                        'id'         => 2,
                        'event_type' => 'order.status.completed',
                        'status'     => 'draft',
                        'priority'   => 6,
                        'title'      => 'قانون پیش‌نویس',
                        'reward'     => wp_json_encode( array( 'type' => 'percent', 'value' => 10 ) ),
                ) );
                $rules->seed( array(
                        'id'         => 3,
                        'event_type' => 'order.status.completed',
                        'status'     => 'active',
                        'priority'   => 7,
                        'title'      => 'قانون منقضی',
                        'reward'     => wp_json_encode( array( 'type' => 'fixed', 'value' => 5000 ) ),
                        'ends_at'    => '2020-01-01 00:00:00',
                ) );
                $rules->seed( array(
                        'id'         => 4,
                        'event_type' => 'order.status.completed',
                        'status'     => 'active',
                        'priority'   => 8,
                        'title'      => 'قانون آینده',
                        'reward'     => wp_json_encode( array( 'type' => 'percent', 'value' => 3 ) ),
                        'starts_at'  => '2099-01-01 00:00:00',
                ) );

                $view = $endpoint->build_view( 2, 1 );

                $this->assertCount( 1, $view['rules'] );
                $this->assertSame( 'کش‌بک سفارش‌های تکمیل‌شده', $view['rules'][0]['title'] );
                $this->assertSame( '۲٪ کش‌بک', $view['rules'][0]['reward'] );
                $this->assertSame( 'percent', $view['rules'][0]['reward_kind'] );
                $this->assertArrayNotHasKey( 'max_cap', $view['rules'][0], 'caps must not reach the customer view' );
                $this->assertStringNotContainsString( 'سقف', $view['rules'][0]['reward'] );
        }

        public function test_menu_item_is_first(): void {
                list( $endpoint ) = $this->endpoint();
                $items = $endpoint->add_menu_item( array( 'dashboard' => 'پیشخوان', 'orders' => 'سفارش‌ها' ) );
                $this->assertSame( 'club', array_key_first( $items ) );
                $this->assertSame( 'باشگاه', $items['club'] );
                $this->assertSame( 'سفارش‌ها', $items['orders'] );
        }
}
