<?php

namespace AriunetClub\Tests;

use AriunetClub\Modules\Events\SkipEventException;
use AriunetClub\Modules\Ledger\LedgerService;
use AriunetClub\Modules\Rules\LimitChecker;
use AriunetClub\Modules\Rules\OrderSnapshot;
use AriunetClub\Modules\Rules\RewardService;
use AriunetClub\Modules\Rules\RuleContext;
use AriunetClub\Modules\Rules\RuleEngine;
use AriunetClub\Modules\Rules\Conditions\Category;
use AriunetClub\Modules\Rules\Conditions\FirstOrder;
use AriunetClub\Modules\Rules\Conditions\MinAmount;
use AriunetClub\Modules\Rules\Conditions\PaymentMethod;
use AriunetClub\Modules\Rules\Conditions\Product;
use AriunetClub\Modules\Rules\Conditions\UserRole;
use AriunetClub\Modules\Rules\Rewards\FixedReward;
use AriunetClub\Modules\Rules\Rewards\PercentageReward;
use PHPUnit\Framework\TestCase;

class RewardServiceTest extends TestCase {
        private InMemoryRulesLedger $ledger_gateway;
        private InMemoryEventGateway $events;
        private FakeRuleGateway $rules;
        private FakeContextFactory $factory;
        private FakeOrderLookup $lookup;
        private LedgerService $ledger;

        private function completed_event( int $order_id = 1, int $user_id = 7 ): array {
                return array(
                        'type'            => 'order.status.completed',
                        'user_id'         => $user_id,
                        'object_type'     => 'order',
                        'object_id'       => $order_id,
                        'payload'         => '{"from":"processing","to":"completed"}',
                        'idempotency_key' => 'order:' . $order_id . ':status:completed',
                        'status'          => 'pending',
                );
        }

        private function service(): RewardService {
                $this->ledger_gateway = new InMemoryRulesLedger();
                $this->events         = new InMemoryEventGateway();
                $this->rules          = new FakeRuleGateway();
                $this->factory        = new FakeContextFactory();
                $this->lookup         = new FakeOrderLookup();
                $this->ledger         = new LedgerService( $this->ledger_gateway );

                $engine = new RuleEngine(
                        array(
                                'min_amount'     => new MinAmount(),
                                'category'       => new Category(),
                                'product'        => new Product(),
                                'first_order'    => new FirstOrder(),
                                'user_role'      => new UserRole(),
                                'payment_method' => new PaymentMethod(),
                        ),
                        array(
                                'percent' => new PercentageReward(),
                                'fixed'   => new FixedReward(),
                        )
                );

                return new RewardService(
                        $this->rules,
                        $engine,
                        new LimitChecker( $this->ledger_gateway ),
                        $this->ledger,
                        $this->ledger_gateway,
                        $this->factory,
                        new FakeOrderHistory(),
                        $this->lookup
                );
        }

        private function context( float $base = 1000.0, int $user_id = 7 ): RuleContext {
                $context = new RuleContext( 'order.status.completed', $user_id );
                $context->base_amount = $base;
                return $context;
        }

        private function active_rule( int $id, array $overrides = array() ): array {
                return array_merge(
                        array(
                                'id'         => $id,
                                'title'      => 'قانون آزمایشی',
                                'status'     => 'active',
                                'event_type' => 'order.status.completed',
                                'conditions' => '{}',
                                'reward'     => '{"type":"percent","value":10,"max_cap":0}',
                                'limits'     => '{}',
                                'priority'   => 10,
                                'exclusive'  => 0,
                                'starts_at'  => null,
                                'ends_at'    => null,
                        ),
                        $overrides
                );
        }

        public function test_resending_the_same_order_event_credits_once(): void {
                $service = $this->service();
                $context = $this->context( 1000.0 );
                $this->factory->contexts['order:1:status:completed'] = $context;
                $this->rules->seed( $this->active_rule( 1 ) );
                $event = $this->completed_event( 1 );

                $service->handle_event( $event );
                $service->handle_event( $event );

                $credits = $this->ledger_gateway->entries_for_reference( 'order', 1, array( 'credit' ) );
                $this->assertCount( 1, $credits );
                $this->assertSame( 100.0, $this->ledger_gateway->balance( 7 )['available'] );
        }

