<?php
/**
 * AriunetClub rebrand migration tests — real PHP, no WordPress.
 *
 * Loads the REAL Migrator on a stubbed wpdb/options/cron/ActionScheduler
 * layer and proves the lclub -> ariunetclub data migration: table renames
 * with data preservation, settings copy, gateway id and order-meta rewrites
 * (postmeta + HPOS), scheduled-action rescheduling and full idempotency.
 *
 * Run: php tools/rebrand-functional-tests.php  (exit code 0 = all green)
 */

error_reporting( E_ALL );

$GLOBALS['ariunetclub_test_options']    = array();
$GLOBALS['ariunetclub_test_tables']     = array(); // existing table names.
$GLOBALS['ariunetclub_test_sql']        = array(); // every query issued.
$GLOBALS['ariunetclub_test_dbdelta']    = array();
$GLOBALS['ariunetclub_test_cron']       = array();
$GLOBALS['ariunetclub_test_as_actions'] = array(); // pending AS action objects by hook.
$GLOBALS['ariunetclub_test_as_scheduled'] = array(); // as_schedule_single_action calls.
$GLOBALS['ariunetclub_test_as_unscheduled'] = array(); // as_unschedule_action calls.

define( 'ABSPATH', __DIR__ . '/wp-stubs/wordpress/' );
define( 'ARIUNETCLUB_VERSION', '0.4.0' );
define( 'ARIUNETCLUB_FILE', __DIR__ . '/../ariunetclub.php' );
define( 'ARIUNETCLUB_PATH', __DIR__ . '/../' );
define( 'ARIUNETCLUB_URL', 'https://example.test/wp-content/plugins/ariunetclub/' );

require_once __DIR__ . '/../vendor/autoload.php';

function get_option( $name, $default = false ) { return $GLOBALS['ariunetclub_test_options'][ $name ] ?? $default; }
function add_option( $name, $value ) { if ( ! array_key_exists( $name, $GLOBALS['ariunetclub_test_options'] ) ) { $GLOBALS['ariunetclub_test_options'][ $name ] = $value; } return true; }
function update_option( $name, $value ) { $GLOBALS['ariunetclub_test_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['ariunetclub_test_options'][ $name ] ); return true; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_mkdir_p( $dir ) { return false; }
function wp_upload_dir() { return array( 'error' => 'uploads disabled in tests', 'basedir' => '/tmp' ); }
function current_time( $type, $gmt = false ) { return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
function absint( $value ) { return abs( (int) $value ); }

// WP-Cron stubs operating on the 'cron' option exactly like WP core does.
function rebrand_cron_store() {
        if ( ! isset( $GLOBALS['ariunetclub_test_options']['cron'] ) || ! is_array( $GLOBALS['ariunetclub_test_options']['cron'] ) ) {
                $GLOBALS['ariunetclub_test_options']['cron'] = array();
        }
        return $GLOBALS['ariunetclub_test_options']['cron'];
}
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
        $cron                                                = rebrand_cron_store();
        $cron[ (int) $timestamp ][ $hook ][ md5( wp_json_encode( $args ) ) ] = array( 'args' => $args );
        $GLOBALS['ariunetclub_test_options']['cron']        = $cron;
        return true;
}
function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
        $cron = rebrand_cron_store();
        if ( isset( $cron[ (int) $timestamp ][ $hook ] ) ) {
                foreach ( $cron[ (int) $timestamp ][ $hook ] as $signature => $data ) {
                        if ( ( $data['args'] ?? array() ) === $args ) {
                                unset( $cron[ (int) $timestamp ][ $hook ][ $signature ] );
                        }
                }
                if ( empty( $cron[ (int) $timestamp ][ $hook ] ) ) {
                        unset( $cron[ (int) $timestamp ][ $hook ] );
                }
        }
        $GLOBALS['ariunetclub_test_options']['cron'] = $cron;
        return true;
}
function wp_clear_scheduled_hook( $hook ) {
        $cron = rebrand_cron_store();
        foreach ( $cron as $timestamp => $hooks ) {
                unset( $cron[ $timestamp ][ $hook ] );
        }
        $GLOBALS['ariunetclub_test_options']['cron'] = $cron;
        return true;
}
function rebrand_cron_events( $hook ) {
        $events = array();
        foreach ( rebrand_cron_store() as $timestamp => $hooks ) {
                foreach ( $hooks as $cron_hook => $signatures ) {
                        if ( $cron_hook !== $hook ) {
                                continue;
                        }
                        foreach ( $signatures as $data ) {
                                $events[] = array( (int) $timestamp, $data['args'] ?? array() );
                        }
                }
        }
        return $events;
}

// Action Scheduler stubs.
class RebrandFakeSchedule {
        /** @var int */
        public $timestamp;
        public function __construct( int $timestamp ) { $this->timestamp = $timestamp; }
        public function get_date(): \DateTimeImmutable { return new \DateTimeImmutable( '@' . $this->timestamp ); }
}
class RebrandFakeAction {
        /** @var array<int,int> */
        public $args;
        /** @var RebrandFakeSchedule */
        public $schedule;
        public function __construct( array $args, int $timestamp ) {
                $this->args     = $args;
                $this->schedule = new RebrandFakeSchedule( $timestamp );
        }
        public function get_args(): array { return $this->args; }
        public function get_schedule(): RebrandFakeSchedule { return $this->schedule; }
}
function as_get_scheduled_actions( $args = array(), $return = 'OBJECT' ) {
        return $GLOBALS['ariunetclub_test_as_actions'][ $args['hook'] ?? '' ] ?? array();
}
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
        $GLOBALS['ariunetclub_test_as_scheduled'][] = array( (int) $timestamp, $hook, $args, $group );
        return 0;
}
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
        $GLOBALS['ariunetclub_test_as_unscheduled'][] = array( $hook, $args, $group );
        unset( $GLOBALS['ariunetclub_test_as_actions'][ $hook ] );
        return true;
}
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
        $GLOBALS['ariunetclub_test_as_unscheduled'][] = array( $hook, $args, $group, 'ALL' );
        unset( $GLOBALS['ariunetclub_test_as_actions'][ $hook ] );
        return true;
}

