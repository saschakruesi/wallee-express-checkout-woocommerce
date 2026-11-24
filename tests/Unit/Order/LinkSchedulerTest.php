<?php
/**
 * LinkScheduler tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Order\LinkScheduler;
use Wallee\ExpressCheckout\Order\OrderLinker;
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;
use Wallee\ExpressCheckout\Tests\Support\FakeOrderService;
use Wallee\ExpressCheckout\Tests\Support\FakeSessionRepository;
use Wallee\ExpressCheckout\Tests\Support\RecordingAdapter;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;
use Wallee\ExpressCheckout\Tests\Unit\Http\ReturnHandlerTest;

/**
 * @covers \Wallee\ExpressCheckout\Order\LinkScheduler
 * @covers \Wallee\ExpressCheckout\Order\CustomerDetails
 */
class LinkSchedulerTest extends TestCase {

	/** @var FakeSessionRepository */
	private $repository;

	/** @var FakeApiClient */
	private $api;

	/** @var FakeOrderService */
	private $orders;

	/** @var RecordingAdapter */
	private $adapter;

	/** @var \WC_Order */
	private $order;

	/** @var array[] Calls of the scheduler: order ID, delay, deduplicate. */
	private $scheduled = array();

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

		$this->scheduled  = array();
		$this->repository = new FakeSessionRepository(
			CartSnapshotTest::data(),
			array(
				'state'          => 'processed',
				'order_id'       => 501,
				'shipping_quote' => ReturnHandlerTest::quote(),
			)
		);
		$this->api        = new FakeApiClient();
		$this->orders     = new FakeOrderService();
		$this->adapter    = new RecordingAdapter();
		$this->order      = new \WC_Order( 501, 60.11 );

		$this->order->meta['_wallee_ec_session_uuid'] = str_repeat( 'a', 64 );
		$this->order->meta['_wallee_ec_session_id']   = 335;
		$this->orders->orders[501]                    = $this->order;
		$this->api->sessions                          = array( ReturnHandlerTest::processing() );
		$this->api->transaction                       = array(
			'id'    => 629017977,
			'state' => 'AUTHORIZED',
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function scheduler(): LinkScheduler {
		$log = function ( $message, $level = 'info' ) {
			$this->adapter->log( $message, $level );
		};

		return new LinkScheduler(
			$this->orders,
			new CustomerDetails( $this->api, $this->repository, $this->orders, $log ),
			new OrderLinker( $this->adapter ),
			$this->repository,
			function ( $order_id, $delay, $deduplicate ) {
				$this->scheduled[] = array( $order_id, $delay, $deduplicate );
				return true;
			},
			$log
		);
	}

	public function test_known_transaction_is_linked_without_reschedule() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;

		$this->scheduler()->run( 501 );

		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
		$this->assertSame( array( 501 ), $this->adapter->links );
		$this->assertSame( array(), $this->api->calls );
		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( 1, $this->order->get_meta( LinkScheduler::META_ATTEMPTS ) );
	}

