<?php
/**
 * AriunetClub Phase 5 functional tests — real PHP, no WordPress server.
 *
 * Loads the REAL license and dashboard classes on a runtime-stubbed
 * WordPress layer (plus a PDO/SQLite wpdb shim for dashboard SQL) and runs
 * the S18 license matrix, the lock/blur behaviour, guards on rule and
 * adjustment actions, settings blocking, no-network enforcement (S15),
 * migration 1.4.0 idempotency, secret scanning (S16) and dashboard data
 * accuracy end to end.
 *
 * Run: php tools/license-functional-tests.php   (exit 0 = all green)
 * Needs PHP with ext-sodium (bundled since 7.2) and pdo_sqlite.
 */

error_reporting( E_ALL );

if ( PHP_SAPI !== 'cli' ) {
        exit( 'CLI only.' );
}
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
        fwrite( STDERR, "ext-sodium is required for the phase 5 functional suite.\n" );
        exit( 1 );
}

// ---------------------------------------------------------------------------
// 1) WordPress runtime stubs
// ---------------------------------------------------------------------------

$GLOBALS['ariunetclub_test_settings']    = array();
$GLOBALS['ariunetclub_test_user_meta']   = array();
$GLOBALS['ariunetclub_test_hooks']       = array();
$GLOBALS['ariunetclub_test_user_id']     = 1;
$GLOBALS['ariunetclub_test_can_manage']  = true;
$GLOBALS['ariunetclub_test_redirect']    = null;
$GLOBALS['ariunetclub_test_submenus']    = array();
$GLOBALS['ariunetclub_test_settings_err']= array();
$GLOBALS['ariunetclub_test_php_errors']  = array();
$GLOBALS['ariunetclub_test_test_time']   = 1790000000;

define( 'ARIUNETCLUB_VERSION', '0.5.0' );
define( 'ARIUNETCLUB_FILE', __DIR__ . '/../ariunetclub.php' );
define( 'ARIUNETCLUB_PATH', __DIR__ . '/../' );
define( 'ARIUNETCLUB_URL', 'https://example.test/wp-content/plugins/ariunetclub/' );
define( 'ARIUNETCLUB_TEST_SITE_URL', 'https://www.example.test' );
define( 'ABSPATH', __DIR__ . '/wp-stubs/wordpress/' ); // Migrator require_once's wp-admin/includes/upgrade.php from here.
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/pdo-wpdb.php';

