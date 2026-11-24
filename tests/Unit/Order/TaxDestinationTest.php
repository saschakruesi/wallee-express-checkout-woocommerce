<?php
/**
 * TaxDestination tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Order\TaxDestination;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';
require_once dirname( __DIR__, 2 ) . '/Support/wc-tax-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\TaxDestination
 */
class TaxDestinationTest extends TestCase {

	/** @var array<string, string> Options returned by get_option(). */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\WC_Tax::reset();
		$this->options = array(
			'woocommerce_tax_based_on'       => 'shipping',
			'woocommerce_shipping_tax_class' => 'inherit',
		);
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Snapshot for a tax location, standard class item, one fee.
	 *
	 * @param array $location Tax location fields to change.
	 * @param array $over     Top-level snapshot fields to change.
	 */
	private function snapshot( array $location = array(), array $over = array() ): CartSnapshot {
		$data                 = CartSnapshotTest::data( $over );
		$data['tax_location'] = array_merge( $data['tax_location'], $location );

		return CartSnapshot::fromArray( $data );
	}

	private static function address( string $country, string $state = '', string $postcode = '', string $city = '' ): array {
		return array(
			'country'  => $country,
			'state'    => $state,
			'postcode' => $postcode,
			'city'     => $city,
		);
	}

	public function test_the_basis_follows_woocommerce_settings() {
		$this->assertSame( TaxDestination::BASIS_SHIPPING, TaxDestination::basis() );
		$this->options['woocommerce_tax_based_on'] = 'billing';
		$this->assertSame( TaxDestination::BASIS_BILLING, TaxDestination::basis() );
		$this->options['woocommerce_tax_based_on'] = 'base';
		$this->assertSame( TaxDestination::BASIS_NONE, TaxDestination::basis() );
		$this->options['woocommerce_tax_based_on'] = 'shipping';
		Functions\when( 'wc_tax_enabled' )->justReturn( false );
		$this->assertSame( TaxDestination::BASIS_NONE, TaxDestination::basis(), 'taxes disabled' );
	}

	public function test_same_country_with_the_same_rates_is_accepted() {
		$this->assertTrue( TaxDestination::sameRates( $this->snapshot(), self::address( 'CH', '', '3000', 'Bern' ) ) );
		$this->assertTrue( TaxDestination::sameRates( $this->snapshot(), self::address( 'ch' ) ) );
	}

	public function test_another_country_with_other_rates_is_refused() {
		$this->assertFalse( TaxDestination::sameRates( $this->snapshot(), self::address( 'DE', '', '10115', 'Berlin' ) ) );
	}

	public function test_a_state_in_the_tax_location_does_not_refuse_another_state_when_rates_are_national() {
		$snapshot = $this->snapshot( array( 'state' => 'ZH' ) );

		$this->assertTrue( TaxDestination::sameRates( $snapshot, self::address( 'CH', 'BE' ) ) );
		$this->assertTrue( TaxDestination::sameRates( $snapshot, self::address( 'CH', 'Zürich' ) ), 'state in another format' );
	}

	public function test_state_specific_rates_refuse_another_state() {
		\WC_Tax::$rows[] = \WC_Tax::row( 20, 'US', '', 7.25, array( 'state' => 'CA' ) );
		\WC_Tax::$rows[] = \WC_Tax::row( 21, 'US', '', 4.0, array( 'state' => 'NY' ) );
		$snapshot        = $this->snapshot( array( 'country' => 'US', 'state' => 'CA', 'postcode' => '', 'city' => '' ) );

		$this->assertTrue( TaxDestination::sameRates( $snapshot, self::address( 'US', 'ca' ) ) );
		$this->assertFalse( TaxDestination::sameRates( $snapshot, self::address( 'US', 'NY' ) ) );
		$this->assertFalse( TaxDestination::sameRates( $snapshot, self::address( 'US', '' ) ) );
	}

	public function test_postcode_specific_rates_refuse_another_postcode_in_the_same_country() {
		\WC_Tax::$rows[] = \WC_Tax::row( 30, 'ES', '', 21.0 );
		\WC_Tax::$rows[] = \WC_Tax::row( 31, 'ES', '', 7.0, array( 'postcode' => '35*' ) );
		$snapshot        = $this->snapshot( array( 'country' => 'ES', 'postcode' => '35001', 'city' => '' ) );

		$this->assertTrue( TaxDestination::sameRates( $snapshot, self::address( 'ES', '', '35100' ) ) );
		$this->assertFalse( TaxDestination::sameRates( $snapshot, self::address( 'ES', '', '28001' ) ) );
	}