	public function test_order_whose_browser_never_returned_is_collected_then_linked() {
		$this->scheduler()->run( 501 );

		$this->assertSame( array( 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( 629017977, $this->order->get_meta( '_wallee_ec_transaction_id' ) );
		$this->assertSame( 629017977, $this->repository->row['transaction_id'] );
		$this->assertCount( 1, $this->orders->applied );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_session_without_transaction_is_retried_with_back_off_and_no_note() {
		$this->api->sessions = array( ReturnHandlerTest::approved() );

		$this->scheduler()->run( 501 );
		$this->scheduler()->run( 501 );

		$this->assertSame( array( array( 501, 10, false ), array( 501, 20, false ) ), $this->scheduled );
		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( 'wallee_express_checkout', $this->order->get_payment_method() );
	}

	public function test_transaction_not_final_is_retried_without_note() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		$this->adapter->gateway                         = null;
		$this->adapter->state                           = 'PROCESSING';

		$this->scheduler()->run( 501 );

		$this->assertSame( array( array( 501, 10, false ) ), $this->scheduled );
		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( '', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
	}

	public function test_read_errors_are_retried_and_the_last_attempt_flags() {
		$this->api->sessions = array( new ApiException( 'down', 503 ) );

		for ( $i = 0; $i < count( LinkScheduler::DELAYS ); $i++ ) {
			$this->scheduler()->run( 501 );
		}

		$this->assertSame( array( 10, 20, 40, 80, 160 ), array_column( $this->scheduled, 1 ) );
		$this->assertSame( 6, $this->order->get_meta( LinkScheduler::META_ATTEMPTS ) );
		$this->assertSame( 'yes', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'wallee portal', $this->order->notes[0] );
		$this->assertStringContainsString( 'not linked after 6 attempts', implode( "\n", $this->adapter->logs ) );
	}

	public function test_last_attempt_with_unreadable_transaction_flags() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		$this->order->meta[ LinkScheduler::META_ATTEMPTS ] = 5;
		$this->adapter->read_error                       = new \InvalidArgumentException( 'enum' );

		$this->scheduler()->run( 501 );

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( 'yes', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
	}

	public function test_linked_foreign_and_missing_orders_are_left_alone() {
		$this->order->payment_method = 'wallee_7';
		$this->scheduler()->run( 501 );

		$other              = new \WC_Order( 502, 1.0 );
		$other->created_via = 'checkout';
		$this->orders->orders[502] = $other;
		$this->scheduler()->run( 502 );

		$this->scheduler()->run( 999 );

		$this->assertSame( array(), $this->api->calls );
		$this->assertSame( array(), $this->adapter->reads );
		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( '', $other->get_meta( LinkScheduler::META_ATTEMPTS ) );
	}

	public function test_purged_row_falls_back_to_the_session_id_on_the_order() {
		$this->repository->row = null;

		$this->scheduler()->run( 501 );

		$this->assertSame( array( array( 'getSession', 335 ) ), $this->api->calls );
		$this->assertSame( 629017977, $this->order->get_meta( '_wallee_ec_transaction_id' ) );
		$this->assertSame( array(), $this->orders->applied, 'no customer data without the row' );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
	}

	public function test_missing_email_after_payment_keeps_the_order_with_one_note() {
		$this->orders->applied_email = '';

		$this->scheduler()->run( 501 );
		unset( $this->order->meta['_wallee_ec_transaction_id'] );
		$this->order->payment_method = 'wallee_express_checkout';
		$this->scheduler()->run( 501 );

		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( array( 'The payment is approved, but wallee returned no valid e-mail address for the customer.' ), $this->order->notes );
	}

	public function test_admin_retry_needs_no_flag_and_no_transaction_meta() {
		$this->scheduler()->retryNow( $this->order );

		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( '', $this->order->get_meta( LinkScheduler::META_ATTEMPTS ), 'the admin view is not a counted attempt' );
	}

	public function test_admin_retry_ignores_non_orders_and_linked_orders() {
		$this->scheduler()->retryNow( new \stdClass() );
		$this->order->payment_method = 'wallee_7';
		$this->scheduler()->retryNow( $this->order );

		$this->assertSame( array(), $this->api->calls );
		$this->assertSame( array(), $this->adapter->reads );
	}

	public function test_schedule_uses_action_scheduler_once_per_order() {
		$pending  = false;
		$recorded = array();
		Functions\when( 'as_next_scheduled_action' )->alias(
			function () use ( &$pending ) {
				return $pending;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args, $group ) use ( &$recorded ) {
				$recorded[] = array( $timestamp - time(), $hook, $args, $group );
				return 17;
			}
		);

		$this->assertTrue( LinkScheduler::schedule( 501 ) );
		$pending = 1767225600;
		$this->assertTrue( LinkScheduler::schedule( 501 ), 'already pending' );
		$this->assertTrue( LinkScheduler::scheduleAttempt( 501, 10, false ), 'next attempt from inside the job' );
		$this->assertFalse( LinkScheduler::schedule( 0 ) );

		$this->assertCount( 2, $recorded );
		$this->assertSame( array( 'wallee_ec_link_order', array( 501 ), 'wallee-ec' ), array_slice( $recorded[0], 1 ) );
		$this->assertEqualsWithDelta( 300, $recorded[0][0], 2 );
		$this->assertEqualsWithDelta( 600, $recorded[1][0], 2 );
	}
}