class LicenseTestExit extends RuntimeException {
}
class LicenseTestWpDie extends RuntimeException {
        /** @var string */
        public $message_text;
        public function __construct( string $message ) {
                parent::__construct( $message );
                $this->message_text = $message;
        }
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $text, $domain = null ) { echo esc_html__((string) $text ); }
function esc_attr_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ $hook ][] = array( $cb, $args ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ $hook ][] = array( $cb, $args ); }
function apply_filters( $hook, $value, ...$args ) {
        foreach ( $GLOBALS['ariunetclub_test_hooks'][ $hook ] ?? array() as $pair ) {
                $value = call_user_func( $pair[0], $value );
        }
        return $value;
}
function get_option( $name, $default = false ) { return $GLOBALS['ariunetclub_test_settings'][ $name ] ?? $default; }
function add_option( $name, $value ) { if ( ! array_key_exists( $name, $GLOBALS['ariunetclub_test_settings'] ) ) { $GLOBALS['ariunetclub_test_settings'][ $name ] = $value; } return true; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['ariunetclub_test_settings'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['ariunetclub_test_settings'][ $name ] ); return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return $GLOBALS['ariunetclub_test_user_id']; }
function current_user_can( $capability ) { return $GLOBALS['ariunetclub_test_can_manage']; }
function current_time( $type, $gmt = false ) { return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
function wp_salt( $scheme = 'auth' ) { return 'functional-salt-' . $scheme; }
function site_url( $path = '' ) { return 'https://www.example.test' . $path; }
function wp_timezone() { return new DateTimeZone( 'Asia/Tehran' ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, '.', ',' ); }
function wc_get_price_decimals() { return 0; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) {
        if ( ! is_array( $args ) ) {
                return $url;
        }
        $sep = false === strpos( $url, '?' ) ? '?' : '&';
        return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) {
        foreach ( (array) $keys as $key ) {
                $url = (string) preg_replace( '~[&?]' . preg_quote( $key, '~' ) . '=[^&]*~', '', $url );
        }
        return $url;
}
function wp_nonce_url( $url, $action = -1 ) { return add_query_arg( array( '_wpnonce' => 'test-nonce' ), $url ); }
function wp_create_nonce( $action = -1 ) { return 'test-nonce'; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
        $html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';
        if ( $echo ) {
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup from the stub.
        }
        return $html;
}
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
        if ( 'test-nonce' !== ( $_POST[ $query_arg ] ?? $_GET['_wpnonce'] ?? '' ) ) {
                throw new LicenseTestWpDie( 'nonce-failed' );
        }
}
function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
        check_admin_referer( $action, $query_arg ?: '_wpnonce' );
}
function wp_die( $message = '' ) { throw new LicenseTestWpDie( is_string( $message ) ? strip_tags( $message ) : 'wp_die' ); }
function wp_send_json_error( $data = null, $status = null ) {
        throw new LicenseTestExit( 'json-error:' . ( is_array( $data ) ? ( $data['message'] ?? '' ) : '' ) . ':' . (string) $status );
}
function wp_send_json_success( $data = null ) {
        throw new LicenseTestExit( 'json-success' );
}
function wp_safe_redirect( $url ) { $GLOBALS['ariunetclub_test_redirect'] = (string) $url; throw new LicenseTestExit( 'redirect' ); }
function wp_get_referer() { return 'https://example.test/wp-admin/admin.php?page=ariunetclub-rules'; }
function get_user_meta( $user_id, $key, $single = false ) { return $GLOBALS['ariunetclub_test_user_meta'][ (int) $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['ariunetclub_test_user_meta'][ (int) $user_id ][ $key ] = $value; return true; }
function add_settings_error( $setting, $code, $message, $type = 'error' ) { $GLOBALS['ariunetclub_test_settings_err'][] = compact( 'setting', 'code', 'message', 'type' ); }
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $menu_slug, $callback, $position = null ) {
        $GLOBALS['ariunetclub_test_submenus'][] = compact( 'parent', 'menu_title', 'capability', 'menu_slug' );
        return $menu_slug;
}
if ( ! class_exists( 'WP_User' ) ) {
        class WP_User {
                /** @var int */
                public $ID;
                /** @var string */
                public $display_name;
                public function __construct( $id, $name ) {
                        $this->ID           = (int) $id;
                        $this->display_name = (string) $name;
                }
        }
}

function get_users( $args = array() ) {
        $out    = array();
        $ids    = $args['include'] ?? array();
        $names  = $GLOBALS['ariunetclub_test_display_names'] ?? array();
        foreach ( (array) $ids as $id ) {
                if ( isset( $names[ (int) $id ] ) ) {
                        $out[] = new WP_User( (int) $id, $names[ (int) $id ] );
                }
        }
        return $out;
}
function get_userdata( $id ) {
        $names = $GLOBALS['ariunetclub_test_display_names'] ?? array();
        return isset( $names[ (int) $id ] ) ? (object) array( 'ID' => (int) $id, 'display_name' => $names[ (int) $id ] ) : false;
}
// S15: every outbound HTTP attempt explodes.
function wp_remote_get( $url, $args = array() ) { throw new RuntimeException( 'NETWORK CALL ATTEMPTED (S15)' ); }
function wp_remote_post( $url, $args = array() ) { throw new RuntimeException( 'NETWORK CALL ATTEMPTED (S15)' ); }
function wp_remote_request( $url, $args = array() ) { throw new RuntimeException( 'NETWORK CALL ATTEMPTED (S15)' ); }

set_error_handler( static function ( $no, $str, $file, $line ) {
        $GLOBALS['ariunetclub_test_php_errors'][] = "$str at $file:$line";
        return true;
} );

/**
 * Verifier double that simulates a host without libsodium (S18).
 */
class LicenseVerifierNoSodium extends \AriunetClub\Modules\License\LicenseVerifier {
        protected function crypto_probe(): bool {
                return false;
        }
}

// ---------------------------------------------------------------------------
// 2) Harness helpers
// ---------------------------------------------------------------------------

use AriunetClub\Modules\Dashboard\DashboardPage;
use AriunetClub\Modules\Dashboard\DashboardStats;
use AriunetClub\Modules\Dashboard\DashboardStatsRepository;
use AriunetClub\Modules\License\LicenseAdminPage;
use AriunetClub\Modules\License\LicenseGuard;
use AriunetClub\Modules\License\LicenseModule;
use AriunetClub\Modules\License\LicenseStore;
use AriunetClub\Modules\License\LicenseVerifier;

