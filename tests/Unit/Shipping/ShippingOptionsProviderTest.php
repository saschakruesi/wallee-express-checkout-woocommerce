<?php
/**
 * ShippingOptionsProvider tests (the parts that do not need a WooCommerce runtime).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Shipping;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Shipping\ShippingOptionsProvider;

/**
 * @covers \Wallee\ExpressCheckout\Shipping\ShippingOptionsProvider
 */
class ShippingOptionsProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$countries = new class() {
			public function get_shipping_countries() {
				return array(
					'CH' => 'Switzerland',
					'DE' => 'Germany',
				);
			}
		};
		$wc        = new \stdClass();
		$wc->countries = $countries;
		Functions\when( 'WC' )->justReturn( $wc );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_unknown_country_is_unavailable() {
		$this->assertSame( 'COUNTRY_UNAVAILABLE', ( new ShippingOptionsProvider() )->errorCodeFor( array( 'country' => 'US' ) ) );
	}

	public function test_shipped_country_without_rates_is_a_method_problem() {
		$this->assertSame( 'SHIPPING_METHOD_UNAVAILABLE', ( new ShippingOptionsProvider() )->errorCodeFor( array( 'country' => 'DE' ) ) );
	}
}
