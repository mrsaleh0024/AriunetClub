<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules\Conditions;

use AriunetClub\Modules\Rules\RuleContext;

/**
 * Product-category include/exclude condition.
 * Config shape: {"categories":{"ids":[1,2],"mode":"include|exclude"}}.
 */
class Category implements ConditionInterface {
	public function id(): string {
		return 'category';
	}

	/** @param array<string,mixed> $config */
	public function check( RuleContext $context, array $config ): bool {
		$group = isset( $config['categories'] ) && is_array( $config['categories'] ) ? $config['categories'] : array();
		$ids   = isset( $group['ids'] ) && is_array( $group['ids'] ) ? array_map( 'intval', $group['ids'] ) : array();
		$ids   = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return true;
		}
		if ( null === $context->category_ids ) {
			return false;
		}
		$mode    = isset( $group['mode'] ) && 'exclude' === $group['mode'] ? 'exclude' : 'include';
		$overlap = (bool) array_intersect( $ids, $context->category_ids );
		return 'include' === $mode ? $overlap : ! $overlap;
	}
}
