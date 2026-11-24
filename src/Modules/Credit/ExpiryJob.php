<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Logger;
use AriunetClub\Core\Money;
use AriunetClub\Modules\Events\EventBus;
use AriunetClub\Modules\Ledger\LedgerService;

/**
 * Daily credit-expiry job (phase 6).
 *
 * Two responsibilities, both idempotent and batched:
 *  1. credit.expiring events exactly 7, 3 and 1 local days before a lot's
 *     expiry (the message itself is phase 7; here we only record the event);
 *  2. expiring the unheld part of every due lot through a dedicated `expire`
 *     ledger entry (key lot:{id}:expire:unheld) so the money trail stays
 *     complete. Held parts never expire until their hold is released.
 */
class ExpiryJob {

        const ACTION       = 'ariunetclub_credit_expiry';
        const BATCH        = 200;
        const REMINDER_DAYS = array( 7, 3, 1 );

        /** @var LotRepository */
        private $lots;

        /** @var LedgerService */
        private $ledger;

        /** @var EventBus|null */
        private $bus;

        /** @var Logger */
        private $logger;

        public function __construct( LotRepository $lots, LedgerService $ledger, ?EventBus $bus, Logger $logger ) {
                $this->lots   = $lots;
                $this->ledger = $ledger;
                $this->bus    = $bus;
                $this->logger = $logger;
        }

        public function register(): void {
                add_action( self::ACTION, array( $this, 'run' ) );
                $this->schedule_daily();
        }

        /**
         * One job tick: reminders first, then one expiry batch. When the batch
         * is full, more due lots may remain — a follow-up tick is scheduled
         * after five minutes so a huge backlog drains without a long request.
         *
         * @return void
         */
        public function run(): void {
                $this->emit_reminders();
                $processed = $this->expire_due_batch();
                if ( $processed >= self::BATCH ) {
                        $this->schedule_follow_up();
                }
        }

        /**
         * @return int Number of lots whose unheld part was expired.
         */
        public function expire_due_batch(): int {
                $now_gmt = current_time( 'mysql', true );
                $due     = $this->lots->due_lots( $now_gmt, self::BATCH );
                $done    = 0;
                foreach ( $due as $lot ) {
                        $lot_id  = (int) $lot['id'];
                        $user_id = (int) $lot['user_id'];
                        $amount  = Money::round( (float) $lot['remaining'] - (float) $lot['held'] );
                        if ( $amount <= LotRepository::EPSILON ) {
                                continue;
                        }
                        try {
                                $this->ledger->expire(
                                        $user_id,
                                        $amount,
                                        'lot:' . $lot_id . ':expire:unheld',
                                        array(
                                                'ref_type' => 'lot',
                                                'ref_id'   => $lot_id,
                                                'note'     => __( 'انقضای اعتبار باشگاه', 'ariunetclub' ),
                                                '_lot_id'  => $lot_id,
                                        )
                                );
                                ++$done;
                        } catch ( \Throwable $error ) {
                                // Retry happens on the next daily tick (idempotent key); the
                                // reconciliation job flags a lot stuck past the grace window.
                                $this->logger->error(
                                        'Credit lot expiry failed.',
                                        array(
                                                'lot_id' => $lot_id,
                                                'error'  => $error->getMessage(),
                                        )
                                );
                        }
                }
                if ( $done > 0 ) {
                        $this->logger->info( 'Credit lot expiry batch finished.', array( 'expired_lots' => $done ) );
                }
                return $done;
        }

        /**
         * Records credit.expiring events for lots that expire exactly 7, 3 or
         * 1 local days from now (whole local days, Jalali/customer-facing).
         *
         * @return void
         */
        public function emit_reminders(): void {
                if ( null === $this->bus ) {
                        return;
                }
                try {
                        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
                } catch ( \Throwable $error ) {
                        $timezone = new \DateTimeZone( 'UTC' );
                }
                $now_local = new \DateTimeImmutable( 'now', $timezone );
                $utc       = new \DateTimeZone( 'UTC' );
                foreach ( self::REMINDER_DAYS as $days ) {
                        $day_start = $now_local->modify( '+' . $days . ' days' )->setTime( 0, 0, 0 );
                        $day_end   = $day_start->modify( '+1 day' );
                        $start_gmt = $day_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
                        $end_gmt   = $day_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
                        foreach ( $this->lots->lots_expiring_within( $start_gmt, $end_gmt, 500 ) as $lot ) {
                                $this->bus->record(
                                        'credit.expiring',
                                        (int) $lot['user_id'],
                                        'lot',
                                        (int) $lot['id'],
                                        'credit:expiring:lot:' . (int) $lot['id'] . ':' . $days . 'd',
                                        array(
                                                'lot_id'     => (int) $lot['id'],
                                                'days_left'  => $days,
                                                'expires_at' => (string) $lot['expires_at'],
                                                'amount'     => (float) $lot['remaining'],
                                        )
                                );
                        }
                }
        }

        /**
         * @return void
         */
        private function schedule_daily(): void {
                if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
                        if ( false === as_next_scheduled_action( self::ACTION, array(), 'ariunetclub' ) ) {
                                as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::ACTION, array(), 'ariunetclub', true );
                        }
                        return;
                }
                if ( ! wp_next_scheduled( self::ACTION ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::ACTION );
                }
        }

        /**
         * @return void
         */
        private function schedule_follow_up(): void {
                if ( function_exists( 'as_schedule_single_action' ) ) {
                        as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::ACTION, array(), 'ariunetclub' );
                        return;
                }
                if ( ! wp_next_scheduled( self::ACTION ) ) {
                        wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::ACTION );
                }
        }
}
