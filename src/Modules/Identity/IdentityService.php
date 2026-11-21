<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Identity;

use AriunetClub\Core\Settings;

class IdentityService {
	/** @var PhoneNormalizer */
	private $normalizer;

	public function __construct( PhoneNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	public function phone_for_user( int $user_id ): ?string {
		$settings = ( new Settings() )->get();
		$meta_key = isset( $settings['phone_meta'] ) && is_string( $settings['phone_meta'] ) ? $settings['phone_meta'] : 'billing_phone';
		$phone    = get_user_meta( $user_id, $meta_key, true );
		if ( '' === $phone && 'billing_phone' !== $meta_key ) {
			$phone = get_user_meta( $user_id, 'billing_phone', true );
		}

		return is_scalar( $phone ) ? $this->normalizer->normalize( (string) $phone ) : null;
	}

	public function find_user_by_phone( string $phone ): ?int {
		$normalized = $this->normalizer->normalize( $phone );
		if ( null === $normalized ) {
			return null;
		}

		$settings = ( new Settings() )->get();
		$meta_key = isset( $settings['phone_meta'] ) && is_string( $settings['phone_meta'] ) ? $settings['phone_meta'] : 'billing_phone';
		$query    = new \WP_User_Query(
			array(
				'fields'      => 'ID',
				'meta_key'    => $meta_key,
				'number'      => 500,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);
		foreach ( $query->get_results() as $user_id ) {
			if ( $normalized === $this->phone_for_user( (int) $user_id ) ) {
				return (int) $user_id;
			}
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
}
