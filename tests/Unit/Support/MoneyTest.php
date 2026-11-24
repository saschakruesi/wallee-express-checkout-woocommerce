<?php
/**
 * Money tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Support\Money;

/**
 * @covers \Wallee\ExpressCheckout\Support\Money
 */
class MoneyTest extends TestCase {

	public function test_money_equals_at_currency_precision() {
		$this->assertTrue( Money::equals( 32.5, '32.50', 2 ) );
		$this->assertTrue( Money::equals( 0.1 + 0.2, 0.3, 2 ) );
		$this->assertFalse( Money::equals( 32.50, 32.51, 2 ) );
		$this->assertTrue( Money::equals( 1000.4, 1000, 0 ) );
	}

	public function test_round() {
		$this->assertSame( 1.01, Money::round( '1.005001', 2 ) );
		$this->assertSame( 1000.0, Money::round( 1000.4, 0 ) );
	}

	public function test_decimals_per_currency() {
		foreach ( array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK' ) as $c ) {
			$this->assertSame( 0, Money::decimals( $c ), $c );
		}
		foreach ( array( 'BHD', 'KWD', 'OMR', 'JOD', 'TND' ) as $c ) {
			$this->assertSame( 3, Money::decimals( $c ), $c );
		}
		foreach ( array( 'CHF', 'EUR', 'USD' ) as $c ) {
			$this->assertSame( 2, Money::decimals( $c ), $c );
		}
	}
}
