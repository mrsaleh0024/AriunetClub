<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules\Conditions;

use AriunetClub\Modules\Rules\RuleContext;

/**
 * User-role condition. No selected roles means the condition is inactive.
 * Config shape: {"user_roles":["customer","subscriber"]}.
 */
class UserRole implements ConditionInterface {
	public function id(): string {
		return 'user_role';
	}

	/** @param array<string,mixed> $config */
	public function check( RuleContext $context, array $config ): bool {
		$roles = isset( $config['user_roles'] ) && is_array( $config['user_roles'] ) ? array_map( 'strval', $config['user_roles'] ) : array();
		$roles = array_values( array_filter( $roles ) );
		if ( empty( $roles ) ) {
			return true;
		}
		return (bool) array_intersect( $roles, $context->user_roles );
	}
}
