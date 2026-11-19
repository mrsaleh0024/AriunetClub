<?php
namespace LClub\Tests;
use LClub\Core\JalaliDate;
use PHPUnit\Framework\TestCase;
class JalaliDateTest extends TestCase {
	public function test_converts_nowruz(): void { $this->assertSame( array( 1403, 1, 1 ), JalaliDate::from_gregorian( 2024, 3, 20 ) ); }
	public function test_formats_utc_date(): void { $this->assertSame( '1403/01/01', JalaliDate::format( '2024-03-20 08:30:00' ) ); }
}
