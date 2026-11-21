<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Ledger;

use AriunetClub\Core\Money;

class LedgerRepository implements LedgerGateway {
	private const IDEMPOTENCY_LOCK_TIMEOUT = 10;

		/** @var \wpdb */
		private $wpdb;

		/** @var string */
		private $ledger_table;

		/** @var string */
		private $balances_table;

	public function __construct( \wpdb $wpdb ) {
			$this->wpdb           = $wpdb;
			$this->ledger_table   = $wpdb->prefix . 'ariunetclub_ledger';
			$this->balances_table = $wpdb->prefix . 'ariunetclub_balances';
	}

		/** @param array $entry @return array */
	public function record( array $entry ): array {
			$key = $entry['idempotency_key'] ?? null;
		if ( ! is_string( $key ) || '' === $key ) {
				throw new \InvalidArgumentException( 'Ledger entry requires a non-empty idempotency key.' );
		}
			$idempotency_lock    = $this->acquire_idempotency_lock( $key );
			$transaction_started = false;
		try {
				$this->start_transaction();
				$transaction_started = true;
				$existing            = $this->find_by_key( $key );
			if ( $existing ) {
				$this->commit();
				return $existing;
			}
				$this->ensure_balance_row( (int) $entry['user_id'], (string) $entry['asset'] );
				$balance   = $this->lock_balance( (int) $entry['user_id'], (string) $entry['asset'] );
				$available = (float) $balance['available'];
				$held      = (float) $balance['held'];
				$amount    = Money::round( (float) $entry['amount'] );
				$type      = (string) $entry['entry_type'];
				$delta     = 0.0;
			switch ( $type ) {
				case 'credit':
					$available += $amount;
					$delta      = $amount;
					break;
				case 'debit':
						$this->require_available( $available, $amount );
						$available -= $amount;
						$delta      = -$amount;
					break;
				case 'hold':
						$this->require_available( $available, $amount );
						$available -= $amount;
						$held      += $amount;
					break;
				case 'capture':
						$this->require_held( $held, $amount );
						$held -= $amount;
						$delta = -$amount;
					break;
				case 'release':
						$this->require_held( $held, $amount );
						$held      -= $amount;
						$available += $amount;
					break;
				case 'adjust':
						$available += $amount;
						$delta      = $amount;
					break;
				default:
					throw new \InvalidArgumentException( 'Unsupported ledger entry type.' );
			}
				$available = Money::round( $available );
				$held      = Money::round( $held );
				$this->update_balance( (int) $entry['user_id'], (string) $entry['asset'], $available, $held );
				$entry['amount']        = $delta;
				$entry['balance_after'] = Money::round( $available + $held );
				$entry['created_at']    = current_time( 'mysql', true );
			if ( ! $this->insert_entry( $entry ) ) {
					$existing = $this->find_by_key( $key );
				if ( $existing ) {
						$this->commit();
						return $existing;
				}
					throw new \RuntimeException( 'Unable to insert ledger entry.' );
			}
				$entry['id'] = (int) $this->wpdb->insert_id;
				$this->commit();
				return $entry;
		} catch ( \Throwable $error ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
				throw $error;
		} finally {
				$this->release_idempotency_lock( $idempotency_lock );
		}
	}

		/** @return array */
	public function balance( int $user_id, string $asset = 'cashback' ): array {
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT user_id, asset, available, held, updated_at FROM {$this->balances_table} WHERE user_id = %d AND asset = %s", $user_id, $asset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
			return is_array( $row ) ? $row : array(
				'user_id'    => $user_id,
				'asset'      => $asset,
				'available'  => '0',
				'held'       => '0',
				'updated_at' => null,
			);
	}

		/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function balances( array $user_ids = array(), int $page = 1, int $per_page = 20 ): array {
			$page     = max( 1, $page );
			$per_page = max( 1, min( 100, $per_page ) );
			$offset   = ( $page - 1 ) * $per_page;
			$where    = "asset = 'cashback'";
		if ( $user_ids ) {
				$ids    = implode( ',', array_map( 'absint', $user_ids ) );
				$where .= ' AND user_id IN (' . $ids . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- absint creates an integer-only list.
		}
			$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->balances_table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- controlled table and integer-only predicate.
			$items = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT user_id, asset, available, held, updated_at FROM {$this->balances_table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- controlled table and integer-only predicate.
			return array(
				'items' => is_array( $items ) ? $items : array(),
				'total' => $total,
			);
	}

		/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function history( int $user_id, string $asset = 'cashback', int $page = 1, int $per_page = 20 ): array {
			$page     = max( 1, $page );
			$per_page = max( 1, min( 100, $per_page ) );
			$offset   = ( $page - 1 ) * $per_page;
			$total    = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->ledger_table} WHERE user_id = %d AND asset = %s", $user_id, $asset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
			$items    = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE user_id = %d AND asset = %s ORDER BY id DESC LIMIT %d OFFSET %d", $user_id, $asset, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
			return array(
				'items' => is_array( $items ) ? $items : array(),
				'total' => $total,
			);
	}

