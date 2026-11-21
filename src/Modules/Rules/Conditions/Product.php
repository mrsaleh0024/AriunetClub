<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules\Conditions;

use AriunetClub\Modules\Rules\RuleContext;

/**
 * Product include/exclude condition (matches the parent product of line items).
 * Config shape: {"products":{"ids":[1,2],"mode":"include|exclude"}}.
 */
class Product implements ConditionInterface {
	public function id(): string {
		return 'product';
	}

	/** @param array<string,mixed> $config */
	public function check( RuleContext $context, array $config ): bool {
		$group = isset( $config['products'] ) && is_array( $config['products'] ) ? $config['products'] : array();
		$ids   = isset( $group['ids'] ) && is_array( $group['ids'] ) ? array_map( 'intval', $group['ids'] ) : array();
		$ids   = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return true;
		}
		if ( null === $context->product_ids ) {
			return false;
		}
		$mode    = isset( $group['mode'] ) && 'exclude' === $group['mode'] ? 'exclude' : 'include';
		$overlap = (bool) array_intersect( $ids, $context->product_ids );
		return 'include' === $mode ? $overlap : ! $overlap;
	}
}
