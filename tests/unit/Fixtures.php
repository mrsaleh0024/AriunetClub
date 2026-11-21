<?php
/**
 * Shared in-memory fixtures for AriunetClub unit tests. Loaded by tests/bootstrap.php.
 *
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Modules\Events\EventGateway;
use AriunetClub\Modules\Events\SkipEventException;
use AriunetClub\Modules\Ledger\LedgerGateway;
use AriunetClub\Modules\Rules\ContextFactoryInterface;
use AriunetClub\Modules\Rules\OrderHistoryInterface;
use AriunetClub\Modules\Rules\OrderLookupInterface;
use AriunetClub\Modules\Rules\OrderSnapshot;
use AriunetClub\Modules\Rules\RuleContext;
use AriunetClub\Modules\Rules\RuleGateway;

/**
 * Event queue fake with the same idempotency semantics as the SQL table.
 */
class InMemoryEventGateway implements EventGateway {
	/** @var array<int,array<string,mixed>> */
	public $rows = array();

	/** @var int */
	private $next_id = 1;

	/** @return array<string,mixed>|null */
	public function find_by_key( string $key ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $row['idempotency_key'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $event @return array<string,mixed> */
	public function insert( array $event ): array {
		$known = $this->find_by_key( (string) $event['idempotency_key'] );
		if ( null !== $known ) {
			return $known;
		}
		$event['id']            = $this->next_id++;
		$event['attempts']      = 0;
		$event['error']         = null;
		$event['processed_at']  = null;
		$event['created_at']    = isset( $event['created_at'] ) ? $event['created_at'] : gmdate( 'Y-m-d H:i:s' );
		$this->rows[]           = $event;
		return $event;
	}

	/** @return array<int,array<string,mixed>> */
	public function pending_batch( int $limit ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( 'pending' === $row['status'] ) {
				$out[] = $row;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	public function claim( int $id ): void {
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->rows[ $index ]['attempts'] = (int) $row['attempts'] + 1;
				$this->rows[ $index ]['error']    = null;
			}
		}
	}

	public function mark_done( int $id ): void {
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->rows[ $index ]['status']       = 'done';
				$this->rows[ $index ]['error']        = null;
				$this->rows[ $index ]['processed_at'] = gmdate( 'Y-m-d H:i:s' );
			}
		}
	}

	public function mark_skipped( int $id, string $reason ): void {
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->rows[ $index ]['status']       = 'skipped';
				$this->rows[ $index ]['error']        = $reason;
				$this->rows[ $index ]['processed_at'] = gmdate( 'Y-m-d H:i:s' );
			}
		}
	}

	public function mark_error( int $id, string $error, int $max_attempts ): void {
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->rows[ $index ]['error']  = $error;
				$this->rows[ $index ]['status'] = (int) $row['attempts'] >= $max_attempts ? 'failed' : 'pending';
			}
		}
	}

	public function requeue( int $id ): void {
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->rows[ $index ]['status']       = 'pending';
				$this->rows[ $index ]['attempts']     = 0;
				$this->rows[ $index ]['error']        = null;
				$this->rows[ $index ]['processed_at'] = null;
			}
		}
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function list( string $status, int $page, int $per_page ): array {
		$items = array();
		foreach ( $this->rows as $row ) {
			if ( '' === $status || $status === $row['status'] ) {
				$items[] = $row;
			}
		}
		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}

	/** @return array<string,int> */
	public function status_counts(): array {
		$counts = array( 'pending' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( $this->rows as $row ) {
			$counts[ (string) $row['status'] ] = ( $counts[ (string) $row['status'] ] ?? 0 ) + 1;
		}
		return $counts;
	}
}

/**
 * Ledger fake with reference and rule lookups for phase 2 logic.
 */
class InMemoryRulesLedger implements LedgerGateway {
	/** @var array<int,array<string,mixed>> */
	public $entries = array();

	/** @var array<string,array<string,float>> */
	private $balances = array();

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	public function record( array $entry ): array {
		foreach ( $this->entries as $stored ) {
			if ( $stored['idempotency_key'] === $entry['idempotency_key'] ) {
				return $stored;
			}
		}
		$key     = $entry['user_id'] . ':' . $entry['asset'];
		$balance = isset( $this->balances[ $key ] ) ? $this->balances[ $key ] : array( 'available' => 0.0, 'held' => 0.0 );
		$amount  = (float) $entry['amount'];
		switch ( $entry['entry_type'] ) {
			case 'credit':
			case 'adjust':
				$balance['available'] += $amount;
				$entry['amount']       = $amount;
				break;
			case 'debit':
				$balance['available'] -= $amount;
				$entry['amount']       = -$amount;
				break;
			case 'hold':
				$balance['available'] -= $amount;
				$balance['held']      += $amount;
				$entry['amount']       = 0.0;
				break;
			case 'capture':
				$balance['held'] -= $amount;
				$entry['amount'] = -$amount;
				break;
			case 'release':
				$balance['held']      -= $amount;
				$balance['available'] += $amount;
				$entry['amount']       = 0.0;
				break;
		}
		$entry['id']             = count( $this->entries ) + 1;
		$entry['balance_after']  = $balance['available'] + $balance['held'];
		$entry['created_at']     = isset( $entry['created_at'] ) ? $entry['created_at'] : gmdate( 'Y-m-d H:i:s' );
		$entry['ref_type']       = $entry['ref_type'] ?? null;
		$entry['ref_id']         = $entry['ref_id'] ?? null;
		$entry['rule_id']        = $entry['rule_id'] ?? null;
		$this->balances[ $key ]  = $balance;
		$this->entries[]         = $entry;
		return $entry;
	}