$PASS = 0;
$FAIL = 0;
function check( $label, $condition, $detail = '' ) {
        global $PASS, $FAIL;
        if ( $condition ) {
                $PASS++;
                echo "  PASS  $label\n";
        } else {
                $FAIL++;
                echo "  FAIL  $label" . ( '' !== $detail ? " — $detail" : '' ) . "\n";
        }
}

/** Test keypair (separate from the embedded production key on purpose). */
$test_keypair     = sodium_crypto_sign_keypair();
$test_secret_hex  = sodium_bin2hex( sodium_crypto_sign_secretkey( $test_keypair ) );
$test_public_hex  = sodium_bin2hex( sodium_crypto_sign_publickey( $test_keypair ) );

function b64url( string $bytes ): string {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

function make_token( string $secret_hex, array $overrides = array() ): string {
        $payload = array_merge(
                array(
                        'product'       => 'ariunetclub',
                        'domain'        => 'example.test',
                        'plan'          => 'pro',
                        'issued_at'     => 1790000000,
                        'duration_days' => 365,
                        'jti'           => bin2hex( random_bytes( 8 ) ),
                ),
                $overrides
        );
        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $sig  = sodium_crypto_sign_detached( $json, sodium_hex2bin( $secret_hex ) );
        return b64url( $json ) . '.' . b64url( $sig );
}

function fresh_license_world() {
        $GLOBALS['ariunetclub_test_settings']     = array();
        $GLOBALS['ariunetclub_test_user_meta']    = array();
        $GLOBALS['ariunetclub_test_hooks']        = array();
        $GLOBALS['ariunetclub_test_redirect']     = null;
        $GLOBALS['ariunetclub_test_php_errors']   = array();
        $GLOBALS['ariunetclub_test_test_time']    = 1790000000;
        LicenseGuard::set_instance( null );

        global $test_public_hex;
        $store = new LicenseStore(
                new LicenseVerifier( $test_public_hex ),
                function (): int {
                        return $GLOBALS['ariunetclub_test_test_time'];
                },
                function ( string $scheme ): string {
                        return 'functional-salt-' . $scheme;
                }
        );
        $guard = new LicenseGuard( $store );
        LicenseGuard::set_instance( $guard );
        return array( $store, new LicenseAdminPage( $store ), $guard );
}

function capture_render( callable $render ): string {
        ob_start();
        try {
                $render();
        } catch ( Throwable $error ) {
                ob_end_clean();
                throw $error;
        }
        return (string) ob_get_clean();
}

echo "=== 1) S18 matrix: verifier end to end ===\n";
list( $store ) = fresh_license_world();
$good = make_token( $GLOBALS['test_secret_hex'] );
$st = $store->activate( $good );
check( 'genuine token activates', $st['ok'] && 'ok' === $store->status()['code'] );

// Tampered payload (field changed, signature untouched).
list( $store ) = fresh_license_world();
list( $p, $s ) = explode( '.', make_token( $GLOBALS['test_secret_hex'] ), 2 );
$json   = base64_decode( strtr( $p, '-_', '+/' ) . '==' );
$tamper = b64url( str_replace( '"pro"', '"x"', $json ) ) . '.' . $s;
check( 'tampered payload rejected', 'bad_signature' === $store->activate( $tamper )['code'] );

// Wrong domain.
list( $store ) = fresh_license_world();
check( 'other-domain token rejected', 'bad_domain' === $store->activate( make_token( $GLOBALS['test_secret_hex'], array( 'domain' => 'evil.example' ) ) )['code'] );

// Expired after the term.
list( $store ) = fresh_license_world();
$store->activate( make_token( $GLOBALS['test_secret_hex'], array( 'duration_days' => 10 ) ) );
$GLOBALS['ariunetclub_test_test_time'] += 11 * DAY_IN_SECONDS;
check( 'expired license reported', 'expired' === $store->status()['code'] );

// Removed payload part.
list( $store ) = fresh_license_world();
list( $p ) = explode( '.', make_token( $GLOBALS['test_secret_hex'] ), 2 );
$json    = json_decode( base64_decode( strtr( $p, '-_', '+/' ) . '==' ), true );
unset( $json['duration_days'] );
$partial = b64url( json_encode( $json ) ) . '.' . explode( '.', make_token( $GLOBALS['test_secret_hex'] ), 2 )[1];
check( 'payload with a removed part rejected', 'bad_payload' === $store->activate( $partial )['code'] );

// Token signed by a stranger against the EMBEDDED production key.
list( $store ) = fresh_license_world();
$embedded_store = new LicenseStore( new LicenseVerifier() ); // embedded key, no injection.
check( 'foreign key cannot forge a license for the embedded key', 'bad_signature' === $embedded_store->activate( make_token( $GLOBALS['test_secret_hex'] ) )['code'] );

// No libsodium: transparent error, no fatal (S18).
list( $store ) = fresh_license_world();
$no_sodium_store = new LicenseStore( new LicenseVerifierNoSodium( $test_public_hex ) );
$res = $no_sodium_store->activate( make_token( $GLOBALS['test_secret_hex'] ) );
check( 'missing libsodium is a transparent error', 'crypto_unavailable' === $res['code'] );
// A stored binding on a sodium-less host must surface the same clear code (no fatal).
$GLOBALS['ariunetclub_test_settings'][ LicenseStore::OPTION ] = array(
        'token'        => make_token( $GLOBALS['test_secret_hex'] ),
        'jti'          => 'aaaabbbbccccdddd',
        'activated_at' => 1790000000,
        'expires_at'   => 1790000000 + 365 * DAY_IN_SECONDS,
        'fingerprint'  => 'irrelevant-on-this-host',
);
check( 'missing libsodium never fatals the status path', 'crypto_unavailable' === $no_sodium_store->status()['code'] );

echo "=== 2) Activation term: one year from activation, anti-reset ===\n";
list( $store ) = fresh_license_world();
$t1 = make_token( $GLOBALS['test_secret_hex'], array( 'jti' => 'aaaabbbbccccdddd' ) );
$store->activate( $t1 );
$first = $store->status();
check( 'term starts at activation time', $first['activated_at'] === 1790000000 && $first['expires_at'] === 1790000000 + 365 * DAY_IN_SECONDS );

$GLOBALS['ariunetclub_test_test_time'] += 60 * DAY_IN_SECONDS;
$store->activate( $t1 );
check( 're-entering the same token does not extend', $store->status()['expires_at'] === $first['expires_at'] );

$store->deactivate();
$store->activate( $t1 );
check( 'deactivate + reactivate keeps the original window', $store->status()['expires_at'] === $first['expires_at'] );

$t2 = make_token( $GLOBALS['test_secret_hex'], array( 'jti' => 'eeeeffff00001111', 'duration_days' => 30 ) );
$store->activate( $t2 );
check( 'a new token starts a fresh term', $store->status()['expires_at'] === 1790000000 + 60 * DAY_IN_SECONDS + 30 * DAY_IN_SECONDS );

echo "=== 3) License admin page: activation flow and rendering ===\n";
list( $store, $page, $guard ) = fresh_license_world();
$page->register();
$page->menu();
check( 'admin_post hooks registered', isset( $GLOBALS['ariunetclub_test_hooks']['admin_post_ariunetclub_activate_license'] ) && isset( $GLOBALS['ariunetclub_test_hooks']['admin_post_ariunetclub_deactivate_license'] ) );
check( 'license submenu registered under the club menu', 1 === count( array_filter( $GLOBALS['ariunetclub_test_submenus'], static function ( $item ) { return 'ariunetclub-license' === $item['menu_slug']; } ) ) );

$_POST = array(
        'ariunetclub_license_token' => "  \n" . $good . "  \n",
        'ariunetclub_nonce'         => 'test-nonce',
);
try {
        $page->activate();
        check( 'activation redirects', false );
} catch ( LicenseTestExit $exit ) {
        check( 'activation redirects to the license page', false !== strpos( $GLOBALS['ariunetclub_test_redirect'], 'admin.php' ) && false !== strpos( $GLOBALS['ariunetclub_test_redirect'], 'page=ariunetclub-license' ) );
        check( 'activation flash is positive', false !== strpos( $GLOBALS['ariunetclub_test_redirect'], 'activated' ) );
}
check( 'whitespace-padded token still activated', 'ok' === $store->status()['code'] );
$_POST = array();

// XSS in the flash code parameter must never reach the raw HTML.
$_GET = array( 'ariunetclub_license_flash' => 'invalid', 'ariunetclub_license_code' => '"><script>alert(1)</script>' );
$html = capture_render( static function () use ( $page ) { $page->render(); } );
check( 'invalid flash renders an escaped message', false === strpos( $html, '<script>' ) );
check( 'invalid flash shows a Persian reason', false !== strpos( $html, 'لایسنس' ) );
$_GET = array();

$html = capture_render( static function () use ( $page ) { $page->render(); } );
check( 'status card shows the domain', false !== strpos( $html, 'example.test' ) );
check( 'status card shows Jalali activation date', false !== strpos( $html, '۱۴' ) );
check( 'activation form has a nonce field', false !== strpos( $html, 'ariunetclub_nonce' ) );
check( 'no full token is echoed back', false === strpos( $html, $good ) );
check( 'deactivate form present while licensed', false !== strpos( $html, 'ariunetclub_deactivate_license' ) );
check( 'render produced zero PHP warnings/notices', array() === $GLOBALS['ariunetclub_test_php_errors'], implode( ' | ', $GLOBALS['ariunetclub_test_php_errors'] ) );

// Capability gate.
$GLOBALS['ariunetclub_test_can_manage'] = false;
try {
        $page->render();
        check( 'render without capability is refused', false );
} catch ( LicenseTestWpDie $die ) {
        check( 'render without capability is refused', false !== strpos( $die->message_text, 'اجازه' ) );
}
$GLOBALS['ariunetclub_test_can_manage'] = true;

// Deactivation.
$_POST = array( 'ariunetclub_nonce' => 'test-nonce' );
try {
        $page->deactivate();
} catch ( LicenseTestExit $exit ) {
}
$_POST = array();
check( 'deactivate clears the binding', 'missing' === $store->status()['code'] );

echo "=== 4) Guards lock the mutating admin actions ===\n";
list( $store, $page, $guard ) = fresh_license_world();

$rules_page = new class() {
        public function save_guarded(): void {
                \AriunetClub\Modules\License\LicenseGuard::instance()->assert_rule_management();
        }
        public function adjust_guarded( bool $ajax ): void {
                \AriunetClub\Modules\License\LicenseGuard::instance()->assert_balance_adjustment( $ajax );
        }
};

try {
        $rules_page->save_guarded();
        check( 'rule save locked without license', false );
} catch ( LicenseTestWpDie $die ) {
        check( 'rule save locked without license', false !== strpos( $die->message_text, 'لایسنس' ) );
}

try {
        $rules_page->adjust_guarded( false );
        check( 'manual adjust locked without license', false );
} catch ( LicenseTestWpDie $die ) {
        check( 'manual adjust locked without license', false !== strpos( $die->message_text, 'لایسنس' ) );
}

try {
        $rules_page->adjust_guarded( true );
        check( 'ajax adjust returns a 403 JSON error', false );
} catch ( LicenseTestExit $exit ) {
        $message = $exit->getMessage();
        check( 'ajax adjust returns a 403 JSON error', 0 === strpos( $message, 'json-error:' ) && false !== strpos( $message, 'لایسنس' ) && ':403' === substr( $message, -4 ) );
}

$store->activate( $good );
// A new request (fresh guard) sees the active license.
LicenseGuard::set_instance( new LicenseGuard( $store ) );
try {
        $rules_page->save_guarded();
        $rules_page->adjust_guarded( false );
        check( 'guards open with an active license', true );
} catch ( LicenseTestWpDie $die ) {
        check( 'guards open with an active license', false );
}

echo "=== 5) Settings saving is blocked server-side while locked ===\n";
list( $store, $page, $guard ) = fresh_license_world();
$old = array( 'wallet_fee_title' => 'قبل' );
$new = array( 'wallet_fee_title' => 'بعد' );
$GLOBALS['ariunetclub_test_settings']['ariunetclub_settings'] = $old;
check( 'locked save keeps the stored value', $old === $guard->maybe_block_settings_save( $new, $old ) );
check( 'settings error was raised for the lock', count( $GLOBALS['ariunetclub_test_settings_err'] ) > 0 );

$store->activate( $good );
$guard2 = new LicenseGuard( $store );
check( 'unlocked save passes through', $new === $guard2->maybe_block_settings_save( $new, $old ) );

echo "=== 6) Lock screen: blur + lock icon on plugin pages ===\n";
list( $store, $page, $guard ) = fresh_license_world();
$guard->register();

$_GET['page'] = 'ariunetclub-rules';
$html = capture_render( static function () use ( $guard ) { $guard->render_lock_screen(); } );
check( 'lock screen renders on a plugin page while locked', false !== strpos( $html, 'ariunetclub-lock-screen' ) );
check( 'lock screen carries the lock icon SVG', false !== strpos( $html, '<svg class="ariunetclub-lock-screen__icon"' ) );
check( 'lock screen links to the license page', false !== strpos( $html, 'page=ariunetclub-license' ) );
check( 'lock screen mentions continuing earning/spending', false !== strpos( $html, 'کسب کش‌بک و خرج کیف پول' ) );

$_GET['page'] = 'ariunetclub-license';
$html = capture_render( static function () use ( $guard ) { $guard->render_lock_screen(); } );
check( 'license page itself never locks', '' === trim( $html ) );

$_GET['page'] = 'upload.php';
$html = capture_render( static function () use ( $guard ) { $guard->render_lock_screen(); } );
check( 'non-plugin pages are not overlaid', '' === trim( $html ) );

$store->activate( $good );
$guard_active = new LicenseGuard( $store );
$_GET['page'] = 'ariunetclub-rules';
$html = capture_render( static function () use ( $guard_active ) { $guard_active->render_lock_screen(); } );
check( 'no lock screen with an active license', '' === trim( $html ) );
$_GET = array();
check( 'lock screen rendering raised no PHP warnings', array() === $GLOBALS['ariunetclub_test_php_errors'] );

echo "=== 7) Smart admin notice (dismissable, per status code) ===\n";
list( $store, $page, $guard ) = fresh_license_world();
$GLOBALS['ariunetclub_test_can_manage'] = true;
$html = capture_render( static function () use ( $guard ) { $guard->render_admin_notice(); } );
check( 'notice shown for shop managers while missing', false !== strpos( $html, 'ariunetclub-license-notice' ) );
check( 'notice links to the license page', false !== strpos( $html, 'page=ariunetclub-license' ) );

// Dismiss for this code, then re-appear on a different code.
update_user_meta( 1, 'ariunetclub_license_notice_dismissed', 'missing' );
$html = capture_render( static function () use ( $guard ) { $guard->render_admin_notice(); } );
check( 'dismissed notice hidden', '' === trim( $html ) );

$store->activate( make_token( $GLOBALS['test_secret_hex'], array( 'duration_days' => 3 ) ) );
$guard_exp = new LicenseGuard( $store );
$html = capture_render( static function () use ( $guard_exp ) { $guard_exp->render_admin_notice(); } );
check( 'expiring-soon warning appears within 14 days', false !== strpos( $html, 'به پایان می‌رسد' ) );

$store->deactivate();
$GLOBALS['ariunetclub_test_can_manage'] = false;
$html = capture_render( static function () use ( $guard ) { $guard->render_admin_notice(); } );
check( 'non-managers never see the notice', '' === trim( $html ) );
$GLOBALS['ariunetclub_test_can_manage'] = true;

echo "=== 8) S15: no outbound network anywhere ===\n";
list( $store, $page, $guard ) = fresh_license_world();
$guard->register();
$store->activate( $good );
capture_render( static function () use ( $page ) { $page->render(); } );
check( 'full license lifecycle made zero network attempts', true ); // wp_remote_* stubs would have thrown.

$forbidden = array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_safe_remote', 'curl_exec', 'curl_init', 'fsockopen', 'file_get_contents( \'http', 'file_get_contents("http' );
$hits = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
        if ( 'php' !== $file->getExtension() ) {
                continue;
        }
        $body = (string) file_get_contents( $file->getPathname() );
        foreach ( $forbidden as $needle ) {
                if ( false !== strpos( $body, $needle ) ) {
                        $hits[] = $file->getPathname() . ':' . $needle;
                }
        }
}
check( 'static scan: no network calls in src/', array() === $hits, implode( ', ', $hits ) );

