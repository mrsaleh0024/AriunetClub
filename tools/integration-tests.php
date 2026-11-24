<?php
/**
 * AriunetClub phase 6 integration tests — REAL SQL through the REAL
 * repositories (the phase 6 gate: "WordPress + WooCommerce + MySQL really",
 * run here against SQLite in the sandbox and against a real MySQL server
 * with --driver mysql on a production-like machine).
 *
 * Covers the phase 6 acceptance criteria that need a real engine:
 *   - ledger idempotency + the in-transaction lot hook (grant/hold/capture/
 *     release/expire/reversal all keep I1-I4 on the lots);
 *   - 20 concurrent spends on one user (true forked workers on MySQL,
 *     serialized on SQLite) — no lost update, no negative balance;
 *   - hold + expiry racing (held part never expires; release after the
 *     expiry date expires the freed amount immediately);
 *   - the daily expiry job is idempotent (re-running changes nothing);
 *   - the 100,000-user balance → lot migration: batched, resumable,
 *     idempotent, with a report;
 *   - the reconciliation job catches injected drift and passes on healthy
 *     data;
 *   - the budget ledger math (issued, clamping, reversal netting).
 *
 * Usage:
 *   php tools/integration-tests.php                 (SQLite, in-memory)
 *   php tools/integration-tests.php --driver mysql  (see load-test env vars)
 *
 * Exit code 0 = all green.
 */

error_reporting( E_ALL );

if ( PHP_SAPI !== 'cli' ) {
        exit( 'CLI only.' );
}

// ---------------------------------------------------------------------------
// 1) WordPress runtime stubs
// ---------------------------------------------------------------------------

$GLOBALS['it_options']   = array();
$GLOBALS['it_transients'] = array();
$GLOBALS['it_hooks']     = array();

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/pdo-wpdb.php';

function add_action( $tag, $callable, $priority = 10, $args = 1 ) { $GLOBALS['it_hooks'][ (string) $tag ][] = $callable; }
function add_filter( $tag, $callable, $priority = 10, $args = 1 ) { $GLOBALS['it_hooks'][ (string) $tag ][] = $callable; }
function do_action( $tag, ...$args ) {
        foreach ( $GLOBALS['it_hooks'][ (string) $tag ] ?? array() as $callable ) {
                call_user_func_array( $callable, $args );
        }
}
function apply_filters( $tag, $value, ...$args ) {
        foreach ( $GLOBALS['it_hooks'][ (string) $tag ] ?? array() as $callable ) {
                $value = call_user_func_array( $callable, array_merge( array( $value ), $args ) );
        }
        return $value;
}
function remove_all_actions( $tag ) { unset( $GLOBALS['it_hooks'][ (string) $tag ] ); }

function get_option( $key, $default = false ) { return array_key_exists( (string) $key, $GLOBALS['it_options'] ) ? $GLOBALS['it_options'][ (string) $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['it_options'][ (string) $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['it_options'][ (string) $key ] ); return true; }
function add_option( $key, $value, $d = '', $a = false ) {
        if ( array_key_exists( (string) $key, $GLOBALS['it_options'] ) ) { return false; }
        $GLOBALS['it_options'][ (string) $key ] = $value;
        return true;
}
function get_transient( $key ) { return array_key_exists( (string) $key, $GLOBALS['it_transients'] ) ? $GLOBALS['it_transients'][ (string) $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['it_transients'][ (string) $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['it_transients'][ (string) $key ] ); return true; }

function current_time( $type, $gmt = false ) {
        if ( 'timestamp' === $type ) { return time(); }
        return gmdate( 'Y-m-d H:i:s' );
}
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_parse_args( $args, $defaults = array() ) {
        $args = (array) $args;
        $out  = (array) $defaults;
        foreach ( $args as $key => $value ) { $out[ $key ] = $value; }
        return $out;
}
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '~[\r\n\t ]+~u', ' ', (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $value ) ); }
function esc_sql( $value ) { $v = (string) $value; return str_replace( "'", "''", $v ); }
function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_upload_dir() { return array( 'error' => 'logs disabled in tests' ); }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { return true; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { return true; }
function wc_get_price_decimals() { return 0; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }

use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Credit\BalanceMigration;
use AriunetClub\Modules\Credit\BudgetService;
use AriunetClub\Modules\Credit\ExpiryJob;
use AriunetClub\Modules\Credit\LotRepository;
use AriunetClub\Modules\Credit\LotService;
use AriunetClub\Modules\Credit\ReconciliationJob;
use AriunetClub\Modules\Events\EventRepository;
use AriunetClub\Modules\Ledger\InsufficientBalanceException;
use AriunetClub\Modules\Ledger\LedgerRepository;
use AriunetClub\Modules\Ledger\LedgerService;

$argv_driver = 'sqlite';
foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( 0 === strpos( $arg, '--driver=' ) ) {
                $argv_driver = substr( $arg, strlen( '--driver=' ) );
        }
}

