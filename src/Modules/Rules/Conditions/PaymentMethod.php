<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules\Conditions;

use LClub\Modules\Rules\RuleContext;

/**
 * Payment-gateway condition. No selected gateways means inactive.
 * Config shape: {"payment_methods":["bacs","cod"]}.
 */
class PaymentMethod implements ConditionInterface {
	public function id(): string {
		return 'payment_method';
	}

	/** @param array<string,mixed> $config */
	public function check( RuleContext $context, array $config ): bool {
		$methods = isset( $config['payment_methods'] ) && is_array( $config['payment_methods'] ) ? array_map( 'strval', $config['payment_methods'] ) : array();
		$methods = array_values( array_filter( $methods ) );
		if ( empty( $methods ) ) {
			return true;
		}
		if ( null === $context->payment_method ) {
			return false;
		}
		return in_array( $context->payment_method, $methods, true );
	}
}