echo "=== 9) Migration 1.4.0 adds the ledger index, idempotently ===\n";
$GLOBALS['ariunetclub_test_settings'] = array();
$GLOBALS['ariunetclub_test_dbdelta']  = array();
$GLOBALS['ariunetclub_test_tables']   = array();

if ( ! function_exists( 'dbDelta' ) ) {
        function dbDelta( $queries = '', $execute = true ) {
                $GLOBALS['ariunetclub_test_dbdelta'][] = $queries;
                return array();
        }
}

// pdo-wpdb.php already declared the empty wpdb base; the fake extends it so
// the Migrator's instanceof guard passes.
class LicenseFakeWpdb extends wpdb {
        public $prefix    = 'wp_';
        public $postmeta  = 'wp_postmeta';
        /** @var array<int,string> */
        public $log = array();
        public function query( $sql ) { $this->log[] = $sql; return 1; }
        public function prepare( $sql, ...$args ) {
                foreach ( $args as $arg ) {
                        $sql = (string) preg_replace( '~%[dsf]~', is_numeric( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
                }
                return $sql;
        }
        public function get_var( $sql ) { return 'wp_ariunetclub_events'; } // table_exists probe: modern names exist, legacy do not.
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
}

$fake_wpdb       = new LicenseFakeWpdb();
$GLOBALS['wpdb'] = $fake_wpdb; // Migrator reads the global.
$migrator        = new AriunetClub\Core\Migrator();
$migrator->migrate();
check( 'dbDelta received the ledger table', false !== strpos( (string) $GLOBALS['ariunetclub_test_dbdelta'][0], 'wp_ariunetclub_ledger' ) );
check( 'phase 5 created_at index present in the schema', false !== strpos( (string) $GLOBALS['ariunetclub_test_dbdelta'][0], 'KEY ledger_created_at (created_at)' ) );
check( 'db version option set to 1.4.0', '1.4.0' === get_option( 'ariunetclub_db_version' ) );
$migrator->migrate();
check( 'second migrate run is harmless', 2 === count( $GLOBALS['ariunetclub_test_dbdelta'] ) && '1.4.0' === get_option( 'ariunetclub_db_version' ) );

echo "=== 10) Dashboard: real data, charts, escaping, no N+1 ===\n";
$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$dash_wpdb = new AriunetClub_PdoWpdb( $pdo, 'wp_', true );
$dash_wpdb->create_schema();

$pdo->exec( "INSERT INTO wp_ariunetclub_rules (title, status, event_type, priority) VALUES ('کش‌بک ویژه', 'active', 'order.status.completed', 5)" );
$now        = $GLOBALS['ariunetclub_test_test_time'];
$today_utc  = strtotime( 'today', $now );
$insert     = $pdo->prepare( 'INSERT INTO wp_ariunetclub_ledger (user_id, asset, entry_type, amount, balance_after, ref_type, ref_id, rule_id, idempotency_key, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)' );
// Yesterday (inside the 30-day window): 40,000 earned by rule 1.
$insert->execute( array( 7, 'cashback', 'credit', 40000, 40000, 'order', 500, 1, 'd-1', 'کش‌بک سفارش ۵۰۰', gmdate( 'Y-m-d H:i:s', $today_utc - DAY_IN_SECONDS + 3600 ) ) );
// Today (local Tehran day): 12,500 earned + 3,000 spent.
$tehran_today_start = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( new DateTimeZone( 'Asia/Tehran' ) )->setTime( 0, 0, 0 )->getTimestamp();
$insert->execute( array( 7, 'cashback', 'credit', 12500, 52500, 'order', 501, 1, 'd-2', 'کش‌بک امروز', gmdate( 'Y-m-d H:i:s', $tehran_today_start + 3600 ) ) );
$insert->execute( array( 7, 'cashback', 'capture', -3000, 49500, 'order', 501, null, 'd-3', 'خرج کیف پول', gmdate( 'Y-m-d H:i:s', $tehran_today_start + 7200 ) ) );
// Long ago: 90,000 earned (outside the window, inside lifetime totals).
$insert->execute( array( 9, 'cashback', 'credit', 90000, 90000, 'order', 400, 1, 'd-4', 'قدیمی', gmdate( 'Y-m-d H:i:s', $today_utc - 100 * DAY_IN_SECONDS ) ) );
// XSS payload in a note.
$insert->execute( array( 9, 'cashback', 'adjust', 100, 100, 'manual_adjustment', null, null, 'd-5', '<script>alert("x")</script>', gmdate( 'Y-m-d H:i:s', $tehran_today_start + 8000 ) ) );
$pdo->exec( "INSERT INTO wp_ariunetclub_balances (user_id, asset, available, held, updated_at) VALUES (7, 'cashback', 49500, 0, '2026-01-01 00:00:00'), (9, 'cashback', 90100, 0, '2026-01-01 00:00:00')" );
$GLOBALS['ariunetclub_test_display_names'] = array( 7 => 'مشتری الف', 9 => '<b>مشتری ب</b>' );

$repository = new DashboardStatsRepository( $dash_wpdb );
$page       = new DashboardPage( $repository );

// Query-count assertions: exactly 5 + 1 + 1 + 1 + 1 repository queries for a full view.
$dash_wpdb->queries = array();
$view = $page->build_view( $GLOBALS['ariunetclub_test_test_time'] );
check( 'dashboard uses exactly 9 queries (no N+1)', 9 === count( $dash_wpdb->queries ), count( $dash_wpdb->queries ) . ' queries' );
check( 'lifetime issued total is exact (142,500)', 142500.0 === (float) $view['totals']['issued'] );
check( 'lifetime spent total is exact (3,000)', 3000.0 === (float) $view['totals']['spent'] );
check( 'today earned counts only the local day (12,500)', 12500.0 === (float) $view['today']['earned'] );
check( 'today spent counts only the local day (3,000)', 3000.0 === (float) $view['today']['spent'] );
check( 'held total from balances (0)', 0.0 === (float) $view['totals']['held'] );
check( 'wallet holders counted (2)', 2 === (int) $view['totals']['users'] );
check( 'series has 30 days', 30 === count( $view['series'] ) );
$series_earned = 0.0;
foreach ( $view['series'] as $point ) { $series_earned += $point['earned']; }
check( '30-day series totals 52,500', 52500.0 === $series_earned );
check( 'top rule joined with its title', 1 === count( $view['top_rules'] ) && 'کش‌بک ویژه' === $view['top_rules'][0]['title'] && 142500.0 === (float) $view['top_rules'][0]['total'] );
check( 'recent activity has 5 rows', 5 === count( $view['recent'] ) );

$html = capture_render( static function () use ( $page ) { $page->render(); } );
check( 'dashboard renders the SVG chart', false !== strpos( $html, '<svg class="ariunetclub-chart"' ) );
check( 'chart has 60 bars (30 days x 2 series)', 60 === substr_count( $html, '<rect class="ariunetclub-chart__bar' ) );
check( 'dashboard shows Persian digits totals', false !== strpos( $html, '۱۴۲,۵۰۰' ) );
check( 'note XSS is escaped', false === strpos( $html, '<script>alert' ) && false !== strpos( $html, '&lt;script&gt;' ) );
check( 'username XSS is escaped', false === strpos( $html, '<b>مشتری' ) );
check( 'render produced zero PHP warnings/notices', array() === $GLOBALS['ariunetclub_test_php_errors'], implode( ' | ', $GLOBALS['ariunetclub_test_php_errors'] ) );


echo "=== 11) Module wiring ===\n";
list( $store ) = fresh_license_world();
$GLOBALS['ariunetclub_test_hooks'] = array();
$module = new LicenseModule();
$module->boot();
check( 'license module id and dependencies', 'license' === $module->id() && array( 'core' ) === $module->dependencies() );
check( 'module boot registered admin hooks', isset( $GLOBALS['ariunetclub_test_hooks']['admin_notices'] ) && isset( $GLOBALS['ariunetclub_test_hooks']['admin_menu'] ) );
check( 'module boot published the global guard', LicenseGuard::instance() instanceof LicenseGuard );

LicenseGuard::set_instance( null );
$guard = LicenseGuard::instance();
check( 'fallback guard fails closed', false === $guard->is_active() && 'unavailable' === $guard->status()['code'] );

$dash_module = new AriunetClub\Modules\Dashboard\DashboardModule();
$dash_module->boot(); // No global wpdb in this harness: must be a silent no-op.
check( 'dashboard module boots safely without wpdb', true );

echo "=== 12) S16: no secrets in the repository ===\n";
$private_hits = array();
$secret_patterns = array(
        '~BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY~',
        '~[0-9a-f]{128}~i', // Ed25519 secret keys in hex.
);
$repo_root = dirname( __DIR__ );
$scan_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $repo_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $scan_iterator as $file ) {
        $relative = ltrim( str_replace( $repo_root, '', $file->getPathname() ), '/' );
        if ( 0 === strpos( $relative, 'vendor/' ) || 0 === strpos( $relative, '.git/' ) || 0 === strpos( $relative, 'node_modules/' ) ) {
                continue;
        }
        if ( 'php' !== $file->getExtension() && 'css' !== $file->getExtension() && 'js' !== $file->getExtension() && 'txt' !== $file->getExtension() && 'po' !== $file->getExtension() && 'md' !== $file->getExtension() ) {
                continue;
        }
        $body = (string) file_get_contents( $file->getPathname() );
        foreach ( $secret_patterns as $pattern ) {
                if ( 1 === preg_match( $pattern, $body ) ) {
                        $private_hits[] = $relative . ' matches ' . $pattern;
                        break;
                }
        }
}
check( 'no private keys or 64-byte secrets anywhere in the plugin tree', array() === $private_hits, implode( '; ', $private_hits ) );
check( 'embedded public key is exactly 32 bytes (hex 64)', 1 === preg_match( '/^[0-9a-f]{64}$/', LicenseVerifier::PUBLIC_KEY_HEX ) );
check( 'signer tool is present for the maintainer', is_file( $repo_root . '/tools/license-signer/license-signer.php' ) );

echo "\n================ RESULT: $PASS passed, $FAIL failed ================\n";
exit( $FAIL > 0 ? 1 : 0 );