// dbDelta stub; Migrator require_once's ABSPATH/wp-admin/includes/upgrade.php.
if ( ! function_exists( 'dbDelta' ) ) {
        function dbDelta( $queries = '', $execute = true ) {
                $GLOBALS['ariunetclub_test_dbdelta'][] = $queries;
                return array();
        }
}

/**
 * Minimal wpdb base so the Migrator's instanceof guard passes; every method
 * is overridden by the recording subclass below.
 */
class RebrandWpdbBase {
}
if ( ! class_exists( 'wpdb' ) ) {
        // phpcs:ignore Generic.Classes.DuplicateClassName.Found -- analysis-only alias for the stub environment.
        class_alias( 'RebrandWpdbBase', 'wpdb' );
}

/**
 * wpdb double: records every query, answers SHOW TABLES from the table set,
 * and lets tests assert the exact migration SQL.
 */
class RebrandFakeWpdb extends RebrandWpdbBase {
        public $prefix    = 'wp_';
        public $postmeta  = 'wp_postmeta';
        /** @var array<int,string> */
        public $log = array();

        public function query( $sql ) {
                $this->log[] = $sql;
                if ( 0 === strpos( $sql, 'RENAME TABLE' ) ) {
                        preg_match( '/RENAME TABLE `([^`]+)` TO `([^`]+)`/', $sql, $matches );
                        if ( isset( $matches[2] ) ) {
                                $GLOBALS['ariunetclub_test_tables'][] = $matches[2];
                                $index                               = array_search( $matches[1], $GLOBALS['ariunetclub_test_tables'], true );
                                if ( false !== $index ) {
                                        unset( $GLOBALS['ariunetclub_test_tables'][ $index ] );
                                }
                        }
                        return 1;
                }
                return 1;
        }

