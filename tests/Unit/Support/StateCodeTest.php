<?php
/**
 * StateCode tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Support\StateCode;
use Wallee\ExpressCheckout\Tests\Support\FakeWc;

/**
 * @covers \Wallee\ExpressCheckout\Support\StateCode
 */
class StateCodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$wc                           = new FakeWc();
		$wc->countries->states['AX'] = array( 'AX1' => '&#197;land Main' );
		Functions\when( 'WC' )->justReturn( $wc );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider regions
	 *
	 * @param string      $country  Country.
	 * @param string      $region   Region as sent by wallee.
	 * @param string|null $expected Resolved code.
	 */
	public function test_a_region_resolves_to_the_woocommerce_state_code( string $country, string $region, ?string $expected ) {
		$this->assertSame( $expected, StateCode::resolve( $country, $region ) );
	}

	public function regions(): array {
		return array(
			'exact code'               => array( 'US', 'CA', 'CA' ),
			'name'                     => array( 'US', 'California', 'CA' ),
			'name in other case'       => array( 'US', ' new YORK ', 'NY' ),
			'ISO prefix and code'      => array( 'US', 'US-CA', 'CA' ),
			'ISO prefix and name'      => array( 'US', 'us-California', 'CA' ),
			'prefix of another country' => array( 'US', 'DE-CA', null ),
			'unknown region'           => array( 'US', 'Atlantis', null ),
			'code in lower case'       => array( 'US', 'ca', 'CA' ),
			'entity in the state name' => array( 'AX', 'åland main', 'AX1' ),
			'empty region'             => array( 'US', '  ', '' ),
			'country without states'   => array( 'CH', 'Zürich', 'Zürich' ),
		);
	}

	public function test_normalise_keeps_an_unresolved_region_as_sent() {
		$this->assertSame( 'CA', StateCode::normalise( 'US', 'California' ) );
		$this->assertSame( 'Atlantis', StateCode::normalise( 'US', 'Atlantis' ) );
	}

	public function test_without_woocommerce_countries_the_region_is_kept() {
		Functions\when( 'WC' )->justReturn( null );

		$this->assertSame( 'California', StateCode::resolve( 'US', 'California' ) );
	}
}
