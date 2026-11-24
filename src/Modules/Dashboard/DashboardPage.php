<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Dashboard;

use AriunetClub\Core\JalaliDate;
use AriunetClub\Core\Money;
use AriunetClub\Modules\Account\ClubEndpoint;

/**
 * Phase 5 admin dashboard: real-time totals, a 30-day earning/spending
 * chart (server-rendered SVG), top earning rules and the latest activity —
 * every number straight from the ledger, nothing cached or estimated.
 */
final class DashboardPage {

		const CHART_DAYS = 30;

		/** @var DashboardStatsRepository */
		private $stats;

	public function __construct( DashboardStatsRepository $stats ) {
			$this->stats = $stats;
	}

	public function register(): void {
			add_action( 'admin_menu', array( $this, 'menu' ), 5 );
	}

	public function menu(): void {
			add_submenu_page(
				'ariunetclub-settings',
				__( 'داشبورد', 'ariunetclub' ),
				__( 'داشبورد', 'ariunetclub' ),
				'manage_woocommerce',
				'ariunetclub-dashboard',
				array( $this, 'render' )
			);
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'ariunetclub' ) );
		}
	}

		/**
		 * Builds the full view model (separated for testability).
		 *
		 * @param int|null $now Reference timestamp (tests).
		 * @return array{totals:array{issued:float,spent:float,held:float,users:int,adjustments:float},today:array{earned:float,spent:float},series:array<int,array{ts:int,earned:float,spent:float}>,top_rules:array<int,array{rule_id:int,title:string,total:float}>,recent:array<int,array{date:string,label:string,family:string,amount_display:string,user:string,note:string}>,chart:string}
		 */
	public function build_view( ?int $now = null ): array {
			$tz        = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$today     = DashboardStats::window( 1, $tz, $now );
			$window    = DashboardStats::window( self::CHART_DAYS, $tz, $now );
			$totals    = $this->stats->totals();
			$today_row = $this->stats->range( $today['start_gmt'], $today['end_gmt'] );
			$series    = DashboardStats::fill_series(
				$window['buckets'],
				$this->stats->daily_rows( $window['buckets'], $window['start_gmt'], $window['end_gmt'], $window['offset_minutes'] )
			);

			return array(
				'totals'    => $totals,
				'today'     => $today_row,
				'series'    => $series,
				'top_rules' => $this->stats->top_rules( 5 ),
				'recent'    => $this->map_recent( $this->stats->recent( 10 ) ),
				'chart'     => SvgChart::daily_bars(
					$series,
					array(
						'title'        => __( 'کسب و خرج اعتبار باشگاه در ۳۰ روز اخیر', 'ariunetclub' ),
						'earned_label' => __( 'کسب', 'ariunetclub' ),
						'spent_label'  => __( 'خرج', 'ariunetclub' ),
					)
				),
			);
	}

	public function render(): void {
			$this->guard();
			$view     = $this->build_view();
			$totals   = $view['totals'];
			$today    = $view['today'];
			$max_rule = 0.0;
		foreach ( $view['top_rules'] as $rule ) {
				$max_rule = max( $max_rule, (float) $rule['total'] );
		}
		?>
				<div class="wrap ariunetclub-scope" dir="rtl">
						<div class="ariunetclub-admin-shell">
								<header class="ariunetclub-admin-header">
										<span class="ariunetclub-admin-kicker"><?php esc_html_e( 'ARIUNETCLUB', 'ariunetclub' ); ?></span>
										<h1><?php esc_html_e( 'داشبورد باشگاه مشتریان', 'ariunetclub' ); ?></h1>
										<p><?php esc_html_e( 'آمار واقعی و لحظه‌ای از دفتر کل باشگاه؛ بدون کش و بدون تخمین.', 'ariunetclub' ); ?></p>
								</header>

								<section class="ariunetclub-dashboard-cards" aria-label="<?php esc_attr_e( 'آمار کلی', 'ariunetclub' ); ?>">
										<div class="ariunetclub-card ariunetclub-card--issued">
												<span class="ariunetclub-card__label"><?php esc_html_e( 'کل کش‌بک صادرشده', 'ariunetclub' ); ?></span>
												<strong class="ariunetclub-card__value"><?php echo esc_html( Money::format( (float) $totals['issued'] ) ); ?></strong>
												<span class="ariunetclub-card__hint"><?php esc_html_e( 'مجموع همهٔ اعتبارهای کسب‌شده مشتریان', 'ariunetclub' ); ?></span>
										</div>
										<div class="ariunetclub-card ariunetclub-card--held">
												<span class="ariunetclub-card__label"><?php esc_html_e( 'رزروشدهٔ فعلی', 'ariunetclub' ); ?></span>
												<strong class="ariunetclub-card__value"><?php echo esc_html( Money::format( (float) $totals['held'] ) ); ?></strong>
												<span class="ariunetclub-card__hint"><?php esc_html_e( 'رزروهای باز چک‌اوت‌های در جریان', 'ariunetclub' ); ?></span>
										</div>
										<div class="ariunetclub-card ariunetclub-card--earned">
												<span class="ariunetclub-card__label"><?php esc_html_e( 'کسب امروز', 'ariunetclub' ); ?></span>
												<strong class="ariunetclub-card__value"><?php echo esc_html( Money::format( (float) $today['earned'] ) ); ?></strong>
												<span class="ariunetclub-card__hint"><?php echo esc_html( sprintf( /* translators: %s: number of wallet holders in Persian digits. */ __( 'در میان %s دارندهٔ اعتبار باشگاه', 'ariunetclub' ), Money::to_persian_digits( (string) $totals['users'] ) ) ); ?></span>
										</div>
										<div class="ariunetclub-card ariunetclub-card--spent">
												<span class="ariunetclub-card__label"><?php esc_html_e( 'خرج امروز', 'ariunetclub' ); ?></span>
												<strong class="ariunetclub-card__value"><?php echo esc_html( Money::format( (float) $today['spent'] ) ); ?></strong>
												<span class="ariunetclub-card__hint"><?php echo esc_html( sprintf( /* translators: %s: total spent amount. */ __( 'کل خرج تا امروز: %s', 'ariunetclub' ), Money::format( (float) $totals['spent'] ) ) ); ?></span>
										</div>
								</section>

								<section class="ariunetclub-panel ariunetclub-dashboard-chart" aria-labelledby="ariunetclub-dashboard-chart-title">
										<div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-dashboard-chart-title"><?php esc_html_e( 'کسب و خرج ۳۰ روز اخیر', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'مقادیر بر اساس روز سایت و از دفتر کل واقعی محاسبه می‌شود.', 'ariunetclub' ); ?></p></div>
										<span class="ariunetclub-chart-legend"><span class="ariunetclub-chart-legend__item"><i class="ariunetclub-chart-legend__swatch ariunetclub-chart-legend__swatch--earned"></i><?php esc_html_e( 'کسب', 'ariunetclub' ); ?></span><span class="ariunetclub-chart-legend__item"><i class="ariunetclub-chart-legend__swatch ariunetclub-chart-legend__swatch--spent"></i><?php esc_html_e( 'خرج', 'ariunetclub' ); ?></span></span></div>
									<?php echo $view['chart']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-generated SVG containing only computed numbers and escaped labels. ?>
								</section>

								<div class="ariunetclub-dashboard-grid">
										<section class="ariunetclub-panel" aria-labelledby="ariunetclub-dashboard-rules-title">
												<div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-dashboard-rules-title"><?php esc_html_e( 'قوانین پرکسب', 'ariunetclub' ); ?></h2><p><?php esc_html_e( 'بیشترین کش‌بک صادرشده به تفکیک قانون.', 'ariunetclub' ); ?></p></div></div>
											<?php if ( empty( $view['top_rules'] ) ) : ?>
														<p class="ariunetclub-dashboard__empty"><?php esc_html_e( 'هنوز کش‌بکی صادر نشده است.', 'ariunetclub' ); ?></p>
												<?php else : ?>
														<ul class="ariunetclub-dashboard-rules">
																<?php foreach ( $view['top_rules'] as $rule ) : ?>
																		<li>
																				<span class="ariunetclub-dashboard-rules__title"><?php echo esc_html( '' !== $rule['title'] ? $rule['title'] : sprintf( /* translators: %s: rule id in Persian digits. */ __( 'قانون شمارهٔ %s', 'ariunetclub' ), Money::to_persian_digits( (string) $rule['rule_id'] ) ) ); ?></span>
																				<span class="ariunetclub-dashboard-rules__bar"><i class="ariunetclub-dashboard-rules__fill" style="inline-size: <?php echo esc_attr( self::percent( (float) $rule['total'], $max_rule ) ); ?>%;"></i></span>
																				<span class="ariunetclub-dashboard-rules__value"><?php echo esc_html( Money::format( (float) $rule['total'] ) ); ?></span>
																		</li>
																<?php endforeach; ?>
														</ul>
												<?php endif; ?>
										</section>

										<section class="ariunetclub-panel" aria-labelledby="ariunetclub-dashboard-activity-title">
												<div class="ariunetclub-section-heading"><div><h2 id="ariunetclub-dashboard-activity-title"><?php esc_html_e( 'آخرین فعالیت‌ها', 'ariunetclub' ); ?></h2><p><?php esc_html_e( '۱۰ ردیف آخر دفتر کل باشگاه.', 'ariunetclub' ); ?></p></div></div>
											<?php if ( empty( $view['recent'] ) ) : ?>
														<p class="ariunetclub-dashboard__empty"><?php esc_html_e( 'هنوز تراکنشی ثبت نشده است.', 'ariunetclub' ); ?></p>
												<?php else : ?>
														<div class="ariunetclub-table-scroll">
																<table class="ariunetclub-table">
																		<thead><tr><th><?php esc_html_e( 'تاریخ', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'نوع', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'مبلغ', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'مشتری', 'ariunetclub' ); ?></th><th><?php esc_html_e( 'توضیح', 'ariunetclub' ); ?></th></tr></thead>
																		<tbody>
																				<?php foreach ( $view['recent'] as $entry ) : ?>
																						<tr>
																								<td><?php echo esc_html( $entry['date'] ); ?></td>
																								<td><span class="ariunetclub-account__badge ariunetclub-account__badge--<?php echo esc_attr( $entry['family'] ); ?>"><?php echo esc_html( $entry['label'] ); ?></span></td>
																								<td class="ariunetclub-balance"><span dir="ltr"><?php echo esc_html( $entry['amount_display'] ); ?></span></td>
																								<td><?php echo esc_html( $entry['user'] ); ?></td>
																								<td><?php echo esc_html( $entry['note'] ); ?></td>
																						</tr>
																				<?php endforeach; ?>
																		</tbody>
																</table>
														</div>
												<?php endif; ?>
										</section>
								</div>
						</div>
				</div>
				<?php
	}

		/**
		 * Maps raw ledger rows onto the display shape with Jalali dates,
		 * Persian digits, labels and resolved customer names.
		 *
		 * @param array<int,array<string,mixed>> $rows Ledger rows.
		 * @return array<int,array{date:string,label:string,family:string,amount_display:string,user:string,note:string}>
		 */
	private function map_recent( array $rows ): array {
			$user_ids = array();
		foreach ( $rows as $row ) {
			$user_ids[] = is_numeric( $row['user_id'] ?? null ) ? (int) $row['user_id'] : 0;
		}
			$names = self::user_names( array_values( array_unique( array_filter( $user_ids ) ) ) );

			$out = array();
		foreach ( $rows as $row ) {
				$type    = isset( $row['entry_type'] ) && is_string( $row['entry_type'] ) ? $row['entry_type'] : '';
				$amount  = isset( $row['amount'] ) && is_numeric( $row['amount'] ) ? (float) $row['amount'] : 0.0;
				$user_id = is_numeric( $row['user_id'] ?? null ) ? (int) $row['user_id'] : 0;

			if ( $amount > 0.0 ) {
					$amount_display = '+' . Money::format( $amount );
			} elseif ( $amount < 0.0 ) {
					$amount_display = '−' . Money::format( abs( $amount ) );
			} else {
					$amount_display = '—';
			}

				$out[] = array(
					'date'           => isset( $row['created_at'] ) && is_string( $row['created_at'] ) ? Money::to_persian_digits( JalaliDate::format( $row['created_at'] ) ) : '',
					'label'          => ClubEndpoint::entry_label( $type ),
					'family'         => ClubEndpoint::entry_family( $type ),
					'amount_display' => $amount_display,
					'user'           => $names[ $user_id ] ?? '—',
					'note'           => isset( $row['note'] ) && is_scalar( $row['note'] ) ? (string) $row['note'] : '',
				);
		}
			return $out;
	}

		/**
		 * Resolves display names with a single users query when possible.
		 *
		 * @param array<int,int> $user_ids
		 * @return array<int,string>
		 */
	private static function user_names( array $user_ids ): array {
			$names = array();
		if ( empty( $user_ids ) ) {
				return $names;
		}
		if ( function_exists( 'get_users' ) ) {
				$users = get_users(
					array(
						'include' => $user_ids,
					)
				);
			foreach ( $users as $user ) {
				if ( $user instanceof \WP_User ) {
							$names[ (int) $user->ID ] = (string) $user->display_name;
				}
			}
				return $names;
		}
			return $names;
	}

		/**
		 * @param float $value
		 * @param float $max
		 * @return string Percentage with one decimal, 0..100.
		 */
	private static function percent( float $value, float $max ): string {
		if ( $max <= 0.0 ) {
				return '0';
		}
			return number_format( min( 100.0, max( 0.0, $value / $max * 100.0 ) ), 1, '.', '' );
	}
}
