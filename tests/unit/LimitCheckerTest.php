<?php

namespace AriunetClub\Tests;

use AriunetClub\Modules\Rules\LimitChecker;
use PHPUnit\Framework\TestCase;

class LimitCheckerTest extends TestCase {
        private function rule( array $limits ): array {
                return array(
                        'id'         => 1,
                        'title'      => 'قانون آزمایشی',
                        'status'     => 'active',
                        'event_type' => 'order.status.completed',
                        'conditions' => '{}',
                        'reward'     => '{"type":"percent","value":10}',
                        'limits'     => (string) wp_json_encode( $limits ),
                        'priority'   => 10,
                        'exclusive'  => 0,
                        'starts_at'  => null,
                        'ends_at'    => null,
                );
        }

        private function seed_credit( InMemoryRulesLedger $ledger, int $user_id, float $amount, string $created_at, int $rule_id = 1 ): void {
                $ledger->record(
                        array(
                                'user_id'         => $user_id,
                                'asset'           => 'cashback',
                                'entry_type'      => 'credit',
                                'amount'          => $amount,
                                'idempotency_key' => 'rule:' . $rule_id . ':seed:' . $user_id . ':' . $created_at . ':' . count( $ledger->entries ),
                                'ref_type'        => 'order',
                                'ref_id'          => count( $ledger->entries ) + 1,
                                'rule_id'         => $rule_id,
                                'created_at'      => $created_at,
                        )
                );
        }

        public function test_per_user_rolling_window_blocks_after_limit(): void {
                $ledger = new InMemoryRulesLedger();
                $now = time();
                $this->seed_credit( $ledger, 7, 10.0, gmdate( 'Y-m-d H:i:s', $now - 86400 ) );
                $this->seed_credit( $ledger, 7, 10.0, gmdate( 'Y-m-d H:i:s', $now - 7200 ) );

                $checker = new LimitChecker( $ledger );
                $this->assertTrue( $checker->exhausted( $this->rule( array( 'per_user_count' => 2, 'per_user_days' => 30 ) ), 7, $now ) );
                $this->assertFalse( $checker->exhausted( $this->rule( array( 'per_user_count' => 3, 'per_user_days' => 30 ) ), 7, $now ) );
        }

        public function test_rolling_window_only_counts_recent_entries(): void {
                $ledger = new InMemoryRulesLedger();
                $now = time();
                $this->seed_credit( $ledger, 7, 10.0, gmdate( 'Y-m-d H:i:s', $now - 90 * 86400 ) );
                $this->seed_credit( $ledger, 7, 10.0, gmdate( 'Y-m-d H:i:s', $now - 40 * 86400 ) );

                $checker = new LimitChecker( $ledger );
                $this->assertFalse( $checker->exhausted( $this->rule( array( 'per_user_count' => 2, 'per_user_days' => 30 ) ), 7, $now ) );
        }

        public function test_other_users_do_not_fill_my_window(): void {
                $ledger = new InMemoryRulesLedger();
                $now = time();
                $this->seed_credit( $ledger, 8, 10.0, gmdate( 'Y-m-d H:i:s', $now - 3600 ) );
                $this->seed_credit( $ledger, 8, 10.0, gmdate( 'Y-m-d H:i:s', $now - 1800 ) );

                $checker = new LimitChecker( $ledger );
                $this->assertFalse( $checker->exhausted( $this->rule( array( 'per_user_count' => 2, 'per_user_days' => 30 ) ), 7, $now ) );
        }

        public function test_total_times_cap(): void {
                $ledger = new InMemoryRulesLedger();
                $now = time();
                $this->seed_credit( $ledger, 7, 10.0, gmdate( 'Y-m-d H:i:s', $now - 3600 ) );
                $this->seed_credit( $ledger, 8, 10.0, gmdate( 'Y-m-d H:i:s', $now - 1800 ) );

                $checker = new LimitChecker( $ledger );
                $this->assertTrue( $checker->exhausted( $this->rule( array( 'total_times' => 2 ) ), 9, $now ) );
                $this->assertFalse( $checker->exhausted( $this->rule( array( 'total_times' => 3 ) ), 9, $now ) );
        }

        public function test_total_amount_cap(): void {
                $ledger = new InMemoryRulesLedger();
                $now = time();
                $this->seed_credit( $ledger, 7, 1000.0, gmdate( 'Y-m-d H:i:s', $now - 3600 ) );

                $checker = new LimitChecker( $ledger );
                $this->assertTrue( $checker->exhausted( $this->rule( array( 'total_amount' => 1000.0 ) ), 7, $now ) );
                $this->assertFalse( $checker->exhausted( $this->rule( array( 'total_amount' => 1001.0 ) ), 7, $now ) );
        }
}