        public function get_var( $sql ) {
                $this->log[] = $sql;
                if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
                        preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $matches );
                        $name = $matches[1] ?? '';
                        return in_array( $name, $GLOBALS['ariunetclub_test_tables'], true ) ? $name : '';
                }
                return '';
        }

        public function get_results( $sql, $output = null ) { $this->log[] = $sql; return array(); }
        public function get_col( $sql = null ) { $this->log[] = (string) $sql; return array(); }
        public function get_row( $sql = null, $output = null ) { $this->log[] = (string) $sql; return null; }
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
        public function prepare( $query, ...$args ) {
                if ( ! $args ) {
                        return $query;
                }
                $sql = $query;
                foreach ( $args as $arg ) {
                        $sql = preg_replace( '/%[sd]/', is_numeric( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
                }
                return $sql;
        }
        public function insert( $table, $data, $format = null ) { $this->log[] = 'INSERT'; return 1; }
        public function update( $table, $data, $where, $format = null, $where_format = null ) { $this->log[] = 'UPDATE'; return 1; }
        public function delete( $table, $where, $where_format = null ) { $this->log[] = 'DELETE'; return 1; }
        public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
}

use AriunetClub\Core\Migrator;
use AriunetClub\Core\Plugin;

$PASS = 0;
$FAIL = 0;
function check( $label, $condition ) {
        global $PASS, $FAIL;
        if ( $condition ) {
                $PASS++;
                echo "  PASS  $label\n";
        } else {
                $FAIL++;
                echo "  FAIL  $label\n";
        }
}
function queries_matching( $needle ) {
        $wpdb = $GLOBALS['ariunetclub_test_wpdb'];
        $hits = array();
        foreach ( $wpdb->log as $sql ) {
                if ( false !== strpos( $sql, $needle ) ) {
                        $hits[] = $sql;
                }
        }
        return $hits;
}

function fresh_install() {
        $GLOBALS['ariunetclub_test_options']      = array();
        $GLOBALS['ariunetclub_test_tables']       = array();
        $GLOBALS['ariunetclub_test_as_actions']   = array();
        $GLOBALS['ariunetclub_test_as_scheduled'] = array();
        $GLOBALS['ariunetclub_test_as_unscheduled'] = array();
        $GLOBALS['ariunetclub_test_wpdb']         = new RebrandFakeWpdb();
        $GLOBALS['wpdb']                          = $GLOBALS['ariunetclub_test_wpdb']; // Migrator reads global $wpdb.
        return new Migrator();
}

function legacy_install() {
        $migrator = fresh_install();
        // Phase 1-3 state: legacy tables, legacy settings, legacy data.
        $GLOBALS['ariunetclub_test_tables'] = array(
                'wp_lclub_events',
                'wp_lclub_ledger',
                'wp_lclub_balances',
                'wp_lclub_rules',
                'wp_postmeta',
        );
        $GLOBALS['ariunetclub_test_options']['lclub_settings']   = array( 'phone_meta' => 'billing_phone', 'delete_data' => false );
        $GLOBALS['ariunetclub_test_options']['lclub_db_version'] = '1.2.0';
        $GLOBALS['ariunetclub_test_as_actions']['lclub_release_expired_hold'] = array(
                new RebrandFakeAction( array( 120 ), time() + 3600 ),
                new RebrandFakeAction( array( 130 ), time() + 7200 ),
        );
        $GLOBALS['ariunetclub_test_as_actions']['lclub_process_events'] = array();
        $GLOBALS['ariunetclub_test_options']['cron'] = array(
                time() + 600 => array( 'lclub_release_expired_hold' => array( 'sig1' => array( 'args' => array( 140 ) ) ) ),
                time() + 60  => array( 'lclub_process_events' => array( 'sig2' => array( 'args' => array() ) ) ),
        );
        return $migrator;
}

echo "=== 1) Fresh install: no legacy, clean create ===\n";
$migrator = fresh_install();
$migrator->migrate();
check( 'no RENAME issued on fresh install', array() === queries_matching( 'RENAME TABLE' ) );
check( 'dbDelta received the four ariunetclub tables', 1 === count( $GLOBALS['ariunetclub_test_dbdelta'] ) && false !== strpos( $GLOBALS['ariunetclub_test_dbdelta'][0], 'wp_ariunetclub_ledger' ) && false !== strpos( $GLOBALS['ariunetclub_test_dbdelta'][0], 'wp_ariunetclub_rules' ) );
check( 'db version option set', Plugin::DB_VERSION === get_option( 'ariunetclub_db_version' ) );

echo "=== 2) Upgraded install: tables renamed with data kept ===\n";
$migrator = legacy_install();
$migrator->migrate();
$renames = queries_matching( 'RENAME TABLE' );
check( 'all four legacy tables renamed', 4 === count( $renames ) );
check( 'ledger renamed to ariunetclub_ledger', false !== strpos( implode( ' ', $renames ), '`wp_lclub_ledger` TO `wp_ariunetclub_ledger`' ) );
check( 'balances renamed', false !== strpos( implode( ' ', $renames ), 'wp_ariunetclub_balances' ) );
check( 'events renamed', false !== strpos( implode( ' ', $renames ), 'wp_ariunetclub_events' ) );
check( 'rules renamed', false !== strpos( implode( ' ', $renames ), 'wp_ariunetclub_rules' ) );
check( 'table set now has modern names', in_array( 'wp_ariunetclub_ledger', $GLOBALS['ariunetclub_test_tables'], true ) && ! in_array( 'wp_lclub_ledger', $GLOBALS['ariunetclub_test_tables'], true ) );

echo "=== 3) Settings and options copied forward ===\n";
check( 'settings value copied to new option', get_option( 'ariunetclub_settings' ) === array( 'phone_meta' => 'billing_phone', 'delete_data' => false ) );
check( 'legacy settings option untouched (rollback safety)', null !== get_option( 'lclub_settings' ) );

echo "=== 4) Gateway id and order meta rewritten ===\n";
$gateway = queries_matching( "meta_value = 'ariunetclub_wallet'" );
check( 'postmeta gateway id rewritten', 1 === count( $gateway ) && false !== strpos( $gateway[0], "meta_key = '_payment_method'" ) );
$meta_moves = queries_matching( "SET meta_key = '_ariunetclub_wallet_state'" );
check( 'wallet state meta key rewritten', 1 === count( $meta_moves ) );
check( 'all four meta keys rewritten', 4 === count( queries_matching( '_ariunetclub_wallet_' ) ) );
check( 'HPOS gateway rewrite only when the table exists', array() === queries_matching( "SET payment_method = 'ariunetclub_wallet' WHERE payment_method" ) );

echo "=== 5) Scheduled actions moved to the new hooks ===\n";
$scheduled = $GLOBALS['ariunetclub_test_as_scheduled'];
check( 'two expiry actions rescheduled under the new hook', 2 === count( $scheduled ) && 'ariunetclub_release_expired_hold' === $scheduled[0][1] && 'ariunetclub_release_expired_hold' === $scheduled[1][1] );
check( 'order ids carried over', array( 120 ) === $scheduled[0][2] && array( 130 ) === $scheduled[1][2] );
check( 'rescheduled into the ariunetclub group', 'ariunetclub' === $scheduled[0][3] );
check( 'legacy expiry actions unscheduled', 2 === count( array_filter( $GLOBALS['ariunetclub_test_as_unscheduled'], static function ( $call ) {
        return 'lclub_release_expired_hold' === $call[0] && ! isset( $call[3] );
} ) ) );
check( 'legacy recurring processor dropped', 1 === count( array_filter( $GLOBALS['ariunetclub_test_as_unscheduled'], static function ( $call ) {
        return 'lclub_process_events' === $call[0] && isset( $call[3] );
} ) ) );
check( 'wp-cron expiry event moved', array( array( time() + 600, array( 140 ) ) ) === rebrand_cron_events( 'ariunetclub_release_expired_hold' ) );
check( 'wp-cron legacy hooks cleared', array() === rebrand_cron_events( 'lclub_release_expired_hold' ) && array() === rebrand_cron_events( 'lclub_process_events' ) );

echo "=== 6) Idempotency: a second run changes nothing ===\n";
$sql_before = count( $GLOBALS['ariunetclub_test_wpdb']->log );
$migrator->migrate();
check( 'no further RENAME on the second run', 4 === count( queries_matching( 'RENAME TABLE' ) ) );
check( 'settings not copied again (value kept)', get_option( 'ariunetclub_settings' ) === array( 'phone_meta' => 'billing_phone', 'delete_data' => false ) );
check( 'no duplicate expiry rescheduling', 2 === count( $GLOBALS['ariunetclub_test_as_scheduled'] ) );
check( 'db version still current', Plugin::DB_VERSION === get_option( 'ariunetclub_db_version' ) );

echo "=== 7) Both legacy and modern tables exist: modern wins ===\n";
$migrator = fresh_install();
$GLOBALS['ariunetclub_test_tables'] = array( 'wp_lclub_ledger', 'wp_ariunetclub_ledger', 'wp_lclub_rules', 'wp_ariunetclub_rules', 'wp_postmeta' );
$migrator->migrate();
$renames = queries_matching( 'RENAME TABLE' );
check( 'ledger not renamed when the modern table exists', array() === array_filter( $renames, static function ( $sql ) {
        return false !== strpos( $sql, 'lclub_ledger' );
} ) );
check( 'rules not renamed when the modern table exists', array() === array_filter( $renames, static function ( $sql ) {
        return false !== strpos( $sql, 'lclub_rules' );
} ) );

echo "=== 8) maybe_migrate only runs on a version change ===\n";
$migrator = fresh_install();
$migrator->migrate();
$sql_after_first = count( $GLOBALS['ariunetclub_test_wpdb']->log );
$migrator->maybe_migrate();
check( 'no queries when the stored version matches', count( $GLOBALS['ariunetclub_test_wpdb']->log ) === $sql_after_first );
delete_option( 'ariunetclub_db_version' );
$migrator->maybe_migrate();
check( 'runs again once the stored version is missing', count( $GLOBALS['ariunetclub_test_wpdb']->log ) > $sql_after_first );

echo "\n================ RESULT: $PASS passed, $FAIL failed ================\n";
exit( $FAIL > 0 ? 1 : 0 );