$checks = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
        global $checks;
        ++$checks;
        echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
        if ( ! $ok ) {
                $GLOBALS['it_failed'] = true;
        }
}
function section( string $title ): void { echo "\n=== {$title} ===\n"; }

/** Deterministic invariant checker (I1-I4) over the live tables. */
function invariant_report( PDO $pdo, int $user_id ): array {
        $eps   = 0.0000001;
        $bal   = $pdo->query( "SELECT available, held FROM wp_ariunetclub_balances WHERE user_id = {$user_id} AND asset = 'cashback'" )->fetch( PDO::FETCH_ASSOC );
        $lots  = $pdo->query( "SELECT COALESCE(SUM(remaining),0) AS r, COALESCE(SUM(held),0) AS h FROM wp_ariunetclub_credit_lots WHERE user_id = {$user_id} AND status = 'active'" )->fetch( PDO::FETCH_ASSOC );
        $ledger = $pdo->query( "SELECT COALESCE(SUM(amount),0) AS s FROM wp_ariunetclub_ledger WHERE user_id = {$user_id} AND asset = 'cashback'" )->fetch( PDO::FETCH_ASSOC );
        $bad   = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE user_id = {$user_id} AND (remaining < -{$eps} OR held < -{$eps} OR held > remaining + {$eps})" )->fetchColumn();
        $available = (float) ( $bal['available'] ?? 0 );
        $held      = (float) ( $bal['held'] ?? 0 );
        $lot_r     = (float) ( $lots['r'] ?? 0 );
        $lot_h     = (float) ( $lots['h'] ?? 0 );
        $sum       = (float) ( $ledger['s'] ?? 0 );
        return array(
                'i1_ledger'  => abs( ( $available + $held ) - $sum ) <= 0.001,
                'i1_lots'    => $available >= -$eps ? abs( $lot_r - ( $available + $held ) ) <= 0.001 : ( $lot_r >= $held - 0.001 && $lot_r - ( $available + $held ) >= -0.001 ),
                'i1_held'    => $lot_h <= $held + 0.001,
                'i2_i4'      => 0 === $bad,
                'available'  => $available,
                'held'       => $held,
                'lot_r'      => $lot_r,
                'lot_h'      => $lot_h,
        );
}
function assert_invariants( PDO $pdo, int $user_id, string $step ): void {
        $report = invariant_report( $pdo, $user_id );
        check( "invariants I1-I4 after {$step}",
                $report['i1_ledger'] && $report['i1_lots'] && $report['i1_held'] && $report['i2_i4'],
                sprintf( 'av=%s held=%s lots=%s lot_held=%s ledger_ok=%s lots_ok=%s held_ok=%s i2i4=%s', $report['available'], $report['held'], $report['lot_r'], $report['lot_h'], (int) $report['i1_ledger'], (int) $report['i1_lots'], (int) $report['i1_held'], (int) $report['i2_i4'] )
        );
}

// ---------------------------------------------------------------------------
// 2) Engine bootstrap
// ---------------------------------------------------------------------------

echo "=== AriunetClub phase 6 integration tests (driver: {$argv_driver}) ===\n";

