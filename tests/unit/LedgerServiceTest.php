<?php

namespace LClub\Tests;

use LClub\Modules\Ledger\LedgerGateway;
use LClub\Modules\Ledger\LedgerService;
use PHPUnit\Framework\TestCase;

class LedgerServiceTest extends TestCase {
	public function test_exposes_all_phase_one_entry_types(): void {
		$gateway = new InMemoryLedgerGateway();
		$ledger = new LedgerService( $gateway );
		$ledger->credit( 1, 100, 'credit' ); $ledger->debit( 1, 10, 'debit' ); $ledger->hold( 1, 20, 'hold' );
		$ledger->capture( 1, 5, 'capture' ); $ledger->release( 1, 15, 'release' ); $ledger->adjust( 1, -2, 'adjust' );
		$this->assertSame( array( 'credit', 'debit', 'hold', 'capture', 'release', 'adjust' ), array_column( $gateway->entries, 'entry_type' ) );
	}

	public function test_idempotency_key_is_effective_at_the_gateway_boundary(): void {
		$gateway = new InMemoryLedgerGateway(); $ledger = new LedgerService( $gateway );
		$first = $ledger->credit( 1, 10, 'same-key' ); $second = $ledger->credit( 1, 10, 'same-key' );
		$this->assertSame( $first['id'], $second['id'] ); $this->assertCount( 1, $gateway->entries );
	}
}

class InMemoryLedgerGateway implements LedgerGateway {
	/** @var array<int,array<string,mixed>> */
	public $entries = array();
	/** @var array<string,array<string,mixed>> */
	private $balances = array();
	/** @param array<string,mixed> $entry @return array<string,mixed> */
	public function record( array $entry ): array {
		foreach ( $this->entries as $stored ) { if ( $stored['idempotency_key'] === $entry['idempotency_key'] ) { return $stored; } }
		$key = $entry['user_id'] . ':' . $entry['asset']; $balance = isset( $this->balances[ $key ] ) ? $this->balances[ $key ] : array( 'available' => 0.0, 'held' => 0.0 ); $amount = (float) $entry['amount'];
		switch ( $entry['entry_type'] ) { case 'credit': case 'adjust': $balance['available'] += $amount; $entry['amount'] = $amount; break; case 'debit': $balance['available'] -= $amount; $entry['amount'] = -$amount; break; case 'hold': $balance['available'] -= $amount; $balance['held'] += $amount; $entry['amount'] = 0.0; break; case 'capture': $balance['held'] -= $amount; $entry['amount'] = -$amount; break; case 'release': $balance['held'] -= $amount; $balance['available'] += $amount; $entry['amount'] = 0.0; break; }
		$entry['id'] = count( $this->entries ) + 1; $entry['balance_after'] = $balance['available'] + $balance['held']; $this->balances[ $key ] = $balance; $this->entries[] = $entry; return $entry;
	}
	/** @return array<string,mixed> */ public function balance( int $user_id, string $asset = 'cashback' ): array { $key = $user_id . ':' . $asset; return isset( $this->balances[ $key ] ) ? $this->balances[ $key ] : array( 'available' => 0.0, 'held' => 0.0 ); }
	/** @return array{items:array<int,array<string,mixed>>,total:int} */ public function balances( array $user_ids = array(), int $page = 1, int $per_page = 20 ): array { return array( 'items' => array(), 'total' => 0 ); }
	/** @return array{items:array<int,array<string,mixed>>,total:int} */ public function history( int $user_id, string $asset = 'cashback', int $page = 1, int $per_page = 20 ): array { return array( 'items' => array(), 'total' => 0 ); }
}
