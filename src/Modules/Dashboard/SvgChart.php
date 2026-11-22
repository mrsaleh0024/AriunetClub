<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Dashboard;

/**
 * Server-rendered, dependency-free SVG charts (no JS libraries, no external
 * requests). Colors come from CSS classes bound to the admin design tokens;
 * every value is a computed number formatted with Persian digits.
 */
final class SvgChart {

	/**
	 * Grouped bar chart (two series per bucket), laid out right-to-left.
	 *
	 * @param array<int,array{ts:int,earned:float,spent:float}> $series Filled daily series.
	 * @param array{title:string,earned_label:string,spent_label:string} $labels
	 * @return string SVG markup (safe: only generated numbers inside).
	 */
	public static function daily_bars( array $series, array $labels ): string {
		$series = array_slice( array_values( $series ), -60 );
		$count  = count( $series );
		if ( 0 === $count ) {
			return '';
		}

		$width      = 720;
		$height     = 280;
		$pad_top    = 18;
		$pad_bottom = 34;
		$pad_x      = 8;
		$plot_h     = $height - $pad_top - $pad_bottom;
		$plot_w     = $width - 2 * $pad_x;

		$max_value = 0.0;
		foreach ( $series as $point ) {
			$max_value = max( $max_value, (float) $point['earned'], (float) $point['spent'] );
		}
		$axis_max = DashboardStats::nice_max( $max_value );

		$slot  = $plot_w / $count;
		$bar_w = max( 2.0, min( 9.0, $slot / 3.2 ) );
		$gap   = max( 1.0, $bar_w * 0.35 );

		$svg   = array();
		$svg[] = '<svg class="ariunetclub-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . esc_attr( (string) $labels['title'] ) . '" preserveAspectRatio="xMidYMid meet" focusable="false">';
		$svg[] = '<title>' . esc_html( (string) $labels['title'] ) . '</title>';

		// Horizontal gridlines + axis labels (4 steps).
		for ( $step = 0; $step <= 4; ++$step ) {
			$ratio = $step / 4;
			$y     = $pad_top + $plot_h * ( 1 - $ratio );
			$value = $axis_max * $ratio;
			$svg[] = '<line class="ariunetclub-chart__grid" x1="' . self::num( $pad_x ) . '" x2="' . self::num( $width - $pad_x ) . '" y1="' . self::num( $y ) . '" y2="' . self::num( $y ) . '"></line>';
			$svg[] = '<text class="ariunetclub-chart__axis" x="' . self::num( $width - $pad_x + 2 ) . '" y="' . self::num( $y + 4 ) . '" text-anchor="start">' . esc_html( DashboardStats::compact_label( $value ) ) . '</text>';
		}

		// Bars: first bucket on the right (RTL), earned then spent.
		$label_every = max( 1, (int) ceil( $count / 6 ) );
		foreach ( $series as $index => $point ) {
			$slot_center = $width - $pad_x - $slot * ( $index + 0.5 );
			$earned_h    = $axis_max > 0 ? ( (float) $point['earned'] / $axis_max ) * $plot_h : 0.0;
			$spent_h     = $axis_max > 0 ? ( (float) $point['spent'] / $axis_max ) * $plot_h : 0.0;

			$x_earned = $slot_center - $gap / 2 - $bar_w;
			$x_spent  = $slot_center + $gap / 2;
			$base     = $pad_top + $plot_h;

			$svg[] = '<rect class="ariunetclub-chart__bar ariunetclub-chart__bar--earned" x="' . self::num( $x_earned ) . '" y="' . self::num( $base - $earned_h ) . '" width="' . self::num( $bar_w ) . '" height="' . self::num( max( 0.0, $earned_h ) ) . '" rx="1.5"><title>' . esc_html( self::bar_title( $point, $labels, true ) ) . '</title></rect>';
			$svg[] = '<rect class="ariunetclub-chart__bar ariunetclub-chart__bar--spent" x="' . self::num( $x_spent ) . '" y="' . self::num( $base - $spent_h ) . '" width="' . self::num( $bar_w ) . '" height="' . self::num( max( 0.0, $spent_h ) ) . '" rx="1.5"><title>' . esc_html( self::bar_title( $point, $labels, false ) ) . '</title></rect>';

			if ( 0 === $index % $label_every ) {
				$day_label = \AriunetClub\Core\JalaliDate::format( gmdate( 'Y-m-d H:i:s', (int) $point['ts'] ), 'm/d' );
				$svg[]     = '<text class="ariunetclub-chart__axis" x="' . self::num( $slot_center ) . '" y="' . self::num( $height - 10 ) . '" text-anchor="middle">' . esc_html( \AriunetClub\Core\Money::to_persian_digits( $day_label ) ) . '</text>';
			}
		}

		$svg[] = '</svg>';
		return implode( '', $svg );
	}

	/**
	 * @param array{ts:int,earned:float,spent:float} $point
	 * @param array{title:string,earned_label:string,spent_label:string} $labels
	 * @param bool $earned
	 * @return string
	 */
	private static function bar_title( array $point, array $labels, bool $earned ): string {
		$value = $earned ? (float) $point['earned'] : (float) $point['spent'];
		$day   = \AriunetClub\Core\JalaliDate::format( gmdate( 'Y-m-d H:i:s', (int) $point['ts'] ), 'Y/m/d' );
		/* translators: 1: metric label, 2: jalali date, 3: amount. */
		return sprintf( '%1$s %2$s: %3$s', $earned ? (string) $labels['earned_label'] : (string) $labels['spent_label'], $day, \AriunetClub\Core\Money::format( $value ) );
	}

	/**
	 * @param float $value
	 * @return string
	 */
	private static function num( float $value ): string {
		return number_format( $value, 2, '.', '' );
	}
}
