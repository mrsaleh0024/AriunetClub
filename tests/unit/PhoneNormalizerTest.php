<?php

namespace AriunetClub\Tests;

use AriunetClub\Modules\Identity\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase {
	/** @dataProvider phones */
	public function test_normalizes_equivalent_iranian_mobile_numbers( string $input ): void {
		$this->assertSame( '9121234567', ( new PhoneNormalizer() )->normalize( $input ) );
	}

	/** @return array<string,array{string}> */
	public function phones(): array {
		return array( 'local' => array( '09121234567' ), 'country_code' => array( '+989121234567' ), 'double_zero' => array( '00989121234567' ), 'persian_digits' => array( '۰۹۱۲۱۲۳۴۵۶۷' ) );
	}

	public function test_rejects_non_iranian_mobile_number(): void {
		$this->assertNull( ( new PhoneNormalizer() )->normalize( '02112345678' ) );
	}
}
