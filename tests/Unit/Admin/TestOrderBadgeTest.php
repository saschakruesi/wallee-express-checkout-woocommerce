<?php
/**
 * TestOrderBadge tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Admin\TestOrderBadge;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Admin\TestOrderBadge
 */
class TestOrderBadgeTest extends TestCase {

	/** @var \WC_Order */
	private $test_order;

	/** @var \WC_Order */
	private $live_order;

	/** @var \WC_Order */
	private $regular_order;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html__' )->returnArg();

		$this->test_order                                 = new \WC_Order( 501, 1.0 );
		$this->test_order->meta['_wallee_ec_environment'] = 'TEST';
		$this->live_order                                 = new \WC_Order( 502, 1.0 );
		$this->live_order->meta['_wallee_ec_environment'] = 'PRODUCTION';
		$this->regular_order                              = new \WC_Order( 503, 1.0 );
		$this->regular_order->created_via                 = 'checkout';
		$this->regular_order->meta['_wallee_ec_environment'] = 'TEST';

		$orders = array(
			501 => $this->test_order,
			502 => $this->live_order,
			503 => $this->regular_order,
		);
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $orders ) {
				return $orders[ $id ] ?? false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function rendered( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_hooks_cover_the_hpos_and_the_legacy_order_list_and_the_edit_screen() {
		TestOrderBadge::register();

		$this->assertNotFalse( has_filter( 'manage_woocommerce_page_wc-orders_columns', array( TestOrderBadge::class, 'addColumn' ) ) );
		$this->assertNotFalse( has_action( 'manage_woocommerce_page_wc-orders_custom_column', array( TestOrderBadge::class, 'renderColumn' ) ) );
		$this->assertNotFalse( has_filter( 'manage_edit-shop_order_columns', array( TestOrderBadge::class, 'addColumn' ) ) );
		$this->assertNotFalse( has_action( 'manage_shop_order_posts_custom_column', array( TestOrderBadge::class, 'renderColumn' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_admin_order_data_after_order_details', array( TestOrderBadge::class, 'renderEditNotice' ) ) );
	}

	public function test_the_column_comes_right_after_the_status() {
		$columns = TestOrderBadge::addColumn(
			array(
				'cb'           => '',
				'order_number' => 'Order',
				'order_status' => 'Status',
				'order_total'  => 'Total',
			)
		);

		$this->assertSame( array( 'cb', 'order_number', 'order_status', TestOrderBadge::COLUMN, 'order_total' ), array_keys( $columns ) );
		$this->assertSame( 'Express checkout', $columns[ TestOrderBadge::COLUMN ] );
		$this->assertArrayHasKey( TestOrderBadge::COLUMN, TestOrderBadge::addColumn( array( 'order_number' => 'Order' ) ), 'appended without a status column' );
		$this->assertSame( 'x', TestOrderBadge::addColumn( 'x' ), 'anything else is passed on' );
	}

	public function test_a_test_order_shows_the_badge_in_both_list_variants() {
		$hpos   = self::rendered(
			function () {
				TestOrderBadge::renderColumn( TestOrderBadge::COLUMN, $this->test_order );
			}
		);
		$legacy = self::rendered(
			static function () {
				TestOrderBadge::renderColumn( TestOrderBadge::COLUMN, 501 );
			}
		);

		$this->assertSame( '<mark class="order-status status-on-hold wallee-ec-test" title="Test payment in the wallee TEST environment. No real money was moved."><span>Express TEST</span></mark>', $hpos );
		$this->assertSame( $hpos, $legacy );
	}

	public function test_other_orders_and_columns_show_no_badge() {
		$live    = self::rendered(
			function () {
				TestOrderBadge::renderColumn( TestOrderBadge::COLUMN, $this->live_order );
			}
		);
		$regular = self::rendered(
			function () {
				TestOrderBadge::renderColumn( TestOrderBadge::COLUMN, $this->regular_order );
			}
		);
		$other   = self::rendered(
			function () {
				TestOrderBadge::renderColumn( 'order_total', $this->test_order );
				TestOrderBadge::renderColumn( TestOrderBadge::COLUMN, 999 );
			}
		);

		$this->assertSame( 'Express', $live );
		$this->assertSame( '', $regular );
		$this->assertSame( '', $other );
	}

	public function test_the_edit_screen_of_a_test_order_says_do_not_ship() {
		$html = self::rendered(
			function () {
				TestOrderBadge::renderEditNotice( $this->test_order );
			}
		);

		$this->assertStringContainsString( '<span>Express TEST</span>', $html );
		$this->assertStringContainsString( 'No real money was moved. Do not ship this order.', $html );
		$this->assertSame(
			'',
			self::rendered(
				function () {
					TestOrderBadge::renderEditNotice( $this->live_order );
					TestOrderBadge::renderEditNotice( $this->regular_order );
					TestOrderBadge::renderEditNotice( null );
				}
			)
		);
	}
}
