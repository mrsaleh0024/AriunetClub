<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Identity;

class PhoneNormalizer {
	/**
	 * شمارهٔ ایران را به قالب ده رقمی 9xxxxxxxxx تبدیل می‌کند.
	 */
	public function normalize( string $phone ): ?string {
		$phone = strtr(
			$phone,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
		$phone = preg_replace( '/[^0-9+]/', '', $phone );
		if ( null === $phone || '' === $phone ) {
			return null;
		}

		$phone = preg_replace( '/^\\+?98/', '', $phone );
		$phone = preg_replace( '/^0098/', '', (string) $phone );
		$phone = preg_replace( '/^0/', '', (string) $phone );

		return is_string( $phone ) && preg_match( '/^9[0-9]{9}$/', $phone ) ? $phone : null;
	}
}
