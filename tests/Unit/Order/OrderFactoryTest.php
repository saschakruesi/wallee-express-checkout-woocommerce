<?php
/**
 * OrderFactory tests (the parts that need no WooCommerce).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Order\OrderFactory;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\OrderFactory::cancelUnpaid
 */
class OrderFactoryTest extends TestCase {

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
