<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

/**
 * wpdb-backed repository for the ariunetclub_rules table. All rule SQL lives here.
 */
class RuleRepository implements RuleGateway {
	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $table;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'ariunetclub_rules';
	}

	/** @return array<int,array<string,mixed>> */
	public function active_for_event( string $event_type ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'active' AND event_type = %s ORDER BY priority ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
				$event_type
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; id prepared.
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $data @return int */
	public function insert( array $data ): int {
		$this->wpdb->insert( $this->table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- rule insert owned by repository.
		$id = (int) $this->wpdb->insert_id;
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'Unable to store rule.' );
		}
		return $id;
	}

	/** @param int $id @param array<string,mixed> $data @return bool */
	public function update( int $id, array $data ): bool {
		$ok = $this->wpdb->update( $this->table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- rule update owned by repository.
		return false !== $ok;
	}

	public function delete( int $id ): bool {
		return (bool) $this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- rule delete owned by repository.
	}

	/** @param int $id @param string[] $statuses @return bool */
	public function set_status( int $id, array $statuses ): bool {
		$ok = $this->wpdb->update( $this->table, array( 'status' => $statuses[0] ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- status change owned by repository.
		return false !== $ok;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function list( int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; no external input.
		$items    = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
			ARRAY_A
		);
		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}
}
