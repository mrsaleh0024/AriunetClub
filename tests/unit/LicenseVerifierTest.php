<?php
/**
 * License verifier unit tests: the S18 matrix against pure verification
 * (signature, product, domain, payload shape) with a runtime keypair.
 *
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Modules\License\DomainNormalizer;
use AriunetClub\Modules\License\LicenseVerifier;
use PHPUnit\Framework\TestCase;

class LicenseVerifierTest extends TestCase {

        /** @var string */
        private static $secret_key;

        /** @var string */
        private static $public_key_hex;

        public static function setUpBeforeClass(): void {
                $keypair           = sodium_crypto_sign_keypair();
                self::$secret_key  = sodium_crypto_sign_secretkey( $keypair );
                self::$public_key_hex = sodium_bin2hex( sodium_crypto_sign_publickey( $keypair ) );
        }

        /** @var LicenseVerifier */
        private $verifier;

        protected function setUp(): void {
                $this->verifier = new LicenseVerifier( self::$public_key_hex );
        }

        /**
         * Builds a signed token the way the maintainer signer does.
         *
         * @param array<string,mixed> $payload Payload fields.
         * @param string|null         $secret  Signing key override.
         * @return string
         */
        private function token( array $payload, ?string $secret = null ): string {
                $json      = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                $signature = sodium_crypto_sign_detached( $json, $secret ?? self::$secret_key );
                return self::b64url( $json ) . '.' . self::b64url( $signature );
        }

        private static function b64url( string $bytes ): string {
                return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
        }

        /**
         * @return array<string,mixed>
         */
        private function valid_payload(): array {
                return array(
                        'product'       => 'ariunetclub',
                        'domain'        => 'example.test',
                        'plan'          => 'pro',
                        'issued_at'     => 1790000000,
                        'duration_days' => 365,
                        'jti'           => '0123456789abcdef',
                );
        }

        public function test_accepts_a_genuine_token_for_the_right_domain(): void {
                $result = $this->verifier->verify( $this->token( $this->valid_payload() ), 'example.test' );
                $this->assertSame( 'ok', $result['code'] );
                $this->assertSame( 'example.test', $result['payload']['domain'] );
        }

        public function test_domain_match_tolerates_www_and_case_only(): void {
                $result = $this->verifier->verify( $this->token( $this->valid_payload() ), 'WWW.Example.Test:443' );
                $this->assertSame( 'ok', $result['code'] );
        }

        public function test_rejects_a_token_issued_for_another_domain(): void {
                $result = $this->verifier->verify( $this->token( $this->valid_payload() ), 'other.test' );
                $this->assertSame( 'bad_domain', $result['code'] );
        }

        public function test_rejects_a_tampered_payload(): void {
                // Decode the genuine payload, change one field, re-encode WITHOUT
                // re-signing: the signature must no longer match the payload bytes.
                $token  = $this->token( $this->valid_payload() );
                list( $payload_b64, $signature_b64 ) = explode( '.', $token, 2 );
                $json   = base64_decode( strtr( $payload_b64, '-_', '+/' ) . '==', true );
                $forged = str_replace( '"pro"', '"enterprise"', (string) $json );
                $this->assertNotSame( $json, $forged );
                $result = $this->verifier->verify( self::b64url( $forged ) . '.' . $signature_b64, 'example.test' );
                $this->assertSame( 'bad_signature', $result['code'] );
        }

        public function test_rejects_a_wrong_signing_key(): void {
                $other  = sodium_crypto_sign_keypair();
                $result = $this->verifier->verify( $this->token( $this->valid_payload(), sodium_crypto_sign_secretkey( $other ) ), 'example.test' );
                $this->assertSame( 'bad_signature', $result['code'] );
        }

        public function test_rejects_a_product_mismatch(): void {
                $payload = $this->valid_payload();
                $payload['product'] = 'someotherplugin';
                $result  = $this->verifier->verify( $this->token( $payload ), 'example.test' );
                $this->assertSame( 'bad_product', $result['code'] );
        }

        /**
         * @dataProvider payload_shape_provider
         *
         * @param array<string,mixed> $payload
         * @param bool                $expected
         */
        public function test_payload_shape_rules( array $payload, bool $expected ): void {
                $this->assertSame( $expected, LicenseVerifier::payload_is_well_formed( $payload ), wp_json_encode( $payload ) );
        }

        /**
         * @return array<int,array{0:array<string,mixed>,1:bool}>
         */
        public function payload_shape_provider(): array {
                $valid = $this->valid_payload();

                $missing_domain   = $valid;
                unset( $missing_domain['domain'] );

                $missing_days     = $valid;
                unset( $missing_days['duration_days'] );

                $float_days       = $valid;
                $float_days['duration_days'] = 365.5;

                $zero_days        = $valid;
                $zero_days['duration_days'] = 0;

                $huge_days        = $valid;
                $huge_days['duration_days'] = 99999;

                $string_issued    = $valid;
                $string_issued['issued_at'] = '1790000000';

                $bad_jti          = $valid;
                $bad_jti['jti']   = 'not hex!';

                $empty_plan       = $valid;
                $empty_plan['plan'] = '';

                return array(
                        'valid payload'          => array( $valid, true ),
                        'missing domain'         => array( $missing_domain, false ),
                        'missing duration_days'  => array( $missing_days, false ),
                        'float duration_days'    => array( $float_days, false ),
                        'zero duration_days'     => array( $zero_days, false ),
                        'huge duration_days'     => array( $huge_days, false ),
                        'string issued_at'       => array( $string_issued, false ),
                        'non-hex jti'            => array( $bad_jti, false ),
                        'empty plan'             => array( $empty_plan, false ),
                );
        }

        /**
         * @dataProvider malformed_token_provider
         */
        public function test_malformed_tokens_never_fatal( string $token ): void {
                $result = $this->verifier->verify( $token, 'example.test' );
                $this->assertNotSame( 'ok', $result['code'] );
                $this->assertNotSame( 'bad_domain', $result['code'] );
                $this->assertNotSame( 'bad_product', $result['code'] );
        }

        /**
         * @return array<int,array{0:string}>
         */
        public function malformed_token_provider(): array {
                return array(
                        'empty'            => array( '' ),
                        'no dot'           => array( 'abcdef' ),
                        'two dots'         => array( 'a.b.c' ),
                        'empty payload'    => array( '.AAAA' ),
                        'empty signature'  => array( 'AAAA.' ),
                        'invalid b64 char' => array( 'AAA*AAA.AAAA' ),
                        'not json'         => array( self::b64url( 'not json at all' ) . '.' . self::b64url( str_repeat( 'x', 64 ) ) ),
                        'short signature'  => array( self::b64url( '{"product":"ariunetclub"}' ) . '.' . self::b64url( 'short' ) ),
                        'json array'       => array( self::b64url( '[1,2,3]' ) . '.' . self::b64url( str_repeat( 'y', 64 ) ) ),
                );
        }

        public function test_missing_sodium_is_reported_not_fatal(): void {
                $verifier = new LicenseVerifierWithMissingSodium( self::$public_key_hex );
                $result   = $verifier->verify( $this->token( $this->valid_payload() ), 'example.test' );
                $this->assertSame( 'crypto_unavailable', $result['code'] );
        }

        public function test_embedded_public_key_is_a_valid_ed25519_key(): void {
                $this->assertSame( 64, strlen( LicenseVerifier::PUBLIC_KEY_HEX ) );
                $this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', LicenseVerifier::PUBLIC_KEY_HEX ) );
                // A verifier built on the embedded key must be constructible and functional.
                $verifier = new LicenseVerifier( LicenseVerifier::PUBLIC_KEY_HEX );
                $result   = $verifier->verify( 'garbage', 'example.test' );
                $this->assertSame( 'malformed', $result['code'] );
        }

        /**
         * @dataProvider domain_normalizer_provider
         */
        public function test_domain_normalizer( string $input, string $expected ): void {
                $this->assertSame( $expected, DomainNormalizer::normalize( $input ) );
        }

        /**
         * @return array<int,array{0:string,1:string}>
         */
        public function domain_normalizer_provider(): array {
                return array(
                        'bare domain'     => array( 'shop.example.ir', 'shop.example.ir' ),
                        'leading www'     => array( 'www.shop.example.ir', 'shop.example.ir' ),
                        'uppercase'       => array( 'SHOP.Example.IR', 'shop.example.ir' ),
                        'full url'        => array( 'https://Shop.Example.ir:8443/path?q=1', 'shop.example.ir' ),
                        'trailing dot'    => array( 'shop.example.ir.', 'shop.example.ir' ),
                        'spaces'          => array( '  shop.example.ir  ', 'shop.example.ir' ),
                        'garbage'         => array( 'not a domain!!!', '' ),
                        'empty'           => array( '', '' ),
                        'scheme only'     => array( 'https://', '' ),
                );
        }
}

/**
 * Simulates a runtime without the sodium extension: the instance probe
 * reports false while the class logic stays intact.
 */
class LicenseVerifierWithMissingSodium extends LicenseVerifier {
        protected function crypto_probe(): bool {
                return false;
        }
}
