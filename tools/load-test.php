<?php
/**
 * AriunetClub phase 5 load test — 100,000 ledger rows (acceptance: balance
 * read under 50ms, every admin list under one second, no N+1 query
 * patterns; query counts asserted).
 *
 * Drivers:
 *   php tools/load-test.php --driver sqlite   (default; runs anywhere, PDO SQLite)
 *   php tools/load-test.php --driver mysql    (needs a real MySQL server)
 *
 * MySQL mode reads connection settings from environment variables (all
 * optional): ARIUNETCLUB_LOAD_DB_HOST (default 127.0.0.1), ARIUNETCLUB_LOAD_DB_PORT
 * (3306), ARIUNETCLUB_LOAD_DB_NAME (ariunetclub_load), ARIUNETCLUB_LOAD_DB_USER (root),
 * ARIUNETCLUB_LOAD_DB_PASS (empty). The database must exist; tables are
 * created with the plugin prefix wp_ and dropped beforehand.
 *
 * Exit code 0 = all thresholds met.
 */

error_reporting( E_ALL );

if ( PHP_SAPI !== 'cli' ) {
        exit( 'CLI only.' );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/pdo-wpdb.php';

use AriunetClub\Modules\Dashboard\DashboardStatsRepository;
use AriunetClub\Modules\Ledger\LedgerRepository;

$argv_driver = 'sqlite';
foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( 0 === strpos( $arg, '--driver=' ) ) {
                $argv_driver = substr( $arg, strlen( '--driver=' ) );
        }
}

const ROWS_TARGET = 100000;
const USERS = 5000;

function lt_fail( string $message ): void {
        fwrite( STDERR, "  FAIL  $message\n" );
        exit( 1 );
}

function lt_ms( float $seconds ): string {
        return number_format( $seconds * 1000, 2 ) . 'ms';
}

function lt_check( string $label, bool $ok, string $detail = '' ): void {
        echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . ( '' !== $detail ? " — $detail" : '' ) . "\n";
        if ( ! $ok ) {
                exit( 1 );
        }
}

echo "=== AriunetClub load test: " . ROWS_TARGET . " ledger rows, driver {$argv_driver} ===\n";

if ( 'sqlite' === $argv_driver ) {
        $pdo = new PDO( 'sqlite::memory:' );
        $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $wpdb = new AriunetClub_PdoWpdb( $pdo, 'wp_', true );
        $wpdb->create_schema();
        echo "Driver: in-memory SQLite (development sandbox; advisory locks simulated)\n";
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
        create_mysql_schema( $pdo );
        echo "Driver: MySQL {$host}:{$port}/{$name}\n";
} else {
        fwrite( STDERR, "Unknown driver {$argv_driver} (use sqlite or mysql)\n" );
        exit( 1 );
}

/**
 * Creates the plugin's production tables (same DDL the Migrator sends to
 * dbDelta, including the phase 5 created_at index) for a faithful MySQL
 * benchmark, dropping any previous run's tables first.
 */
