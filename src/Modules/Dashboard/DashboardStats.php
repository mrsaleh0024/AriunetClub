<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Dashboard;

/**
 * Pure date/bucket math for the dashboard (unit-tested without SQL):
 * local-day boundaries converted to UTC, zero-filling of daily series and
 * compact Persian number formatting for chart labels.
 */
final class DashboardStats {

		/**
		 * Local-day window [start, end) in UTC covering the last $days days
		 * including today (site timezone).
		 *
		 * Bucket keys intentionally use "the local date string interpreted as
		 * UTC midnight" so they match SQL's UNIX_TIMESTAMP(DATE(created_at +
		 * offset)) exactly on both MySQL and the SQLite test driver.
		 *
		 * @param int                $days Number of days (>= 1).
		 * @param \DateTimeZone|null $tz   Site timezone.
		 * @param int|null           $now  Reference UTC timestamp (tests).
		 * @return array{start_gmt:string,end_gmt:string,offset_minutes:int,buckets:non-empty-list<int>}
		 *         buckets: local-day UTC-midnight timestamps, oldest first.
		 */
	public static function window( int $days, ?\DateTimeZone $tz = null, ?int $now = null ): array {
			$days = max( 1, min( 120, $days ) );
			$tz   = $tz ?? new \DateTimeZone( 'UTC' );
			$now  = $now ?? time();

			$today_local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
			$start_local = $today_local->modify( '-' . ( $days - 1 ) . ' days' );
			$end_local   = $today_local->modify( '+1 day' );

			$utc     = new \DateTimeZone( 'UTC' );
			$buckets = array();
			$cursor  = $start_local;
		for ( $i = 0; $i < $days; ++$i ) {
				$buckets[] = ( new \DateTimeImmutable( $cursor->format( 'Y-m-d' ), $utc ) )->getTimestamp();
				$cursor    = $cursor->modify( '+1 day' );
		}

			return array(
				'start_gmt'      => gmdate( 'Y-m-d H:i:s', $start_local->getTimestamp() ),
				'end_gmt'        => gmdate( 'Y-m-d H:i:s', $end_local->getTimestamp() ),
				'offset_minutes' => (int) round( $today_local->getOffset() / 60 ),
				'buckets'        => $buckets,
			);
	}

		/**
		 * Aligns sparse SQL rows onto the full bucket list, filling gaps with
		 * zeros. Rows are keyed by their local-day timestamp.
		 *
		 * @param array<int,int>            $buckets   Bucket timestamps (epoch).
		 * @param array<int,array<string,mixed>> $rows   SQL rows with bucket/earned/spent.
		 * @return array<int,array{ts:int,earned:float,spent:float}>
		 */
	public static function fill_series( array $buckets, array $rows ): array {
			$by_day = array();
		foreach ( $rows as $row ) {
				$ts = is_numeric( $row['bucket'] ?? null ) ? (int) $row['bucket'] : 0;
			if ( $ts <= 0 ) {
				continue;
			}
				$by_day[ $ts ] = array(
					'earned' => isset( $row['earned'] ) && is_numeric( $row['earned'] ) ? (float) $row['earned'] : 0.0,
					'spent'  => isset( $row['spent'] ) && is_numeric( $row['spent'] ) ? (float) $row['spent'] : 0.0,
				);
		}

			$out = array();
		foreach ( $buckets as $ts ) {
				$out[] = array(
					'ts'     => (int) $ts,
					'earned' => isset( $by_day[ $ts ] ) ? $by_day[ $ts ]['earned'] : 0.0,
					'spent'  => isset( $by_day[ $ts ] ) ? $by_day[ $ts ]['spent'] : 0.0,
				);
		}
			return $out;
	}

		/**
		 * Compact Persian label for chart axis values: ۱۲۰ هزار / ۳٫۵ میلیون.
		 */
	public static function compact_label( float $value ): string {
			$value = max( 0.0, $value );
		if ( $value >= 1000000000 ) {
				return self::fa( $value / 1000000000, 1 ) . ' میلیارد';
		}
		if ( $value >= 1000000 ) {
				return self::fa( $value / 1000000, 1 ) . ' میلیون';
		}
		if ( $value >= 1000 ) {
				return self::fa( $value / 1000, 0 ) . ' هزار';
		}
			return self::fa( $value, 0 );
	}

		/**
		 * "Nice" axis maximum: 1/2/5 × 10^k just above the data maximum, so the
		 * chart never lies about scale.
		 */
	public static function nice_max( float $max ): float {
		if ( $max <= 0.0 ) {
				return 100.0;
		}
			$exponent = (int) floor( log10( $max ) );
			$base     = pow( 10.0, $exponent );
			$scaled   = $max / $base;
		if ( $scaled <= 1.0 ) {
				$nice = 1.0;
		} elseif ( $scaled <= 2.0 ) {
				$nice = 2.0;
		} elseif ( $scaled <= 5.0 ) {
				$nice = 5.0;
		} else {
				$nice = 10.0;
		}
			return $nice * $base;
	}

		/**
		 * @param float $value
		 * @param int   $decimals
		 * @return string Persian digits with Persian decimal separator.
		 */
	private static function fa( float $value, int $decimals ): string {
			$formatted = number_format( $value, $decimals, '.', '' );
		if ( false !== strpos( $formatted, '.' ) ) {
				$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		}
			return \AriunetClub\Core\Money::to_persian_digits( str_replace( '.', '٫', $formatted ) );
	}
}
