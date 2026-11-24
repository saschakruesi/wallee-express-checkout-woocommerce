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
		$this->assertSame( '', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertSame( array(), $this->adapter->links );
		$this->assertStringContainsString( 'InvalidArgumentException', $this->adapter->logs[0] );
	}

	public function test_read_error_on_the_last_attempt_flags_and_notes_once() {
		$this->adapter->read_error = new \InvalidArgumentException( 'enum' );

		$this->assertFalse( $this->linker()->link( $this->order, 629017977, true ) );
		$this->assertFalse( $this->linker()->link( $this->order, 629017977, true ) );

		$this->assertSame( 'yes', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'could not be linked to its wallee transaction', $this->order->notes[0] );
	}

	public function test_unresolved_gateway_before_a_final_state_means_retry_later_without_note() {
		$this->adapter->gateway = null;
		foreach ( array( 'PROCESSING', 'CONFIRMED', '' ) as $state ) {
			$this->adapter->state = $state;
			$this->assertFalse( $this->linker()->link( $this->order, 629017977 ) );
		}

		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( '', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertStringStartsWith( 'info: ', $this->adapter->logs[0] );
	}

	public function test_unresolved_gateway_on_the_last_attempt_flags_whatever_the_state() {
		$this->adapter->gateway = null;
		$this->adapter->state   = 'PROCESSING';

		$this->assertFalse( $this->linker()->link( $this->order, 629017977, true ) );
		$this->assertSame( 'yes', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertStringContainsString( 'could not be matched', $this->order->notes[0] );
	}

	public function test_unresolved_gateway_in_every_final_state_flags() {
		foreach ( array( 'AUTHORIZED', 'COMPLETED', 'FULFILL' ) as $state ) {
			$order                  = new \WC_Order( 502, 1.0 );
			$this->adapter->gateway = null;
			$this->adapter->state   = $state;

			$this->linker()->link( $order, 629017977 );
			$this->assertSame( 'yes', $order->get_meta( OrderLinker::META_UNRESOLVED ), $state );
		}
	}

	public function test_a_transaction_that_ended_without_money_gets_one_clear_note_and_no_link() {
		foreach ( array( 'FAILED', 'DECLINE', 'VOIDED' ) as $state ) {
			$order                = new \WC_Order( 502, 1.0 );
			$this->adapter->state = $state;
			$this->adapter->links = array();

			$this->assertFalse( $this->linker()->link( $order, 629017977 ), $state );
			$this->assertFalse( $this->linker()->link( $order, 629017977, true ), $state . ', last attempt' );

			$this->assertSame( array(), $this->adapter->links, 'not linked: ' . $state );
			$this->assertSame( array( 'wallee Express Checkout: wallee transaction 629017977 ended in state ' . $state . ', so no money was taken for this order. The order is not linked to the wallee plugin and needs no refund.' ), $order->notes );
			$this->assertSame( '', $order->get_meta( OrderLinker::META_UNRESOLVED ), 'no "complete and refund in the portal" note' );
			$this->assertSame( $state, $order->get_meta( OrderLinker::META_PAYMENT_ENDED ) );
			$this->assertTrue( OrderLinker::paymentEnded( $order ) );
			$this->assertSame( 'wallee_express_checkout', $order->get_payment_method() );
		}
	}

	public function test_an_ended_payment_is_not_recognised_for_other_states() {
		$this->assertFalse( OrderLinker::paymentEnded( $this->order ) );
		$this->assertFalse( OrderLinker::notePaymentEnded( $this->order, 629017977, 'AUTHORIZED' ) );
		$this->assertSame( array(), $this->order->notes );
	}
}
