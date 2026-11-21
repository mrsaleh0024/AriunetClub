<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class Money {
	public static function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
	}

	public static function round( float $amount ): float {
		return round( (float) $amount, self::decimals() );
	}

	public static function format( float $amount ): string {
		$formatted = number_format_i18n( self::round( $amount ), self::decimals() );
		return self::to_persian_digits( $formatted );
	}

	public static function to_persian_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}
}
