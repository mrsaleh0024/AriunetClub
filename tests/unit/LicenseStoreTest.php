<?php
/**
 * License store unit tests: activation binding, the one-year term measured
 * from activation, anti-reset via history, and fingerprint tamper
 * detection. Uses the runtime-stubbed option layer from tests/bootstrap.php.
 *
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Modules\License\LicenseStore;
use AriunetClub\Modules\License\LicenseVerifier;
use PHPUnit\Framework\TestCase;

class LicenseStoreTest extends TestCase {

        /** @var string */
        private static $secret_key;

        /** @var string */
        private static $public_key_hex;

        /** @var int */
        private $now = 1790000000;

        public static function setUpBeforeClass(): void {
                $keypair               = sodium_crypto_sign_keypair();
                self::$secret_key      = sodium_crypto_sign_secretkey( $keypair );
                self::$public_key_hex  = sodium_bin2hex( sodium_crypto_sign_publickey( $keypair ) );
        }

        /** @var LicenseStore */
        private $store;

        protected function setUp(): void {
                $GLOBALS['ariunetclub_test_settings'] = array();
                $verifier                             = new LicenseVerifier( self::$public_key_hex );
                $this->store                          = new LicenseStore(
                        $verifier,
                        function (): int {
                                return $this->now;
                        },
                        function ( string $scheme ): string {
                                return 'test-salt-' . $scheme;
                        }
                );
        }

        /**
         * Signs a token for the stub site domain (www.example.test → example.test).
         *
         * @param array<string,mixed> $payload
         * @return string
         */
        private function token( array $payload ): string {
                $json      = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                $signature = sodium_crypto_sign_detached( $json, self::$secret_key );
                return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ) . '.' . rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
        }

        /**
         * @param string|null $jti
         * @param int|null    $days
         * @return array<string,mixed>
         */
        private function payload( ?string $jti = null, ?int $days = null ): array {
                return array(
                        'product'       => 'ariunetclub',
                        'domain'        => 'example.test',
                        'plan'          => 'pro',
                        'issued_at'     => $this->now - 100,
                        'duration_days' => $days ?? 365,
                        'jti'           => $jti ?? bin2hex( random_bytes( 8 ) ),
                );
        }

        public function test_missing_license_status(): void {
                $status = $this->store->status();
                $this->assertSame( 'missing', $status['code'] );
        }

        public function test_first_activation_starts_a_one_year_window_from_now(): void {
                $result = $this->store->activate( $this->token( $this->payload( 'aaaa1111aaaa1111' ) ) );
                $this->assertTrue( $result['ok'] );
                $this->assertTrue( $result['renewed'] );

                $status = $this->store->status();
                $this->assertSame( 'ok', $status['code'] );
                $this->assertSame( $this->now, $status['activated_at'] );
                $this->assertSame( $this->now + 365 * DAY_IN_SECONDS, $status['expires_at'] );
        }

        public function test_re_entering_the_same_token_does_not_extend_the_window(): void {
                $token = $this->token( $this->payload( 'bbbb2222bbbb2222' ) );
                $this->store->activate( $token );

                $this->now += 30 * DAY_IN_SECONDS; // One month passes.
                $result = $this->store->activate( $token );
                $this->assertTrue( $result['ok'] );
                $this->assertFalse( $result['renewed'] );

                $status = $this->store->status();
                $this->assertSame( 'ok', $status['code'] );
                $this->assertSame( $this->now - 30 * DAY_IN_SECONDS, $status['activated_at'] );
                $this->assertSame( $this->now - 30 * DAY_IN_SECONDS + 365 * DAY_IN_SECONDS, $status['expires_at'] );
        }

        public function test_deactivate_then_reactivate_cannot_reset_the_term(): void {
                $token = $this->token( $this->payload( 'cccc3333cccc3333' ) );
                $this->store->activate( $token );
                $first = $this->store->status();

                $this->now += 100 * DAY_IN_SECONDS;
                $this->store->deactivate();
                $this->assertSame( 'missing', $this->store->status()['code'] );

                $result = $this->store->activate( $token );
                $this->assertTrue( $result['ok'] );
                $this->assertFalse( $result['renewed'] );

                $second = $this->store->status();
                $this->assertSame( 'ok', $second['code'] );
                $this->assertSame( $first['activated_at'], $second['activated_at'], 'Original activation must survive deactivate/reactivate.' );
                $this->assertSame( $first['expires_at'], $second['expires_at'], 'Original expiry must survive deactivate/reactivate.' );
        }

        public function test_a_new_token_renews_the_window(): void {
                $this->store->activate( $this->token( $this->payload( 'dddd4444dddd4444' ) ) );

                $this->now += 200 * DAY_IN_SECONDS;
                $result = $this->store->activate( $this->token( $this->payload( 'eeee5555eeee5555', 180 ) ) );
                $this->assertTrue( $result['renewed'] );

                $status = $this->store->status();
                $this->assertSame( 'ok', $status['code'] );
                $this->assertSame( $this->now, $status['activated_at'] );
                $this->assertSame( $this->now + 180 * DAY_IN_SECONDS, $status['expires_at'] );
        }

        public function test_expired_license_is_reported_exactly_at_the_boundary(): void {
                $this->store->activate( $this->token( $this->payload( 'ffff6666ffff6666', 10 ) ) );
                $this->assertSame( 'ok', $this->store->status()['code'] );

                $this->now += 10 * DAY_IN_SECONDS - 1;
                $this->assertSame( 'ok', $this->store->status()['code'], 'The final second is still valid.' );

                $this->now += 1;
                $status = $this->store->status();
                $this->assertSame( 'expired', $status['code'] );
        }

        public function test_db_only_expiry_tampering_is_detected(): void {
                $this->store->activate( $this->token( $this->payload( 'aabb1122aabb1122', 30 ) ) );

                $binding               = $this->store->binding();
                $binding['expires_at'] = $binding['expires_at'] + 365 * DAY_IN_SECONDS; // Forged in the DB.
                $GLOBALS['ariunetclub_test_settings'][ LicenseStore::OPTION ] = $binding;

                // A fresh request (new store instance) must catch the forgery.
                $fresh = $this->fresh_store();
                $status = $fresh->status();
                $this->assertSame( 'tampered', $status['code'] );
        }

        public function test_swapping_the_stored_token_is_detected(): void {
                $this->store->activate( $this->token( $this->payload( 'ccdd3344ccdd3344', 30 ) ) );

                $other   = $this->token( $this->payload( 'eeff5566eeff5566', 30 ) );
                $binding = $this->store->binding();
                $binding['token'] = $other;
                $GLOBALS['ariunetclub_test_settings'][ LicenseStore::OPTION ] = $binding;

                // A fresh request sees the swapped token: genuine signature, wrong jti.
                $fresh  = $this->fresh_store();
                $status = $fresh->status();
                $this->assertSame( 'tampered', $status['code'] );
        }

        public function test_invalid_tokens_are_rejected_with_clear_codes(): void {
                $this->assertSame( 'malformed', $this->store->activate( 'not-a-token' )['code'] );
                $this->assertSame( 'malformed', $this->store->activate( '' )['code'] );

                $foreign = sodium_crypto_sign_keypair();
                $json    = json_encode( $this->payload( '00112233', 30 ) );
                $bad_sig = sodium_crypto_sign_detached( $json, sodium_crypto_sign_secretkey( $foreign ) );
                $token   = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ) . '.' . rtrim( strtr( base64_encode( $bad_sig ), '+/', '-_' ), '=' );
                $this->assertSame( 'bad_signature', $this->store->activate( $token )['code'] );
        }

        public function test_history_stays_unique_and_capped(): void {
                for ( $i = 0; $i < 25; ++$i ) {
                        $this->store->activate( $this->token( $this->payload( sprintf( '%032d', $i ), 5 ) ) );
                }
                $this->assertCount( LicenseStore::HISTORY_CAP, $this->store->history() );
        }

        public function test_site_domain_matches_the_test_url_host(): void {
                $this->assertSame( 'example.test', LicenseStore::site_domain() );
        }

        protected function tearDown(): void {
                $GLOBALS['ariunetclub_test_settings'] = array();
        }

        /**
         * A new store instance, as a new HTTP request would build.
         */
        private function fresh_store(): LicenseStore {
                return new LicenseStore(
                        new LicenseVerifier( self::$public_key_hex ),
                        function (): int {
                                return $this->now;
                        },
                        function ( string $scheme ): string {
                                return 'test-salt-' . $scheme;
                        }
                );
        }
}
