<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Events;

/**
 * wpdb-backed repository for the ariunetclub_events table. All SQL for events lives here.
 */
class EventRepository implements EventGateway {
		/** @var \wpdb */
		private $wpdb;

		/** @var string */
		private $table;

		/** @var int */
		private $max_attempts;

	public function __construct( \wpdb $wpdb, int $max_attempts = 3 ) {
			$this->wpdb         = $wpdb;
			$this->table        = $wpdb->prefix . 'ariunetclub_events';
			$this->max_attempts = max( 1, $max_attempts );
	}

		/**
		 * @return int
		 */
	public function max_attempts(): int {
			return $this->max_attempts;
	}

		/** @return array<string,mixed>|null */
	public function find_by_key( string $key ): ?array {
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE idempotency_key = %s", $key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared.
				ARRAY_A
			);
			return is_array( $row ) ? $row : null;
	}

		/** @param array<string,mixed> $event @return array<string,mixed> */
	public function insert( array $event ): array {
			$now    = current_time( 'mysql', true );
			$status = isset( $event['status'] ) && is_string( $event['status'] ) ? $event['status'] : 'pending';

			// INSERT IGNORE makes the UNIQUE idempotency_key the concurrency boundary.
			$this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT IGNORE INTO {$this->table} (type, user_id, object_type, object_id, payload, idempotency_key, status, attempts, error, created_at) VALUES (%s, %d, %s, %d, %s, %s, %s, 0, NULL, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
					(string) $event['type'],
					(int) $event['user_id'],
					(string) $event['object_type'],
					(int) $event['object_id'],
					(string) $event['payload'],
					(string) $event['idempotency_key'],
					$status,
					$now
				)
			);

			$stored = $this->find_by_key( (string) $event['idempotency_key'] );
		if ( null === $stored ) {
				throw new \RuntimeException( 'Unable to store club event.' );
		}
			return $stored;
	}

		/** @return array<int,array<string,mixed>> */
	public function pending_batch( int $limit ): array {
			$limit = max( 1, min( 100, $limit ) );
			$rows  = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; limit prepared.
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
	}

	public function claim( int $id ): void {
			$this->wpdb->query(
				$this->wpdb->prepare( "UPDATE {$this->table} SET attempts = attempts + 1, error = NULL WHERE id = %d AND status = 'pending'", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; id prepared.
			);
	}

	public function mark_done( int $id ): void {
			$this->wpdb->query(
				$this->wpdb->prepare( "UPDATE {$this->table} SET status = 'done', error = NULL, processed_at = %s WHERE id = %d", current_time( 'mysql', true ), $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
			);
	}

	public function mark_skipped( int $id, string $reason ): void {
			$this->wpdb->query(
				$this->wpdb->prepare( "UPDATE {$this->table} SET status = 'skipped', error = %s, processed_at = %s WHERE id = %d", self::clean_error( $reason ), current_time( 'mysql', true ), $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
			);
	}

	public function mark_error( int $id, string $error, int $max_attempts ): void {
			$max_attempts = max( 1, $max_attempts );
			$this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->table} SET error = %s, status = CASE WHEN attempts >= %d THEN 'failed' ELSE 'pending' END WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
					self::clean_error( $error ),
					$max_attempts,
					$id
				)
			);
	}

	public function requeue( int $id ): void {
			$this->wpdb->query(
				$this->wpdb->prepare( "UPDATE {$this->table} SET status = 'pending', attempts = 0, error = NULL, processed_at = NULL WHERE id = %d AND status IN ('failed','skipped')", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; id prepared.
			);
	}

		/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function list( string $status, int $page, int $per_page ): array {
			$page     = max( 1, $page );
			$per_page = max( 1, min( 100, $per_page ) );
			$offset   = ( $page - 1 ) * $per_page;
			$where    = '1=1';
		if ( '' !== $status && in_array( $status, array( 'pending', 'done', 'failed', 'skipped' ), true ) ) {
				$where .= $this->wpdb->prepare( ' AND status = %s', $status );
		}
			$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; status prepared above.
			$items = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
				ARRAY_A
			);
			return array(
				'items' => is_array( $items ) ? $items : array(),
				'total' => $total,
			);
	}

		/** @return array<string,int> */
	public function status_counts(): array {
			$rows = $this->wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; no external input.
			$out  = array(
				'pending' => 0,
				'done'    => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					if ( isset( $row['status'], $row['total'] ) ) {
							$out[ (string) $row['status'] ] = (int) $row['total'];
					}
				}
			}
			return $out;
	}

		/**
		 * Error text is displayed in the admin log; keep it short and free of internals (S11).
		 */
	public static function clean_error( string $error ): string {
			$error = trim( preg_replace( '/\s+/u', ' ', $error ) ?? '' );
		if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $error, 0, 300, 'UTF-8' );
		}
			return substr( $error, 0, 300 );
	}
}
