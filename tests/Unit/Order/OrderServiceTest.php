<?php
/**
 * OrderService tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Order\OrderService;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\OrderService
 */
class OrderServiceTest extends TestCase {

	/**
	 * Order whose data store answers get_recorded_coupon_usage_counts().
	 *
	 * @param mixed $recorded Data-store answer.
	 */
	private static function order( $recorded ): \WC_Order {
		$order             = new \WC_Order( 501, 10.0 );
		$order->data_store = new class( $recorded ) {
			/**
			 * Answer.
			 *
			 * @var mixed
			 */
			private $recorded;

			/**
			 * Constructor.
			 *
			 * @param mixed $recorded Answer.
			 */
			public function __construct( $recorded ) {
				$this->recorded = $recorded;
			}

			/**
			 * As WooCommerce's order data store: whether usage was counted.
			 *
			 * @param \WC_Order $order Order.
			 */
			public function get_recorded_coupon_usage_counts( $order ) {
				return $this->recorded;
			}
		};

		return $order;
	}

	public function test_coupon_usage_counted_by_woocommerce_is_read_from_the_order_data_store() {
		$service = new OrderService();

		$this->assertTrue( $service->couponUsageRecorded( self::order( '1' ) ) );
		$this->assertTrue( $service->couponUsageRecorded( self::order( true ) ) );
		$this->assertFalse( $service->couponUsageRecorded( self::order( '' ) ) );
		$this->assertFalse( $service->couponUsageRecorded( new \WC_Order( 502 ) ), 'no data store' );
	}
}
