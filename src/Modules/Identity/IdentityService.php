<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Identity;

use AriunetClub\Core\Settings;

class IdentityService {
	/** @var PhoneNormalizer */
	private $normalizer;

	/** @var Settings|null */
	private $settings;

	public function __construct( PhoneNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	public function phone_for_user( int $user_id ): ?string {
		$meta_key = $this->phone_meta_key();
		$phone    = get_user_meta( $user_id, $meta_key, true );
		if ( '' === $phone && 'billing_phone' !== $meta_key ) {
			$phone = get_user_meta( $user_id, 'billing_phone', true );
		}

		return is_scalar( $phone ) ? $this->normalizer->normalize( (string) $phone ) : null;
	}

	/**
	 * Finds the customer whose stored phone normalizes to the given number.
	 * Both the configured key and billing_phone are scanned in 500-row
	 * batches, so large stores are searched completely instead of being
	 * silently cut off at the first 500 users.
	 */
	public function find_user_by_phone( string $phone ): ?int {
		$normalized = $this->normalizer->normalize( $phone );
		if ( null === $normalized ) {
			return null;
		}

		$meta_keys = array( $this->phone_meta_key() );
		if ( ! in_array( 'billing_phone', $meta_keys, true ) ) {
			$meta_keys[] = 'billing_phone';
		}

		foreach ( $meta_keys as $meta_key ) {
			$offset = 0;
			do {
				$rows = $this->phone_rows( $meta_key, $offset );
				foreach ( $rows as $row ) {
					$user_id = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
					$value   = isset( $row['meta_value'] ) ? $row['meta_value'] : '';
					if ( $user_id > 0 && is_scalar( $value ) && $normalized === $this->normalizer->normalize( (string) $value ) ) {
						return $user_id;
					}
				}
				$offset   += 500;
				$row_count = count( $rows );
			} while ( $row_count >= 500 );
		}

		return null;
	}

	/** @return string[] */
	public function phone_meta_keys( array $keys ): array {
		global $wpdb;
		$like        = $wpdb->esc_like( 'phone' );
		$like_mobile = $wpdb->esc_like( 'mobile' );
		// Meta keys are installation data; values remain parameterized.
		$sql   = $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s ORDER BY meta_key ASC LIMIT 100", '%' . $like . '%', '%' . $like_mobile . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is WordPress-controlled.
		$found = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- read-only query prepared above.
		$found = is_array( $found ) ? array_filter( $found, 'is_string' ) : array();

		return array_values( array_unique( array_merge( $keys, array( 'billing_phone' ), $found ) ) );
	}

	/**
	 * @return string Configured user-meta key that stores the phone number.
	 */
	private function phone_meta_key(): string {
		$settings = $this->settings()->get();
		$raw_key  = isset( $settings['phone_meta'] ) && is_string( $settings['phone_meta'] ) ? $settings['phone_meta'] : 'billing_phone';
		return '' !== $raw_key ? $raw_key : 'billing_phone';
	}

	private function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
		return $this->settings;
	}

	/**
	 * @return array<int,array<string,mixed>> Rows of (user_id, meta_value) for one key.
	 */
	private function phone_rows( string $meta_key, int $offset ): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id ASC LIMIT 500 OFFSET %d", $meta_key, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup on a WordPress-controlled table; values prepared.
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		/** @var array<int, array<string, mixed>> $rows */
		return $rows;
	}
}