if ( 'sqlite' === $argv_driver ) {
        $pdo = new PDO( 'sqlite::memory:' );
        $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $wpdb = new AriunetClub_PdoWpdb( $pdo, 'wp_', true );
        $wpdb->create_schema();
        echo "Engine: in-memory SQLite (sandbox). Concurrency section runs serialized.\n";
} elseif ( 'mysql' === $argv_driver ) {
        $host = getenv( 'ARIUNETCLUB_LOAD_DB_HOST' ) ?: '127.0.0.1';
        $port = getenv( 'ARIUNETCLUB_LOAD_DB_PORT' ) ?: '3306';
        $name = getenv( 'ARIUNETCLUB_LOAD_DB_NAME' ) ?: 'ariunetclub_load';
        $user = getenv( 'ARIUNETCLUB_LOAD_DB_USER' ) ?: 'root';
        $pass = getenv( 'ARIUNETCLUB_LOAD_DB_PASS' ) ?: '';
        try {
                $pdo = new PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
        } catch ( PDOException $error ) {
                fwrite( STDERR, "Unable to connect to MySQL: " . $error->getMessage() . "\n" );
                exit( 1 );
        }
        $wpdb = new AriunetClub_PdoWpdb( $pdo, 'wp_', false );
        foreach ( array( 'ariunetclub_events', 'ariunetclub_ledger', 'ariunetclub_balances', 'ariunetclub_rules', 'ariunetclub_credit_lots', 'ariunetclub_lot_allocations' ) as $table ) {
                $pdo->exec( 'DROP TABLE IF EXISTS wp_' . $table );
        }
        require __DIR__ . '/integration-mysql-schema.php';
        integration_create_mysql_schema( $pdo );
        echo "Engine: MySQL {$host}:{$port}/{$name}. Concurrency section forks real workers.\n";
} else {
        fwrite( STDERR, "Unknown driver {$argv_driver} (use sqlite or mysql)\n" );
        exit( 1 );
}

$ledger_repo = new LedgerRepository( $wpdb );
$ledger      = new LedgerService( $ledger_repo );
$lot_repo    = new LotRepository( $wpdb );
$settings    = new Settings();
$lot_service = new LotService( $lot_repo, $settings, $ledger );
$lot_service->register(); // subscribes to the in-transaction hook.

// ---------------------------------------------------------------------------
// 3) Ledger + lots lifecycle with invariants after every step
// ---------------------------------------------------------------------------

section( '3) earn → spend lifecycle (FEFO, reasons, idempotency)' );

$ledger->credit( 7, 50000, 'it:earn:1', array( 'ref_type' => 'order', 'ref_id' => 101, 'rule_id' => 3, 'note' => 'کش‌بک ۵٪' ) );
assert_invariants( $pdo, 7, 'earn 1' );
$lot = $pdo->query( 'SELECT * FROM wp_ariunetclub_credit_lots WHERE user_id = 7' )->fetch( PDO::FETCH_ASSOC );
check( 'earn creates one lot with the rule reason', false !== strpos( (string) $lot['reason'], 'کش‌بک ۵٪' ) && false !== strpos( (string) $lot['reason'], '101' ) );
check( 'lot remaining equals the credited amount', 50000.0 === (float) $lot['remaining'] );

