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


	/**
	 * Coerces a raw DB row value to float without PHPStan complaints.
	 *
	 * @param mixed $value Raw row value.
	 * @return float
	 */
	public static function f( $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Coerces a raw DB row value to int without PHPStan complaints.
	 *
	 * @param mixed $value Raw row value.
	 * @return int
	 */
	public static function i( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Coerces a raw DB row value to string without PHPStan complaints.
	 *
	 * @param mixed $value Raw row value.
	 * @return string
	 */
	public static function s( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Coerces a raw DB row value to a nullable int.
	 *
	 * @param mixed $value Raw row value.
	 * @return int|null
	 */
	public static function n( $value ) {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * esc_sql() may receive arrays in general; our datetimes are always
	 * plain strings, narrowed once for both PHPStan and runtime safety.
	 *
	 * @param string $value UTC datetime.
	 * @return string
	 */
	private static function escape_datetime( string $value ): string {
		$escaped = esc_sql( $value );
		return is_string( $escaped ) ? $escaped : '';
	}

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
			/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
				"SELECT COALESCE(SUM(remaining), 0) AS remaining, COALESCE(SUM(held), 0) AS held FROM {$this->lots_table} WHERE user_id = " . (int) $user_id . " AND status = 'active' FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer-only predicate; locking read inside the ledger transaction.
				ARRAY_A
			);
			return array(
				'remaining' => is_array( $row ) && is_numeric( $row['remaining'] ?? null ) ? LotRepository::f( $row['remaining'] ) : 0.0,
				'held'      => is_array( $row ) && is_numeric( $row['held'] ?? null ) ? LotRepository::f( $row['held'] ) : 0.0,
			);
	}

		/**
		 * Lots a new hold may reserve from: active, unheld headroom, not past
		 * expiry (I3 — an expired lot can never be spent again).
		 *
		 * @return array<int,array<string,mixed>>
		 */
	public function lock_allocatable_lots( int $user_id, string $now_gmt ): array {
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->lots_table} WHERE user_id = " . (int) $user_id . " AND status = 'active' AND remaining > held + " . self::EPSILON . " AND (expires_at IS NULL OR expires_at > '" . self::escape_datetime( $now_gmt ) . "') ORDER BY expires_at IS NULL ASC, expires_at ASC, created_at ASC, id ASC FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer/epsilon predicates and an escaped UTC datetime; locking read inside the ledger transaction.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->lots_table} WHERE user_id = " . (int) $user_id . " AND status = 'active' AND remaining > held + " . self::EPSILON . " ORDER BY expires_at IS NULL ASC, expires_at ASC, created_at ASC, id ASC FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer/epsilon predicates; locking read inside the ledger transaction.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->lots_table} WHERE user_id = " . (int) $user_id . " AND status = 'active' AND ref_type = 'order' AND ref_id = " . (int) $order_id . " AND remaining > held + " . self::EPSILON . " ORDER BY expires_at IS NULL ASC, expires_at ASC, id ASC FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer/epsilon predicates; locking read inside the ledger transaction.
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
	}

		/**
		 * @return array<string,mixed>|null
		 */
	public function lock_lot( int $lot_id ): ?array {
			/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
				"SELECT * FROM {$this->lots_table} WHERE id = " . (int) $lot_id . " FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer-only predicate; locking read.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT a.*, l.expires_at AS lot_expires_at FROM {$this->allocations_table} a INNER JOIN {$this->lots_table} l ON l.id = a.lot_id WHERE a.order_id = " . (int) $order_id . " AND a.user_id = " . (int) $user_id . " AND a.state = 'held' ORDER BY a.id ASC FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; integer-only predicates; locking read inside the ledger transaction.
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
				'user_id'        => LotRepository::i( $lot['user_id'] ),
				'source_type'    => LotRepository::s( $lot['source_type'] ),
				'source_ref'     => isset( $lot['source_ref'] ) ? LotRepository::i( $lot['source_ref'] ) : null,
				'ref_type'       => LotRepository::s( $lot['ref_type'] ?? '' ) !== '' ? LotRepository::s( $lot['ref_type'] ) : null,
				'ref_id'         => is_numeric( $lot['ref_id'] ?? null ) ? LotRepository::i( $lot['ref_id'] ) : null,
				'rule_id'        => is_numeric( $lot['rule_id'] ?? null ) ? LotRepository::i( $lot['rule_id'] ) : null,
				'initial_amount' => Money::round( LotRepository::f( $lot['initial_amount'] ) ),
				'remaining'      => Money::round( LotRepository::f( $lot['remaining'] ) ),
				'held'           => Money::round( LotRepository::f( $lot['held'] ) ),
				'expires_at'     => LotRepository::s( $lot['expires_at'] ?? '' ) !== '' ? LotRepository::s( $lot['expires_at'] ) : null,
				'status'         => isset( $lot['status'] ) ? LotRepository::s( $lot['status'] ) : self::STATUS_ACTIVE,
				'reason'         => LotRepository::s( $lot['reason'] ?? '' ) !== '' ? LotRepository::s( $lot['reason'] ) : null,
				'campaign_id'    => is_numeric( $lot['campaign_id'] ?? null ) ? LotRepository::i( $lot['campaign_id'] ) : null,
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
				"SELECT id, source_type, ref_type, ref_id, remaining, held, expires_at, status, reason, created_at FROM {$this->lots_table} WHERE user_id = " . (int) $user_id . " AND status = 'active' AND remaining > " . self::EPSILON . " ORDER BY expires_at IS NULL ASC, expires_at ASC, created_at ASC, id ASC LIMIT " . (int) $limit, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer/epsilon predicates.
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
	}

		/**
		 * @return int Live lot rows for the user (for the "and N more" note).
		 */
	public function count_lots_for_user( int $user_id ): int {
			return self::i( $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->lots_table} WHERE user_id = " . (int) $user_id . " AND status = 'active' AND remaining > " . self::EPSILON // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer/epsilon predicates.
			) );
	}

		/**
		 * Club liability summary: every unspent unit is the shop's debt.
		 *
		 * @return array{outstanding:float,expiring_30d:float,lots:int,users:int}
		 */
	public function liability_summary( string $now_gmt, string $in_30d_gmt ): array {
			/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
				"SELECT COALESCE(SUM(remaining), 0) AS outstanding, COALESCE(SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= '" . self::escape_datetime( $in_30d_gmt ) . "' THEN remaining ELSE 0 END), 0) AS expiring, COUNT(*) AS lots, COUNT(DISTINCT user_id) AS users FROM {$this->lots_table} WHERE status = 'active' AND remaining > " . self::EPSILON, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; escaped UTC datetime and epsilon predicate.
				ARRAY_A
			);
			return array(
				'outstanding'  => is_array( $row ) && is_numeric( $row['outstanding'] ?? null ) ? LotRepository::f( $row['outstanding'] ) : 0.0,
				'expiring_30d' => is_array( $row ) && is_numeric( $row['expiring'] ?? null ) ? LotRepository::f( $row['expiring'] ) : 0.0,
				'lots'         => is_array( $row ) ? LotRepository::i( $row['lots'] ?? 0 ) : 0,
				'users'        => is_array( $row ) ? LotRepository::i( $row['users'] ?? 0 ) : 0,
			);
	}

		/**
		 * Lots whose unheld part is due for expiry right now.
		 *
		 * @return array<int,array<string,mixed>>
		 */
	public function due_lots( string $now_gmt, int $limit ): array {
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->lots_table} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= '" . self::escape_datetime( $now_gmt ) . "' AND remaining > held + " . self::EPSILON . " ORDER BY expires_at ASC, id ASC LIMIT " . (int) $limit, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; escaped UTC datetime, epsilon and integer predicates.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->lots_table} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at >= '" . self::escape_datetime( $start_gmt ) . "' AND expires_at < '" . self::escape_datetime( $end_gmt ) . "' AND remaining > " . self::EPSILON . " ORDER BY expires_at ASC, id ASC LIMIT " . (int) $limit, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; escaped UTC datetimes, epsilon and integer predicates.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT b.user_id AS user_id, b.available AS available, b.held AS held, COALESCE(l.sr, 0) AS lot_remaining, COALESCE(l.sh, 0) AS lot_held FROM {$this->balances_table} b LEFT JOIN (SELECT user_id, SUM(remaining) AS sr, SUM(held) AS sh FROM {$this->lots_table} WHERE status = 'active' GROUP BY user_id) l ON l.user_id = b.user_id WHERE b.asset = 'cashback' AND b.user_id > " . (int) $after_user_id . " AND (b.available <> 0 OR b.held <> 0) ORDER BY b.user_id ASC LIMIT " . (int) $batch, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; integer-only predicates; read-only migration scan.
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
			/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
				"SELECT available, held FROM {$this->balances_table} WHERE user_id = " . (int) $user_id . " AND asset = 'cashback' FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; integer-only predicate; locking read inside the migration transaction.
				ARRAY_A
			);
		if ( ! is_array( $row ) ) {
				return array(
					'available' => 0.0,
					'held'      => 0.0,
				);
		}
			return array(
				'available' => is_numeric( $row['available'] ?? null ) ? LotRepository::f( $row['available'] ) : 0.0,
				'held'      => is_numeric( $row['held'] ?? null ) ? LotRepository::f( $row['held'] ) : 0.0,
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
				"UPDATE {$this->lots_table} SET expires_at = '" . self::escape_datetime( $expires_at ) . "' WHERE source_type = 'legacy' AND status = 'active' AND expires_at IS NULL AND remaining > " . self::EPSILON // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot admin maintenance action; escaped UTC datetime and epsilon predicate.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT b.user_id AS user_id, b.available AS available, b.held AS held, COALESCE(l.sr, 0) AS lot_remaining, COALESCE(l.sh, 0) AS lot_held, COALESCE(g.ledger_sum, 0) AS ledger_sum FROM {$this->balances_table} b LEFT JOIN (SELECT user_id, SUM(remaining) AS sr, SUM(held) AS sh FROM {$this->lots_table} WHERE status = 'active' GROUP BY user_id) l ON l.user_id = b.user_id LEFT JOIN (SELECT user_id, SUM(amount) AS ledger_sum FROM {$this->ledger_table} WHERE asset = 'cashback' GROUP BY user_id) g ON g.user_id = b.user_id WHERE b.asset = 'cashback' AND b.user_id > " . (int) $after_user_id . " ORDER BY b.user_id ASC LIMIT " . (int) $batch, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; integer-only predicates; read-only reconciliation scan.
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
			/** @var array<int, array<string,mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
				"SELECT id, user_id, remaining, held, expires_at, status FROM {$this->lots_table} WHERE remaining < -1 * " . self::EPSILON . " OR held < -1 * " . self::EPSILON . " OR held > remaining + " . self::EPSILON . " OR (status = 'active' AND expires_at IS NOT NULL AND expires_at < '" . self::escape_datetime( $grace_gmt ) . "' AND remaining > held + " . self::EPSILON . ")", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; epsilon and escaped-datetime predicates; read-only invariant scan.
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
	}
}
