<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Money;

/**
 * SQL layer for phase 6 credit lots and their hold allocations.
 *
 * Every mutating caller (LotService) runs inside the open ledger transaction
 * and holds the row lock on the user's balance, so the read-modify-write
 * helpers here are serialized per user on MySQL (locking reads) and safe on
 * the SQLite test shim (single writer). FEFO = "first to expire, first out",
 * then oldest first, and no-expiry lots last.
 */
class LotRepository {

        const STATUS_ACTIVE    = 'active';
        const STATUS_EXHAUSTED = 'exhausted';
        const STATUS_EXPIRED   = 'expired';

        const EPSILON = 0.0000001;

        /** @var \wpdb */
        private $wpdb;

        /** @var string */
        private $lots_table;

        /** @var string */
        private $allocations_table;

        /** @var string */
        private $balances_table;

        /** @var string */
        private $ledger_table;

        public function __construct( \wpdb $wpdb ) {
                $this->wpdb              = $wpdb;
                $this->lots_table        = $wpdb->prefix . 'ariunetclub_credit_lots';
                $this->allocations_table = $wpdb->prefix . 'ariunetclub_lot_allocations';
                $this->balances_table    = $wpdb->prefix . 'ariunetclub_balances';
                $this->ledger_table      = $wpdb->prefix . 'ariunetclub_ledger';
        }

