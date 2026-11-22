<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\License;

/**
 * Pure, offline Ed25519 license-token verification (phase 5).
 *
 * Token shape: base64url(payload JSON) + "." + base64url(64-byte detached
 * signature). The signature covers the exact payload bytes as received, so
 * no canonicalization is needed. The payload must carry: product, domain,
 * plan, issued_at, duration_days, jti.
 *
 * Expiry is intentionally NOT checked here: the license term starts at
 * activation time (stored server-side by LicenseStore), so only the store
 * can evaluate it. This class only proves the token is authentic, intact,
 * for this product and for this site domain.
 *
 * Every string comparison on attacker-influenced values is timing-safe
 * (sodium verify is constant-time; product/domain use hash_equals).
 */
class LicenseVerifier {

		/** Product identifier embedded in every valid token. */
	public const PRODUCT = 'ariunetclub';

		/**
		 * Ed25519 public key (32-byte hex) issued by the maintainer's signer.
		 * The matching private key never leaves the maintainer's machine; the
		 * phase 5 gate scans the repository for it (S16/S18).
		 */
	public const PUBLIC_KEY_HEX = '8578c091d7c5adfc7e859b1a8ddfd4dcff519f8925754917e7d0a5a14a24ac27';

		/** Hard ceiling for the decoded payload, guarding absurd inputs (S14). */
	private const MAX_PAYLOAD_BYTES = 4096;

		/** Maximum accepted license term. Renewals issue a fresh token. */
	public const MAX_DURATION_DAYS = 3650;

		/** @var string */
		private $public_key;

		/** @var bool */
		private $crypto_available;

	public function __construct( ?string $public_key_hex = null ) {
			$hex                    = $public_key_hex ?? self::PUBLIC_KEY_HEX;
			$this->crypto_available = $this->crypto_probe();
			$this->public_key       = $this->crypto_available && self::valid_hex( $hex, 64 ) ? sodium_hex2bin( $hex ) : '';
	}

		/**
		 * Whether this PHP runtime can verify licenses at all.
		 *
		 * @return bool
		 */
	public static function sodium_available(): bool {
			return function_exists( 'sodium_crypto_sign_verify_detached' )
					&& function_exists( 'sodium_hex2bin' )
					&& function_exists( 'sodium_bin2hex' );
	}

		/**
		 * Instance-level probe so tests can simulate a host without libsodium.
		 *
		 * @return bool
		 */
	protected function crypto_probe(): bool {
			return self::sodium_available();
	}

		/**
		 * Verifies a raw token against this site's product and domain.
		 *
		 * @param string $token       Raw token as pasted by the shop manager.
		 * @param string $site_domain Normalized site domain (DomainNormalizer::normalize).
		 * @return array{code:string,payload:array<string,mixed>|null,message:string}
		 *         code is one of: ok, malformed, bad_signature, bad_payload,
		 *         bad_product, bad_domain, crypto_unavailable.
		 */
	public function verify( string $token, string $site_domain ): array {
		if ( ! $this->crypto_available || '' === $this->public_key ) {
				return self::result( 'crypto_unavailable', null, 'کتابخانهٔ رمزنگاری libsodium روی این هاست در دسترس نیست؛ با مدیر هاست تماس بگیرید.' );
		}

			$token = trim( $token );
		if ( '' === $token || substr_count( $token, '.' ) !== 1 ) {
				return self::result( 'malformed', null, 'قالب کد لایسنس نامعتبر است.' );
		}

			list( $payload_b64, $signature_b64 ) = explode( '.', $token, 2 );
		if ( '' === $payload_b64 || '' === $signature_b64 ) {
				return self::result( 'malformed', null, 'قالب کد لایسنس نامعتبر است.' );
		}

			$payload_json = self::b64url_decode( $payload_b64 );
			$signature    = self::b64url_decode( $signature_b64 );
		if ( null === $payload_json || '' === $payload_json || strlen( $payload_json ) > self::MAX_PAYLOAD_BYTES ) {
				return self::result( 'malformed', null, 'قالب کد لایسنس نامعتبر است.' );
		}
		if ( null === $signature || 64 !== strlen( $signature ) ) {
				return self::result( 'malformed', null, 'امضای کد لایسنس نامعتبر است.' );
		}

			$payload = json_decode( $payload_json, true, 4 );
		if ( ! is_array( $payload ) ) {
			return self::result( 'malformed', null, 'قالب کد لایسنس نامعتبر است.' );
		}
		$string_payload = self::string_keyed_array( $payload );
		if ( null === $string_payload ) {
			return self::result( 'malformed', null, 'قالب کد لایسنس نامعتبر است.' );
		}

		if ( ! self::payload_is_well_formed( $string_payload ) ) {
				return self::result( 'bad_payload', null, 'اطلاعات داخل کد لایسنس کامل یا معتبر نیست.' );
		}

		if ( ! sodium_crypto_sign_verify_detached( $signature, $payload_json, $this->public_key ) ) {
				return self::result( 'bad_signature', null, 'امضای دیجیتال کد لایسنس معتبر نیست؛ کد دستکاری شده یا برای این افزونه صادر نشده است.' );
		}

		$product = $string_payload['product'];
		if ( ! is_string( $product ) || ! hash_equals( self::PRODUCT, $product ) ) {
			return self::result( 'bad_product', null, 'این کد لایسنس برای محصول دیگری صادر شده است.' );
		}

			$raw_domain   = $string_payload['domain'];
			$token_domain = is_string( $raw_domain ) ? DomainNormalizer::normalize( $raw_domain ) : '';
			$site         = DomainNormalizer::normalize( $site_domain );
		if ( '' === $token_domain || '' === $site || ! hash_equals( $token_domain, $site ) ) {
				return self::result( 'bad_domain', null, 'این کد لایسنس برای دامنهٔ دیگری صادر شده است.' );
		}

			return self::result( 'ok', $string_payload, '' );
	}

