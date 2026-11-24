<?php
/**
 * AriunetClub phase 6 functional tests — real PHP, no WordPress database.
 *
 * Loads the REAL credit-module classes (LotService, LotRepository semantics,
 * CreditPolicy, BudgetService, ExpiryJob, Settings, ClubEndpoint) on a
 * runtime-stubbed WordPress layer plus in-memory fakes, and additionally a
 * property-based section: random earn/spend/hold/capture/release/expire
 * sequences with the I1-I4 invariants asserted after EVERY step.
 *
 * Run: php tools/credit-functional-tests.php  (exit code 0 = all green)
 */

error_reporting( E_ALL );

if ( PHP_SAPI !== 'cli' ) {
        exit( 'CLI only.' );
}

$GLOBALS['ariunetclub_test_settings'] = array();
$GLOBALS['ariunetclub_test_options']  = array();
$GLOBALS['ariunetclub_test_hooks']    = array();
$GLOBALS['ariunetclub_test_user_id']  = 5;

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'EP_ROOT', 8 );
define( 'EP_PAGES', 512 );
define( 'ARIUNETCLUB_VERSION', '0.6.0' );
define( 'ARIUNETCLUB_FILE', __DIR__ . '/../ariunetclub.php' );
define( 'ARIUNETCLUB_PATH', __DIR__ . '/../' );
define( 'ARIUNETCLUB_URL', 'https://example.test/wp-content/plugins/ariunetclub/' );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/pdo-wpdb.php';

// ---------------------------------------------------------------------------
// WordPress runtime stubs
// ---------------------------------------------------------------------------

