<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Core\Money;

/**
 * Customer-safe rule summary for the account "club" tab. It intentionally
 * renders only the reward (title is printed by the caller) and never
 * conditions, caps or limits, so shop strategy stays private while the
 * customer still learns what they can earn.
 */
class RulePublicSummary {

		/**
		 * One-line reward text such as «۲٪ کش‌بک» or «کش‌بک ۵۰٬۰۰۰».
		 *
		 * @param array<string,mixed> $rule Rule row with a JSON reward column.
		 * @return string
		 */
	public static function reward_text( array $rule ): string {
			$reward = RuleEngine::json( $rule['reward'] ?? null );
			$type   = ( isset( $reward['type'] ) && is_string( $reward['type'] ) ) ? $reward['type'] : 'percent';
			$value  = ( isset( $reward['value'] ) && is_numeric( $reward['value'] ) ) ? (float) $reward['value'] : 0.0;

		if ( 'percent' === $type && $value > 0.0 ) {
				return Money::to_persian_digits( self::trim_number( $value ) ) . '٪ کش‌بک';
		}
		if ( 'fixed' === $type && $value > 0.0 ) {
				return 'کش‌بک ' . Money::format( $value );
		}

			return __( 'کش‌بک', 'ariunetclub' );
	}

		/**
		 * Drops trailing zeros from a percentage value (2 → "2", 2.50 → "2.5").
		 *
		 * @param float $value Raw percentage.
		 * @return string
		 */
	private static function trim_number( float $value ): string {
			$formatted = number_format( $value, 2, '.', '' );
			return rtrim( rtrim( $formatted, '0' ), '.' );
	}
}
