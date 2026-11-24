<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Logger;
use AriunetClub\Core\Money;

/**
 * Batched, resumable migration of pre-phase-6 balances onto credit lots
 * (phase 6): every user's current balance becomes one "legacy" lot without
 * expiry, so the club's history keeps working after the upgrade.
 *
 * The job is idempotent: a user is only topped up to the invariant
 *   lot_remaining == max(available, 0) + held
 * so re-running it (or racing it with live earning) is always safe. Each user
 * is processed inside a short transaction that re-locks the balance row, which
 * serializes against concurrent ledger writes.
 */
class BalanceMigration {

		const ACTION  = 'ariunetclub_credit_migrate_balances';
		const OPTION  = 'ariunetclub_balance_migration';
		const BATCH   = 500;
		const EPSILON = 0.0000001;

		/** @var LotRepository */
		private $lots;

		/** @var \wpdb */
		private $wpdb;

		/** @var Logger */
		private $logger;

	public function __construct( LotRepository $lots, \wpdb $wpdb, Logger $logger ) {
			$this->lots   = $lots;
			$this->wpdb   = $wpdb;
			$this->logger = $logger;
	}

	public function register(): void {
			add_action( self::ACTION, array( $this, 'run' ) );
		if ( $this->needs_run() ) {
				$this->kick_off();
		}
	}

		/**
		 * @return bool True when the stored report is missing (first phase 6 boot).
		 */
	public function needs_run(): bool {
			return false === get_option( self::OPTION, false );
	}

		/**
		 * Initializes the status row and schedules the first batch.
		 *
		 * @return void
		 */
	public function kick_off(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
				return;
		}
			update_option(
				self::OPTION,
				array(
					'status'      => 'running',
					'cursor'      => 0,
					'processed'   => 0,
					'created'     => 0,
					'topped_up'   => 0,
					'skipped'     => 0,
					'errors'      => array(),
					'started_at'  => time(),
					'updated_at'  => time(),
					'finished_at' => null,
				),
				false
			);
			$this->schedule_next();
	}

		/**
		 * One batch tick; chains itself until the balances table is exhausted.
		 *
		 * @return void
		 */
	public function run(): void {
			$status = $this->status();
		if ( 'done' === LotRepository::s( $status['status'] ?? '' ) ) {
				return;
		}
			$result = $this->migrate_batch( LotRepository::i( $status['cursor'] ?? 0 ) );

			$status['cursor']     = $result['cursor'];
			$status['processed']  = LotRepository::i( $status['processed'] ) + $result['processed'];
			$status['created']    = LotRepository::i( $status['created'] ) + $result['created'];
			$status['topped_up']  = LotRepository::i( $status['topped_up'] ) + $result['topped_up'];
			$status['skipped']    = LotRepository::i( $status['skipped'] ) + $result['skipped'];
			$status['errors']     = array_slice( array_merge( (array) $status['errors'], $result['errors'] ), 0, 20 );
			$status['updated_at'] = time();
		if ( $result['finished'] ) {
				$status['status']      = 'done';
				$status['finished_at'] = time();
				$this->logger->info(
					'Balance-to-lot migration finished.',
					array(
						'processed' => $status['processed'],
						'created'   => $status['created'],
						'topped_up' => $status['topped_up'],
						'skipped'   => $status['skipped'],
					)
				);
		}
			update_option( self::OPTION, $status, false );

		if ( ! $result['finished'] ) {
				$this->schedule_next();
		}
	}

		/**
		 * @param int $after_user_id Cursor.
		 * @return array{cursor:int,processed:int,created:int,topped_up:int,skipped:int,errors:array<int,string>,finished:bool}
		 */
	public function migrate_batch( int $after_user_id ): array {
			$rows     = $this->lots->migration_batch( $after_user_id, self::BATCH );
			$cursor   = $after_user_id;
			$created  = 0;
			$topped   = 0;
			$skipped  = 0;
			$errors   = array();
			$finished = true;

		foreach ( $rows as $row ) {
				$user_id = LotRepository::i( $row['user_id'] );
				$cursor  = $user_id;
			try {
				$outcome = $this->migrate_user( $user_id );
				if ( 'created' === $outcome ) {
						++$created;
				} elseif ( 'topped_up' === $outcome ) {
						++$topped;
				} else {
						++$skipped;
				}
			} catch ( \Throwable $error ) {
					$errors[] = 'user ' . $user_id . ': ' . $error->getMessage();
					$this->logger->error(
						'Balance-to-lot migration failed for one user.',
						array(
							'user_id' => $user_id,
							'error'   => $error->getMessage(),
						)
					);
			}
		}

		if ( count( $rows ) >= self::BATCH ) {
				$finished = false;
		}

			return array(
				'cursor'    => $cursor,
				'processed' => count( $rows ),
				'created'   => $created,
				'topped_up' => $topped,
				'skipped'   => $skipped,
				'errors'    => $errors,
				'finished'  => $finished,
			);
	}

		/**
		 * Migrates one user inside a short, balance-locked transaction.
		 *
		 * @return string 'created' | 'topped_up' | 'skipped'.
		 */
	private function migrate_user( int $user_id ): string {
			$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- short per-user migration transaction.
		try {
				$balance = $this->lots->lock_balance( $user_id );
				$sums    = $this->lots->sums_for_user( $user_id );
				$target  = Money::round( max( 0.0, $balance['available'] ) + $balance['held'] );
				$have    = Money::round( $sums['remaining'] );
				$gap     = Money::round( $target - $have );
			if ( $gap <= self::EPSILON ) {
				$this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- paired commit.
				return 'skipped';
			}
				$had_lots = $have > self::EPSILON;
				$this->lots->insert_lot(
					array(
						'user_id'        => $user_id,
						'source_type'    => LotService::SOURCE_LEGACY,
						'source_ref'     => null,
						'ref_type'       => null,
						'ref_id'         => null,
						'rule_id'        => null,
						'initial_amount' => $gap,
						'remaining'      => $gap,
						'held'           => 0.0,
						'expires_at'     => null,
						'status'         => LotRepository::STATUS_ACTIVE,
						'reason'         => __( 'ماندهٔ اعتبار پیش از ارتقا', 'ariunetclub' ),
					)
				);
				$this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- paired commit.
				return $had_lots ? 'topped_up' : 'created';
		} catch ( \Throwable $error ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- best-effort rollback.
				throw $error;
		}
	}

		/**
		 * Manual restart from the admin page (idempotent; only missing gaps fill).
		 *
		 * @return void
		 */
	public function restart(): void {
			delete_option( self::OPTION );
			$this->kick_off();
	}

		/**
		 * @return array<string,mixed> Persisted report row.
		 */
	public function status(): array {
			$status = get_option( self::OPTION, array() );
			if ( ! is_array( $status ) ) {
					return array();
			}
			$typed = array();
			foreach ( $status as $key => $value ) {
					if ( is_string( $key ) ) {
							$typed[ $key ] = $value;
					}
			}
			return $typed;
	}

		/**
		 * @return void
		 */
	private function schedule_next(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::ACTION, array(), 'ariunetclub' );
				return;
		}
		if ( ! wp_next_scheduled( self::ACTION ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ACTION );
		}
	}
}