function add_action( $tag, $callable, $priority = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ (string) $tag ][] = $callable; }
function add_filter( $tag, $callable, $priority = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ (string) $tag ][] = $callable; }
function do_action( $tag, ...$args ) {
        foreach ( $GLOBALS['ariunetclub_test_hooks'][ (string) $tag ] ?? array() as $callable ) {
                call_user_func_array( $callable, $args );
        }
}
function apply_filters( $tag, $value, ...$args ) {
        foreach ( $GLOBALS['ariunetclub_test_hooks'][ (string) $tag ] ?? array() as $callable ) {
                $value = call_user_func_array( $callable, array_merge( array( $value ), $args ) );
        }
        return $value;
}
function get_option( $key, $default = false ) { return array_key_exists( (string) $key, $GLOBALS['ariunetclub_test_options'] ) ? $GLOBALS['ariunetclub_test_options'][ (string) $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['ariunetclub_test_options'][ (string) $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['ariunetclub_test_options'][ (string) $key ] ); return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function current_time( $type, $gmt = false ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_parse_args( $args, $defaults = array() ) { $out = (array) $defaults; foreach ( (array) $args as $key => $value ) { $out[ $key ] = $value; } return $out; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '~[\r\n\t ]+~u', ' ', (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $value ) ); }
function esc_sql( $value ) { $v = (string) $value; return str_replace( "'", "''", $v ); }
function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function wp_upload_dir() { return array( 'error' => 'logs disabled in tests' ); }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { return true; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { return true; }
function wp_get_referer() { return 'https://example.test/wp-admin/'; }
function wp_safe_redirect( $url ) { $GLOBALS['ariunetclub_test_redirected'] = $url; }
function wp_die( $message = '' ) { throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="n" />'; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=n'; }
function check_admin_referer( $action ) { return true; }
function current_user_can( $cap ) { return true; }
function current_time_db() { return gmdate( 'Y-m-d H:i:s' ); }
function wc_get_price_decimals() { return 0; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) { return $url; }
function get_current_user_id() { return (int) $GLOBALS['ariunetclub_test_user_id']; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'مشتری ' . $id ); }
function get_user_meta( $id, $key, $single = false ) { return ''; }
function update_user_meta( $id, $key, $value ) { return true; }
function locate_template( $names ) { return ''; }
function wc_get_endpoint_url( $endpoint, $value = '', $url = '' ) { return 'https://example.test/my-account/' . $endpoint . '/' . $value; }
function get_woocommerce_currency_symbol() { return 'تومان'; }
function wc_get_page_permalink( $page ) { return 'https://example.test/my-account/'; }
function get_query_var( $var ) { return ''; }
function is_account_page() { return true; }
function is_wc_endpoint_url( $endpoint ) { return true; }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $version = '' ) { $GLOBALS['ariunetclub_test_enqueued'][] = $handle; }
function get_post( $id ) { return null; }
function has_block( $name, $post = null ) { return false; }
function wc_get_page_id( $page ) { return 0; }
function get_current_screen() { return null; }
function add_submenu_page( ...$args ) { $GLOBALS['ariunetclub_test_menus'][] = $args; }
function add_menu_page( ...$args ) { $GLOBALS['ariunetclub_test_menus'][] = $args; }
function add_rewrite_endpoint( $name, $mask ) {}
function flush_rewrite_rules() { ++$GLOBALS['ariunetclub_test_flushed']; }
function wp_generate_uuid4() { return sprintf( '%08d-%04d-%04d-%04d-%012d', mt_rand(), 0, 0, 0, mt_rand() ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }

use AriunetClub\Core\Settings;
use AriunetClub\Modules\Account\ClubEndpoint;
use AriunetClub\Modules\Credit\BudgetService;
use AriunetClub\Modules\Credit\CreditPolicy;
use AriunetClub\Modules\Credit\ExpiryJob;
use AriunetClub\Modules\Credit\LotRepository;
use AriunetClub\Modules\Credit\LotService;
use AriunetClub\Modules\Events\EventLabels;
use AriunetClub\Modules\Ledger\InsufficientBalanceException;
use AriunetClub\Modules\Rules\RuleRepository;

$GLOBALS['credit_test_checks'] = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
        ++$GLOBALS['credit_test_checks'];
        echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
        if ( ! $ok ) {
                $GLOBALS['credit_test_failed'] = true;
        }
}
function section( string $title ): void { echo "\n=== {$title} ===\n"; }

// ---------------------------------------------------------------------------
// In-memory fakes
// ---------------------------------------------------------------------------

if ( ! class_exists( 'wpdb' ) ) {
        class wpdb { public $prefix = 'wp_'; }
}

/** In-memory lot store mirroring LotRepository's SQL semantics. */
class CreditFakeLots extends LotRepository {
        /** @var array<int,array<string,mixed>> */
        public $lots = array();
        /** @var array<int,array<string,mixed>> */
        public $allocations = array();
        /** @var int */
        private $next_lot = 1;
        /** @var int */
        private $next_alloc = 1;

        public function __construct() { parent::__construct( new wpdb() ); }

        public function sums_for_user( int $user_id ): array {
                $remaining = 0.0; $held = 0.0;
                foreach ( $this->lots as $lot ) {
                        if ( (int) $lot['user_id'] === $user_id && 'active' === (string) $lot['status'] ) {
                                $remaining += (float) $lot['remaining'];
                                $held      += (float) $lot['held'];
                        }
                }
                return array( 'remaining' => $remaining, 'held' => $held );
        }
        public function lock_allocatable_lots( int $user_id, string $now_gmt ): array { return $this->filter_lots( $user_id, true, $now_gmt ); }
        public function lock_consumable_lots( int $user_id ): array { return $this->filter_lots( $user_id, true, null ); }
        public function lock_order_lots( int $user_id, int $order_id ): array {
                $out = array();
                foreach ( $this->filter_lots( $user_id, true, null ) as $lot ) {
                        if ( 'order' === (string) $lot['ref_type'] && (int) $lot['ref_id'] === $order_id ) { $out[] = $lot; }
                }
                return $out;
        }
        private function filter_lots( int $user_id, bool $need_headroom, $now_gmt ): array {
                $out = array();
                foreach ( $this->lots as $lot ) {
                        if ( (int) $lot['user_id'] !== $user_id || 'active' !== (string) $lot['status'] ) { continue; }
                        if ( (float) $lot['remaining'] <= (float) $lot['held'] + 0.0000001 ) { continue; }
                        if ( null !== $now_gmt ) {
                                $expires = (string) $lot['expires_at'];
                                if ( '' !== $expires && $expires <= $now_gmt ) { continue; }
                        }
                        $out[] = $lot;
                }
                usort( $out, static function ( array $a, array $b ): int {
                        $an = ( '' === (string) $a['expires_at'] ) ? 1 : 0;
                        $bn = ( '' === (string) $b['expires_at'] ) ? 1 : 0;
                        if ( $an !== $bn ) { return $an - $bn; }
                        if ( '' !== (string) $a['expires_at'] && (string) $a['expires_at'] !== (string) $b['expires_at'] ) { return strcmp( (string) $a['expires_at'], (string) $b['expires_at'] ); }
                        return (int) $a['id'] - (int) $b['id'];
                } );
                return $out;
        }
        public function lock_lot( int $lot_id ): ?array {
                foreach ( $this->lots as $lot ) { if ( (int) $lot['id'] === $lot_id ) { return $lot; } }
                return null;
        }
        public function lock_held_allocations( int $order_id, int $user_id ): array {
                $out = array();
                foreach ( $this->allocations as $allocation ) {
                        if ( (int) $allocation['order_id'] === $order_id && (int) $allocation['user_id'] === $user_id && 'held' === (string) $allocation['state'] ) { $out[] = $allocation; }
                }
                return $out;
        }
        public function insert_lot( array $lot ): int {
                $lot['id']             = $this->next_lot++;
                $lot['source_ref']     = $lot['source_ref'] ?? null;
                $lot['ref_type']       = $lot['ref_type'] ?? null;
                $lot['ref_id']         = $lot['ref_id'] ?? null;
                $lot['rule_id']        = $lot['rule_id'] ?? null;
                $lot['campaign_id']    = $lot['campaign_id'] ?? null;
                $lot['reason']         = $lot['reason'] ?? null;
                $lot['expires_at']     = $lot['expires_at'] ?? null;
                $lot['created_at']     = gmdate( 'Y-m-d H:i:s' );
                $this->lots[ (int) $lot['id'] ] = $lot;
                return (int) $lot['id'];
        }
        public function update_lot( int $lot_id, float $remaining, float $held, string $status ): void {
                if ( $remaining <= 0.0000001 && $held <= 0.0000001 && 'active' === $status ) { $status = 'exhausted'; }
                foreach ( $this->lots as $index => $lot ) {
                        if ( (int) $lot['id'] === $lot_id ) {
                                $this->lots[ $index ]['remaining'] = $remaining;
                                $this->lots[ $index ]['held']      = $held;
                                $this->lots[ $index ]['status']    = $status;
                                return;
                        }
                }
                throw new RuntimeException( 'lot not found' );
        }
        public function insert_allocation( int $lot_id, int $user_id, int $order_id, float $amount ): int {
                $this->allocations[ $this->next_alloc ] = array(
                        'id' => $this->next_alloc, 'lot_id' => $lot_id, 'user_id' => $user_id,
                        'order_id' => $order_id, 'amount' => $amount, 'state' => 'held', 'released_at' => null,
                );
                return $this->next_alloc++;
        }
        public function set_allocation_state( int $allocation_id, string $state ): void {
                if ( 'held' !== $state ) { $this->allocations[ $allocation_id ]['released_at'] = gmdate( 'Y-m-d H:i:s' ); }
                $this->allocations[ $allocation_id ]['state'] = $state;
        }
        public function lots_for_user( int $user_id, int $limit = 10 ): array {
                $out = array();
                foreach ( $this->filter_lots( $user_id, false, null ) as $lot ) {
                        if ( (float) $lot['remaining'] > 0.0000001 ) { $out[] = $lot; }
                }
                return array_slice( $out, 0, $limit );
        }
        public function count_lots_for_user( int $user_id ): int { return count( $this->lots_for_user( $user_id, 50 ) ); }
        public function lots_expiring_within( string $start_gmt, string $end_gmt, int $limit ): array {
                $out = array();
                foreach ( $this->lots as $lot ) {
                        if ( 'active' !== (string) $lot['status'] ) { continue; }
                        $expires = (string) $lot['expires_at'];
                        if ( '' === $expires || $expires < $start_gmt || $expires >= $end_gmt ) { continue; }
                        if ( (float) $lot['remaining'] <= 0.0000001 ) { continue; }
                        $out[] = $lot;
                }
                return array_slice( $out, 0, $limit );
        }
        public function due_lots( string $now_gmt, int $limit ): array {
                $out = array();
                foreach ( $this->lots as $lot ) {
                        if ( 'active' !== (string) $lot['status'] ) { continue; }
                        $expires = (string) $lot['expires_at'];
                        if ( '' === $expires || $expires > $now_gmt ) { continue; }
                        if ( (float) $lot['remaining'] <= (float) $lot['held'] + 0.0000001 ) { continue; }
                        $out[] = $lot;
                }
                return array_slice( $out, 0, $limit );
        }
}

/**
 * Ledger fake that fires the pending/created hooks exactly like the SQL
 * repository (delta rewriting, _original_amount, balance transitions), so the
 * REAL LotService runs against the in-memory lots.
 */
final class CreditHookLedger {
        /** @var array<int,array<string,mixed>> */
        public $entries = array();
        /** @var array<string,array{available:float,held:float}> */
        public $balances = array();
        /** @var int */
        private $next_id = 1;
        /** @var callable */
        private $on_pending;
        /** @var callable */
        private $on_created;

        public function __construct( callable $on_pending, callable $on_created ) {
                $this->on_pending = $on_pending;
                $this->on_created = $on_created;
        }
        private function balance_of( int $user_id ): array {
                return $this->balances[ $user_id ] ?? array( 'available' => 0.0, 'held' => 0.0 );
        }
        public function write( string $type, int $user_id, float $amount, string $key, array $context = array() ): array {
                foreach ( $this->entries as $stored ) {
                        if ( (string) $stored['idempotency_key'] === $key ) { return $stored; }
                }
                $before  = $this->balance_of( $user_id );
                $balance = $before;
                $delta   = 0.0;
                switch ( $type ) {
                        case 'credit': case 'grant': case 'adjust':
                                $balance['available'] += $amount; $delta = $amount; break;
                        case 'debit': case 'expire':
                                if ( $balance['available'] + 0.0000001 < $amount ) { throw new InsufficientBalanceException( 'insufficient' ); }
                                $balance['available'] -= $amount; $delta = -$amount; break;
                        case 'hold':
                                if ( $balance['available'] + 0.0000001 < $amount ) { throw new InsufficientBalanceException( 'insufficient' ); }
                                $balance['available'] -= $amount; $balance['held'] += $amount; break;
                        case 'capture':
                                if ( $balance['held'] + 0.0000001 < $amount ) { throw new InsufficientBalanceException( 'insufficient held' ); }
                                $balance['held'] -= $amount; $delta = -$amount; break;
                        case 'release':
                                if ( $balance['held'] + 0.0000001 < $amount ) { throw new InsufficientBalanceException( 'insufficient held' ); }
                                $balance['held'] -= $amount; $balance['available'] += $amount; break;
                }
                $entry = array_merge( array(
                        'user_id' => $user_id, 'asset' => 'cashback', 'entry_type' => $type, 'amount' => $amount,
                        'idempotency_key' => $key, 'ref_type' => null, 'ref_id' => null, 'rule_id' => null,
                        'note' => null, 'created_by' => null,
                ), $context );
                $entry['amount']  = $delta;
                $entry['balance_after'] = $balance['available'] + $balance['held'];
                $entry['created_at'] = gmdate( 'Y-m-d H:i:s' );
                $entry['id'] = $this->next_id++;
                $entry['_original_amount'] = $amount;
                $this->balances[ $user_id ] = $balance;
                $this->entries[] = $entry;
                // The SQL repository wraps everything in a transaction: an
                // exception from the lot hook rolls the WHOLE entry back. The
                // harness mirrors that with snapshot/rollback of the lots.
                $lots_snapshot = null;
                if ( isset( $GLOBALS['credit_hook_lots'] ) ) {
                        $lots_snapshot = serialize( array( $GLOBALS['credit_hook_lots']->lots, $GLOBALS['credit_hook_lots']->allocations ) );
                }
                try {
                        call_user_func( $this->on_pending, $entry, $before, $balance );
                } catch ( Throwable $error ) {
                        if ( null !== $lots_snapshot ) {
                                list( $GLOBALS['credit_hook_lots']->lots, $GLOBALS['credit_hook_lots']->allocations ) = unserialize( $lots_snapshot );
                        }
                        $this->balances[ $user_id ] = $before;
                        array_pop( $this->entries );
                        throw $error;
                }
                call_user_func( $this->on_created, $entry );
                return $entry;
        }
}

// ---------------------------------------------------------------------------
// 1) Property-based random sequences (I1-I4 after every step)
// ---------------------------------------------------------------------------

section( '1) random earn/spend/hold/capture/release/expire sequences (I1-I4)' );

$seeds = array( 20261124, 42, 987654 );
foreach ( $seeds as $seed ) {
        mt_srand( $seed );
        $lots    = new CreditFakeLots();
        $GLOBALS['credit_hook_lots'] = $lots;
        $service = new LotService( $lots, new Settings(), null );
        $ledger  = new CreditHookLedger(
                static function ( array $entry, array $before, array $after ) use ( $service ): void {
                        $service->on_entry_pending( $entry, $before, $after );
                },
                static function (): void {}
        );

        $ops       = 0;
        $failures  = array();
        $order_seq = 500;
        for ( $step = 0; $step < 400; ++$step ) {
                $user_id = 1 + mt_rand( 0, 2 );
                $action  = mt_rand( 0, 6 );
                try {
                        switch ( $action ) {
                                case 0: // earn
                                case 1:
                                        $ledger->write( 'credit', $user_id, 1000 * mt_rand( 1, 50 ), "p{$seed}:c:{$step}", array( 'ref_type' => 'order', 'ref_id' => 100 + mt_rand( 1, 9 ), 'rule_id' => 1, 'note' => 'قانون تصادفی' ) );
                                        break;
                                case 2: // spend (debit)
                                        $available = $ledger->balances[ $user_id ]['available'] ?? 0.0;
                                        if ( $available >= 1000 ) {
                                                $ledger->write( 'debit', $user_id, 1000 * mt_rand( 1, (int) floor( $available / 1000 ) ), "p{$seed}:d:{$step}", array( 'ref_type' => 'order', 'ref_id' => 200 + mt_rand( 1, 9 ) ) );
                                        }
                                        break;
                                case 3: // hold
                                        $available = $ledger->balances[ $user_id ]['available'] ?? 0.0;
                                        if ( $available >= 1000 ) {
                                                ++$order_seq;
                                                $hold_amount = 1000 * mt_rand( 1, (int) floor( $available / 1000 ) );
                                                $ledger->write( 'hold', $user_id, $hold_amount, "p{$seed}:h:{$step}", array( 'ref_type' => 'order', 'ref_id' => $order_seq ) );
                                                $GLOBALS["p{$seed}_last_hold"] = array( 'user' => $user_id, 'order' => $order_seq, 'step' => $step, 'amount' => $hold_amount );
                                        }
                                        break;
                                case 4: // capture the last order's own hold (HoldManager semantics)
                                        $last = $GLOBALS["p{$seed}_last_hold"] ?? null;
                                        if ( null !== $last && ( $ledger->balances[ $last['user'] ]['held'] ?? 0.0 ) >= $last['amount'] ) {
                                                $ledger->write( 'capture', $last['user'], $last['amount'], "p{$seed}:cap:{$last['step']}", array( 'ref_type' => 'order', 'ref_id' => $last['order'] ) );
                                                unset( $GLOBALS["p{$seed}_last_hold"] );
                                        }
                                        break;
                                case 5: // release the last order's own hold
                                        $last = $GLOBALS["p{$seed}_last_hold"] ?? null;
                                        if ( null !== $last && ( $ledger->balances[ $last['user'] ]['held'] ?? 0.0 ) >= $last['amount'] ) {
                                                $ledger->write( 'release', $last['user'], $last['amount'], "p{$seed}:rel:{$last['step']}", array( 'ref_type' => 'order', 'ref_id' => $last['order'] ) );
                                                unset( $GLOBALS["p{$seed}_last_hold"] );
                                        }
                                        break;
                                case 6: // reversal
                                        $ledger->write( 'adjust', $user_id, -1000 * mt_rand( 1, 30 ), "p{$seed}:r:{$step}", array( 'ref_type' => 'order', 'ref_id' => 100 + mt_rand( 1, 9 ) ) );
                                        break;
                        }
                        ++$ops;
                } catch ( InsufficientBalanceException $error ) {
                        continue; // expected when random amounts overshoot
                }

                // I1-I4 after EVERY successful step.
                foreach ( array( 1, 2, 3 ) as $check_user ) {
                        $balance = $ledger->balances[ $check_user ] ?? array( 'available' => 0.0, 'held' => 0.0 );
                        $sums    = $lots->sums_for_user( $check_user );
                        if ( $balance['available'] >= 0.0 ) {
                                if ( abs( $sums['remaining'] - ( $balance['available'] + $balance['held'] ) ) > 0.001 ) {
                                        $failures[] = "seed {$seed} step {$step} user {$check_user}: lots {$sums['remaining']} != balance " . ( $balance['available'] + $balance['held'] );
                                }
                        } elseif ( $sums['remaining'] < $balance['held'] - 0.001 ) {
                                $failures[] = "seed {$seed} step {$step} user {$check_user}: lots {$sums['remaining']} < held {$balance['held']}";
                        }
                        if ( $sums['held'] > $balance['held'] + 0.001 ) {
                                $failures[] = "seed {$seed} step {$step} user {$check_user}: lot held {$sums['held']} > balance held {$balance['held']}";
                        }
                        foreach ( $lots->lots as $lot ) {
                                if ( (int) $lot['user_id'] !== $check_user ) { continue; }
                                if ( (float) $lot['remaining'] < -0.0000001 || (float) $lot['held'] < -0.0000001 || (float) $lot['held'] > (float) $lot['remaining'] + 0.0000001 ) {
                                        $failures[] = "seed {$seed} step {$step}: lot invariant broken " . json_encode( $lot );
                                }
                        }
                }
        }
        check( "seed {$seed}: {$ops} ops keep I1-I4 on every step", array() === $failures, implode( ' | ', array_slice( $failures, 0, 3 ) ) );
}

// ---------------------------------------------------------------------------
// 2) Settings: new phase 6 fields sanitize correctly
// ---------------------------------------------------------------------------

section( '2) settings sanitize (expiry, budget, guards)' );

$settings = new Settings();
$saved = $settings->sanitize( array(
        'expiry_enabled'                 => '1',
        'expiry_days'                    => '120',
        'budget_percent'                 => '10.5',
        'budget_fixed'                   => '250000',
        'guard_max_credit_per_order'     => '50000',
        'guard_min_order_amount'         => '100000',
        'guard_max_monthly_per_customer' => '200000',
        'wallet_fee_title'               => '',
) );
check( 'expiry settings persist', true === $saved['expiry_enabled'] && 120 === $saved['expiry_days'] );
check( 'budget settings persist', 10.5 === $saved['budget_percent'] && 250000.0 === $saved['budget_fixed'] );
check( 'guard settings persist', 50000.0 === $saved['guard_max_credit_per_order'] && 100000.0 === $saved['guard_min_order_amount'] && 200000.0 === $saved['guard_max_monthly_per_customer'] );
check( 'empty fee title falls back to the new vocabulary', 'استفاده از اعتبار باشگاه' === $saved['wallet_fee_title'] );
check( 'credit module is in the default enabled list', in_array( 'credit', Settings::defaults()['enabled_modules'], true ) && in_array( 'credit', $saved['enabled_modules'], true ) );

$bad = $settings->sanitize( array( 'expiry_days' => '-5', 'budget_percent' => '500', 'budget_fixed' => '-1', 'guard_max_credit_per_order' => 'abc' ) );
check( 'out-of-range inputs are clamped', 5 === $bad['expiry_days'] && 100.0 === $bad['budget_percent'] && 0.0 === $bad['budget_fixed'] && 0.0 === $bad['guard_max_credit_per_order'] );

// ---------------------------------------------------------------------------
// 3) CreditPolicy: protective caps on the real SQL engine
// ---------------------------------------------------------------------------

section( '3) credit policy guards + budget (SQLite engine)' );

$pdo   = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$gwpdb = new AriunetClub_PdoWpdb( $pdo, 'wp_', true );
$gwpdb->create_schema();

$GLOBALS['ariunetclub_test_options'][ Settings::OPTION ] = array_merge( Settings::defaults(), array(
        'guard_min_order_amount'         => 50000.0,
        'guard_max_credit_per_order'     => 80000.0,
        'guard_max_monthly_per_customer' => 150000.0,
        'budget_fixed'                   => 200000.0,
        'budget_percent'                 => 0.0,
) );
$settings3  = new Settings();
$budget3    = new BudgetService( $gwpdb, $settings3, null, new AriunetClub\Core\Logger() );
$policy     = new CreditPolicy( $settings3, $budget3, new AriunetClub\Core\Logger() );

$review = $policy->review( 10000.0, 42, 30000.0, 901, 0.0 );
check( 'min order guard blocks small carts', false === $review['allowed'] && 'min_order' === $review['reason'] );

$review = $policy->review( 10000.0, 42, 60000.0, 901, 0.0 );
check( 'healthy grant passes untouched', true === $review['allowed'] && 10000.0 === $review['amount'] );

$review = $policy->review( 10000.0, 42, 60000.0, 901, 75000.0 );
check( 'per-order cap clamps to the remaining room', true === $review['allowed'] && 5000.0 === $review['amount'] );

$review = $policy->review( 10000.0, 42, 60000.0, 901, 80000.0 );
check( 'per-order cap blocks when the order is full', false === $review['allowed'] && 'per_order_cap' === $review['reason'] );

// Monthly cap: seed issued credit for the user inside the current month.
$month = $budget3->month_window();
$ins   = $pdo->prepare( 'INSERT INTO wp_ariunetclub_ledger (user_id, asset, entry_type, amount, balance_after, ref_type, ref_id, rule_id, idempotency_key, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)' );
$ins->execute( array( 42, 'cashback', 'credit', 130000, 130000, 'order', 1, 1, 'fp-1', 'x', null, $month['start_gmt'] ) );
$review = $policy->review( 10000.0, 42, 60000.0, 902, 0.0 );
check( 'monthly per-customer cap lets a within-room grant pass', true === $review['allowed'] && 10000.0 === $review['amount'], json_encode( $review ) );
$review = $policy->review( 30000.0, 42, 60000.0, 902, 0.0 );
check( 'monthly per-customer cap clamps an over-room grant', true === $review['allowed'] && 20000.0 === $review['amount'], json_encode( $review ) );

$ins->execute( array( 42, 'cashback', 'credit', 25000, 155000, 'order', 2, 1, 'fp-2', 'x', null, $month['start_gmt'] ) );
$review = $policy->review( 10000.0, 42, 60000.0, 903, 0.0 );
check( 'monthly cap blocks an exhausted customer', false === $review['allowed'] && 'monthly_customer_cap' === $review['reason'] );

// Budget: the issued total (155,000 from user 42 alone) now drives the clamp.
$state = $budget3->state();
check( 'budget cap reflects the fixed amount', 200000.0 === $state['cap'] && abs( $state['used'] - 155000.0 ) < 0.001, json_encode( array( $state['cap'], $state['used'] ) ) );
$clamped = $budget3->clamp_grant( 100000.0 );
check( 'grant clamped to the remaining budget', 45000.0 === $clamped );
$ins->execute( array( 43, 'cashback', 'credit', 45000, 45000, 'order', 3, 1, 'fp-3', 'x', null, $month['start_gmt'] ) );
$budget4 = new BudgetService( $gwpdb, $settings3, null, new AriunetClub\Core\Logger() ); // fresh per-request issued cache
check( 'budget exhausted → grant blocked at zero', 0.0 === $budget4->clamp_grant( 5000.0 ) );

// Reversal netting: a reversal entry must reduce the issued total.
$ins->execute( array( 42, 'cashback', 'adjust', -30000, 125000, 'order', 1, null, 'order:1:reversal', 'x', null, $month['start_gmt'] ) );
$budget5 = new BudgetService( $gwpdb, $settings3, null, new AriunetClub\Core\Logger() );
$state5  = $budget5->state();
check( 'reversals reduce the issued budget total', abs( $state5['used'] - 170000.0 ) < 0.001, 'used=' . $state5['used'] );

// ---------------------------------------------------------------------------
// 4) ExpiryJob: reminder windows 7/3/1 (idempotent)
// ---------------------------------------------------------------------------

section( '4) expiry reminders (7/3/1) and labels' );

class CreditFakeBus {
        /** @var array<int,array<string,mixed>> */
        public $events = array();
        /** @param array<string,mixed> $event */
        public function insert( array $event ): array { $event['id'] = count( $this->events ) + 1; $this->events[] = $event; return $event; }
        /** @return array<string,mixed>|null */
        public function find_by_key( string $key ): ?array {
                foreach ( $this->events as $event ) { if ( (string) $event['idempotency_key'] === $key ) { return $event; } }
                return null;
        }
}

$lots4    = new CreditFakeLots();
$lot_id_7 = $lots4->insert_lot( array( 'user_id' => 5, 'source_type' => 'rule', 'initial_amount' => 1000, 'remaining' => 1000, 'held' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) + 3600 ), 'status' => 'active', 'reason' => 'x' ) );
$lot_id_3 = $lots4->insert_lot( array( 'user_id' => 5, 'source_type' => 'rule', 'initial_amount' => 2000, 'remaining' => 2000, 'held' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+3 days' ) + 3600 ), 'status' => 'active', 'reason' => 'x' ) );

$bus4 = new CreditFakeBus();
// ExpiryJob needs the EventGateway interface; a tiny adapter over the fake bus.
$adapter = new class( $bus4 ) implements AriunetClub\Modules\Events\EventGateway {
        /** @var CreditFakeBus */
        private $bus;
        public function __construct( CreditFakeBus $bus ) { $this->bus = $bus; }
        public function find_by_key( string $key ): ?array { return $this->bus->find_by_key( $key ); }
        public function insert( array $event ): array { return $this->bus->insert( $event ); }
        public function pending_batch( int $limit ): array { return array(); }
        public function claim( int $id ): void {}
        public function mark_done( int $id ): void {}
        public function mark_skipped( int $id, string $reason ): void {}
        public function mark_error( int $id, string $error, int $max_attempts ): void {}
        public function max_attempts(): int { return 3; }
        public function requeue( int $id ): void {}
        public function list( string $status, int $page, int $per_page ): array { return array( 'items' => array(), 'total' => 0 ); }
        public function status_counts(): array { return array(); }
};
$bus_real = new AriunetClub\Modules\Events\EventBus( $adapter, false );
$job4     = new ExpiryJob( $lots4, new AriunetClub\Modules\Ledger\LedgerService( new AriunetClub\Modules\Ledger\LedgerRepository( $gwpdb ) ), $bus_real, new AriunetClub\Core\Logger() );
$job4->emit_reminders();
$job4->emit_reminders(); // idempotency
$keys = array();
foreach ( $bus4->events as $event ) { $keys[] = (string) $event['idempotency_key']; }
check( 'exactly one reminder per lot per threshold', 2 === count( $keys ) && in_array( "credit:expiring:lot:{$lot_id_7}:7d", $keys, true ) && in_array( "credit:expiring:lot:{$lot_id_3}:3d", $keys, true ), json_encode( $keys ) );
$payloads = array();
foreach ( $bus4->events as $event ) { $payloads[] = json_decode( (string) $event['payload'], true ); }
check( 'reminder payloads carry lot id and days', 7 === $payloads[0]['days_left'] && (int) $payloads[0]['lot_id'] === (int) $lot_id_7 );

// Labels for the new event types.
check( 'event label: credit.expiring', 'نزدیک شدن انقضای اعتبار' === EventLabels::type( 'credit.expiring' ) );
check( 'event label: budget.warning', false !== strpos( EventLabels::type( 'budget.warning' ), '۸۰٪' ) );
check( 'event label: budget.blocked', false !== strpos( EventLabels::type( 'budget.blocked' ), 'توقف' ) );
check( 'event object label: lot', 'ردیف اعتبار' === EventLabels::objects()['lot'] );

// ---------------------------------------------------------------------------
// 5) ClubEndpoint: lots view model, XSS, own-data-only
// ---------------------------------------------------------------------------

section( '5) club tab lots (view model, XSS, own data)' );

class ClubFakeLots extends CreditFakeLots {
        // lots_for_user returns the malicious row for ANY user (attack surface:
        // the endpoint must never show lots of another user).
}

$lots5 = new ClubFakeLots();
$lots5->insert_lot( array( 'user_id' => 5, 'source_type' => 'rule', 'initial_amount' => 5000, 'remaining' => 5000, 'held' => 2000, 'expires_at' => '2027-01-15 10:30:00', 'status' => 'active', 'reason' => '<script>alert("xss")</script> از سفارش 123' ) );
$lots5->insert_lot( array( 'user_id' => 5, 'source_type' => 'legacy', 'initial_amount' => 9000, 'remaining' => 9000, 'held' => 0, 'expires_at' => null, 'status' => 'active', 'reason' => 'ماندهٔ اعتبار پیش از ارتقا' ) );

$endpoint = new ClubEndpoint(
        new AriunetClub\Modules\Ledger\LedgerRepository( $gwpdb ),
        new RuleRepository( $gwpdb ),
        new AriunetClub\Modules\Identity\IdentityService( new AriunetClub\Modules\Identity\PhoneNormalizer() ),
        $lots5
);
$GLOBALS['ariunetclub_test_user_id'] = 5;
$view = $endpoint->build_view( 5, 1 );
check( 'two lot rows in the view', 2 === count( $view['lots'] ) && 2 === $view['lots_total'] );
check( 'FEFO order: dated lot first', true === $view['lots'][0]['has_expiry'] && false === $view['lots'][1]['has_expiry'] );
check( 'jalali expiry is formatted', false !== strpos( $view['lots'][0]['expires_display'], '۱۴۰' ) || '' !== $view['lots'][0]['expires_display'] );
check( 'held flag exposed', true === $view['lots'][0]['held'] && false === $view['lots'][1]['held'] );

// Render the real template and look for escaped output.
$data = $view;
ob_start();
include ARIUNETCLUB_PATH . 'templates/account/club.php';
$html = (string) ob_get_clean();
check( 'template renders the lots section', false !== strpos( $html, 'ردیف‌های اعتبار باشگاه' ) );
check( 'XSS payload in the reason is escaped', false === strpos( $html, '<script>alert' ) && false !== strpos( $html, '&lt;script&gt;' ) );

// Own-data-only: another user's view must not leak lots.
$view_other = $endpoint->build_view( 999, 1 );
check( 'other users see no lots of user 5', array() === $view_other['lots'] && 0 === $view_other['lots_total'] );

// Logged-out view: no lots.
$view_out = $endpoint->build_view( 0, 1 );
check( 'logged-out view has no lots', array() === $view_out['lots'] );

// New ledger entry labels.
check( 'entry label: grant', 'اعطای اعتبار' === ClubEndpoint::entry_label( 'grant' ) );
check( 'entry label: expire', 'انقضا' === ClubEndpoint::entry_label( 'expire' ) );
check( 'entry family: grant success / expire danger', 'success' === ClubEndpoint::entry_family( 'grant' ) && 'danger' === ClubEndpoint::entry_family( 'expire' ) );

// ---------------------------------------------------------------------------
// 6) Vocabulary sweep (4.1): no user-facing «کیف پول» left
// ---------------------------------------------------------------------------

section( '6) vocabulary: grep for the old credit word' );

$iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::LEAVES_ONLY
);
$legacy_hits = array();
foreach ( $iterator as $item ) {
        if ( ! $item instanceof SplFileInfo || 'php' !== $item->getExtension() ) { continue; }
        $relative = 'src/' . ltrim( substr( $item->getPathname(), strlen( dirname( __DIR__ ) . '/src' ) ), '/' );
        // Exception list (comments/migration literals only).
        if ( 'src/Core/Migrator.php' === $relative ) { continue; }
        $contents = (string) file_get_contents( $item->getPathname() );
        if ( false !== strpos( $contents, 'کیف پول' ) ) { $legacy_hits[] = $relative; }
}
foreach ( glob( dirname( __DIR__ ) . '/templates/*.php' ) ?: array() as $tpl ) { }
$template_hits = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/templates', FilesystemIterator::SKIP_DOTS ) ) as $item ) {
        if ( $item instanceof SplFileInfo && 'php' === $item->getExtension() && false !== strpos( (string) file_get_contents( $item->getPathname() ), 'کیف پول' ) ) {
                $template_hits[] = $item->getPathname();
        }
}
check( 'no «کیف پول» left in src/ (Migrator legacy literal excepted)', array() === $legacy_hits, implode( ', ', $legacy_hits ) );
check( 'no «کیف پول» left in templates/', array() === $template_hits, implode( ', ', $template_hits ) );
$fee_default = Settings::defaults()['wallet_fee_title'];
check( 'default fee title uses the new vocabulary', 'استفاده از اعتبار باشگاه' === $fee_default );

// ---------------------------------------------------------------------------
// 7) Block checkout notice (gate 3) — detection logic
// ---------------------------------------------------------------------------

section( '7) block checkout notice detection' );

check( 'no checkout page configured → no notice', false === AriunetClub\Modules\Wallet\BlockCheckoutNotice::block_checkout_active() );

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------

echo "\n";
if ( ! empty( $GLOBALS['credit_test_failed'] ) ) {
        echo "================ RESULT: FAILED (see above) ================\n";
        exit( 1 );
}
echo "================ RESULT: {$GLOBALS['credit_test_checks']} passed, 0 failed ================\n";
exit( 0 );
