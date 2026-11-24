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

// ---------------------------------------------------------------------------
// 5) Phase 6: one MILLION ledger rows + credit lots, admin pages < 1s
// ---------------------------------------------------------------------------
echo "=== 5) Phase 6 credit lots: 1,000,000 ledger rows ===\n";

use AriunetClub\Modules\Credit\LotRepository;
use AriunetClub\Modules\Credit\ReconciliationJob;

$million_start = microtime( true );
$pdo->beginTransaction();
$million_stmt = $pdo->prepare( 'INSERT INTO wp_ariunetclub_ledger (user_id, asset, entry_type, amount, balance_after, ref_type, ref_id, rule_id, idempotency_key, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)' );
for ( $i = ROWS_TARGET + 1; $i <= 1000000; ++$i ) {
        $type   = $types[ $i % 8 ];
        $amount = ( 'debit' === $type || 'capture' === $type ) ? -1 * mt_rand( 100, 90000 ) : mt_rand( 100, 120000 );
        $million_stmt->execute( array(
                1 + ( $i % USERS ),
                'cashback',
                $type,
                $amount / 10,
                0,
                'order',
                1000 + ( $i % 900 ),
                ( 0 === $i % 3 ) ? null : 1 + ( $i % 5 ),
                'million-' . $i,
                'load test million row ' . $i,
                null,
                gmdate( 'Y-m-d H:i:s', $day_start - mt_rand( 0, 1095 ) * 86400 + mt_rand( 0, 86399 ) ),
        ) );
}
$pdo->commit();
$total_rows = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_ariunetclub_ledger' )->fetchColumn();
lt_check( 'ledger grew to 1,000,000 rows', 1000000 === $total_rows, number_format( $total_rows ) . ' rows in ' . lt_ms( microtime( true ) - $million_start ) );

// Credit lots: ~6 per user, a third of them with expiry dates, some held.
$lot_repo = new LotRepository( $wpdb );
$pdo->beginTransaction();
$lot_stmt = $pdo->prepare( 'INSERT INTO wp_ariunetclub_credit_lots (user_id, source_type, source_ref, ref_type, ref_id, rule_id, initial_amount, remaining, held, expires_at, status, reason, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)' );
for ( $u = 1; $u <= USERS; ++$u ) {
        for ( $j = 0; $j < 6; ++$j ) {
                $remaining = mt_rand( 100, 90000 ) / 10;
                $held      = 0 === $j % 5 ? mt_rand( 0, (int) $remaining ) : 0;
                $expires   = $j % 3 === 0 ? gmdate( 'Y-m-d H:i:s', $now + mt_rand( 1, 120 ) * 86400 ) : null;
                $lot_stmt->execute( array(
                        $u,
                        0 === $j % 6 ? 'legacy' : 'rule',
                        $j,
                        'order',
                        1000 + $j,
                        1 + ( $j % 5 ),
                        $remaining + mt_rand( 0, 500 ),
                        $remaining,
                        $held,
                        $expires,
                        'active',
                        'load test lot',
                        gmdate( 'Y-m-d H:i:s', $now - mt_rand( 0, 200 ) * 86400 ),
                ) );
        }
}
$pdo->commit();
$total_lots = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_ariunetclub_credit_lots' )->fetchColumn();
lt_check( 'credit lots seeded (6 per user)', USERS * 6 === $total_lots, number_format( $total_lots ) . ' lots' );

// Admin page: liability summary card.
$wpdb->queries = array();
$t         = microtime( true );
$liability = $lot_repo->liability_summary( gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + 30 * 86400 ) );
$elapsed   = microtime( true ) - $t;
lt_check( 'liability summary under 1s on 1M ledger rows', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'liability summary is one query', 1 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );
lt_check( 'liability numbers are non-zero', $liability['outstanding'] > 0 && $liability['users'] > 0 );

// Admin page: one customer's lots (the admin wallet history view).
$wpdb->queries = array();
$t        = microtime( true );
$user_lots = $lot_repo->lots_for_user( 1234, 20 );
$lot_count = $lot_repo->count_lots_for_user( 1234 );
$elapsed  = microtime( true ) - $t;
lt_check( 'customer lots view under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'customer lots use 2 queries', 2 === count( $wpdb->queries ), count( $wpdb->queries ) . ' queries' );
lt_check( 'customer lots capped at 20 rows', 6 === count( $user_lots ) && $lot_count === 6, count( $user_lots ) . ' rows, total ' . $lot_count );

// Daily expiry job scans.
$wpdb->queries = array();
$t    = microtime( true );
$due  = $lot_repo->due_lots( gmdate( 'Y-m-d H:i:s', $now ), 200 );
$elapsed = microtime( true ) - $t;
lt_check( 'expiry due-lots scan under 1s', $elapsed < 1.0, lt_ms( $elapsed ) . ' (' . count( $due ) . ' due now)' );

$t      = microtime( true );
$window7 = $lot_repo->lots_expiring_within( gmdate( 'Y-m-d H:i:s', $now + 7 * 86400 ), gmdate( 'Y-m-d H:i:s', $now + 8 * 86400 ), 500 );
$elapsed = microtime( true ) - $t;
lt_check( '7-day expiring scan under 1s', $elapsed < 1.0, lt_ms( $elapsed ) . ' (' . count( $window7 ) . ' lots)' );

// Reconciliation: one batch of 1,000 users through the three-way join.
$recon = new ReconciliationJob( $lot_repo, new AriunetClub\Core\Logger() );
$t     = microtime( true );
$batch = $recon->check_batch( 0 );
$elapsed = microtime( true ) - $t;
lt_check( 'reconciliation batch (1000 users, 3-way join) under 1s', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'reconciliation batch checked 1000 users', 1000 === $batch['checked'], $batch['checked'] . ' users' );

// Budget issued aggregate: the production query only scans the CURRENT MONTH
// through the ledger_created_at index (never the whole 1M-row history).
$month_start = gmdate( 'Y-m-01 00:00:00' );
$wpdb->queries = array();
$t = microtime( true );
$sum_all = (float) $pdo->query( "SELECT COALESCE(SUM(CASE WHEN entry_type IN ('credit', 'grant') AND amount > 0 THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN entry_type = 'adjust' AND amount < 0 AND (idempotency_key LIKE 'order:%:reversal' OR idempotency_key LIKE 'order:%:refund:%:reversal') THEN -amount ELSE 0 END), 0) + COALESCE(SUM(CASE WHEN entry_type = 'adjust' AND amount > 0 AND idempotency_key LIKE 'wallet:refund:order:%' THEN amount ELSE 0 END), 0) FROM wp_ariunetclub_ledger WHERE created_at >= '" . $month_start . "' AND created_at < '2100-01-01'" )->fetchColumn();
$elapsed = microtime( true ) - $t;
lt_check( 'budget issued aggregate (current month, indexed) under 1s on 1M rows', $elapsed < 1.0, lt_ms( $elapsed ) );
lt_check( 'issued aggregate is non-zero', $sum_all > 0 );

echo "\n================ LOAD TEST PASSED ================\n";
exit( 0 );
