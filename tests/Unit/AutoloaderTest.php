<?php
/**
 * Autoloader tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Wallee\ExpressCheckout\Autoloader
 */
class AutoloaderTest extends TestCase {

	public function test_loads_class_from_src() {
		require_once dirname( __DIR__, 2 ) . '/plugin/wallee-express-checkout/src/Autoloader.php';

		$fixture = 'Wallee\ExpressCheckout\Sample\Thing';
		$this->assertFalse( class_exists( $fixture, false ) );

		\Wallee\ExpressCheckout\Autoloader::register( dirname( __DIR__ ) . '/fixtures/autoload' );

		$this->assertTrue( class_exists( $fixture ) );
		$this->assertFalse( class_exists( 'Wallee\ExpressCheckout\DoesNotExist' ) );
		$this->assertFalse( class_exists( 'Other\Vendor\Foo' ) );
	}
}
