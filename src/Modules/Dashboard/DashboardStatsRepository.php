<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Dashboard;

/**
 * wpdb-backed read-only statistics for the admin dashboard. Every query is
 * either fully literal or prepared; there is no user input anywhere in this
 * class (day windows, limits and offsets are computed server-side).
 */
final class DashboardStatsRepository {

	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $ledger_table;

	/** @var string */
	private $balances_table;

	/** @var string */
	private $rules_table;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb           = $wpdb;
		$this->ledger_table   = $wpdb->prefix . 'ariunetclub_ledger';
		$this->balances_table = $wpdb->prefix . 'ariunetclub_balances';
		$this->rules_table    = $wpdb->prefix . 'ariunetclub_rules';
	}

	/**
	 * Lifetime totals. Earning = positive credits; spending = wallet debits
	 * and captures (absolute); issued is the total cashback ever granted.
	 *
	 * @return array{issued:float,spent:float,held:float,users:int,adjustments:float}
	 */
	public function totals(): array {
		$issued = (float) $this->wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$this->ledger_table} WHERE entry_type = 'credit' AND amount > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; fully literal query.
		$spent  = (float) $this->wpdb->get_var( "SELECT COALESCE(SUM(-amount), 0) FROM {$this->ledger_table} WHERE entry_type IN ('debit', 'capture') AND amount < 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; fully literal query.
		$adjust = (float) $this->wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$this->ledger_table} WHERE entry_type = 'adjust'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; fully literal query.
		$held   = (float) $this->wpdb->get_var( "SELECT COALESCE(SUM(held), 0) FROM {$this->balances_table} WHERE asset = 'cashback'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; fully literal query.
		$users  = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->balances_table} WHERE asset = 'cashback' AND (available <> 0 OR held <> 0)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; fully literal query.

		return array(
			'issued'      => $issued,
			'spent'       => $spent,
			'adjustments' => $adjust,
			'held'        => $held,
			'users'       => $users,
		);
	}

	/**
	 * Earned and spent inside a UTC [start, end) window.
	 *
	 * @param string $start_gmt Y-m-d H:i:s UTC.
	 * @param string $end_gmt   Y-m-d H:i:s UTC.
	 * @return array{earned:float,spent:float}
	 */
	public function range( string $start_gmt, string $end_gmt ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN entry_type = 'credit' AND amount > 0 THEN amount ELSE 0 END), 0) AS earned, COALESCE(SUM(CASE WHEN entry_type IN ('debit', 'capture') AND amount < 0 THEN -amount ELSE 0 END), 0) AS spent FROM {$this->ledger_table} WHERE created_at >= %s AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
				$start_gmt,
				$end_gmt
			),
			ARRAY_A
		);
		return array(
			'earned' => is_array( $row ) && is_numeric( $row['earned'] ?? null ) ? (float) $row['earned'] : 0.0,
			'spent'  => is_array( $row ) && is_numeric( $row['spent'] ?? null ) ? (float) $row['spent'] : 0.0,
		);
	}

	/**
	 * Daily earning/spending bucketed by LOCAL day (the site timezone offset
	 * is applied inside SQL so no approximation is made). Zero days are
	 * filled by DashboardStats::fill_series.
	 *
	 * @param array<int,int> $buckets        Local day-start timestamps.
	 * @param string            $start_gmt      Window start (UTC).
	 * @param string            $end_gmt        Window end (UTC).
	 * @param int               $offset_minutes Site UTC offset in minutes.
	 * @return array<int,array<string,mixed>>
	 */
	public function daily_rows( array $buckets, string $start_gmt, string $end_gmt, int $offset_minutes ): array {
		/** @var array<int, array<string, mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT UNIX_TIMESTAMP(DATE(created_at + INTERVAL %d MINUTE)) AS bucket, COALESCE(SUM(CASE WHEN entry_type = 'credit' AND amount > 0 THEN amount ELSE 0 END), 0) AS earned, COALESCE(SUM(CASE WHEN entry_type IN ('debit', 'capture') AND amount < 0 THEN -amount ELSE 0 END), 0) AS spent FROM {$this->ledger_table} WHERE created_at >= %s AND created_at < %s GROUP BY bucket ORDER BY bucket ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
				$offset_minutes,
				$start_gmt,
				$end_gmt
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Top earning rules by total granted cashback.
	 *
	 * @param int $limit Row cap.
	 * @return array<int,array{rule_id:int,title:string,total:float}>
	 */
	public function top_rules( int $limit = 5 ): array {
		$limit = max( 1, min( 20, $limit ) );
		/** @var array<int, array<string, mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.rule_id AS rule_id, COALESCE(r.title, '') AS title, COALESCE(SUM(l.amount), 0) AS total FROM {$this->ledger_table} l LEFT JOIN {$this->rules_table} r ON r.id = l.rule_id WHERE l.rule_id IS NOT NULL AND l.rule_id <> 0 AND l.entry_type = 'credit' AND l.amount > 0 GROUP BY l.rule_id, r.title ORDER BY total DESC, l.rule_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled tables; limit prepared.
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'rule_id' => is_numeric( $row['rule_id'] ?? null ) ? (int) $row['rule_id'] : 0,
				'title'   => is_string( $row['title'] ?? null ) ? $row['title'] : '',
				'total'   => is_numeric( $row['total'] ?? null ) ? (float) $row['total'] : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Latest ledger entries.
	 *
	 * @param int $limit Row cap.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		/** @var array<int, array<string, mixed>>|null $rows */
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->ledger_table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; limit prepared.
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
