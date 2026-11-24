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
		$pluginDir = dirname( __DIR__, 2 ) . '/plugin/wallee-express-checkout';
		require_once $pluginDir . '/src/Autoloader.php';

		\Wallee\ExpressCheckout\Autoloader::register( $pluginDir . '/src' );

		$this->assertFalse( class_exists( 'Wallee\ExpressCheckout\DoesNotExist' ) );
		$this->assertTrue( class_exists( 'Wallee\ExpressCheckout\Autoloader' ) );
		$this->assertFalse( class_exists( 'Other\Vendor\Foo' ) );
	}
}
