<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\License;

/**
 * Canonical site-domain normalization shared by the license verifier.
 *
 * The maintainer-side signer tool (tools/license-signer/license-signer.php)
 * mirrors this exact algorithm; the phase 5 functional suite asserts the two
 * stay in sync. Strict by design: no wildcard subdomains, no localhost or
 * staging exceptions (phase 5 decision) — the token domain must match the
 * shop domain exactly after both are normalized.
 */
final class DomainNormalizer {

		/**
		 * Normalizes a host/domain to its canonical ASCII form.
		 *
		 * Steps: lowercase → strip scheme/path/port → trim leading/trailing dots
		 * → IDN to punycode → strip one leading "www." → validate charset.
		 *
		 * @param string $domain Raw host, URL or bare domain.
		 * @return string Canonical domain, or '' when nothing usable remains.
		 */
	public static function normalize( string $domain ): string {
			$domain = strtolower( trim( $domain ) );
		if ( '' === $domain ) {
				return '';
		}

		if ( false !== strpos( $domain, '://' ) ) {
				$parts  = self::parse_host_parts( $domain );
				$domain = $parts;
		}

			$split  = preg_split( '~[/?#:]+~', $domain, 2 );
			$domain = is_array( $split ) ? (string) $split[0] : '';
			$domain = trim( $domain, ". \t\n\r" );

		if ( function_exists( 'idn_to_ascii' ) ) {
				$ascii = idn_to_ascii( $domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			if ( is_string( $ascii ) && '' !== $ascii ) {
					$domain = $ascii;
			}
		}

		if ( 0 === strpos( $domain, 'www.' ) ) {
				$domain = substr( $domain, 4 );
		}

		if ( 1 !== preg_match( '~^[a-z0-9.-]{1,253}[a-z0-9]$~', $domain ) ) {
				return '';
		}

			return $domain;
	}

		/**
		 * Host extraction through the WordPress wrapper when available (the
		 * sniff discourages raw parse_url). Returns '' on any malformed input.
		 *
		 * @param string $url Raw candidate URL/host.
		 * @return string
		 */
	private static function parse_host_parts( string $url ): string {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback for non-WordPress runtimes (tests, tools).
			return is_array( $parts ) && isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
	}
}
