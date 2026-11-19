<?php
/**
 * @package LClub
 */

namespace LClub\Modules\Ledger;

use LClub\Core\Money;

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
		$this->ledger_table   = $wpdb->prefix . 'lclub_ledger';
		$this->balances_table = $wpdb->prefix . 'lclub_balances';
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	public function record( array $entry ): array {
		$idempotency_lock = $this->acquire_idempotency_lock( (string) $entry['idempotency_key'] );
		$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction control is required for atomic balance updates.
		try {
			$existing = $this->find_by_key( (string) $entry['idempotency_key'] );
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
				$existing = $this->find_by_key( (string) $entry['idempotency_key'] );
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
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- paired transaction rollback.
			throw $error;
		} finally {
			$this->release_idempotency_lock( $idempotency_lock );
		}
	}

	/** @return array<string,mixed> */
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
		$total    = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->ledger_table} WHERE user_id = %d AND asset = %s", $user_id, $asset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
		$items    = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE user_id = %d AND asset = %s ORDER BY id DESC LIMIT %d OFFSET %d", $user_id, $asset, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; values prepared.
		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/** @return array<string,mixed>|null */
	private function find_by_key( string $key ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE idempotency_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; value prepared.
		return is_array( $row ) ? $row : null;
	}

	private function acquire_idempotency_lock( string $key ): string {
		$lock_name = 'lclub:' . sha1( $key );
		$acquired  = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::IDEMPOTENCY_LOCK_TIMEOUT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- advisory lock name and timeout are prepared.
		if ( '1' !== (string) $acquired ) {
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
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- balance row is prepared above.
	}

	/** @return array<string,mixed> */
	private function lock_balance( int $user_id, string $asset ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT available, held FROM {$this->balances_table} WHERE user_id = %d AND asset = %s FOR UPDATE", $user_id, $asset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-controlled table; lock values prepared.
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'Unable to lock ledger balance.' ); }
		return $row;
	}

	private function update_balance( int $user_id, string $asset, float $available, float $held ): void {
		$now = current_time( 'mysql', true );
		$sql = $this->wpdb->prepare( "UPDATE {$this->balances_table} SET available = %f, held = %f, updated_at = %s WHERE user_id = %d AND asset = %s", $available, $held, $now, $user_id, $asset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is plugin-controlled.
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- balance update prepared above.
	}

	/** @param array<string,mixed> $entry */
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
			throw new InsufficientBalanceException( 'Insufficient available balance.' ); } }
	private function require_held( float $held, float $amount ): void {
		if ( $amount <= 0 || $held + 0.00000001 < $amount ) {
			throw new InsufficientBalanceException( 'Insufficient held balance.' ); } }
	private function commit(): void {
		$this->wpdb->query( 'COMMIT' ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- paired transaction commit.
}
