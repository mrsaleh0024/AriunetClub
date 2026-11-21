<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

/**
 * Order lookup contract. Implementation reads WooCommerce; tests use fakes.
 */
interface OrderLookupInterface {
	/**
	 * @param int $order_id Order id.
	 * @return OrderSnapshot|null Null when the order does not exist.
	 */
	public function find( int $order_id ): ?OrderSnapshot;
}
