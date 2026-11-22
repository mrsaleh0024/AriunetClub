<?php
/**
 * Dashboard pure-logic unit tests: local-day windows with timezone offsets,
 * zero-filling, nice axis maximums and compact Persian labels.
 *
 * @package AriunetClub
 */

namespace AriunetClub\Tests;

use AriunetClub\Modules\Dashboard\DashboardStats;
use AriunetClub\Modules\Dashboard\SvgChart;
use PHPUnit\Framework\TestCase;

class DashboardStatsTest extends TestCase {

        public function test_window_uses_local_days_and_utc_strings(): void {
                $tz      = new \DateTimeZone( 'Asia/Tehran' ); // UTC+03:30, no DST since 2022.
                $now     = 1795372800; // 2026-11-22 18:40 UTC = 22:10 local.
                $window  = DashboardStats::window( 3, $tz, $now );

                $this->assertSame( 210, $window['offset_minutes'] );
                $this->assertSame( '2026-11-19 20:30:00', $window['start_gmt'], 'Three local days ago at local midnight.' );
                $this->assertSame( '2026-11-22 20:30:00', $window['end_gmt'], 'Tomorrow at local midnight.' );
                $this->assertCount( 3, $window['buckets'] );

                // Bucket keys are UTC midnights of the LOCAL dates 20, 21, 22.
                $this->assertSame( '2026-11-20 00:00:00', gmdate( 'Y-m-d H:i:s', $window['buckets'][0] ) );
                $this->assertSame( '2026-11-22 00:00:00', gmdate( 'Y-m-d H:i:s', $window['buckets'][2] ) );
        }

        public function test_window_handles_negative_offsets(): void {
                $tz     = new \DateTimeZone( 'America/New_York' );
                $now    = 1795372800; // 2026-11-22 08:00 UTC = 03:00 EST (-05:00).
                $window = DashboardStats::window( 1, $tz, $now );

                $this->assertSame( -300, $window['offset_minutes'] );
                $this->assertSame( '2026-11-22 05:00:00', $window['start_gmt'] );
                $this->assertSame( '2026-11-23 05:00:00', $window['end_gmt'] );
        }

        public function test_fill_series_fills_missing_days_with_zero(): void {
                $buckets = array( 100, 200, 300, 400 );
                $rows    = array(
                        array( 'bucket' => 200, 'earned' => '10.5', 'spent' => '4' ),
                        array( 'bucket' => 400, 'earned' => 7, 'spent' => 0 ),
                );
                $series = DashboardStats::fill_series( $buckets, $rows );

                $this->assertCount( 4, $series );
                $this->assertSame( array( 'ts' => 100, 'earned' => 0.0, 'spent' => 0.0 ), $series[0] );
                $this->assertSame( 10.5, $series[1]['earned'] );
                $this->assertSame( 4.0, $series[1]['spent'] );
                $this->assertSame( 0.0, $series[2]['earned'] );
                $this->assertSame( 7.0, $series[3]['earned'] );
        }

        public function test_fill_series_ignores_rows_outside_buckets(): void {
                $series = DashboardStats::fill_series( array( 100 ), array( array( 'bucket' => 999, 'earned' => 5, 'spent' => 5 ) ) );
                $this->assertSame( 0.0, $series[0]['earned'] );
        }

        /**
         * @dataProvider nice_max_provider
         */
        public function test_nice_max( float $input, float $expected ): void {
                $this->assertSame( $expected, DashboardStats::nice_max( $input ) );
        }

        /**
         * @return array<int,array{0:float,1:float}>
         */
        public function nice_max_provider(): array {
                return array(
                        'zero'         => array( 0.0, 100.0 ),
                        'small'        => array( 1.0, 1.0 ),
                        'just above 2' => array( 2.4, 5.0 ),
                        'just above 5' => array( 5.1, 10.0 ),
                        'thousands'    => array( 48000.0, 50000.0 ),
                        'millions'     => array( 2100000.0, 5000000.0 ),
                );
        }

        public function test_compact_labels_are_persian(): void {
                $this->assertSame( '۹۹', DashboardStats::compact_label( 99.4 ) );
                $this->assertSame( '۲ هزار', DashboardStats::compact_label( 2000 ) );
                $this->assertSame( '۱۲ هزار', DashboardStats::compact_label( 12345 ) );
                $this->assertSame( '۳٫۵ میلیون', DashboardStats::compact_label( 3500000 ) );
                $this->assertSame( '۱٫۲ میلیارد', DashboardStats::compact_label( 1200000000 ) );
        }

        public function test_chart_renders_bars_accessibly_with_persian_digits(): void {
                $series = array(
                        array( 'ts' => 1795372800, 'earned' => 100.0, 'spent' => 40.0 ),
                        array( 'ts' => 1795459200, 'earned' => 0.0, 'spent' => 0.0 ),
                );
                $svg = SvgChart::daily_bars(
                        $series,
                        array( 'title' => 'نمودار آزمایشی', 'earned_label' => 'کسب', 'spent_label' => 'خرج' )
                );

                $this->assertStringContainsString( '<svg class="ariunetclub-chart"', $svg );
                $this->assertStringContainsString( 'role="img"', $svg );
                $this->assertStringContainsString( 'aria-label="نمودار آزمایشی"', $svg );
                $this->assertSame( 4, substr_count( $svg, '<rect class="ariunetclub-chart__bar' ), 'Two bars per day.' );
                $this->assertStringNotContainsString( '<script>', $svg );
        }

        public function test_chart_with_all_zero_data_stays_upright(): void {
                $series = array( array( 'ts' => 1795372800, 'earned' => 0.0, 'spent' => 0.0 ) );
                $svg    = SvgChart::daily_bars( $series, array( 'title' => 't', 'earned_label' => 'e', 'spent_label' => 's' ) );
                $this->assertStringContainsString( 'height="0.00"', $svg, 'Zero bars must not render negative heights.' );
        }
}