        /**
         * Locking aggregate of a user's live lots (invariant I1 inputs).
         *
         * @return array{remaining:float,held:float}
         */
        public function sums_for_user( int $user_id ): array {
                $row = $this->wpdb->get_row(
                        $this->wpdb->prepare( "SELECT COALESCE(SUM(remaining), 0) AS remaining, COALESCE(SUM(held), 0) AS held FROM {$this->lots_table} WHERE user_id = %d AND status = 'active' FOR UPDATE", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared; locking read inside the ledger transaction.
                        ARRAY_A
                );
                return array(
                        'remaining' => is_array( $row ) && is_numeric( $row['remaining'] ?? null ) ? (float) $row['remaining'] : 0.0,
                        'held'      => is_array( $row ) && is_numeric( $row['held'] ?? null ) ? (float) $row['held'] : 0.0,
                );
        }

        /**
         * Lots a new hold may reserve from: active, unheld headroom, not past
         * expiry (I3 — an expired lot can never be spent again).
         *
         * @return array<int,array<string,mixed>>
         */
        public function lock_allocatable_lots( int $user_id, string $now_gmt ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT * FROM {$this->lots_table} WHERE user_id = %d AND status = 'active' AND remaining > held + %f AND (expires_at IS NULL OR expires_at > %s) ORDER BY expires_at IS NULL ASC, expires_at ASC, created_at ASC, id ASC FOR UPDATE", $user_id, self::EPSILON, $now_gmt ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared; locking read inside the ledger transaction.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Lots any consumption (debit, reversal, negative adjustment) may draw
         * from: active with unheld headroom; past-expiry lots appear first in
         * FEFO order because their expires_at is smallest.
         *
         * @return array<int,array<string,mixed>>
         */
        public function lock_consumable_lots( int $user_id ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT * FROM {$this->lots_table} WHERE user_id = %d AND status = 'active' AND remaining > held + %f ORDER BY expires_at IS NULL ASC, expires_at ASC, created_at ASC, id ASC FOR UPDATE", $user_id, self::EPSILON ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared; locking read inside the ledger transaction.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Live lots that reference one order (precise claw-back target for
         * reversals of that order's cashback).
         *
         * @return array<int,array<string,mixed>>
         */
        public function lock_order_lots( int $user_id, int $order_id ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT * FROM {$this->lots_table} WHERE user_id = %d AND status = 'active' AND ref_type = 'order' AND ref_id = %d AND remaining > held + %f ORDER BY expires_at IS NULL ASC, expires_at ASC, id ASC FOR UPDATE", $user_id, $order_id, self::EPSILON ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared; locking read inside the ledger transaction.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * @return array<string,mixed>|null
         */
        public function lock_lot( int $lot_id ): ?array {
                $row = $this->wpdb->get_row(
                        $this->wpdb->prepare( "SELECT * FROM {$this->lots_table} WHERE id = %d FOR UPDATE", $lot_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared; locking read.
                        ARRAY_A
                );
                return is_array( $row ) ? $row : null;
        }

        /**
         * Held allocations recorded for one order (the reservation map of a
         * wallet hold; pre-upgrade holds have none).
         *
         * @return array<int,array<string,mixed>>
         */
        public function lock_held_allocations( int $order_id, int $user_id ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT a.*, l.expires_at AS lot_expires_at FROM {$this->allocations_table} a INNER JOIN {$this->lots_table} l ON l.id = a.lot_id WHERE a.order_id = %d AND a.user_id = %d AND a.state = 'held' ORDER BY a.id ASC FOR UPDATE", $order_id, $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; values prepared; locking read inside the ledger transaction.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * @param array<string,mixed> $lot Lot row values.
         * @return int New lot id.
         */
        public function insert_lot( array $lot ): int {
                $now  = current_time( 'mysql', true );
                $data = array(
                        'user_id'        => (int) $lot['user_id'],
                        'source_type'    => (string) $lot['source_type'],
                        'source_ref'     => isset( $lot['source_ref'] ) ? (int) $lot['source_ref'] : null,
                        'ref_type'       => isset( $lot['ref_type'] ) && null !== $lot['ref_type'] ? (string) $lot['ref_type'] : null,
                        'ref_id'         => isset( $lot['ref_id'] ) && null !== $lot['ref_id'] ? (int) $lot['ref_id'] : null,
                        'rule_id'        => isset( $lot['rule_id'] ) && null !== $lot['rule_id'] ? (int) $lot['rule_id'] : null,
                        'initial_amount' => Money::round( (float) $lot['initial_amount'] ),
                        'remaining'      => Money::round( (float) $lot['remaining'] ),
                        'held'           => Money::round( (float) $lot['held'] ),
                        'expires_at'     => isset( $lot['expires_at'] ) && null !== $lot['expires_at'] ? (string) $lot['expires_at'] : null,
                        'status'         => isset( $lot['status'] ) ? (string) $lot['status'] : self::STATUS_ACTIVE,
                        'reason'         => isset( $lot['reason'] ) && null !== $lot['reason'] ? (string) $lot['reason'] : null,
                        'campaign_id'    => isset( $lot['campaign_id'] ) && null !== $lot['campaign_id'] ? (int) $lot['campaign_id'] : null,
                        'created_at'     => $now,
                );
                $ok   = $this->wpdb->insert( $this->lots_table, $data, array( '%d', '%s', '%d', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only insert owned by the repository.
                if ( false === $ok ) {
                        throw new \RuntimeException( 'Unable to insert credit lot.' );
                }
                return (int) $this->wpdb->insert_id;
        }

        /**
         * Persists a lot row already locked inside the transaction. The status
         * is derived by the caller; I2/I4 are re-asserted here as a hard guard.
         */
        public function update_lot( int $lot_id, float $remaining, float $held, string $status ): void {
                $remaining = Money::round( $remaining );
                $held      = Money::round( $held );
                if ( $remaining < -1 * self::EPSILON || $held < -1 * self::EPSILON || $held > $remaining + self::EPSILON ) {
                        throw new \RuntimeException( 'Credit lot update would violate the lot invariants.' );
                }
                if ( $remaining <= self::EPSILON && $held <= self::EPSILON && self::STATUS_ACTIVE === $status ) {
                        $status = self::STATUS_EXHAUSTED;
                }
                $ok = $this->wpdb->update(
                        $this->lots_table,
                        array(
                                'remaining' => $remaining,
                                'held'      => $held,
                                'status'    => $status,
                        ),
                        array( 'id' => $lot_id ),
                        array( '%f', '%f', '%s' ),
                        array( '%d' )
                ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- row already locked inside the caller's transaction.
                if ( false === $ok ) {
                        throw new \RuntimeException( 'Unable to update credit lot.' );
                }
        }

        /**
         * @return int New allocation id.
         */
        public function insert_allocation( int $lot_id, int $user_id, int $order_id, float $amount ): int {
                $ok = $this->wpdb->insert(
                        $this->allocations_table,
                        array(
                                'lot_id'     => $lot_id,
                                'user_id'    => $user_id,
                                'order_id'   => $order_id,
                                'amount'     => Money::round( $amount ),
                                'state'      => 'held',
                                'created_at' => current_time( 'mysql', true ),
                        ),
                        array( '%d', '%d', '%d', '%f', '%s', '%s' )
                ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only insert owned by the repository.
                if ( false === $ok ) {
                        throw new \RuntimeException( 'Unable to insert lot allocation.' );
                }
                return (int) $this->wpdb->insert_id;
        }

        public function set_allocation_state( int $allocation_id, string $state ): void {
                $data   = array( 'state' => $state );
                $format = array( '%s' );
                if ( 'held' !== $state ) {
                        $data['released_at'] = current_time( 'mysql', true );
                        $format[]            = '%s';
                }
                $ok = $this->wpdb->update( $this->allocations_table, $data, array( 'id' => $allocation_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- row already locked inside the caller's transaction.
                if ( false === $ok ) {
                        throw new \RuntimeException( 'Unable to update lot allocation.' );
                }
        }

        /**
         * Customer-facing lot list: live lots with money in them, soonest
         * expiry first (FEFO — exactly the order they will be spent in).
         *
         * @return array<int,array<string,mixed>>
         */
        public function lots_for_user( int $user_id, int $limit = 10 ): array {
                $limit = max( 1, min( 50, $limit ) );
                $rows  = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT id, source_type, ref_type, ref_id, remaining, held, expires_at, status, reason, created_at FROM {$this->lots_table} WHERE user_id = %d AND status = 'active' AND remaining > %f ORDER BY expires_at IS NULL ASC, expires_at ASC, created_at ASC, id ASC LIMIT %d", $user_id, self::EPSILON, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * @return int Live lot rows for the user (for the "and N more" note).
         */
        public function count_lots_for_user( int $user_id ): int {
                return (int) $this->wpdb->get_var(
                        $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->lots_table} WHERE user_id = %d AND status = 'active' AND remaining > %f", $user_id, self::EPSILON ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
                );
        }

        /**
         * Club liability summary: every unspent unit is the shop's debt.
         *
         * @return array{outstanding:float,expiring_30d:float,lots:int,users:int}
         */
        public function liability_summary( string $now_gmt, string $in_30d_gmt ): array {
                $row = $this->wpdb->get_row(
                        $this->wpdb->prepare( "SELECT COALESCE(SUM(remaining), 0) AS outstanding, COALESCE(SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= %s THEN remaining ELSE 0 END), 0) AS expiring, COUNT(*) AS lots, COUNT(DISTINCT user_id) AS users FROM {$this->lots_table} WHERE status = 'active' AND remaining > %f", $in_30d_gmt, self::EPSILON ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
                        ARRAY_A
                );
                return array(
                        'outstanding'  => is_array( $row ) && is_numeric( $row['outstanding'] ?? null ) ? (float) $row['outstanding'] : 0.0,
                        'expiring_30d' => is_array( $row ) && is_numeric( $row['expiring'] ?? null ) ? (float) $row['expiring'] : 0.0,
                        'lots'         => is_array( $row ) ? (int) ( $row['lots'] ?? 0 ) : 0,
                        'users'        => is_array( $row ) ? (int) ( $row['users'] ?? 0 ) : 0,
                );
        }

        /**
         * Lots whose unheld part is due for expiry right now.
         *
         * @return array<int,array<string,mixed>>
         */
        public function due_lots( string $now_gmt, int $limit ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT * FROM {$this->lots_table} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s AND remaining > held + %f ORDER BY expires_at ASC, id ASC LIMIT %d", $now_gmt, self::EPSILON, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Live lots expiring inside a [start, end) UTC window (the 7/3/1-day
         * reminder scan). Includes held-only lots: the customer still owns them.
         *
         * @return array<int,array<string,mixed>>
         */
        public function lots_expiring_within( string $start_gmt, string $end_gmt, int $limit ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT * FROM {$this->lots_table} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at >= %s AND expires_at < %s AND remaining > %f ORDER BY expires_at ASC, id ASC LIMIT %d", $start_gmt, $end_gmt, self::EPSILON, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * One migration batch: balances joined with live-lot sums, ordered by
         * user id so the job is resumable and idempotent.
         *
         * @return array<int,array<string,mixed>>
         */
        public function migration_batch( int $after_user_id, int $batch ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT b.user_id AS user_id, b.available AS available, b.held AS held, COALESCE(l.sr, 0) AS lot_remaining, COALESCE(l.sh, 0) AS lot_held FROM {$this->balances_table} b LEFT JOIN (SELECT user_id, SUM(remaining) AS sr, SUM(held) AS sh FROM {$this->lots_table} WHERE status = 'active' GROUP BY user_id) l ON l.user_id = b.user_id WHERE b.asset = 'cashback' AND b.user_id > %d AND (b.available <> 0 OR b.held <> 0) ORDER BY b.user_id ASC LIMIT %d", $after_user_id, $batch ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; values prepared; read-only migration scan.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Re-locks the balance row of one user (serialized against concurrent
         * ledger writes) and returns fresh values.
         *
         * @return array{available:float,held:float}
         */
        public function lock_balance( int $user_id ): array {
                $row = $this->wpdb->get_row(
                        $this->wpdb->prepare( "SELECT available, held FROM {$this->balances_table} WHERE user_id = %d AND asset = 'cashback' FOR UPDATE", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared; locking read inside the migration transaction.
                        ARRAY_A
                );
                if ( ! is_array( $row ) ) {
                        return array( 'available' => 0.0, 'held' => 0.0 );
                }
                return array(
                        'available' => is_numeric( $row['available'] ?? null ) ? (float) $row['available'] : 0.0,
                        'held'      => is_numeric( $row['held'] ?? null ) ? (float) $row['held'] : 0.0,
                );
        }

        /**
         * Applies expiry to legacy lots that never had one (the explicit,
         * confirmed admin action; the date is computed by the caller).
         *
         * @return int Affected rows.
         */
        public function apply_legacy_expiry( string $expires_at ): int {
                $affected = $this->wpdb->query(
                        $this->wpdb->prepare( "UPDATE {$this->lots_table} SET expires_at = %s WHERE source_type = 'legacy' AND status = 'active' AND expires_at IS NULL AND remaining > %f", $expires_at, self::EPSILON ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot admin maintenance action; value prepared.
                );
                return is_numeric( $affected ) ? (int) $affected : 0;
        }

        /**
         * One reconciliation batch: users (cursor-ordered) with balances, live
         * lot sums and the true ledger sum, for the I1 three-way compare.
         *
         * @return array<int,array<string,mixed>>
         */
        public function reconciliation_batch( int $after_user_id, int $batch ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT b.user_id AS user_id, b.available AS available, b.held AS held, COALESCE(l.sr, 0) AS lot_remaining, COALESCE(l.sh, 0) AS lot_held, COALESCE(g.ledger_sum, 0) AS ledger_sum FROM {$this->balances_table} b LEFT JOIN (SELECT user_id, SUM(remaining) AS sr, SUM(held) AS sh FROM {$this->lots_table} WHERE status = 'active' GROUP BY user_id) l ON l.user_id = b.user_id LEFT JOIN (SELECT user_id, SUM(amount) AS ledger_sum FROM {$this->ledger_table} WHERE asset = 'cashback' GROUP BY user_id) g ON g.user_id = b.user_id WHERE b.asset = 'cashback' AND b.user_id > %d ORDER BY b.user_id ASC LIMIT %d", $after_user_id, $batch ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; values prepared; read-only reconciliation scan.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Lot-level invariant violations (I2/I4) and stale expiry states (I3).
         *
         * @return array<int,array<string,mixed>>
         */
        public function invariant_violations( string $grace_gmt ): array {
                $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare( "SELECT id, user_id, remaining, held, expires_at, status FROM {$this->lots_table} WHERE remaining < -1 * %f OR held < -1 * %f OR held > remaining + %f OR (status = 'active' AND expires_at IS NOT NULL AND expires_at < %s AND remaining > held + %f)", self::EPSILON, self::EPSILON, self::EPSILON, $grace_gmt, self::EPSILON ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared; read-only invariant scan.
                        ARRAY_A
                );
                return is_array( $rows ) ? $rows : array();
        }
}
