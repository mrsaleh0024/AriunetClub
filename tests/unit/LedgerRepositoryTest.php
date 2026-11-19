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

	public function __construct( bool $can_acquire_lock ) {
		$this->can_acquire_lock = $can_acquire_lock;
	}

	/** @return string */
	public function prepare( $query, ...$args ) {
		return (string) $query;
	}

	/** @return int */
	public function query( $query ) {
		$this->queries[] = (string) $query;
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
		return array( 'id' => 99, 'idempotency_key' => 'shared-key' );
	}
}
