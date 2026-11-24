<?php
/**
 * OrderFactory tests (the parts that need no WooCommerce).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Order\OrderFactory;
use Wallee\ExpressCheckout\Tests\Support\FakeWc;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';
require_once dirname( __DIR__, 2 ) . '/Support/wc-product-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\OrderFactory
 */
class OrderFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Snapshot with one untaxed line of a variation (no tax lookups needed).
	 *
	 * @param array $variation Variation data of the line.
	 */
	private function variation_snapshot( array $variation ): CartSnapshot {
		$item = CartSnapshotTest::item(
			array(
				'product_id'        => 20,
				'variation_id'      => 21,
				'variation'         => $variation,
				'line_subtotal_tax' => 0,
				'line_tax'          => 0,
				'tax_rates'         => array(),
			)
		);

		return CartSnapshot::fromArray(
			CartSnapshotTest::data(
				array(
					'items'   => array( $item ),
					'fees'    => array(),
					'coupons' => array(),
				)
			)
		);
	}

	private function product_lookup(): void {
		Functions\when( 'wc_get_product' )->justReturn(
			new \WC_Product(
				array(
					'id'        => 21,
					'parent_id' => 20,
					'type'      => 'variation',
				)
			)
		);
	}

	/**
	 * @dataProvider internal_meta_keys
	 *
	 * @param string $key Key of attribute form whose meta name is an internal property.
	 */
	public function test_variation_data_that_would_become_internal_meta_is_refused_before_any_order( string $key ) {
		Functions\expect( 'wc_create_order' )->never();

		$this->expectException( \InvalidArgumentException::class );
		$this->variation_snapshot( array( $key => '12' ) );
	}

	public function internal_meta_keys(): array {
		return array(
			'product id'    => array( 'attribute__product_id' ),
			'variation id'  => array( 'attribute__variation_id' ),
			'nested prefix' => array( 'attribute_attribute__qty' ),
		);
	}

	public function test_a_line_whose_product_differs_from_the_snapshot_creates_no_order() {
		Functions\when( 'wc_get_product' )->justReturn(
			new \WC_Product(
				array(
					'id'        => 21,
					'parent_id' => 99,
					'type'      => 'variation',
				)
			)
		);
		Functions\expect( 'wc_create_order' )->never();

		try {
			OrderFactory::create( $this->variation_snapshot( array( 'attribute_size' => 'M' ) ), null, 'u', 'w', 1 );
			$this->fail( 'refusal expected' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( OrderFactory::LINE_IDENTITY_CHANGED, $e->getMessage() );
		}
	}

	public function test_a_line_whose_variation_differs_from_the_snapshot_creates_no_order() {
		Functions\when( 'wc_get_product' )->justReturn(
			new \WC_Product(
				array(
					'id'        => 22,
					'parent_id' => 20,
					'type'      => 'variation',
				)
			)
		);
		Functions\expect( 'wc_create_order' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( OrderFactory::LINE_IDENTITY_CHANGED );
		OrderFactory::create( $this->variation_snapshot( array( 'attribute_size' => 'M' ) ), null, 'u', 'w', 1 );
	}

	public function test_a_line_with_attribute_data_keeps_its_identity_and_the_order_is_created() {
		$this->product_lookup();
		Functions\expect( 'wc_create_order' )->once()->andReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The order could not be created.' );
		OrderFactory::create( $this->variation_snapshot( array( 'attribute_size' => 'M' ) ), null, 'u', 'w', 1 );
	}

	public function test_only_attribute_keys_reach_the_order_item() {
		$this->assertSame(
			array(
				'attribute_size'     => 'M',
				'attribute_pa_color' => '1',
			),
			OrderFactory::attributesOnly(
				array(
					'attribute_size'     => 'M',
					'_product_id'        => '12',
					'_variation_id'      => '21',
					'_reduced_stock'     => '1',
					'_tax_class'         => 'zero-rate',
					'attribute_pa_color' => 1,
					'attribute_bad'      => array( 'x' ),
				)
			)
		);
	}

	/**
	 * @dataProvider stored_regions
	 *
	 * @param string $region   wallee's `postalState`.
	 * @param string $expected State stored on the order.
	 */
	public function test_the_order_stores_the_woocommerce_state_code_when_the_region_resolves( string $region, string $expected ) {
		Functions\when( 'WC' )->justReturn( new FakeWc() );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'is_email' )->justReturn( false );
		$order = new class( 501, 10.0 ) extends \WC_Order {
			/**
			 * Addresses set, by type.
			 *
			 * @var array
			 */
			public $addresses = array();

			/**
			 * Records an address.
			 *
			 * @param array  $address Address.
			 * @param string $type    `billing` or `shipping`.
			 */
			public function set_address( $address, $type ) {
				$this->addresses[ $type ] = $address;
			}
		};

		OrderFactory::applyCustomer(
			$order,
			array(
				'shippingAddress' => array(
					'country'     => 'US',
					'postalState' => $region,
				),
			),
			0
		);

		$this->assertSame( $expected, $order->addresses['shipping']['state'] );
		$this->assertSame( $expected, $order->addresses['billing']['state'] );
	}

	public function stored_regions(): array {
		return array(
			'name'         => array( 'California', 'CA' ),
			'ISO code'     => array( 'US-NY', 'NY' ),
			'unresolvable' => array( 'Calif.', 'Calif.' ),
		);
	}

	/**
	 * Order that records the addresses set, by type.
	 */
	private static function recording_order(): \WC_Order {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'is_email' )->justReturn( false );

		return new class( 501, 10.0 ) extends \WC_Order {
			/**
			 * Addresses set, by type.
			 *
			 * @var array
			 */
			public $addresses = array();

			/**
			 * Records an address.
			 *
			 * @param array  $address Address.
			 * @param string $type    `billing` or `shipping`.
			 */
			public function set_address( $address, $type ) {
				$this->addresses[ $type ] = $address;
			}
		};
	}

	public function test_an_order_without_shipping_keeps_empty_shipping_fields_when_only_billing_is_sent() {
		$order = self::recording_order();

		OrderFactory::applyCustomer( $order, array( 'billingAddress' => array( 'country' => 'CH', 'city' => 'Bern' ) ), 0, false );

		$this->assertSame( 'CH', $order->addresses['billing']['country'] );
		$this->assertSame( 'Bern', $order->addresses['billing']['city'] );
		$this->assertSame( '', $order->addresses['shipping']['country'] );
		$this->assertSame( '', $order->addresses['shipping']['city'] );
	}

	public function test_an_order_without_shipping_keeps_a_shipping_address_the_wallet_sent() {
		$order = self::recording_order();

		OrderFactory::applyCustomer( $order, array( 'shippingAddress' => array( 'country' => 'DE' ) ), 0, false );

		$this->assertSame( 'DE', $order->addresses['shipping']['country'] );
		$this->assertSame( 'DE', $order->addresses['billing']['country'], 'billing still falls back to the shipping address' );
	}

	public function test_an_order_with_shipping_copies_billing_to_a_missing_shipping_address() {
		$order = self::recording_order();

		OrderFactory::applyCustomer( $order, array( 'billingAddress' => array( 'country' => 'CH' ) ), 0 );

		$this->assertSame( 'CH', $order->addresses['shipping']['country'] );
	}

	public function test_express_order_that_never_reached_process_is_cancelled() {
		$this->assertTrue( OrderFactory::cancelUnpaid( false, new \WC_Order( 501, 1.0 ) ) );
	}

	public function test_express_order_that_may_have_reached_wallee_is_kept() {
		$started = new \WC_Order( 501, 1.0 );
		$started->meta[ OrderFactory::META_PROCESS_STARTED ] = '1767225600';
		$linked = new \WC_Order( 502, 1.0 );
		$linked->meta['_wallee_ec_transaction_id'] = 629017977;

		$this->assertFalse( OrderFactory::cancelUnpaid( false, $started ) );
		$this->assertFalse( OrderFactory::cancelUnpaid( true, $linked ) );
	}

	public function test_other_orders_keep_woocommerce_decision() {
		$checkout              = new \WC_Order( 503, 1.0 );
		$checkout->created_via = 'checkout';

		$this->assertTrue( OrderFactory::cancelUnpaid( true, $checkout ) );
		$this->assertFalse( OrderFactory::cancelUnpaid( false, $checkout ) );
		$this->assertSame( 'x', OrderFactory::cancelUnpaid( 'x', null ) );
	}
}
