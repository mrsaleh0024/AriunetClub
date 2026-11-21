<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules\Conditions;

use LClub\Modules\Rules\RuleContext;

/**
 * Minimum order/cashback-base amount condition.
 */
class MinAmount implements ConditionInterface {
	public function id(): string {
		return 'min_amount';
	}

	/** @param array<string,mixed> $config */
	public function check( RuleContext $context, array $config ): bool {
		$min = isset( $config['min_amount'] ) ? (float) $config['min_amount'] : 0.0;
		if ( $min <= 0.0 ) {
			return true;
		}
		if ( null === $context->base_amount ) {
			return false;
		}
		return $context->base_amount + 0.00000001 >= $min;
	}
}
