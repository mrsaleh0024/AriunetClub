<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules\Conditions;

use AriunetClub\Modules\Rules\RuleContext;

/**
 * First-order condition. "First order" means the customer has no earlier
 * processing/completed order (paid order history), decided at context build time.
 */
class FirstOrder implements ConditionInterface {
	public function id(): string {
		return 'first_order';
	}

	/** @param array<string,mixed> $config */
	public function check( RuleContext $context, array $config ): bool {
		if ( empty( $config['first_order'] ) ) {
			return true;
		}
		return true === $context->first_order;
	}
}
