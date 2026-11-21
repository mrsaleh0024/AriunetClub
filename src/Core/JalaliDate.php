<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Core;

class JalaliDate {
	/**
	 * @return int[]
	 */
	public static function from_gregorian( int $gy, int $gm, int $gd ): array {
		$g_days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$j_days_in_month = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );
		$gy             -= 1600;
		--$gm;
		--$gd;
		$g_day_no = 365 * $gy + intdiv( $gy + 3, 4 ) - intdiv( $gy + 99, 100 ) + intdiv( $gy + 399, 400 );
		for ( $i = 0; $i < $gm; ++$i ) {
			$g_day_no += $g_days_in_month[ $i ]; }
		if ( $gm > 1 && ( ( 0 === ( $gy + 1600 ) % 4 && 0 !== ( $gy + 1600 ) % 100 ) || 0 === ( $gy + 1600 ) % 400 ) ) {
			++$g_day_no; }
		$g_day_no += $gd;
		$j_day_no  = $g_day_no - 79;
		$j_np      = intdiv( $j_day_no, 12053 );
		$j_day_no %= 12053;
		$jy        = 979 + 33 * $j_np + 4 * intdiv( $j_day_no, 1461 );
		$j_day_no %= 1461;
		if ( $j_day_no >= 366 ) {
			$jy += intdiv( $j_day_no - 1, 365 );
			--$j_day_no;
			$j_day_no %= 365; }
		for ( $i = 0; $i < 11 && $j_day_no >= $j_days_in_month[ $i ]; ++$i ) {
			$j_day_no -= $j_days_in_month[ $i ]; }
		return array( $jy, $i + 1, $j_day_no + 1 );
	}

	public static function format( string $datetime, string $format = 'Y/m/d' ): string {
		$date                       = new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) );
		list( $year, $month, $day ) = self::from_gregorian( (int) $date->format( 'Y' ), (int) $date->format( 'n' ), (int) $date->format( 'j' ) );
		return str_replace( array( 'Y', 'm', 'd' ), array( (string) $year, str_pad( (string) $month, 2, '0', STR_PAD_LEFT ), str_pad( (string) $day, 2, '0', STR_PAD_LEFT ) ), $format );
	}
}
