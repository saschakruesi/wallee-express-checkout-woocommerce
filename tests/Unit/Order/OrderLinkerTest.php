<?php
/**
 * OrderLinker tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Order\OrderLinker;
use Wallee\ExpressCheckout\Tests\Support\RecordingAdapter;

/**
 * @covers \Wallee\ExpressCheckout\Order\OrderLinker
 */
class OrderLinkerTest extends TestCase {

	/** @var RecordingAdapter */
	private $adapter;

	/** @var \WC_Order */
	private $order;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$gateway  = new class() {
			public function get_title() {
				return 'Credit / Debit Card';
			}
		};
		$registry = new class( $gateway ) {
			private $gateway;
			public function __construct( $gateway ) {
				$this->gateway = $gateway;
			}
			public function payment_gateways() {
				return array( 'wallee_7' => $this->gateway );
			}
		};
		$wc       = new class( $registry ) {
			private $registry;
			public function __construct( $registry ) {
				$this->registry = $registry;
			}
			public function payment_gateways() {
				return $this->registry;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );

		$this->adapter = new RecordingAdapter();
		$this->order   = new \WC_Order( 501, 60.11 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function linker(): OrderLinker {
		return new OrderLinker( $this->adapter );
	}

	public function test_link_writes_info_row_and_sets_gateway() {
		$this->assertTrue( $this->linker()->link( $this->order, 629017977 ) );

		$this->assertSame( array( 629017977 ), $this->adapter->reads );
		$this->assertSame( array( 501 ), $this->adapter->links );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
		$this->assertSame( 'Credit / Debit Card', $this->order->get_payment_method_title() );
		$this->assertSame( array(), $this->order->notes );
		$this->assertGreaterThan( 0, $this->order->saves );
	}

	public function test_link_is_idempotent() {
		$this->linker()->link( $this->order, 629017977 );
		$saves = $this->order->saves;

		$this->assertTrue( $this->linker()->link( $this->order, 629017977 ) );
		$this->assertSame( array( 501, 501 ), $this->adapter->links );
		$this->assertSame( $saves, $this->order->saves, 'nothing to change, nothing saved' );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_unresolved_gateway_keeps_provisional_method_and_notes_once() {
		$this->adapter->gateway = null;

		$this->assertFalse( $this->linker()->link( $this->order, 629017977 ) );
		$this->assertFalse( $this->linker()->link( $this->order, 629017977 ) );

		$this->assertSame( 'wallee_express_checkout', $this->order->get_payment_method() );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'wallee portal', $this->order->notes[0] );
		$this->assertSame( 'yes', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertSame( array( 501, 501 ), $this->adapter->links, 'the info row is still written' );
		$this->assertStringStartsWith( 'error: ', $this->adapter->logs[0] );
	}

	public function test_later_resolution_clears_the_guard() {
		$this->adapter->gateway = null;
		$this->linker()->link( $this->order, 629017977 );
		$this->adapter->gateway = 'wallee_7';

		$this->assertTrue( $this->linker()->link( $this->order, 629017977 ) );
		$this->assertSame( '', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
		$this->assertCount( 2, $this->order->notes );
	}

	public function test_read_error_returns_false_without_note() {
		$this->adapter->read_error = new \InvalidArgumentException( 'enum' );

		$this->assertFalse( $this->linker()->link( $this->order, 629017977 ) );
		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( array(), $this->adapter->links );
		$this->assertStringContainsString( 'InvalidArgumentException', $this->adapter->logs[0] );
	}

	public function test_admin_retry_only_runs_for_guarded_orders() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;

		$this->linker()->retry( $this->order );
		$this->assertSame( array(), $this->adapter->reads );

		$this->order->meta[ OrderLinker::META_UNRESOLVED ] = 'yes';
		$this->linker()->retry( $this->order );
		$this->assertSame( array( 629017977 ), $this->adapter->reads );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
	}

	public function test_admin_retry_ignores_non_orders_and_missing_transaction() {
		$this->linker()->retry( new \stdClass() );
		$this->order->meta[ OrderLinker::META_UNRESOLVED ] = 'yes';
		$this->linker()->retry( $this->order );

		$this->assertSame( array(), $this->adapter->reads );
	}
}
