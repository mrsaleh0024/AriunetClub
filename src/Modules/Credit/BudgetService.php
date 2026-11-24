<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Credit;

use AriunetClub\Core\Logger;
use AriunetClub\Core\Money;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Events\EventBus;

/**
 * Monthly credit budget (phase 6): the shop's cost ceiling.
 *
 * Cap = the SMALLER of "percent of this month's completed sales" and "fixed
 * amount" (whichever is configured). Issued credit counts rule cashback and
 * campaign grants plus wallet refund returns, minus compensating reversals;
 * manual admin adjustments are exempt from the cap but stay in the club
 * liability. At 80% of the cap a budget.warning event plus an admin notice
 * fires; at 100% rule issuance stops (budget.blocked) while spending keeps
 * running. A grant that would cross the cap is clamped so the cost never
 * exceeds the ceiling.
 */
class BudgetService {

        const WARNING_RATIO   = 0.8;
        const SALES_CACHE_TTL = 900; // 15 minutes.

        /** @var \wpdb */
        private $wpdb;

        /** @var Settings */
        private $settings;

        /** @var EventBus|null */
        private $bus;

        /** @var Logger */
        private $logger;

        /** @var string */
        private $ledger_table;

        /** @var float|null Per-request cache of the month's issued total. */
        private $issued_cache;

        public function __construct( \wpdb $wpdb, Settings $settings, ?EventBus $bus, Logger $logger ) {
                $this->wpdb        = $wpdb;
                $this->settings    = $settings;
                $this->bus         = $bus;
                $this->logger      = $logger;
                $this->ledger_table = $wpdb->prefix . 'ariunetclub_ledger';
        }

        /**
         * Full budget state for the current month (site timezone boundary).
         *
         * @return array{enabled:bool,cap:float,used:float,remaining:float,ratio:float,sales:float,percent_cap:float,fixed_cap:float,month:string}
         */
        public function state(): array {
                $config  = $this->settings->get();
                $percent = isset( $config['budget_percent'] ) && is_numeric( $config['budget_percent'] ) ? (float) $config['budget_percent'] : 0.0;
                $fixed   = isset( $config['budget_fixed'] ) && is_numeric( $config['budget_fixed'] ) ? (float) $config['budget_fixed'] : 0.0;
                $percent = min( 100.0, max( 0.0, $percent ) );
                $fixed   = max( 0.0, $fixed );

                $month  = $this->month_window();
                $issued = $this->issued_in_window( $month['start_gmt'], $month['end_gmt'], null );

                $sales        = 0.0;
                $percent_cap  = 0.0;
                if ( $percent > 0.0 ) {
                        $sales       = $this->completed_sales_in_window( $month['start_gmt'] );
                        $percent_cap = Money::round( $sales * $percent / 100.0 );
                }

                $candidates = array();
                if ( $percent_cap > 0.0 ) {
                        $candidates[] = $percent_cap;
                }
                if ( $fixed > 0.0 ) {
                        $candidates[] = $fixed;
                }
                $cap = empty( $candidates ) ? 0.0 : min( $candidates );

                return array(
                        'enabled'    => $cap > 0.0,
                        'cap'        => $cap,
                        'used'       => $issued,
                        'remaining'  => max( 0.0, $cap - $issued ),
                        'ratio'      => $cap > 0.0 ? min( 1.0, $issued / $cap ) : 0.0,
                        'sales'      => $sales,
                        'percent_cap' => $percent_cap,
                        'fixed_cap'  => $fixed,
                        'month'      => $month['key'],
                );
        }

        /**
         * Clamps one candidate grant against the remaining budget. Returns the
         * allowed amount (0.0 when the budget is exhausted) and records the
         * budget.blocked event once per month on the first full block.
         *
         * @return float Allowed amount (already rounded; 0 means blocked).
         */
        public function clamp_grant( float $amount ): float {
                $amount = Money::round( $amount );
                if ( $amount <= 0.0 ) {
                        return 0.0;
                }
                $state = $this->state();
                if ( ! $state['enabled'] ) {
                        return $amount;
                }
                $this->issued_cache = null; // the next state() call must re-read the ledger.
                if ( $state['remaining'] <= LotRepository::EPSILON ) {
                        $this->record_blocked( $state );
                        return 0.0;
                }
                if ( $amount > $state['remaining'] ) {
                        $this->logger->info(
                                'Credit budget clamped a grant.',
                                array(
                                        'requested' => $amount,
                                        'allowed'   => $state['remaining'],
                                        'month'     => $state['month'],
                                )
                        );
                        return Money::round( $state['remaining'] );
                }
                return $amount;
        }

        /**
         * Emits budget.warning (once per month) once usage crosses 80%.
         * Called after every successful rule grant.
         *
         * @return void
         */
        public function maybe_warn(): void {
                $state = $this->state();
                if ( ! $state['enabled'] || $state['ratio'] < self::WARNING_RATIO ) {
                        return;
                }
                if ( null === $this->bus ) {
                        return;
                }
                $this->bus->record(
                        'budget.warning',
                        null,
                        'budget',
                        0,
                        'budget:warning:' . $state['month'],
                        array(
                                'month'  => $state['month'],
                                'used'   => $state['used'],
                                'cap'    => $state['cap'],
                                'ratio'  => round( $state['ratio'], 4 ),
                        )
                );
        }