		/** @return array<int,array<string,mixed>> */
	public function entries_for_reference( string $ref_type, int $ref_id, array $entry_types = array() ): array {
			$types   = array_values( array_intersect( array( 'credit', 'debit', 'hold', 'capture', 'release', 'adjust' ), array_map( 'strval', $entry_types ) ) );
			$clauses = 'ref_type = %s AND ref_id = %d';
			$args    = array( $ref_type, $ref_id );
		if ( ! empty( $types ) ) {
				$clauses .= ' AND entry_type IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')';
				$args     = array_merge( $args, $types );
		}
			$rows = $this->wpdb->get_results(
				call_user_func_array( array( $this->wpdb, 'prepare' ), array( "SELECT * FROM {$this->ledger_table} WHERE {$clauses} ORDER BY id ASC" ) + $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
	}

	public function count_for_rule( int $rule_id, ?int $user_id = null, ?string $since_gmt = null ): int {
			$clauses = "rule_id = %d AND entry_type = 'credit'";
			$args    = array( $rule_id );
		if ( null !== $user_id && $user_id > 0 ) {
				$clauses .= ' AND user_id = %d';
				$args[]   = $user_id;
		}
		if ( null !== $since_gmt && '' !== $since_gmt ) {
				$clauses .= ' AND created_at >= %s';
				$args[]   = $since_gmt;
		}
			array_unshift( $args, "SELECT COUNT(*) FROM {$this->ledger_table} WHERE {$clauses}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-controlled table; values prepared below.
			$value = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );
			return (int) $this->wpdb->get_var( $value ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- query built and prepared above.
	}

	public function sum_for_rule( int $rule_id ): float {
			$value = (string) $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$this->ledger_table} WHERE rule_id = %d AND entry_type = 'credit' AND amount > 0", $rule_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; id prepared.
			);
			return (float) $value;
	}

		/** @return array|null */
	private function find_by_key( string $key ): ?array {
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE idempotency_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared.
			return is_array( $row ) ? $row : null;
	}

	private function acquire_idempotency_lock( string $key ): string {
			$lock_name = 'ariunetclub:' . sha1( $key );
			$acquired  = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::IDEMPOTENCY_LOCK_TIMEOUT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- advisory lock name and timeout are prepared.
		if ( '1' !== $acquired ) {
				throw new \RuntimeException( 'Unable to acquire ledger idempotency lock.' );
		}

			return $lock_name;
	}

	private function release_idempotency_lock( string $lock_name ): void {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- advisory lock name is prepared.
	}

	private function ensure_balance_row( int $user_id, string $asset ): void {
			$now = current_time( 'mysql', true );
			$sql = $this->wpdb->prepare( "INSERT INTO {$this->balances_table} (user_id, asset, available, held, updated_at) VALUES (%d, %s, 0, 0, %s) ON DUPLICATE KEY UPDATE updated_at = updated_at", $user_id, $asset, $now ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is plugin-controlled.
		if ( false === $this->wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- balance row is prepared above.
				throw new \RuntimeException( 'Unable to ensure ledger balance.' );
		}
	}

		/** @return array */
	private function lock_balance( int $user_id, string $asset ): array {
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT available, held FROM {$this->balances_table} WHERE user_id = %d AND asset = %s FOR UPDATE", $user_id, $asset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; lock values prepared.
		if ( ! is_array( $row ) ) {
				throw new \RuntimeException( 'Unable to lock ledger balance.' );
		}
			return $row;
	}

	private function update_balance( int $user_id, string $asset, float $available, float $held ): void {
			$now = current_time( 'mysql', true );
			$sql = $this->wpdb->prepare( "UPDATE {$this->balances_table} SET available = %f, held = %f, updated_at = %s WHERE user_id = %d AND asset = %s", $available, $held, $now, $user_id, $asset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is plugin-controlled.
		if ( false === $this->wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- balance update prepared above.
				throw new \RuntimeException( 'Unable to update ledger balance.' );
		}
	}

		/** @param array $entry */
	private function insert_entry( array $entry ): bool {
			$data = array(
				'user_id'         => $entry['user_id'],
				'asset'           => $entry['asset'],
				'entry_type'      => $entry['entry_type'],
				'amount'          => $entry['amount'],
				'balance_after'   => $entry['balance_after'],
				'ref_type'        => $entry['ref_type'],
				'ref_id'          => $entry['ref_id'],
				'rule_id'         => $entry['rule_id'],
				'idempotency_key' => $entry['idempotency_key'],
				'note'            => $entry['note'],
				'created_by'      => $entry['created_by'],
				'created_at'      => $entry['created_at'],
			);
			$ok   = $this->wpdb->insert( $this->ledger_table, $data, array( '%d', '%s', '%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only insert owned by repository.
			return false !== $ok;
	}

	private function require_available( float $available, float $amount ): void {
		if ( $amount <= 0 || $available + 0.00000001 < $amount ) {
				throw new InsufficientBalanceException( 'Insufficient available balance.' ); }
	}

	private function require_held( float $held, float $amount ): void {
		if ( $amount <= 0 || $held + 0.00000001 < $amount ) {
				throw new InsufficientBalanceException( 'Insufficient held balance.' ); }
	}

	private function start_transaction(): void {
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction control is required for atomic balance updates.
				throw new \RuntimeException( 'Unable to start ledger transaction.' );
		}
	}

	private function commit(): void {
		if ( false === $this->wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- paired transaction commit.
				throw new \RuntimeException( 'Unable to commit ledger transaction.' );
		}
	}

	private function rollback(): void {
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- best-effort rollback after a failed transaction.
	}
}