	public function test_city_specific_rates_refuse_another_city() {
		\WC_Tax::$rows[] = \WC_Tax::row( 40, 'CH', '', 1.0, array( 'city' => 'Zurich' ) );

		$this->assertTrue( TaxDestination::sameRates( $this->snapshot(), self::address( 'CH', '', '8001', 'ZURICH' ) ) );
		$this->assertFalse( TaxDestination::sameRates( $this->snapshot(), self::address( 'CH', '', '3000', 'Bern' ) ) );
	}

	public function test_no_default_location_refuses_only_destinations_that_have_rates() {
		$snapshot = $this->snapshot( array( 'country' => '', 'postcode' => '', 'city' => '' ) );

		$this->assertTrue( TaxDestination::sameRates( $snapshot, self::address( 'US', 'CA' ) ), 'no rates there either' );
		$this->assertFalse( TaxDestination::sameRates( $snapshot, self::address( 'CH' ) ) );
	}

	public function test_every_tax_class_of_the_lines_is_compared() {
		$data                          = CartSnapshotTest::data();
		$data['items'][0]['tax_class'] = 'reduced-rate';
		$data['fees']                  = array();
		$snapshot                      = CartSnapshot::fromArray( $data );
		\WC_Tax::$rows[] = \WC_Tax::row( 50, 'LI', 'reduced-rate', 2.5 );

		$this->assertSame( array( 'reduced-rate' ), TaxDestination::classes( $snapshot ) );
		$this->assertFalse( TaxDestination::sameRates( $snapshot, self::address( 'LI' ) ) );
	}

	public function test_fees_and_a_fixed_shipping_tax_class_are_compared_too() {
		$data                          = CartSnapshotTest::data();
		$data['items'][0]['tax_class'] = 'reduced-rate';
		$snapshot                      = CartSnapshot::fromArray( $data );

		$this->assertSame( array( 'reduced-rate', '' ), TaxDestination::classes( $snapshot ), 'fees are taxed with the standard class' );

		$data['fees']                                   = array();
		$this->options['woocommerce_shipping_tax_class'] = 'zero-rate';
		$this->assertSame( array( 'reduced-rate', 'zero-rate' ), TaxDestination::classes( CartSnapshot::fromArray( $data ) ) );

		$this->options['woocommerce_shipping_tax_class'] = 'inherit';
		$this->assertSame( array( 'reduced-rate' ), TaxDestination::classes( CartSnapshot::fromArray( $data ) ), 'inherit: the lines\' classes' );

		$this->options['woocommerce_shipping_tax_class'] = 'zero-rate';
		$data['items'][0]['needs_shipping']             = false;
		$this->assertSame( array( 'reduced-rate' ), TaxDestination::classes( CartSnapshot::fromArray( $data ) ), 'no shipping' );
	}

	public function test_rates_differing_only_in_the_shipping_flag_are_different() {
		\WC_Tax::$rows[] = \WC_Tax::row( 3, 'LU', '', 19.0, array( 'shipping' => 'no' ) );
		\WC_Tax::$rows[] = \WC_Tax::row( 4, 'LU', 'reduced-rate', 7.0 );
		$snapshot        = $this->snapshot( array( 'country' => 'DE', 'postcode' => '', 'city' => '' ) );

		$this->assertFalse( TaxDestination::sameRates( $snapshot, self::address( 'LU' ) ) );
	}

	public function test_the_order_address_follows_the_tax_basis() {
		$order                    = new \WC_Order( 501 );
		$order->billing_country   = 'DE';
		$order->billing_state     = 'BE';
		$order->billing_postcode  = '10115';
		$order->billing_city      = 'Berlin';
		$order->shipping_country  = 'CH';
		$order->shipping_state    = 'ZH';
		$order->shipping_postcode = '8000';
		$order->shipping_city     = 'Zurich';

		$this->assertSame( self::address( 'CH', 'ZH', '8000', 'Zurich' ), TaxDestination::orderAddress( $order ) );
		$this->options['woocommerce_tax_based_on'] = 'billing';
		$this->assertSame( self::address( 'DE', 'BE', '10115', 'Berlin' ), TaxDestination::orderAddress( $order ) );

		$this->options['woocommerce_tax_based_on'] = 'shipping';
		$order->shipping_country                   = '';
		$this->assertSame( 'DE', TaxDestination::orderAddress( $order )['country'], 'billing when there is no shipping address' );
	}
}
