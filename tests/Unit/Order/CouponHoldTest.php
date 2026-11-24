<?php
/**
 * CouponHold tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Order\CouponHold;

require_once dirname( __DIR__, 2 ) . '/Support/wc-coupon-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\CouponHold
 */
class CouponHoldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_email' )->returnArg();
		\WC_Coupon_Data_Store_Stub::$holds = array();
		\WC_Coupon::$registry              = array(
			'save5' => array(
				'id'                   => 7,
				'usage_limit_per_user' => 1,
			),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function order(): \WC_Order {
		$order                = new \WC_Order( 501, 10.0 );
		$order->billing_email = 'Buyer@Example.test';
		$order->data_store    = new class() {
			/**
			 * Saved keys.
			 *
			 * @var array[]
			 */
			public $keys = array();

			/**
			 * Records the held keys.
			 *
			 * @param \WC_Order $order    Order.
			 * @param array     $held     Global hold keys.
			 * @param array     $for_user Per-user hold keys.
			 */
			public function set_coupon_held_keys( $order, $held, $for_user ) {
				$this->keys[] = array( $held, $for_user );
			}
		};

		return $order;
	}

	public function test_no_coupons_take_no_hold() {
		$this->assertSame( array( CouponHold::NONE, '' ), CouponHold::hold( array(), self::order() ) );
		$this->assertSame( array(), \WC_Coupon_Data_Store_Stub::$holds );
	}

	public function test_a_customer_with_an_account_is_held_by_user_id_and_both_emails() {
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_email' => 'account@example.test' ) );
		$order              = self::order();
		$order->customer_id = 42;

		$this->assertSame( array( CouponHold::HELD, '' ), CouponHold::hold( array( 'save5' ), $order ) );
		$this->assertSame(
			array( array( 'check_and_hold_coupon_for_user', 'save5', array( 'buyer@example.test', 'account@example.test' ), 42 ) ),
			\WC_Coupon_Data_Store_Stub::$holds,
			'no global hold without a global limit'
		);
		$this->assertSame( array( array( array(), array( 7 => 'held_save5' ) ) ), $order->data_store->keys );
	}

	public function test_an_unknown_coupon_is_skipped() {
		$this->assertSame( array( CouponHold::NONE, '' ), CouponHold::hold( array( 'gone' ), self::order() ) );
		$this->assertSame( array(), \WC_Coupon_Data_Store_Stub::$holds );
	}

	public function test_without_the_order_data_store_no_hold_is_taken() {
		$order             = self::order();
		$order->data_store = null;

		$this->assertSame( array( CouponHold::NONE, '' ), CouponHold::hold( array( 'save5' ), $order ) );
		$this->assertSame( array(), \WC_Coupon_Data_Store_Stub::$holds );
	}

	public function test_held_keys_are_persisted_on_the_order() {
		$order             = self::order();
		$order->data_store = new class() {
			/**
			 * Stages the keys on the order, as WooCommerce's data store does (no save).
			 *
			 * @param \WC_Order $order    Order.
			 * @param array     $held     Global hold keys.
			 * @param array     $for_user Per-user hold keys.
			 */
			public function set_coupon_held_keys( $order, $held, $for_user ) {
				$order->update_meta_data( '_coupon_held_keys_for_users', $for_user );
			}
		};

		$this->assertSame( array( CouponHold::HELD, '' ), CouponHold::hold( array( 'save5' ), $order ) );
		$this->assertSame( array( 7 => 'held_save5' ), $order->meta['_coupon_held_keys_for_users'] );
		$this->assertSame( 1, $order->saves, 'the staged keys are saved' );
	}

	public function test_an_order_that_cannot_be_saved_is_a_technical_failure() {
		$order             = self::order();
		$order->save_error = new \RuntimeException( 'db' );

		$this->assertSame( array( CouponHold::FAILED, 'keys not saved (RuntimeException)' ), CouponHold::hold( array( 'save5' ), $order ) );
	}

	public function test_keys_that_cannot_be_saved_are_a_technical_failure() {
		$order             = self::order();
		$order->data_store = new class() {
			/**
			 * Fails.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function set_coupon_held_keys() {
				throw new \RuntimeException( 'db' );
			}
		};

		$this->assertSame( array( CouponHold::FAILED, 'keys not saved (RuntimeException)' ), CouponHold::hold( array( 'save5' ), $order ) );
	}
}
