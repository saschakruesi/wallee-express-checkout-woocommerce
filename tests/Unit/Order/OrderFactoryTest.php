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

	public function test_a_line_whose_product_changes_through_its_variation_data_creates_no_order() {
		$this->product_lookup();
		Functions\expect( 'wc_create_order' )->never();

		try {
			// A key of attribute form whose suffix is an internal property.
			OrderFactory::create( $this->variation_snapshot( array( 'attribute__product_id' => '12' ) ), null, 'u', 'w', 1 );
			$this->fail( 'refusal expected' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( OrderFactory::LINE_IDENTITY_CHANGED, $e->getMessage() );
		}
	}

	public function test_a_line_whose_variation_changes_through_its_variation_data_creates_no_order() {
		$this->product_lookup();
		Functions\expect( 'wc_create_order' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( OrderFactory::LINE_IDENTITY_CHANGED );
		OrderFactory::create( $this->variation_snapshot( array( 'attribute__variation_id' => '99' ) ), null, 'u', 'w', 1 );
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
