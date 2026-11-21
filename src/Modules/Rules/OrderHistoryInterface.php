<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

/**
 * Paid-order history contract for the first-order condition.
 * "Paid" means processing or completed orders.
 */
interface OrderHistoryInterface {
	/**
	 * @param int    $user_id    Customer id.
	 * @param string $before_gmt UTC datetime string (Y-m-d H:i:s).
	 * @return bool True when the user has at least one paid order before the datetime.
	 */
	public function has_paid_order_before( int $user_id, string $before_gmt ): bool;
}