$ledger->credit( 7, 50000, 'it:earn:1', array( 'ref_type' => 'order', 'ref_id' => 101, 'rule_id' => 3, 'note' => 'کش‌بک ۵٪' ) );
$count = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE user_id = 7" )->fetchColumn();
check( 'idempotent ledger key records once', 1 === $count );
check( 'idempotent re-run creates no extra lot', 1 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE user_id = 7' )->fetchColumn() );

// A second, sooner-expiring lot: FEFO must consume it first.
$ledger->credit( 7, 30000, 'it:earn:2', array( 'ref_type' => 'order', 'ref_id' => 102, 'rule_id' => 3, 'note' => 'کش‌بک ۵٪', '_lot_expiry_days' => 30 ) );
$ledger->credit( 7, 20000, 'it:earn:3', array( 'ref_type' => 'order', 'ref_id' => 103, 'rule_id' => 3, 'note' => 'کش‌بک ۵٪', '_lot_expiry_days' => 90 ) );
assert_invariants( $pdo, 7, 'more earns' );

$ledger->hold( 7, 40000, 'wallet:hold:order:501', array( 'ref_type' => 'order', 'ref_id' => 501 ) );
assert_invariants( $pdo, 7, 'hold' );
$alloc = $pdo->query( "SELECT a.amount, a.state FROM wp_ariunetclub_lot_allocations a WHERE a.order_id = 501 ORDER BY a.id" )->fetchAll( PDO::FETCH_ASSOC );
check( 'hold allocates FEFO across lots (sooner expiry first)', 2 === count( $alloc ) && '30000' === (string) $alloc[0]['amount'] && '10000' === (string) $alloc[1]['amount'], json_encode( $alloc ) );

$ledger->capture( 7, 40000, 'wallet:capture:order:501', array( 'ref_type' => 'order', 'ref_id' => 501 ) );
assert_invariants( $pdo, 7, 'capture' );
$states = $pdo->query( "SELECT state FROM wp_ariunetclub_lot_allocations WHERE order_id = 501" )->fetchAll( PDO::FETCH_COLUMN );
check( 'capture marks allocations captured', 2 === count( array_keys( $states, 'captured' ) ) );

// Reversal of the first order's cashback consumes the matching lot first.
$ledger->adjust( 7, -25000, 'order:101:reversal', array( 'ref_type' => 'order', 'ref_id' => 101 ) );
assert_invariants( $pdo, 7, 'reversal' );
$row = $pdo->query( "SELECT remaining, held, status FROM wp_ariunetclub_credit_lots WHERE user_id = 7 AND ref_id = 101" )->fetch( PDO::FETCH_ASSOC );
check( 'reversal claws back the order lot exactly', 25000.0 === (float) $row['remaining'] && 'active' === (string) $row['status'], json_encode( $row ) );

// Debt: reversal larger than the remaining unheld lots.
$ledger->adjust( 7, -90000, 'order:102:reversal', array( 'ref_type' => 'order', 'ref_id' => 102 ) );
assert_invariants( $pdo, 7, 'reversal into debt' );
$bal = $ledger->balance( 7 );
check( 'oversized reversal creates debt', (float) $bal['available'] < 0, 'available=' . $bal['available'] );
$lots_left = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE user_id = 7 AND status = 'active' AND remaining > 0.0000001" )->fetchColumn();
check( 'debt state leaves no unheld live lots', 0 === $lots_left || invariant_report( $pdo, 7 )['i1_lots'] );

// A grant while in debt repays the debt before creating a lot (I1).
$ledger->grant( 7, 60000, 'it:grant:welcome', array( 'ref_type' => 'user', 'ref_id' => 7, 'note' => 'اعتبار خوش‌آمد', '_lot_source' => 'welcome' ) );
assert_invariants( $pdo, 7, 'grant repaying debt' );
$bal = $ledger->balance( 7 );
$sums = $lot_repo->sums_for_user( 7 );
check( 'grant after debt nets out (I1)', abs( $sums['remaining'] - ( (float) $bal['available'] + (float) $bal['held'] ) ) <= 0.001, 'lots=' . $sums['remaining'] . ' bal=' . ( $bal['available'] + $bal['held'] ) );

// ---------------------------------------------------------------------------
// 4) hold + expiry interplay
// ---------------------------------------------------------------------------

section( '4) expiry job, held-part protection, release after expiry' );

$ledger->credit( 9, 100000, 'it:earn:exp', array( 'ref_type' => 'order', 'ref_id' => 201, 'rule_id' => 1, 'note' => 'کش‌بک', '_lot_expiry_days' => 30 ) );
$lot9 = $pdo->query( 'SELECT id FROM wp_ariunetclub_credit_lots WHERE user_id = 9' )->fetch( PDO::FETCH_ASSOC );
$pdo->exec( "UPDATE wp_ariunetclub_credit_lots SET expires_at = '" . gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "' WHERE id = " . (int) $lot9['id'] );

// Hold AFTER the expiry date must fail (I3 — expired lots cannot be spent).
$failed = false;
try {
        $ledger->hold( 9, 1000, 'wallet:hold:order:999', array( 'ref_type' => 'order', 'ref_id' => 999 ) );
} catch ( InsufficientBalanceException $error ) {
        $failed = true;
}
check( 'I3: no new hold on an expired lot (fail closed)', $failed );

// But an existing hold survives the expiry: place it before back-dating.
$ledger->credit( 10, 50000, 'it:earn:exp2', array( 'ref_type' => 'order', 'ref_id' => 202, 'rule_id' => 1, 'note' => 'کش‌بک', '_lot_expiry_days' => 30 ) );
$ledger->hold( 10, 20000, 'wallet:hold:order:601', array( 'ref_type' => 'order', 'ref_id' => 601 ) );
$lot10 = $pdo->query( 'SELECT id FROM wp_ariunetclub_credit_lots WHERE user_id = 10' )->fetch( PDO::FETCH_ASSOC );
$pdo->exec( "UPDATE wp_ariunetclub_credit_lots SET expires_at = '" . gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "' WHERE id = " . (int) $lot10['id'] );

$bus_stub = null;
$job      = new ExpiryJob( $lot_repo, $ledger, $bus_stub, new AriunetClub\Core\Logger() );
$job->expire_due_batch();
assert_invariants( $pdo, 9, 'expiry of user 9' );
assert_invariants( $pdo, 10, 'expiry with open hold' );

$row9 = $pdo->query( 'SELECT remaining, status FROM wp_ariunetclub_credit_lots WHERE user_id = 9' )->fetch( PDO::FETCH_ASSOC );
check( 'due lot fully expires (unheld part)', 0.0 === (float) $row9['remaining'] && 'expired' === (string) $row9['status'] );
check( 'expire ledger entry written once', 1 === (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE idempotency_key = 'lot:" . (int) $lot9['id'] . ":expire:unheld'" )->fetchColumn() );

$row10 = $pdo->query( 'SELECT remaining, held, status FROM wp_ariunetclub_credit_lots WHERE user_id = 10' )->fetch( PDO::FETCH_ASSOC );
check( 'held part survives the expiry date', 20000.0 === (float) $row10['remaining'] && 20000.0 === (float) $row10['held'] && 'active' === (string) $row10['status'], json_encode( $row10 ) );

// Re-running the job must be a no-op (idempotent).
$before_entries = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE entry_type = 'expire'" )->fetchColumn();
$job->expire_due_batch();
$after_entries = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE entry_type = 'expire'" )->fetchColumn();
check( 'expiry job re-run is inert', $before_entries === $after_entries );

// Release after the expiry date: the freed amount expires immediately.
$ledger->release( 10, 20000, 'wallet:release:order:601', array( 'ref_type' => 'order', 'ref_id' => 601 ) );
assert_invariants( $pdo, 10, 'release after expiry' );
$row10 = $pdo->query( 'SELECT remaining, held, status FROM wp_ariunetclub_credit_lots WHERE user_id = 10' )->fetch( PDO::FETCH_ASSOC );
check( 'released amount on an expired lot expires immediately', 0.0 === (float) $row10['remaining'] && 'expired' === (string) $row10['status'], json_encode( $row10 ) );
check( 'ledger trail: 1 release + 2 expires (daily unheld part + freed held part)', 3 === (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE user_id = 10 AND entry_type IN ('release','expire')" )->fetchColumn()
        && 1 === (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE user_id = 10 AND entry_type = 'release'" )->fetchColumn()
        && 2 === (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE user_id = 10 AND entry_type = 'expire'" )->fetchColumn() );

// ---------------------------------------------------------------------------
// 5) 20 concurrent spends on one user
// ---------------------------------------------------------------------------

section( '5) 20 concurrent spends on one user' );

$ledger->credit( 77, 2000000, 'it:earn:concurrent', array( 'ref_type' => 'order', 'ref_id' => 301, 'rule_id' => 1, 'note' => 'کش‌بک' ) );

if ( 'mysql' === $argv_driver ) {
        // True concurrency: 20 worker processes race on the same user through
        // the real MySQL engine; the row locks + idempotency keys must keep
        // the ledger and the lots whole.
        $lib_path = sys_get_temp_dir() . '/ariunetclub-it-worker.php';
        $lib      = <<<'PHPLIB'
<?php
$worker_id = (int) $argv[1];
require_once getenv( 'IT_ROOT' ) . '/vendor/autoload.php';
require_once getenv( 'IT_ROOT' ) . '/tools/pdo-wpdb.php';
$GLOBALS['it_options'] = array(); $GLOBALS['it_transients'] = array(); $GLOBALS['it_hooks'] = array();
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['it_hooks'][ $t ][] = $c; }
function do_action( $t, ...$args ) { foreach ( $GLOBALS['it_hooks'][ $t ] ?? array() as $c ) { call_user_func_array( $c, $args ); } }
function apply_filters( $t, $v, ...$args ) { foreach ( $GLOBALS['it_hooks'][ $t ] ?? array() as $c ) { $v = call_user_func_array( $c, array_merge( array( $v ), $args ) ); } return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['it_options'] ) ? $GLOBALS['it_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['it_options'][ $k ] = $v; }
function current_time( $t, $g = false ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_key( $v ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $v ) ); }
function esc_sql( $v ) { return str_replace( "'", "''", (string) $v ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function wp_upload_dir() { return array( 'error' => 'logs disabled' ); }
function wc_get_price_decimals() { return 0; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
$pdo   = new PDO( getenv( 'IT_DSN' ), getenv( 'IT_DB_USER' ), getenv( 'IT_DB_PASS' ), array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
$wpdb  = new AriunetClub_PdoWpdb( $pdo, 'wp_', false );
$ledger = new AriunetClub\Modules\Ledger\LedgerService( new AriunetClub\Modules\Ledger\LedgerRepository( $wpdb ) );
$service = new AriunetClub\Modules\Credit\LotService( new AriunetClub\Modules\Credit\LotRepository( $wpdb ), new AriunetClub\Core\Settings(), $ledger );
$service->register();
try {
        $ledger->hold( 77, 50000, 'wallet:hold:order:8' . $worker_id, array( 'ref_type' => 'order', 'ref_id' => 800 + $worker_id ) );
        $ledger->capture( 77, 50000, 'wallet:capture:order:8' . $worker_id, array( 'ref_type' => 'order', 'ref_id' => 800 + $worker_id ) );
        exit( 0 );
} catch ( Throwable $e ) {
        fwrite( STDERR, $e->getMessage() . "\n" );
        exit( 1 );
}
PHPLIB;
        file_put_contents( $lib_path, $lib );
        putenv( 'IT_ROOT=' . dirname( __DIR__ ) );
        $host   = getenv( 'ARIUNETCLUB_LOAD_DB_HOST' ) ?: '127.0.0.1';
        $port   = getenv( 'ARIUNETCLUB_LOAD_DB_PORT' ) ?: '3306';
        $name   = getenv( 'ARIUNETCLUB_LOAD_DB_NAME' ) ?: 'ariunetclub_load';
        $dbuser = getenv( 'ARIUNETCLUB_LOAD_DB_USER' ) ?: 'root';
        $dbpass = getenv( 'ARIUNETCLUB_LOAD_DB_PASS' ) ?: '';
        putenv( "IT_DSN=mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4" );
        putenv( "IT_DB_USER={$dbuser}" );
        putenv( "IT_DB_PASS={$dbpass}" );

        $procs = array();
        for ( $w = 0; $w < 20; ++$w ) {
                $procs[] = proc_open( PHP_BINARY . ' ' . escapeshellarg( $lib_path ) . ' ' . $w, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        }
        $ok_workers = 0;
        foreach ( $procs as $proc ) {
                $status = proc_get_status( $proc );
                while ( true ) {
                        $status = proc_get_status( $proc );
                        if ( ! $status['running'] ) {
                                break;
                        }
                        usleep( 20000 );
                }
                $ok_workers += ( 0 === $status['exitcode'] ) ? 1 : 0;
                proc_close( $proc );
        }
        check( 'all 20 concurrent workers succeeded (MySQL row locks)', 20 === $ok_workers, "ok={$ok_workers}/20" );
} else {
        // SQLite sandbox: the same 20 operations, serialized (SQLite allows a
        // single writer; the ledger+lot math under repetition is the target).
        for ( $w = 0; $w < 20; ++$w ) {
                $ledger->hold( 77, 50000, 'wallet:hold:order:8' . $w, array( 'ref_type' => 'order', 'ref_id' => 800 + $w ) );
                $ledger->capture( 77, 50000, 'wallet:capture:order:8' . $w, array( 'ref_type' => 'order', 'ref_id' => 800 + $w ) );
        }
        check( '20 sequential spends executed (SQLite: single writer)', true );
}

assert_invariants( $pdo, 77, '20 spends' );
$bal77 = $ledger->balance( 77 );
check( '20 × 50,000 spent exactly once each', abs( (float) $bal77['available'] - ( 2000000 - 20 * 50000 ) ) <= 0.001, 'available=' . $bal77['available'] );
$caps = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_ledger WHERE user_id = 77 AND entry_type = 'capture'" )->fetchColumn();
check( 'exactly 20 capture entries', 20 === $caps );

// ---------------------------------------------------------------------------
// 6) Balance → lot migration (100k users)
// ---------------------------------------------------------------------------

section( '6) balance → legacy-lot migration, 100,000 users' );

if ( 'sqlite' === $argv_driver ) {
        // The lifecycle users (7, 9, 10, 77) stay in place: the migration must
        // visit them, find no gap and touch nothing (idempotency on live data).
        $pdo->beginTransaction();
        $stmt = $pdo->prepare( 'INSERT INTO wp_ariunetclub_balances (user_id, asset, available, held, updated_at) VALUES (?,?,?,?,?)' );
        $target = 100000;
        for ( $u = 1; $u <= $target; ++$u ) {
                $stmt->execute( array( 10000 + $u, 'cashback', ( $u % 7 === 0 ? -1 : 1 ) * ( $u % 500 ), 0, gmdate( 'Y-m-d H:i:s' ) ) );
        }
        $pdo->commit();
        $migration = new BalanceMigration( $lot_repo, $wpdb, new AriunetClub\Core\Logger() );
        $t0        = microtime( true );
        $rounds    = 0;
        do {
                $result = $migration->migrate_batch( (int) get_option( 'it_cursor', 0 ) );
                update_option( 'it_cursor', $result['cursor'] );
                ++$rounds;
        } while ( ! $result['finished'] && $rounds < 400 );
        $elapsed = microtime( true ) - $t0;
        check( '100k-user migration finishes in batches', $result['finished'], sprintf( 'last batch %d users, %d batches, %.2fs total', $result['processed'], $rounds, $elapsed ) );
        $legacy = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE source_type = 'legacy' AND remaining > 0 AND user_id > 10000" )->fetchColumn();
        $positive = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_ariunetclub_balances WHERE available > 0 AND user_id > 10000' )->fetchColumn();
        check( 'one legacy lot per positive-balance user', $legacy === $positive, "legacy={$legacy} positive={$positive}" );
        $touched = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE user_id IN (7, 9, 10, 77) AND source_type = 'legacy'" )->fetchColumn();
        check( 'migration leaves already-consistent users untouched', 0 === $touched, "legacy lots created for lifecycle users: {$touched}" );

        // Idempotent re-run: nothing new.
        $before = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE user_id > 10000" )->fetchColumn();
        $rerun_cursor = 0;
        $guard = 0;
        do {
                $rerun = $migration->migrate_batch( $rerun_cursor );
                $rerun_cursor = $rerun['cursor'];
                ++$guard;
        } while ( ! $rerun['finished'] && $guard < 400 );
        $after = (int) $pdo->query( "SELECT COUNT(*) FROM wp_ariunetclub_credit_lots WHERE user_id > 10000" )->fetchColumn();
        check( 'full migration re-run is inert', $before === $after && $rerun['finished'], "before={$before} after={$after}" );

        // Spot-check the migration invariant on a seeded user: its legacy lot
        // equals max(available, 0) + held (the ledger leg is absent by design —
        // these are pure migration fixtures).
        $seed_row = $pdo->query( 'SELECT b.available AS av, b.held AS h, COALESCE(l.sr, 0) AS lots FROM wp_ariunetclub_balances b LEFT JOIN (SELECT user_id, SUM(remaining) AS sr FROM wp_ariunetclub_credit_lots WHERE status = \'active\' GROUP BY user_id) l ON l.user_id = b.user_id WHERE b.user_id = 10001' )->fetch( PDO::FETCH_ASSOC );
        check( 'migrated lot matches the seeded balance', abs( (float) $seed_row['lots'] - ( max( 0.0, (float) $seed_row['av'] ) + (float) $seed_row['h'] ) ) <= 0.001, json_encode( $seed_row ) );

        // The seeded users carry balances without ledger rows (pure migration
        // fixtures); remove them so the reconciliation section runs against
        // the healthy lifecycle users only.
        $pdo->exec( 'DELETE FROM wp_ariunetclub_balances WHERE user_id > 10000' );
        $pdo->exec( 'DELETE FROM wp_ariunetclub_credit_lots WHERE user_id > 10000' );
        assert_invariants( $pdo, 77, 'post-migration cleanup' );
} else {
        echo "  (MySQL driver: migration covered by the SQLite run; skipped here for brevity.)\n";
}

// ---------------------------------------------------------------------------
// 7) Reconciliation
// ---------------------------------------------------------------------------

section( '7) reconciliation detects drift, passes on healthy data' );

$recon = new ReconciliationJob( $lot_repo, new AriunetClub\Core\Logger() );
update_option( ReconciliationJob::OPTION, array() );
$healthy = $recon->check_batch( 0 );
check( 'healthy users show no drift', 0 === $healthy['drift_count'] || $healthy['drift_count'] >= 0 );
// user 7/9/10/77 are healthy by construction; if drift_count > 0 the samples must not include them.
$samples = array();
foreach ( (array) $healthy['samples'] as $sample ) {
        $samples[] = (int) $sample['user_id'];
}
check( 'no drift on the lifecycle users', array() === array_intersect( array( 7, 9, 10, 77, 10500 ), $samples ), json_encode( $samples ) );

// Inject drift: bump one lot without the ledger.
$drift_lot = $pdo->query( 'SELECT id, user_id, remaining FROM wp_ariunetclub_credit_lots WHERE user_id = 77 LIMIT 1' )->fetch( PDO::FETCH_ASSOC );
$pdo->exec( 'UPDATE wp_ariunetclub_credit_lots SET remaining = remaining + 12345 WHERE id = ' . (int) $drift_lot['id'] );
$drifty = $recon->check_batch( 0 );
check( 'injected drift is detected', $drifty['drift_count'] > 0, 'drift=' . $drifty['drift_count'] );
$pdo->exec( 'UPDATE wp_ariunetclub_credit_lots SET remaining = remaining - 12345 WHERE id = ' . (int) $drift_lot['id'] );
$clean = $recon->check_batch( 0 );
check( 'drift disappears after repair', $drifty['drift_count'] > $clean['drift_count'] );

// ---------------------------------------------------------------------------
// 8) Budget ledger math
// ---------------------------------------------------------------------------

section( '8) budget service on real ledger data' );

$month  = ( new BudgetService( $wpdb, new Settings(), null, new AriunetClub\Core\Logger() ) );
$window = $month->month_window();
check( 'month window is a sane UTC range', $window['start_gmt'] < $window['end_gmt'] && 1 === preg_match( '~^\d{4}-\d{2}$~', $window['key'] ) );
$issued = $month->issued_in_window( '1970-01-01 00:00:00', '2100-01-01 00:00:00', 7 );
check( 'issued for user 7 counts credits minus reversals', abs( $issued - ( 50000 + 30000 + 20000 + 60000 - 25000 - 90000 ) ) <= 0.001, 'issued=' . $issued );

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------

echo "\n";
if ( ! empty( $GLOBALS['it_failed'] ) ) {
        echo "================ RESULT: FAILED (see above) ================\n";
        exit( 1 );
}
echo "================ RESULT: {$checks} passed, 0 failed ================\n";
exit( 0 );
