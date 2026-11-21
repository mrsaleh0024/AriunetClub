<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Rules;

use LClub\Core\Money;

/**
 * Pure math for compensating reversals. Reversals never exceed what was earned
 * on the order; cumulative partial refunds are capped accordingly.
 */
final class ReversalMath {
	/**
	 * Proportional reversal for one refund event.
	 *
	 * @param float $earned           Total cashback credited for the order.
	 * @param float $refund_amount    Refunded amount of this refund event.
	 * @param float $base             Cashback base that produced the earned amount.
	 * @param float $already_reversed Sum of prior reversal entries for the order.
	 * @return float
	 */
	public static function proportional( float $earned, float $refund_amount, float $base, float $already_reversed ): float {
		if ( $earned <= 0.0 || $refund_amount <= 0.0 || $base <= 0.0 ) {
			return 0.0;
		}
		$share = $refund_amount >= $base ? $earned : $earned * $refund_amount / $base;
		$share = min( $earned, max( 0.0, Money::round( $share ) ) );
		return max( 0.0, min( $share, $earned - $already_reversed ) );
	}

	/**
	 * Full reversal for a cancelled/failed order.
	 *
	 * @param float $earned           Total cashback credited for the order.
	 * @param float $already_reversed Sum of prior reversal entries for the order.
	 * @return float
	 */
	public static function full( float $earned, float $already_reversed ): float {
		if ( $earned <= 0.0 ) {
			return 0.0;
		}
		return max( 0.0, min( $earned, Money::round( $earned - $already_reversed ) ) );
	}
}
