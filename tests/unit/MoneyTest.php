<?php
namespace LClub\Tests;
use LClub\Core\Money;
use PHPUnit\Framework\TestCase;
class MoneyTest extends TestCase {
	public function test_formats_persian_digits(): void { $this->assertSame( '۱۲,۳۴۶', Money::format( 12345.6 ) ); }
	public function test_rounds_to_store_precision(): void { $this->assertSame( 12.0, Money::round( 11.5 ) ); }
}
