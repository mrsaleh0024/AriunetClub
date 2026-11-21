<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Modules\Rules\RulePublicSummary;
use PHPUnit\Framework\TestCase;

class RulePublicSummaryTest extends TestCase {

	public function test_percent_reward_uses_persian_digits(): void {
		$this->assertSame( '۲٪ کش‌بک', RulePublicSummary::reward_text( array( 'reward' => wp_json_encode( array( 'type' => 'percent', 'value' => 2 ) ) ) ) );
		$this->assertSame( '۲.۵٪ کش‌بک', RulePublicSummary::reward_text( array( 'reward' => wp_json_encode( array( 'type' => 'percent', 'value' => 2.5 ) ) ) ) );
		$this->assertSame( '۱۰٪ کش‌بک', RulePublicSummary::reward_text( array( 'reward' => wp_json_encode( array( 'type' => 'percent', 'value' => 10.0 ) ) ) ) );
	}

	public function test_fixed_reward_formats_amount(): void {
		$this->assertSame( 'کش‌بک ۵,۰۰۰', RulePublicSummary::reward_text( array( 'reward' => wp_json_encode( array( 'type' => 'fixed', 'value' => 5000 ) ) ) ) );
	}

	public function test_caps_conditions_and_limits_stay_hidden(): void {
		$rule = array(
			'reward'     => wp_json_encode( array( 'type' => 'percent', 'value' => 5, 'max_cap' => 50000 ) ),
			'conditions' => wp_json_encode( array( 'min_amount' => 500000 ) ),
			'limits'     => wp_json_encode( array( 'per_user_count' => 3 ) ),
		);
		$text = RulePublicSummary::reward_text( $rule );
		$this->assertSame( '۵٪ کش‌بک', $text );
		$this->assertStringNotContainsString( 'سقف', $text );
		$this->assertStringNotContainsString( '۵۰۰', $text );
	}

	public function test_missing_or_broken_reward_falls_back(): void {
		$this->assertSame( 'کش‌بک', RulePublicSummary::reward_text( array( 'reward' => null ) ) );
		$this->assertSame( 'کش‌بک', RulePublicSummary::reward_text( array() ) );
		$this->assertSame( 'کش‌بک', RulePublicSummary::reward_text( array( 'reward' => 'not json' ) ) );
		$this->assertSame( 'کش‌بک', RulePublicSummary::reward_text( array( 'reward' => wp_json_encode( array( 'type' => 'percent', 'value' => 0 ) ) ) ) );
	}
}
