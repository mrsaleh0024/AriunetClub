<?php
/**
 * License guard unit tests: status mapping, capability gates and the
 * fail-closed fallback when the license module never booted.
 *
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Modules\License\LicenseGuard;
use AriunetClub\Modules\License\LicenseStore;
use AriunetClub\Modules\License\LicenseVerifier;
use PHPUnit\Framework\TestCase;

class LicenseGuardTest extends TestCase {

        /** @var string */
        private static $public_key_hex;

        /** @var string */
        private static $secret_key;

        /** @var int */
        private $now = 1790000000;

        public static function setUpBeforeClass(): void {
                $keypair              = sodium_crypto_sign_keypair();
                self::$secret_key     = sodium_crypto_sign_secretkey( $keypair );
                self::$public_key_hex = sodium_bin2hex( sodium_crypto_sign_publickey( $keypair ) );
        }

        /** @var LicenseStore */
        private $store;

        protected function setUp(): void {
                $GLOBALS['ariunetclub_test_settings'] = array();
                $this->store                          = new LicenseStore(
                        new LicenseVerifier( self::$public_key_hex ),
                        function (): int {
                                return $this->now;
                        },
                        function ( string $scheme ): string {
                                return 'test-salt-' . $scheme;
                        }
                );
        }

        private function token( int $days ): string {
                $payload = array(
                        'product'       => 'ariunetclub',
                        'domain'        => 'example.test',
                        'plan'          => 'pro',
                        'issued_at'     => $this->now - 50,
                        'duration_days' => $days,
                        'jti'           => bin2hex( random_bytes( 8 ) ),
                );
                $json      = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                $signature = sodium_crypto_sign_detached( $json, self::$secret_key );
                $b64       = static function ( string $bytes ): string {
                        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
                };
                return $b64( $json ) . '.' . $b64( $signature );
        }

        public function test_guard_locks_everything_without_a_license(): void {
                $guard = new LicenseGuard( $this->store );
                $this->assertFalse( $guard->is_active() );
                $this->assertFalse( $guard->can_manage_rules() );
                $this->assertFalse( $guard->can_adjust_balances() );
                $this->assertFalse( $guard->can_save_settings() );
                $this->assertSame( 'missing', $guard->status()['code'] );
        }

        public function test_guard_unlocks_with_a_valid_license(): void {
                $this->store->activate( $this->token( 365 ) );
                $guard = new LicenseGuard( $this->store );
                $this->assertTrue( $guard->is_active() );
                $this->assertTrue( $guard->can_manage_rules() );
                $this->assertTrue( $guard->can_adjust_balances() );
                $this->assertTrue( $guard->can_save_settings() );
        }

        public function test_guard_locks_again_after_expiry(): void {
                $this->store->activate( $this->token( 5 ) );
                $guard = new LicenseGuard( $this->store );
                $this->assertTrue( $guard->is_active() );

                $this->now += 5 * DAY_IN_SECONDS + 1;
                $guard2 = new LicenseGuard( $this->store );
                $this->assertFalse( $guard2->is_active() );
                $this->assertSame( 'expired', $guard2->status()['code'] );
        }

        public function test_settings_save_filter_returns_the_old_value_while_locked(): void {
                $GLOBALS['ariunetclub_test_settings']['ariunetclub_settings'] = array( 'stored' => true );
                $guard = new LicenseGuard( $this->store );

                $old = array( 'stored' => true );
                $new = array( 'stored' => false, 'hacked' => true );
                $this->assertSame( $old, $guard->maybe_block_settings_save( $new, $old ), 'Locked save must keep the stored value.' );

                $this->store->activate( $this->token( 30 ) );
                // A new request (fresh guard) sees the active license and allows saves.
                $fresh = new LicenseGuard( $this->store );
                $this->assertSame( $new, $fresh->maybe_block_settings_save( $new, $old ) );
        }

        public function test_fallback_instance_fails_closed_when_module_never_booted(): void {
                LicenseGuard::set_instance( null );
                $guard = LicenseGuard::instance();
                $this->assertFalse( $guard->is_active() );
                $this->assertSame( 'unavailable', $guard->status()['code'] );
        }

        public function test_notice_messages_exist_for_every_code(): void {
                foreach ( array( 'missing', 'expired', 'crypto_unavailable', 'unavailable', 'bad_signature', 'anything-else' ) as $code ) {
                        $this->assertNotSame( '', LicenseGuard::notice_message_for( $code ) );
                }
        }

        protected function tearDown(): void {
                LicenseGuard::set_instance( null );
        }
}
