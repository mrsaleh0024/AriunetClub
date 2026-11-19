<?php

namespace LClub\Tests;

use LClub\Modules\Ledger\LedgerRepository;
use PHPUnit\Framework\TestCase;

class LedgerRepositoryTest extends TestCase {
	public function test_rejects_a_write_when_the_idempotency_lock_cannot_be_acquired(): void {
		$wpdb       = new LedgerRepositoryWpdb( false );
		$repository = new LedgerRepository( $wpdb );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to acquire ledger idempotency lock.' );

		try {
			$repository->record( $this->entry() );
		} finally {
			$this->assertSame( array(), $wpdb->queries );
		}
	}

	public function test_releases_the_idempotency_lock_after_returning_an_existing_entry(): void {
		$wpdb       = new LedgerRepositoryWpdb( true );
		$repository = new LedgerRepository( $wpdb );

		$this->assertSame( 99, $repository->record( $this->entry() )['id'] );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $wpdb->queries );
		$this->assertSame( 1, $wpdb->released_locks );
	}

	public function test_rolls_back_without_inserting_an_entry_when_the_balance_update_fails(): void {
		$wpdb       = new LedgerRepositoryWpdb( true, array( null, array( 'available' => '0', 'held' => '0' ) ), true );
		$repository = new LedgerRepository( $wpdb );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to update ledger balance.' );

		try {
			$repository->record( $this->entry() );
		} finally {
			$this->assertContains( 'ROLLBACK', $wpdb->queries );
			$this->assertSame( 0, $wpdb->insert_calls );
			$this->assertSame( 1, $wpdb->released_locks );
		}
	}

	/** @return array<string,mixed> */
	private function entry(): array {
		return array(
			'user_id'         => 1,
			'asset'           => 'cashback',
			'entry_type'      => 'credit',
			'amount'          => 10.0,
			'idempotency_key' => 'shared-key',
		);
	}
}

class LedgerRepositoryWpdb extends \wpdb {
	/** @var string */
	public $prefix = 'wp_';
	/** @var array<int,string> */
	public $queries = array();
	/** @var int */
	public $released_locks = 0;
	/** @var bool */
	private $can_acquire_lock;
	/** @var array<int,array<string,mixed>|null> */
	private $rows;
	/** @var bool */
	private $fail_update;
	/** @var int */
	public $insert_calls = 0;

	/** @param array<int,array<string,mixed>|null> $rows */
	public function __construct( bool $can_acquire_lock, array $rows = array( array( 'id' => 99, 'idempotency_key' => 'shared-key' ) ), bool $fail_update = false ) {
		$this->can_acquire_lock = $can_acquire_lock;
		$this->rows             = $rows;
		$this->fail_update      = $fail_update;
	}

	/** @return string */
	public function prepare( $query, ...$args ) {
		return (string) $query;
	}

	/** @return int */
	public function query( $query ) {
		$this->queries[] = (string) $query;
		if ( $this->fail_update && 0 === strpos( (string) $query, 'UPDATE ' ) ) {
			return false;
		}
		return 1;
	}

	/** @return string|null */
	public function get_var( $query ) {
		if ( false !== strpos( (string) $query, 'GET_LOCK' ) ) {
			return $this->can_acquire_lock ? '1' : '0';
		}
		if ( false !== strpos( (string) $query, 'RELEASE_LOCK' ) ) {
			++$this->released_locks;
			return '1';
		}

		return null;
	}

	/** @return array<string,mixed>|null */
	public function get_row( $query, $output = null ) {
		return array_shift( $this->rows );
	}

	/** @return int */
	public function insert( $table, $data, $format ) {
		++$this->insert_calls;
		return 1;
	}
}
