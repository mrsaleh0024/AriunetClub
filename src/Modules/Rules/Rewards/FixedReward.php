<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules\Rewards;

use LClub\Core\Money;
use LClub\Modules\Rules\RuleContext;

/**
 * Fixed cashback amount in the shop currency.
 */
class FixedReward implements RewardInterface {
	public function type(): string {
		return 'fixed';
	}

	/** @param array<string,mixed> $config */
	public function compute( RuleContext $context, array $config ): ?float {
		$value = isset( $config['value'] ) ? (float) $config['value'] : 0.0;
		if ( $value <= 0.0 ) {
			return null;
		}
		$amount = Money::round( $value );
		return $amount > 0.0 ? $amount : null;
	}
}