        public function test_draft_rule_is_never_applied(): void {
                $service = $this->service();
                $this->factory->contexts['order:1:status:completed'] = $this->context();
                $this->rules->seed( $this->active_rule( 1, array( 'status' => 'draft' ) ) );

                $summary = $service->handle_event( $this->completed_event( 1 ) );

                $this->assertSame( 'noop', $summary['mode'] );
                $this->assertCount( 0, $this->ledger_gateway->entries );
        }

        public function test_out_of_window_rule_is_not_applied(): void {
                $service = $this->service();
                $this->factory->contexts['order:1:status:completed'] = $this->context();
                $this->rules->seed( $this->active_rule( 1, array( 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) ) );

                $service->handle_event( $this->completed_event( 1 ) );

                $this->assertCount( 0, $this->ledger_gateway->entries );
        }

        public function test_exclusive_rule_blocks_remaining_rules(): void {
                $service = $this->service();
                $this->factory->contexts['order:1:status:completed'] = $this->context();
                $this->rules->seed( $this->active_rule( 1, array( 'priority' => 5, 'exclusive' => 1, 'reward' => '{"type":"percent","value":5}' ) ) );
                $this->rules->seed( $this->active_rule( 2, array( 'priority' => 20, 'reward' => '{"type":"fixed","value":100}' ) ) );

                $summary = $service->handle_event( $this->completed_event( 1 ) );

                $this->assertCount( 1, $summary['credited'] );
                $this->assertSame( 50.0, $this->ledger_gateway->balance( 7 )['available'] );
        }

        public function test_limits_block_repeated_rewards_on_new_orders(): void {
                $service = $this->service();
                $this->factory->contexts['order:1:status:completed'] = $this->context();
                $this->factory->contexts['order:2:status:completed'] = $this->context();
                $this->rules->seed( $this->active_rule( 1, array( 'limits' => '{"per_user_count":1,"per_user_days":30}' ) ) );

                $first = $service->handle_event( $this->completed_event( 1 ) );
                $second = $service->handle_event( $this->completed_event( 2 ) );

                $this->assertCount( 1, $first['credited'] );
                $this->assertCount( 0, $second['credited'] );
                $this->assertSame( 100.0, $this->ledger_gateway->balance( 7 )['available'] );
        }

        public function test_guest_order_event_is_skipped(): void {
                $service = $this->service();
                $this->rules->seed( $this->active_rule( 1 ) );
                $event = $this->completed_event( 1 );
                $event['user_id'] = 0;
                $this->factory->contexts['order:1:status:completed'] = null;

                $this->expectException( SkipEventException::class );
                $service->handle_event( $event );
        }

        public function test_unknown_event_type_is_skipped(): void {
                $service = $this->service();
                $this->expectException( SkipEventException::class );
                $service->handle_event( array( 'type' => 'order.poisoned', 'object_type' => 'order', 'object_id' => 1 ) );
        }

        public function test_refunded_status_event_is_a_noop(): void {
                $service = $this->service();
                $summary = $service->handle_event( array(
                        'type'            => 'order.status.refunded',
                        'user_id'         => 7,
                        'object_type'     => 'order',
                        'object_id'       => 1,
                        'payload'         => '{}',
                        'idempotency_key' => 'order:1:status:refunded',
                        'status'          => 'pending',
                ) );

                $this->assertSame( 'noop', $summary['mode'] );
                $this->assertCount( 0, $this->ledger_gateway->entries );
        }

