<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\License;

/**
 * Stores the activated license and evaluates its full status.
 *
 * Anti-circumvention properties (phase 5 decisions):
 * - The license term (expires_at) is computed once, at first activation,
 *   from the signed duration_days; re-entering the same token never moves
 *   it forward.
 * - Deactivating keeps a history of previously activated token ids (jti)
 *   with their original windows, so deactivate → re-activate cannot reset
 *   the clock either.
 * - The binding row is HMAC-fingerprinted with the site's secret salt, so a
 *   database-only attacker cannot extend expires_at without also knowing
 *   the wp-config authentication keys.
 */
final class LicenseStore {

	const OPTION         = 'ariunetclub_license';
	const HISTORY_OPTION = 'ariunetclub_license_history';
	const HISTORY_CAP    = 20;

	/** Seconds in one day (self-contained: no WordPress constant needed by tools). */
	private const DAY_SECONDS = 86400;

	/** @var LicenseVerifier */
	private $verifier;

	/** @var callable():int */
	private $now;

	/** @var callable(string):string */
	private $salt;

	/** @var array<string,mixed>|null */
	private $binding_cache;

	public function __construct( LicenseVerifier $verifier, ?callable $now = null, ?callable $salt = null ) {
		$this->verifier = $verifier;
		$this->now      = $now ?? static function (): int {
			return time();
		};
		$this->salt     = $salt ?? static function ( string $scheme ): string {
			return function_exists( 'wp_salt' ) ? (string) wp_salt( $scheme ) : '';
		};
	}

