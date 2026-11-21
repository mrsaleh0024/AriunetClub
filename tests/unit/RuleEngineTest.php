<?php

namespace LClub\Tests;

use LClub\Modules\Rules\RuleContext;
use LClub\Modules\Rules\RuleEngine;
use LClub\Modules\Rules\Conditions\Category;
use LClub\Modules\Rules\Conditions\FirstOrder;
use LClub\Modules\Rules\Conditions\MinAmount;
use LClub\Modules\Rules\Conditions\PaymentMethod;
use LClub\Modules\Rules\Conditions\Product;
use LClub\Modules\Rules\Conditions\UserRole;
use LClub\Modules\Rules\Rewards\FixedReward;
use LClub\Modules\Rules\Rewards\PercentageReward;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase {
	private function context( float $base = 1000.0 ): RuleContext {
		$context = new RuleContext( 'order.status.completed', 7 );
		$context->base_amount   = $base;
		$context->product_ids   = array( 11, 22 );
		$context->category_ids  = array( 3, 4 );
		$context->payment_method = 'bacs';
		$context->user_roles    = array( 'customer' );
		return $context;
	}

	private function rule( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => 1,
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

	private function engine(): RuleEngine {
		return new RuleEngine(
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
	}

	public function test_percentage_reward_with_cap(): void {
		$engine = $this->engine();
		$results = $engine->evaluate( array( $this->rule( array( 'reward' => '{"type":"percent","value":10,"max_cap":0}' ) ) ), $this->context( 1000.0 ), time() );
		$this->assertSame( 100.0, $results[0]['amount'] );

		$capped = $engine->evaluate( array( $this->rule( array( 'reward' => '{"type":"percent","value":10,"max_cap":30}' ) ) ), $this->context( 1000.0 ), time() );
		$this->assertSame( 30.0, $capped[0]['amount'] );
	}

	public function test_fixed_reward(): void {
		$results = $this->engine()->evaluate( array( $this->rule( array( 'reward' => '{"type":"fixed","value":250}' ) ) ), $this->context(), time() );
		$this->assertSame( 250.0, $results[0]['amount'] );
	}

	public function test_percentage_reward_without_order_is_skipped(): void {
		$context = new RuleContext( 'user.registered', 7 );
		$results = $this->engine()->evaluate( array( $this->rule() ), $context, time() );
		$this->assertCount( 0, $results );
	}

	public function test_min_amount_condition(): void {
		$conditions = '{"min_amount":500}';
		$results = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => $conditions ) ) ), $this->context( 500.0 ), time() );
		$this->assertCount( 1, $results );

		$below = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => $conditions ) ) ), $this->context( 499.0 ), time() );
		$this->assertCount( 0, $below );
	}

	public function test_category_include_and_exclude(): void {
		$include = '{"categories":{"ids":[3],"mode":"include"}}';
		$hit = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => $include ) ) ), $this->context(), time() );
		$this->assertCount( 1, $hit );

		$miss = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"categories":{"ids":[9],"mode":"include"}}' ) ) ), $this->context(), time() );
		$this->assertCount( 0, $miss );

		$exclude_hit = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"categories":{"ids":[9],"mode":"exclude"}}' ) ) ), $this->context(), time() );
		$this->assertCount( 1, $exclude_hit );

		$exclude_miss = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"categories":{"ids":[3],"mode":"exclude"}}' ) ) ), $this->context(), time() );
		$this->assertCount( 0, $exclude_miss );
	}

	public function test_product_condition_matches_line_items(): void {
		$hit = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"products":{"ids":[22],"mode":"include"}}' ) ) ), $this->context(), time() );
		$this->assertCount( 1, $hit );

		$miss = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"products":{"ids":[99],"mode":"include"}}' ) ) ), $this->context(), time() );
		$this->assertCount( 0, $miss );
	}

	public function test_user_role_and_payment_method_conditions(): void {
		$hit = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"user_roles":["customer"],"payment_methods":["bacs"]}' ) ) ), $this->context(), time() );
		$this->assertCount( 1, $hit );

		$miss = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"payment_methods":["stripe"]}' ) ) ), $this->context(), time() );
		$this->assertCount( 0, $miss );
	}

	public function test_first_order_condition_uses_context_flag(): void {
		$context = $this->context();
		$context->first_order = true;
		$hit = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"first_order":true}' ) ) ), $context, time() );
		$this->assertCount( 1, $hit );

		$context->first_order = false;
		$miss = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => '{"first_order":true}' ) ) ), $context, time() );
		$this->assertCount( 0, $miss );
	}

	public function test_all_conditions_must_pass_together(): void {
		$conditions = '{"min_amount":500,"user_roles":["customer"]}';
		$hit = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => $conditions ) ) ), $this->context( 600.0 ), time() );
		$this->assertCount( 1, $hit );

		$miss = $this->engine()->evaluate( array( $this->rule( array( 'conditions' => $conditions ) ) ), $this->context( 100.0 ), time() );
		$this->assertCount( 0, $miss );
	}

	public function test_out_of_window_rules_are_ignored(): void {
		$now = time();
		$future = $this->rule( array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $now + 3600 ) ) );
		$this->assertCount( 0, $this->engine()->evaluate( array( $future ), $this->context(), $now ) );

		$expired = $this->rule( array( 'ends_at' => gmdate( 'Y-m-d H:i:s', $now - 3600 ) ) );
		$this->assertCount( 0, $this->engine()->evaluate( array( $expired ), $this->context(), $now ) );
	}

	public function test_exclusive_rule_stops_lower_priority_rules(): void {
		$exclusive = $this->rule( array( 'id' => 1, 'priority' => 5, 'exclusive' => 1, 'reward' => '{"type":"percent","value":5,"max_cap":0}' ) );
		$regular = $this->rule( array( 'id' => 2, 'priority' => 20, 'reward' => '{"type":"fixed","value":100}' ) );
		$results = $this->engine()->evaluate( array( $exclusive, $regular ), $this->context(), time() );
		$this->assertCount( 1, $results );
		$this->assertSame( 1, (int) $results[0]['rule']['id'] );
	}
}