		/**
		 * Strict schema check: every required field present with the right type
		 * and sane bounds. A tampered payload that removes or corrupts a part is
		 * rejected here (S18).
		 *
		 * @param array<string,mixed> $payload Decoded payload.
		 * @return bool
		 */
	public static function payload_is_well_formed( array $payload ): bool {
			$required = array( 'product', 'domain', 'plan', 'issued_at', 'duration_days', 'jti' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				return false;
			}
		}

		foreach ( array( 'product', 'domain', 'plan' ) as $field ) {
			if ( ! is_string( $payload[ $field ] ) || '' === $payload[ $field ] ) {
					return false;
			}
		}

		if ( ! is_int( $payload['issued_at'] ) || $payload['issued_at'] < 1262304000 || $payload['issued_at'] > 4102444800 ) {
				return false; // 2010-01-01 .. 2100-01-01.
		}

		if ( ! is_int( $payload['duration_days'] ) || $payload['duration_days'] < 1 || $payload['duration_days'] > self::MAX_DURATION_DAYS ) {
				return false;
		}

		if ( ! is_string( $payload['jti'] ) || 1 !== preg_match( '/^[0-9a-f]{8,64}$/', $payload['jti'] ) ) {
				return false;
		}

		if ( mb_strlen( $payload['plan'] ) > 60 || mb_strlen( $payload['product'] ) > 60 || strlen( $payload['domain'] ) > 253 ) {
				return false;
		}

			return true;
	}

		/**
		 * base64url decode without padding tolerance errors.
		 *
		 * @param string $text base64url input.
		 * @return string|null Null on any encoding error.
		 */
	public static function b64url_decode( string $text ): ?string {
		if ( '' === $text || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
				return null;
		}
			$raw = strtr( $text, '-_', '+/' );
			$mod = strlen( $raw ) % 4;
		if ( 2 === $mod ) {
				$raw .= '==';
		} elseif ( 3 === $mod ) {
				$raw .= '=';
		} elseif ( 0 !== $mod ) {
				return null;
		}
			$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- strict mode; decodes the license token envelope only, which is then JSON-validated and signature-checked (never eval'd or unserialized).
			return false === $decoded ? null : $decoded;
	}

		/**
	 * Narrows a decoded JSON array to a string-keyed map (json_decode gives
	 * array<int|string, mixed>).
	 *
	 * @param array<array-key, mixed> $raw Decoded JSON.
	 * @return array<string, mixed>|null Null when any key is not a string.
	 */
	private static function string_keyed_array( array $raw ): ?array {
		foreach ( array_keys( $raw ) as $key ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
		}
		/** @var array<string, mixed> $raw */
		return $raw;
	}

	/**
	 * @param string                   $code    Status code.
	 * @param array<string,mixed>|null $payload Decoded payload on success.
	 * @param string                   $message Persian, user-facing reason.
	 * @return array{code:string,payload:array<string,mixed>|null,message:string}
	 */
	private static function result( string $code, ?array $payload, string $message ): array {
			return array(
				'code'    => $code,
				'payload' => $payload,
				'message' => $message,
			);
	}

		/**
		 * @param string $hex Hex string.
		 * @param int    $length Expected byte length as hex chars (2 per byte).
		 * @return bool
		 */
	private static function valid_hex( string $hex, int $length ): bool {
			return 1 === preg_match( '/^[0-9a-f]{' . $length . '}$/', $hex );
	}
}
