<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

/**
 * WooCommerce-backed order history lookup (works with HPOS and legacy storage).
 */
class WcOrderHistory implements OrderHistoryInterface {
	public function has_paid_order_before( int $user_id, string $before_gmt ): bool {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$before = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $before_gmt, new \DateTimeZone( 'UTC' ) );
		if ( false === $before ) {
			return false;
		}
		$orders = wc_get_orders(
			array(
				'customer_id'  => $user_id,
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'limit'        => 1,
				'date_created' => array( 'before' => $before ),
				'return'       => 'ids',
			)
		);
		return is_array( $orders ) && count( $orders ) > 0;
	}
}