function create_mysql_schema( PDO $pdo ): void {
        foreach ( array( 'ariunetclub_events', 'ariunetclub_ledger', 'ariunetclub_balances', 'ariunetclub_rules' ) as $table ) {
                $pdo->exec( 'DROP TABLE IF EXISTS wp_' . $table );
        }
        $sql_file = <<<'SQL'
CREATE TABLE wp_ariunetclub_events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        type varchar(100) NOT NULL,
        user_id bigint(20) unsigned NULL,
        object_type varchar(50) NOT NULL,
        object_id bigint(20) unsigned NULL,
        payload longtext NULL,
        idempotency_key varchar(191) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        error text NULL,
        created_at datetime NOT NULL,
        processed_at datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idempotency_key (idempotency_key),
        KEY status_created_at (status,created_at),
        KEY object_lookup (object_type,object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_ledger (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        asset varchar(32) NOT NULL,
        entry_type varchar(20) NOT NULL,
        amount decimal(20,8) NOT NULL,
        balance_after decimal(20,8) NULL,
        ref_type varchar(50) NULL,
        ref_id bigint(20) unsigned NULL,
        rule_id bigint(20) unsigned NULL,
        idempotency_key varchar(191) NOT NULL,
        note text NULL,
        created_by bigint(20) unsigned NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idempotency_key (idempotency_key),
        KEY user_asset_created (user_id,asset,created_at),
        KEY reference (ref_type,ref_id),
        KEY ledger_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_balances (
        user_id bigint(20) unsigned NOT NULL,
        asset varchar(32) NOT NULL,
        available decimal(20,8) NOT NULL DEFAULT 0,
        held decimal(20,8) NOT NULL DEFAULT 0,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (user_id,asset)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wp_ariunetclub_rules (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(191) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'draft',
        event_type varchar(100) NOT NULL,
        conditions longtext NULL,
        reward longtext NULL,
        limits longtext NULL,
        priority int(11) NOT NULL DEFAULT 10,
        exclusive tinyint(1) NOT NULL DEFAULT 0,
        starts_at datetime NULL,
        ends_at datetime NULL,
        PRIMARY KEY  (id),
        KEY active_rules (status,event_type,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach ( array_filter( array_map( 'trim', explode( ';', $sql_file ) ) ) as $statement ) {
                if ( '' !== $statement ) {
                        $pdo->exec( $statement );
                }
        }
}

// ---------------------------------------------------------------------------
// Seeding
// ---------------------------------------------------------------------------
echo "Seeding " . ROWS_TARGET . " ledger rows across " . USERS . " users...\n";
$seed_start = microtime( true );

$now       = time();
$day_start = strtotime( 'today', $now );

$pdo->beginTransaction();
$ledger_sql  = 'INSERT INTO wp_ariunetclub_ledger (user_id, asset, entry_type, amount, balance_after, ref_type, ref_id, rule_id, idempotency_key, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
$stmt        = $pdo->prepare( $ledger_sql );
$types       = array( 'credit', 'credit', 'credit', 'debit', 'capture', 'hold', 'release', 'adjust' );
for ( $i = 1; $i <= ROWS_TARGET; ++$i ) {
        $type   = $types[ $i % 8 ];
        $amount = ( 'debit' === $type || 'capture' === $type ) ? -1 * mt_rand( 100, 90000 ) : mt_rand( 100, 120000 );
        // 10% of entries land in the last 30 days, the rest spread over 3 years.
        $days_ago = ( 0 === $i % 10 ) ? mt_rand( 0, 29 ) : mt_rand( 30, 1095 );
        $stmt->execute( array(
                1 + ( $i % USERS ),
                'cashback',
                $type,
                $amount / 10,
                0,
                'order',
                1000 + ( $i % 900 ),
                ( 0 === $i % 3 ) ? null : 1 + ( $i % 5 ),
                'seed-' . $i,
                'load test row ' . $i,
                null,
                gmdate( 'Y-m-d H:i:s', $day_start - $days_ago * 86400 + mt_rand( 0, 86399 ) ),
        ) );
}
$balance_stmt = $pdo->prepare( 'INSERT INTO wp_ariunetclub_balances (user_id, asset, available, held, updated_at) VALUES (?,?,?,?,?)' );
for ( $u = 1; $u <= USERS; ++$u ) {
        $balance_stmt->execute( array( $u, 'cashback', mt_rand( 0, 500000 ) / 10, mt_rand( 0, 50000 ) / 10, gmdate( 'Y-m-d H:i:s', $now ) ) );
}
$rules_stmt = $pdo->prepare( 'INSERT INTO wp_ariunetclub_rules (title, status, event_type, conditions, reward, limits, priority, exclusive, starts_at, ends_at) VALUES (?,?,?,?,?,?,?,?,?,?)' );
for ( $r = 1; $r <= 5; ++$r ) {
        $rules_stmt->execute( array( "load test rule {$r}", 'active', 'order.status.completed', '{}', '{}', '{}', 10, 0, null, null ) );
}
$pdo->commit();

$seed_time = microtime( true ) - $seed_start;
echo 'Seeded in ' . lt_ms( $seed_time ) . "\n\n";

$ledger_repository = new LedgerRepository( $wpdb );
$stats             = new DashboardStatsRepository( $wpdb );

// ---------------------------------------------------------------------------
// 1) Balance reads (acceptance: < 50ms)
// ---------------------------------------------------------------------------
echo "=== 1) Wallet balance reads (100 lookups) ===\n";
$wpdb->queries = array();
$t = microtime( true );
for ( $i = 0; $i < 100; ++$i ) {
        $balance = $ledger_repository->balance( 1 + ( $i % USERS ) );
}
$elapsed = microtime( true ) - $t;
$avg     = $elapsed / 100;
lt_check( 'average balance read < 50ms', $avg < 0.050, lt_ms( $avg ) . ' avg, ' . lt_ms( $elapsed ) . ' total' );
lt_check( 'exactly 100 queries for 100 reads (no N+1)', 100 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );

// ---------------------------------------------------------------------------
// 2) History pagination (acceptance: < 1s per page)
// ---------------------------------------------------------------------------
echo "=== 2) Ledger history pages (a user with ~20 rows/page) ===\n";
$wpdb->queries = array();
$t        = microtime( true );
$history  = $ledger_repository->history( 3, 'cashback', 1, 20 );
$elapsed  = microtime( true ) - $t;
lt_check( 'history page under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'history is paginated to 20 rows', 20 === count( $history['items'] ), count( $history['items'] ) . ' rows' );
lt_check( 'history uses exactly 2 queries (count + page)', 2 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );

// ---------------------------------------------------------------------------
// 3) Dashboard totals + 30-day series + top rules + recent (each < 1s)
// ---------------------------------------------------------------------------
echo "=== 3) Dashboard queries on the full ledger ===\n";
$wpdb->queries = array();
$t      = microtime( true );
$totals = $stats->totals();
$elapsed = microtime( true ) - $t;
lt_check( 'lifetime totals under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'totals use 5 constant queries', 5 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );
lt_check( 'totals are non-zero', $totals['issued'] > 0 && $totals['users'] > 0 );

$wpdb->queries = array();
$t     = microtime( true );
$range = $stats->range( gmdate( 'Y-m-d H:i:s', $day_start ), gmdate( 'Y-m-d H:i:s', $day_start + 86400 ) );
$elapsed = microtime( true ) - $t;
lt_check( 'today range under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'range uses 1 query', 1 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );

use AriunetClub\Modules\Dashboard\DashboardStats;
$window = DashboardStats::window( 30, new DateTimeZone( 'Asia/Tehran' ) );
$wpdb->queries = array();
$t     = microtime( true );
$rows  = $stats->daily_rows( $window['buckets'], $window['start_gmt'], $window['end_gmt'], $window['offset_minutes'] );
$elapsed = microtime( true ) - $t;
lt_check( '30-day daily aggregation under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'daily aggregation uses 1 query', 1 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );
lt_check( 'every bucket day present after fill', 30 === count( DashboardStats::fill_series( $window['buckets'], $rows ) ) );

$wpdb->queries = array();
$t      = microtime( true );
$top    = $stats->top_rules( 5 );
$elapsed = microtime( true ) - $t;
lt_check( 'top rules under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'top rules use 1 query', 1 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );

$wpdb->queries = array();
$t       = microtime( true );
$recent  = $stats->recent( 10 );
$elapsed = microtime( true ) - $t;
lt_check( 'recent activity under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'recent activity uses 1 query', 1 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );

// ---------------------------------------------------------------------------
// 4) Dashboard consistency: series sum equals range aggregates
// ---------------------------------------------------------------------------
echo "=== 4) Aggregation consistency ===\n";
$series = DashboardStats::fill_series( $window['buckets'], $rows );
$sum_earned = 0.0;
foreach ( $series as $point ) {
        $sum_earned += $point['earned'];
}
$range_30 = $stats->range( $window['start_gmt'], $window['end_gmt'] );
lt_check(
        '30-day series sum matches the range query',
        abs( $sum_earned - $range_30['earned'] ) < 1.0,
        number_format( $sum_earned, 2 ) . ' vs ' . number_format( $range_30['earned'], 2 )
);

echo "\n================ LOAD TEST PASSED ================\n";
exit( 0 );
