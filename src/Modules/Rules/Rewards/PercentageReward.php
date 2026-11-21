<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules\Rewards;

use LClub\Core\Money;
use LClub\Modules\Rules\RuleContext;

/**
 * Percentage cashback of the order cashback base, with an optional cap.
 */
class PercentageReward implements RewardInterface {
	public function type(): string {
		return 'percent';
	}

	/** @param array<string,mixed> $config */
	public function compute( RuleContext $context, array $config ): ?float {
		if ( null === $context->base_amount ) {
			return null;
		}
		$value = isset( $config['value'] ) ? (float) $config['value'] : 0.0;
		if ( $value <= 0.0 ) {
			return null;
		}
		$value  = min( 100.0, $value );
		$amount = Money::round( $context->base_amount * $value / 100 );
		$cap    = isset( $config['max_cap'] ) ? (float) $config['max_cap'] : 0.0;
		if ( $cap > 0.0 ) {
			$amount = min( Money::round( $cap ), $amount );
		}
		return $amount > 0.0 ? $amount : null;
	}
}