        /**
         * Issued credit inside a UTC window: rule cashback + campaign grants +
         * wallet refund returns − compensating reversals. Manual admin
         * adjustments and wallet debt captures are excluded by key shape.
         *
         * @param int|null $user_id Optional per-customer filter.
         * @return float
         */
        public function issued_in_window( string $start_gmt, string $end_gmt, ?int $user_id = null ): float {
                if ( null !== $user_id ) {
                        return $this->issued_query( $start_gmt, $end_gmt, $user_id );
                }
                if ( null === $this->issued_cache ) {
                        $this->issued_cache = $this->issued_query( $start_gmt, $end_gmt, null );
                }
                return $this->issued_cache;
        }

        /**
         * @return float
         */
        private function issued_query( string $start_gmt, string $end_gmt, ?int $user_id ): float {
                $user_clause = null !== $user_id ? ' AND user_id = ' . (int) $user_id : '';
                $value       = (string) $this->wpdb->get_var(
                        "SELECT COALESCE(SUM(CASE WHEN entry_type IN ('credit', 'grant') AND amount > 0 THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN entry_type = 'adjust' AND amount < 0 AND (idempotency_key LIKE 'order:%:reversal' OR idempotency_key LIKE 'order:%:refund:%:reversal') THEN -amount ELSE 0 END), 0) + COALESCE(SUM(CASE WHEN entry_type = 'adjust' AND amount > 0 AND idempotency_key LIKE 'wallet:refund:order:%' THEN amount ELSE 0 END), 0) FROM {$this->ledger_table} WHERE created_at >= '" . esc_sql( $start_gmt ) . "' AND created_at < '" . esc_sql( $end_gmt ) . "'" . $user_clause // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- plugin-controlled table; the two datetime bounds and the integer user filter are escaped above.
                );
                return Money::round( max( 0.0, (float) $value ) );
        }

        /**
         * Sum of completed order totals with completion on/after the month
         * start. HPOS reads the orders table directly; legacy posts storage
         * joins _order_total with the _date_completed meta. Cached 15 minutes.
         *
         * @return float
         */
        public function completed_sales_in_window( string $start_gmt ): float {
                $month  = $this->month_window();
                $cached = get_transient( 'ariunetclub_budget_sales_' . $month['key'] );
                if ( is_numeric( $cached ) ) {
                        return (float) $cached;
                }
                $total = $this->completed_sales_query( $start_gmt );
                set_transient( 'ariunetclub_budget_sales_' . $month['key'], $total, self::SALES_CACHE_TTL );
                return $total;
        }

        /**
         * @return float
         */
        private function completed_sales_query( string $start_gmt ): float {
                if ( ! function_exists( 'wc_get_orders' ) ) {
                        return 0.0;
                }
                $start_ts = (int) ( new \DateTimeImmutable( $start_gmt, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

                if ( $this->hpos_enabled() ) {
                        $table  = $this->wpdb->prefix . 'wc_orders';
                        $exists = (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- engine detection on a WooCommerce-owned table.
                        if ( '' !== $exists ) {
                                $value = (string) $this->wpdb->get_var(
                                        $this->wpdb->prepare( "SELECT COALESCE(SUM(total_amount), 0) FROM {$table} WHERE status = 'wc-completed' AND date_completed_gmt >= %s", $start_gmt ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- WooCommerce-owned table; value prepared.
                                );
                                return Money::round( max( 0.0, (float) $value ) );
                        }
                }

                $posts     = $this->wpdb->posts;
                $postmeta  = $this->wpdb->postmeta;
                $value     = (string) $this->wpdb->get_var(
                        $this->wpdb->prepare( "SELECT COALESCE(SUM(pm_total.meta_value), 0) FROM {$posts} p INNER JOIN {$postmeta} pm_total ON (pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total') INNER JOIN {$postmeta} pm_done ON (pm_done.post_id = p.ID AND pm_done.meta_key = '_date_completed') WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-completed' AND pm_done.meta_value >= %s", (string) $start_ts ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- WooCommerce-owned tables; value prepared.
                );
                return Money::round( max( 0.0, (float) $value ) );
        }

        /**
         * @return bool True when HPOS is the authoritative order store.
         */
        private function hpos_enabled(): bool {
                if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage' ) ) {
                        return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage();
                }
                return 'yes' === (string) get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
        }

        /**
         * Current month window in the SITE timezone, expressed in UTC bounds.
         *
         * @return array{key:string,start_gmt:string,end_gmt:string}
         */
        public function month_window(): array {
                $now_local = new \DateTimeImmutable( 'now', self::timezone() );
                $start     = $now_local->modify( 'first day of this month' )->setTime( 0, 0, 0 );
                $end       = $start->modify( '+1 month' );
                $utc       = new \DateTimeZone( 'UTC' );
                return array(
                        'key'       => $start->format( 'Y-m' ),
                        'start_gmt' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
                        'end_gmt'   => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
                );
        }

        /**
         * @return \DateTimeZone
         */
        private static function timezone(): \DateTimeZone {
                try {
                        return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
                } catch ( \Throwable $error ) {
                        return new \DateTimeZone( 'UTC' );
                }
        }

        /**
         * @param array<string,mixed> $state Budget state.
         * @return void
         */
        private function record_blocked( array $state ): void {
                $this->logger->info(
                        'Credit budget exhausted; rule grants are blocked for the rest of the month.',
                        array(
                                'month' => $state['month'],
                                'used'  => $state['used'],
                                'cap'   => $state['cap'],
                        )
                );
                if ( null === $this->bus ) {
                        return;
                }
                $this->bus->record(
                        'budget.blocked',
                        null,
                        'budget',
                        0,
                        'budget:blocked:' . $state['month'],
                        array(
                                'month' => $state['month'],
                                'used'  => $state['used'],
                                'cap'   => $state['cap'],
                        )
                );
        }
}