	/** @return array<string,mixed> */
	public function balance( int $user_id, string $asset = 'cashback' ): array {
		$key = $user_id . ':' . $asset;
		return isset( $this->balances[ $key ] ) ? $this->balances[ $key ] : array( 'available' => 0.0, 'held' => 0.0 );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function balances( array $user_ids = array(), int $page = 1, int $per_page = 20 ): array {
		return array( 'items' => array(), 'total' => 0 );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function history( int $user_id, string $asset = 'cashback', int $page = 1, int $per_page = 20 ): array {
		return array( 'items' => array(), 'total' => 0 );
	}

	/** @return array<int,array<string,mixed>> */
	public function entries_for_reference( string $ref_type, int $ref_id, array $entry_types = array() ): array {
		$out = array();
		foreach ( $this->entries as $entry ) {
			if ( (string) ( $entry['ref_type'] ?? '' ) !== $ref_type || (int) ( $entry['ref_id'] ?? 0 ) !== $ref_id ) {
				continue;
			}
			if ( ! empty( $entry_types ) && ! in_array( (string) $entry['entry_type'], $entry_types, true ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	public function count_for_rule( int $rule_id, ?int $user_id = null, ?string $since_gmt = null ): int {
		$count = 0;
		foreach ( $this->entries as $entry ) {
			if ( 'credit' !== $entry['entry_type'] || (int) ( $entry['rule_id'] ?? 0 ) !== $rule_id ) {
				continue;
			}
			if ( null !== $user_id && (int) $entry['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $since_gmt && strtotime( (string) $entry['created_at'] . ' UTC' ) < strtotime( $since_gmt . ' UTC' ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	public function sum_for_rule( int $rule_id ): float {
		$sum = 0.0;
		foreach ( $this->entries as $entry ) {
			if ( 'credit' !== $entry['entry_type'] || (int) ( $entry['rule_id'] ?? 0 ) !== $rule_id ) {
				continue;
			}
			$sum += max( 0.0, (float) $entry['amount'] );
		}
		return $sum;
	}
}

/**
 * Rule storage fake seeded per event type.
 */
class FakeRuleGateway implements RuleGateway {
	/** @var array<string,array<int,array<string,mixed>>> */
	public $rules = array();

	/** @param array<string,mixed> $rule @return self */
	public function seed( array $rule ): self {
		$this->rules[ (string) $rule['event_type'] ][] = $rule;
		return $this;
	}

	/** @return array<int,array<string,mixed>> */
	public function active_for_event( string $event_type ): array {
		return isset( $this->rules[ $event_type ] ) ? $this->rules[ $event_type ] : array();
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		foreach ( $this->rules as $rules ) {
			foreach ( $rules as $rule ) {
				if ( (int) $rule['id'] === $id ) {
					return $rule;
				}
			}
		}
		return null;
	}

	/** @param array<string,mixed> $data @return int */
	public function insert( array $data ): int {
		$data['id'] = 1000 + count( $this->rules, COUNT_RECURSIVE );
		$this->rules[ (string) $data['event_type'] ][] = $data;
		return (int) $data['id'];
	}

	/** @param int $id @param array<string,mixed> $data @return bool */
	public function update( int $id, array $data ): bool {
		return false;
	}

	public function delete( int $id ): bool {
		return false;
	}

	/** @param int $id @param string[] $statuses @return bool */
	public function set_status( int $id, array $statuses ): bool {
		return false;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public function list( int $page, int $per_page ): array {
		return array( 'items' => array(), 'total' => 0 );
	}
}

/**
 * Context factory fake returning prebuilt contexts by event key.
 */
class FakeContextFactory implements ContextFactoryInterface {
	/** @var array<string,RuleContext> */
	public $contexts = array();

	/** @param array<string,mixed> $event @return RuleContext|null @throws SkipEventException */
	public function from_event( array $event ): ?RuleContext {
		$key = (string) ( $event['idempotency_key'] ?? '' );
		if ( isset( $this->contexts[ $key ] ) ) {
			return $this->contexts[ $key ];
		}
		return null;
	}
}

/**
 * Order history fake.
 */
class FakeOrderHistory implements OrderHistoryInterface {
	/** @var bool */
	public $paid_before = false;

	public function has_paid_order_before( int $user_id, string $before_gmt ): bool {
		return $this->paid_before;
	}
}

/**
 * Order lookup fake with prebuilt snapshots.
 */
class FakeOrderLookup implements OrderLookupInterface {
	/** @var array<int,OrderSnapshot> */
	public $snapshots = array();

	/** @return OrderSnapshot|null */
	public function find( int $order_id ): ?OrderSnapshot {
		return isset( $this->snapshots[ $order_id ] ) ? $this->snapshots[ $order_id ] : null;
	}
}