        public function test_cancelled_order_reverses_earned_cashback_exactly_once(): void {
                $service = $this->service();
                $context = $this->context( 1000.0 );
                $this->factory->contexts['order:1:status:completed'] = $context;
                $this->rules->seed( $this->active_rule( 1 ) );
                $service->handle_event( $this->completed_event( 1 ) );
                $this->assertSame( 100.0, $this->ledger_gateway->balance( 7 )['available'] );

                $cancel = array(
                        'type'            => 'order.status.cancelled',
                        'user_id'         => 7,
                        'object_type'     => 'order',
                        'object_id'       => 1,
                        'payload'         => '{"from":"completed","to":"cancelled"}',
                        'idempotency_key' => 'order:1:status:cancelled',
                        'status'          => 'pending',
                );
                $this->lookup->snapshots[1] = $this->snapshot( 1, 7 );
                $service->handle_event( $cancel );
                $this->assertSame( 0.0, $this->ledger_gateway->balance( 7 )['available'] );

                // رخداد دومِ لغو (رفت‌وبرگشت وضعیت) نباید دوبرابر برگشت بزند.
                $service->handle_event( $cancel );
                $this->assertCount( 2, $this->ledger_gateway->entries );
                $this->assertSame( 0.0, $this->ledger_gateway->balance( 7 )['available'] );

                // رسیدن دوبارهٔ سفارش به completed فقط همان رخداد قبلی را می‌بیند و کش‌بک تکراری نمی‌سازد.
                $service->handle_event( $this->completed_event( 1 ) );
                $this->assertCount( 2, $this->ledger_gateway->entries );
                $this->assertSame( 0.0, $this->ledger_gateway->balance( 7 )['available'] );
        }

        public function test_partial_refund_reverses_exactly_proportionally(): void {
                $service = $this->service();
                $this->factory->contexts['order:1:status:completed'] = $this->context( 1000.0 );
                $this->rules->seed( $this->active_rule( 1 ) );
                $this->lookup->snapshots[1] = $this->snapshot( 1, 7, 1000.0 );
                $service->handle_event( $this->completed_event( 1 ) );
                $this->assertSame( 100.0, $this->ledger_gateway->balance( 7 )['available'] );

                $refund = array(
                        'type'            => 'order.refunded',
                        'user_id'         => 7,
                        'object_type'     => 'order',
                        'object_id'       => 1,
                        'payload'         => '{"refund_id":55,"refund_amount":300}',
                        'idempotency_key' => 'order:1:refund:55',
                        'status'          => 'pending',
                );
                $service->handle_event( $refund );

                $this->assertSame( 70.0, $this->ledger_gateway->balance( 7 )['available'] );

                // تکرار همان رخداد مستردشده اثر دومی ندارد.
                $service->handle_event( $refund );
                $this->assertSame( 70.0, $this->ledger_gateway->balance( 7 )['available'] );

                // مستردشدهٔ دوم نسبت باقی‌مانده را کامل پاک می‌کند (شبیه مبلغ کامل).
                $second = $refund;
                $second['payload'] = '{"refund_id":56,"refund_amount":900}';
                $second['idempotency_key'] = 'order:1:refund:56';
                $service->handle_event( $second );
                $this->assertSame( 0.0, $this->ledger_gateway->balance( 7 )['available'] );
        }

        public function test_registration_rule_grants_fixed_reward(): void {
                $service = $this->service();
                $context = new RuleContext( 'user.registered', 9 );
                $this->factory->contexts['user:9:registered'] = $context;
                $this->rules->seed(
                        array_merge(
                                $this->active_rule( 1 ),
                                array(
                                        'event_type' => 'user.registered',
                                        'reward'     => '{"type":"fixed","value":150}',
                                        'conditions' => '{}',
                                )
                        )
                );

                $summary = $service->handle_event( array(
                        'type'            => 'user.registered',
                        'user_id'         => 9,
                        'object_type'     => 'user',
                        'object_id'       => 9,
                        'payload'         => '{}',
                        'idempotency_key' => 'user:9:registered',
                        'status'          => 'pending',
                ) );

                $this->assertCount( 1, $summary['credited'] );
                $this->assertSame( 150.0, $this->ledger_gateway->balance( 9 )['available'] );
        }

        private function snapshot( int $id, int $user_id, float $base = 1000.0 ): OrderSnapshot {
                $snapshot = new OrderSnapshot();
                $snapshot->id          = $id;
                $snapshot->user_id     = $user_id;
                $snapshot->parent_id   = 0;
                $snapshot->base_amount = $base;
                $snapshot->created_gmt = gmdate( 'Y-m-d H:i:s' );
                return $snapshot;
        }
}