	/**
	 * Activates (or re-binds) a license token.
	 *
	 * @param string $token Raw token from the activation form.
	 * @return array{ok:bool,code:string,message:string,renewed:bool}
	 */
	public function activate( string $token ): array {
		$verification = $this->verifier->verify( $token, self::site_domain() );
		if ( 'ok' !== $verification['code'] ) {
			return array(
				'ok'      => false,
				'code'    => $verification['code'],
				'message' => $verification['message'],
				'renewed' => false,
			);
		}

		$payload    = is_array( $verification['payload'] ) ? $verification['payload'] : array();
		$jti        = is_string( $payload['jti'] ?? null ) ? $payload['jti'] : '';
		$days_value = $payload['duration_days'] ?? 0;
		$days       = is_int( $days_value ) ? $days_value : 0;
		$now        = ( $this->now )();

		// Re-entering the currently bound token keeps the original window.
		$current = $this->binding();
		if ( null !== $current && self::binding_field( $current, 'jti' ) === $jti ) {
			return array(
				'ok'      => true,
				'code'    => 'ok',
				'message' => 'این لایسنس از قبل فعال است و تاریخ انقضای آن تغییر نکرد.',
				'renewed' => false,
			);
		}

		// A token seen before (even after a deactivate) restores its own
		// original activation window instead of starting a fresh term.
		foreach ( $this->history() as $entry ) {
			$entry_jti = self::binding_field( $entry, 'jti' );
			if ( '' !== $entry_jti && $entry_jti === $jti ) {
				$binding = $this->build_binding( $token, $payload, self::binding_int( $entry, 'activated_at' ), self::binding_int( $entry, 'expires_at' ) );
				$this->persist( $binding, $this->history() );
				return array(
					'ok'      => true,
					'code'    => 'ok',
					'message' => 'لایسنس قبلاً روی این سایت فعال شده بود؛ بازهٔ اعتبار اصلی آن حفظ شد.',
					'renewed' => false,
				);
			}
		}

		// First activation of a new token: the term starts now.
		$activated_at = $now;
		$expires_at   = $now + ( $days * self::DAY_SECONDS );
		$binding      = $this->build_binding( $token, $payload, $activated_at, $expires_at );
		$this->persist( $binding, $this->history() );
		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => 'لایسنس با موفقیت فعال شد.',
			'renewed' => true,
		);
	}

	/**
	 * Removes the active binding. History is kept on purpose so the same
	 * token cannot be re-activated for a fresh term later.
	 */
	public function deactivate(): void {
		delete_option( self::OPTION );
		$this->binding_cache = null;
	}

	/**
	 * Full license status for this request.
	 *
	 * @return array{code:string,payload:array<string,mixed>|null,activated_at:int,expires_at:int,days_left:int,message:string}
	 *         code is one of: ok, missing, expired, tampered, plus every
	 *         verifier failure code.
	 */
	public function status(): array {
		$now     = ( $this->now )();
		$binding = $this->binding();

		if ( null === $binding ) {
			return self::status_row( 'missing', null, 0, 0, 0, 'لایسنسی فعال نیست.' );
		}

		$verification = $this->verifier->verify( self::binding_field( $binding, 'token' ), self::site_domain() );
		if ( 'ok' !== $verification['code'] ) {
			return self::status_row( $verification['code'], null, 0, 0, 0, $verification['message'] );
		}

		$payload     = is_array( $verification['payload'] ) ? $verification['payload'] : array();
		$payload_jti = is_string( $payload['jti'] ?? null ) ? $payload['jti'] : '';
		if ( self::binding_field( $binding, 'jti' ) !== $payload_jti ) {
			return self::status_row( 'tampered', null, 0, 0, 0, 'اطلاعات لایسنس ذخیره‌شده با کد فعال‌سازی نمی‌خواند؛ لایسنس را دوباره فعال کنید.' );
		}

		$expected = self::binding_field( $binding, 'fingerprint' );
		if ( '' === $expected || ! hash_equals( $this->fingerprint( $binding ), $expected ) ) {
			return self::status_row( 'tampered', null, 0, 0, 0, 'اطلاعات لایسنس ذخیره‌شده دستکاری شده است؛ لایسنس را دوباره فعال کنید.' );
		}

		$activated_at = self::binding_int( $binding, 'activated_at' );
		$expires_at   = self::binding_int( $binding, 'expires_at' );
		if ( $now >= $expires_at ) {
			return self::status_row( 'expired', $payload, $activated_at, $expires_at, 0, 'اعتبار لایسنس به پایان رسیده است.' );
		}

		$days_left = (int) ceil( ( $expires_at - $now ) / self::DAY_SECONDS );
		return self::status_row( 'ok', $payload, $activated_at, $expires_at, $days_left, '' );
	}

	/**
	 * @return array<string,mixed>|null Stored binding or null.
	 */
	public function binding(): ?array {
		if ( null === $this->binding_cache ) {
			$this->binding_cache = self::binding_shape( get_option( self::OPTION, null ) );
		}
		return $this->binding_cache;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function history(): array {
		$raw = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rows = array();
		foreach ( $raw as $entry ) {
			$shaped = self::binding_shape( $entry );
			if ( null !== $shaped ) {
				$rows[] = $shaped;
			}
		}
		return $rows;
	}

	/**
	 * Normalized host of this site (strict: no exceptions, phase 5 choice).
	 *
	 * @return string
	 */
	public static function site_domain(): string {
		$url = '';
		if ( defined( 'ARIUNETCLUB_TEST_SITE_URL' ) ) {
			$url = (string) ARIUNETCLUB_TEST_SITE_URL;
		} elseif ( function_exists( 'site_url' ) ) {
			$url = site_url();
		}
		$host = '';
		if ( '' !== $url ) {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback for non-WordPress runtimes (tests, tools).
			$host  = is_array( $parts ) && isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
		}
		return DomainNormalizer::normalize( $host );
	}

	/**
	 * Narrows any option-sourced value into a string-keyed array.
	 *
	 * @param mixed $raw Value from the options table.
	 * @return array<string,mixed>|null
	 */
	private static function binding_shape( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		foreach ( array_keys( $raw ) as $key ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
		}
		/** @var array<string,mixed> $raw */
		return $raw;
	}

	/**
	 * @param array<string,mixed> $binding Binding-ish row.
	 * @param string              $field   Key to read.
	 * @return string String value or '' when absent/not a string.
	 */
	private static function binding_field( array $binding, string $field ): string {
		$value = $binding[ $field ] ?? null;
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @param array<string,mixed> $binding Binding-ish row.
	 * @param string              $field   Key to read.
	 * @return int Int value or 0 when absent/not an int.
	 */
	private static function binding_int( array $binding, string $field ): int {
		$value = $binding[ $field ] ?? 0;
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * @param string              $token        Raw token.
	 * @param array<string,mixed> $payload      Verified payload.
	 * @param int                 $activated_at UTC timestamp.
	 * @param int                 $expires_at   UTC timestamp.
	 * @return array<string,mixed>
	 */
	private function build_binding( string $token, array $payload, int $activated_at, int $expires_at ): array {
		$jti = is_string( $payload['jti'] ?? null ) ? $payload['jti'] : '';

		$binding = array(
			'token'        => $token,
			'jti'          => $jti,
			'activated_at' => $activated_at,
			'expires_at'   => $expires_at,
		);

		$binding['fingerprint'] = $this->fingerprint( $binding );
		return $binding;
	}

	/**
	 * @param array<string,mixed>            $binding Binding row.
	 * @param array<int,array<string,mixed>> $history Existing history.
	 */
	private function persist( array $binding, array $history ): void {
		update_option( self::OPTION, $binding, false );

		$history[] = array(
			'jti'          => $binding['jti'],
			'activated_at' => $binding['activated_at'],
			'expires_at'   => $binding['expires_at'],
		);

		// Unique by jti, newest last, hard cap.
		$unique = array();
		foreach ( $history as $entry ) {
			$entry_jti = self::binding_field( $entry, 'jti' );
			if ( '' !== $entry_jti ) {
				$unique[ $entry_jti ] = $entry;
			}
		}
		$capped = array_slice( array_values( $unique ), -self::HISTORY_CAP );

		update_option( self::HISTORY_OPTION, $capped, false );
		$this->binding_cache = $binding;
	}

	/**
	 * HMAC over the binding fields with the site's secret salt. An attacker
	 * who can only edit the database cannot forge it, because wp_salt()
	 * derives from the authentication keys in wp-config.php.
	 *
	 * @param array<string,mixed> $binding Binding row (token excluded from
	 *        the digest input; jti + window identify it uniquely).
	 * @return string
	 */
	private function fingerprint( array $binding ): string {
		$data = self::binding_field( $binding, 'jti' ) . '|' . self::binding_int( $binding, 'activated_at' ) . '|' . self::binding_int( $binding, 'expires_at' ) . '|' . self::site_domain() . '|' . substr( LicenseVerifier::PUBLIC_KEY_HEX, 0, 16 );
		return hash_hmac( 'sha256', $data, ( $this->salt )( 'auth' ) );
	}

	/**
	 * @param string                   $code
	 * @param array<string,mixed>|null $payload
	 * @param int                      $activated_at
	 * @param int                      $expires_at
	 * @param int                      $days_left
	 * @param string                   $message
	 * @return array{code:string,payload:array<string,mixed>|null,activated_at:int,expires_at:int,days_left:int,message:string}
	 */
	private static function status_row( string $code, ?array $payload, int $activated_at, int $expires_at, int $days_left, string $message ): array {
		return array(
			'code'         => $code,
			'payload'      => $payload,
			'activated_at' => $activated_at,
			'expires_at'   => $expires_at,
			'days_left'    => $days_left,
			'message'      => $message,
		);
	}
}
