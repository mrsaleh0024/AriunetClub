<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Logger;

/**
 * Daily three-way reconciliation (phase 6, I1-I4).
 *
 * For every user: live-lot sums vs. the balances cache vs. the true ledger
 * sum, plus lot-level I2/I4 checks and stale-expiry (I3) detection. Batches
 * are chained through Action Scheduler; deviations are logged and raise an
 * admin notice — never auto-corrected (a human must look at money drift).
 *
 * Invariant detail: implicit debt := lot_remaining − (available + held) must
 * stay >= 0, and whenever available >= 0 the debt must be exactly zero, i.e.
 * lot_remaining == available + held. Legacy pre-upgrade balances may carry
 * debt together with held amounts; that state is allowed while available < 0.
 */
class ReconciliationJob {

        const ACTION        = 'ariunetclub_credit_reconcile';
        const OPTION        = 'ariunetclub_reconcile_status';
        const BATCH         = 1000;
        const EXPIRY_GRACE  = '-26 hours';
        const EPSILON       = 0.0000001;

        /** @var LotRepository */
        private $lots;

        /** @var Logger */
        private $logger;

        public function __construct( LotRepository $lots, Logger $logger ) {
                $this->lots   = $lots;
                $this->logger = $logger;
        }

        public function register(): void {
                add_action( self::ACTION, array( $this, 'run' ) );
                $this->schedule_daily();
        }

        /**
         * One batch tick; chains itself until every user has been checked.
         *
         * @return void
         */
        public function run(): void {
                $status = $this->status();
                if ( ! isset( $status['running'] ) || ! $status['running'] ) {
                        $status = $this->fresh_status();
                }
                $result   = $this->check_batch( (int) $status['cursor'] );
                $status['cursor']      = $result['cursor'];
                $status['checked']     = (int) $status['checked'] + $result['checked'];
                $status['drift_count'] = (int) $status['drift_count'] + $result['drift_count'];
                $status['samples']     = array_slice( array_merge( (array) $status['samples'], $result['samples'] ), 0, 20 );
                $status['updated_at']  = time();
                $status['finished_at'] = $result['finished'] ? time() : null;
                $status['running']     = ! $result['finished'];
                update_option( self::OPTION, $status, false );

                if ( $result['drift_count'] > 0 ) {
                        $this->logger->error(
                                'Credit reconciliation found balance drift.',
                                array(
                                        'drift_users' => $result['drift_count'],
                                        'samples'     => $result['samples'],
                                )
                        );
                }
                if ( ! $result['finished'] ) {
                        $this->schedule_follow_up();
                }
        }

        /**
         * @param int $after_user_id Cursor (last checked user id).
         * @return array{cursor:int,checked:int,drift_count:int,samples:array<int,array<string,mixed>>,finished:bool}
         */
        public function check_batch( int $after_user_id ): array {
                $rows          = $this->lots->reconciliation_batch( $after_user_id, self::BATCH );
                $drift         = array();
                $drift_samples = array();
                $checked       = 0;
                $cursor        = $after_user_id;
                $finished      = true;

                foreach ( $rows as $row ) {
                        ++$checked;
                        $user_id       = (int) $row['user_id'];
                        $cursor        = $user_id;
                        $available     = (float) $row['available'];
                        $held          = (float) $row['held'];
                        $lot_remaining = (float) $row['lot_remaining'];
                        $lot_held      = (float) $row['lot_held'];
                        $ledger_sum    = (float) $row['ledger_sum'];
                        $problem       = '';

                        // Leg 1: the balances cache must equal the ledger sum.
                        if ( abs( ( $available + $held ) - $ledger_sum ) > self::EPSILON ) {
                                $problem = 'ledger_mismatch';
                        } elseif ( $lot_held > $held + self::EPSILON ) {
                                $problem = 'lot_held_exceeds_balance';
                        } elseif ( $available >= -1 * self::EPSILON ) {
                                // Debt must be zero while available is non-negative.
                                if ( abs( $lot_remaining - ( $available + $held ) ) > self::EPSILON ) {
                                        $problem = 'lot_balance_mismatch';
                                }
                        } elseif ( $lot_remaining < $held - self::EPSILON ) {
                                // Debt state: lots must at least cover the held amount.
                                $problem = 'lot_below_held';
                        }

                        if ( '' !== $problem ) {
                                $drift[]         = $user_id;
                                $drift_samples[] = array(
                                        'user_id' => $user_id,
                                        'problem' => $problem,
                                );
                        }
                }

                if ( count( $rows ) >= self::BATCH ) {
                        $finished = false; // more users may follow the cursor.
                }

                $samples = $drift_samples;
                if ( $finished ) {
                        // Lot-level I2/I4 and stale I3 states, once per completed scan.
                        $grace_gmt = gmdate( 'Y-m-d H:i:s', strtotime( self::EXPIRY_GRACE ) );
                        foreach ( $this->lots->invariant_violations( $grace_gmt ) as $lot ) {
                                $drift[]  = (int) $lot['user_id'];
                                $samples[] = array(
                                        'user_id' => (int) $lot['user_id'],
                                        'lot_id'  => (int) $lot['id'],
                                        'problem' => 'lot_invariant',
                                );
                        }
                }

                return array(
                        'cursor'      => $cursor,
                        'checked'     => $checked,
                        'drift_count' => count( array_unique( $drift ) ),
                        'samples'     => $samples,
                        'finished'    => $finished,
                );
        }

        /**
         * Manual "check now" trigger from the admin page: restarts the scan.
         *
         * @return void
         */
        public function restart(): void {
                update_option( self::OPTION, $this->fresh_status(), false );
                $this->schedule_follow_up();
        }

        /**
         * @return array<string,mixed> Persisted status row.
         */
        public function status(): array {
                $status = get_option( self::OPTION, array() );
                return is_array( $status ) ? $status : array();
        }

        /**
         * True when the last completed pass found drift (drives the admin notice).
         *
         * @return bool
         */
        public function has_drift(): bool {
                $status = $this->status();
                return isset( $status['drift_count'] ) && (int) $status['drift_count'] > 0 && empty( $status['running'] );
        }

        /**
         * @return array<string,mixed>
         */
        private function fresh_status(): array {
                return array(
                        'running'     => true,
                        'cursor'      => 0,
                        'checked'     => 0,
                        'drift_count' => 0,
                        'samples'     => array(),
                        'started_at'  => time(),
                        'updated_at'  => time(),
                        'finished_at' => null,
                );
        }

        /**
         * @return void
         */
        private function schedule_daily(): void {
                if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
                        if ( false === as_next_scheduled_action( self::ACTION, array(), 'ariunetclub' ) ) {
                                as_schedule_recurring_action( time() + 2 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::ACTION, array(), 'ariunetclub', true );
                        }
                        return;
                }
                if ( ! wp_next_scheduled( self::ACTION ) ) {
                        wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::ACTION );
                }
        }

        /**
         * @return void
         */
        private function schedule_follow_up(): void {
                if ( function_exists( 'as_schedule_single_action' ) ) {
                        as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::ACTION, array(), 'ariunetclub' );
                        return;
                }
                if ( ! wp_next_scheduled( self::ACTION ) ) {
                        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ACTION );
                }
        }
}
