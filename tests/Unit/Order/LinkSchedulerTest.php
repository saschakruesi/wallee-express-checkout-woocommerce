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
use Wallee\ExpressCheckout\Order\FailedOrderSettlement;
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
			new CustomerDetails( $this->api, $this->repository, $this->orders, $log, false ),
			new OrderLinker( $this->adapter ),
			$this->repository,
			function ( $order_id, $delay, $deduplicate ) {
				$this->scheduled[] = array( $order_id, $delay, $deduplicate );
				return true;
			},
			$log,
			new FailedOrderSettlement( $this->api, $log )
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

	/**
	 * The return could not read the transaction (checks deferred); the
	 * customer's browser never came back.
	 */
	private function late_authorization_without_email( string $state ): void {
		$this->order->meta[ CustomerDetails::META_CHECKS_PENDING ] = 'yes';
		$this->orders->applied_email                               = '';
		$this->api->transaction['state']                           = $state;
	}

	public function test_a_late_authorization_without_email_fails_the_order_and_voids_the_reservation() {
		$this->late_authorization_without_email( 'AUTHORIZED' );

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertSame( 'no_email', FailedOrderSettlement::failedReason( $this->order ) );
		$this->assertSame( array( 'getSession', 'getTransaction', 'voidTransaction' ), $this->api->methods() );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertSame( 'wallee_express_checkout', $this->order->get_payment_method(), 'not linked' );
		$this->assertSame( array(), $this->adapter->links );
		$this->assertSame( array(), $this->scheduled, 'settled: no further attempt' );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'void of transaction 629017977 was requested automatically because this order failed (no_email)', $this->order->notes[0] );
		$this->assertNotContains( 'The payment is approved, but wallee returned no valid e-mail address for the customer.', $this->order->notes );
		$this->assertStringContainsString( 'error: Order 501: no e-mail address from wallee (transaction 629017977, state AUTHORIZED); order failed (no_email).', implode( "\n", $this->adapter->logs ) );
	}

	public function test_a_late_authorization_whose_void_fails_gets_the_action_required_note() {
		$this->late_authorization_without_email( 'AUTHORIZED' );
		$this->api->void = new ApiException( 'refused', 409 );

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertStringStartsWith( 'ACTION REQUIRED: wallee transaction 629017977 is AUTHORIZED', $this->order->notes[0] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_late_capture_without_email_fails_the_order_and_asks_for_a_refund() {
		$this->late_authorization_without_email( 'FULFILL' );

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
		$this->assertStringContainsString( 'A refund is needed', $this->order->notes[0] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_an_undecided_payment_without_email_fails_the_order_and_voids_once_authorized() {
		$this->late_authorization_without_email( 'PROCESSING' );

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
		$this->assertSame( array( array( 501, 10, false ) ), $this->scheduled, 'checked again' );

		$this->order->status             = 'failed';
		$this->api->calls                = array();
		$this->api->transaction['state'] = 'AUTHORIZED';
		$this->scheduler()->run( 501 );

		$this->assertSame( array( 'getTransaction', 'voidTransaction' ), $this->api->methods() );
		$this->assertCount( 1, $this->scheduled );
	}

	public function test_the_background_fails_an_order_without_email_also_without_the_pending_mark() {
		$this->orders->applied_email = '';

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed, 'an e-mail was never seen for this order' );
	}

	public function test_a_transaction_that_ended_stops_the_retries_with_one_clear_note() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		$this->adapter->gateway                         = null;
		$this->adapter->state                           = 'FAILED';

		$this->scheduler()->run( 501 );
		$this->scheduler()->run( 501 );

		$this->assertSame( array(), $this->scheduled, 'no further attempt' );
		$this->assertSame( array( 629017977 ), $this->adapter->reads, 'the second run reads nothing' );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'ended in state FAILED, so no money was taken', $this->order->notes[0] );
		$this->assertSame( '', $this->order->get_meta( OrderLinker::META_UNRESOLVED ) );
		$this->assertSame( array(), $this->orders->failed, 'the status is left to the merchant' );
	}

	public function test_an_unexpected_error_in_a_run_is_logged_by_class_and_retried() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		Functions\when( 'WC' )->alias(
			static function () {
				throw new \RuntimeException( 'secret detail' );
			}
		);

		$this->scheduler()->run( 501 );

		$this->assertSame( array( array( 501, 10, false ) ), $this->scheduled );
		$logs = implode( "\n", $this->adapter->logs );
		$this->assertStringContainsString( 'error: Order 501: link attempt 1 stopped by an error (RuntimeException).', $logs );
		$this->assertStringNotContainsString( 'secret detail', $logs );
	}

	public function test_an_unexpected_error_on_the_last_attempt_ends_with_the_usual_log() {
		$this->order->meta['_wallee_ec_transaction_id']    = 629017977;
		$this->order->meta[ LinkScheduler::META_ATTEMPTS ] = 5;
		Functions\when( 'WC' )->alias(
			static function () {
				throw new \RuntimeException( 'x' );
			}
		);

		$this->scheduler()->run( 501 );

		$this->assertSame( array(), $this->scheduled );
		$this->assertStringContainsString( 'not linked after 6 attempts', implode( "\n", $this->adapter->logs ) );
	}

	public function test_an_attempt_counter_that_cannot_be_saved_stops_the_chain_with_an_error_log() {
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		$this->adapter->gateway                         = null;
		$this->adapter->state                           = 'PROCESSING';
		$this->order->save_error                        = new \RuntimeException( 'db gone' );

		$this->scheduler()->run( 501 );

		$this->assertSame( array(), $this->scheduled, 'no endless chain without a counter' );
		$this->assertStringContainsString( 'error: Order 501: link attempt counter not saved (RuntimeException); no further automatic attempt.', implode( "\n", $this->adapter->logs ) );
	}

	public function test_checks_skipped_at_return_are_repeated_even_with_a_known_transaction() {
		$this->order->meta['_wallee_ec_transaction_id']              = 629017977;
		$this->order->meta[ CustomerDetails::META_CHECKS_PENDING ] = 'yes';
		$this->api->transaction['shippingAddress']                  = array( 'country' => 'DE' );

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( array( 'ACTION REQUIRED: check before shipping (destination_changed).' ), $this->order->notes );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method(), 'still linked' );
		$this->assertFalse( CustomerDetails::checksPending( $this->order ) );
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

	/**
	 * The order failed at return for an owner reason while the payment was undecided.
	 */
	private function failed_for_owner_reason(): void {
		$this->order->status                            = 'failed';
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		FailedOrderSettlement::markFailed( $this->order, 'coupon_invalid' );
	}

	public function test_a_failed_order_whose_payment_got_authorized_is_voided_and_not_linked() {
		$this->failed_for_owner_reason();

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 'getTransaction', 'voidTransaction' ), $this->api->methods() );
		$this->assertSame( 'wallee_express_checkout', $this->order->get_payment_method(), 'not linked' );
		$this->assertSame( array(), $this->scheduled, 'settled, no further attempt' );
		$this->assertSame( 'failed', $this->order->status );
		$this->assertStringContainsString( 'void of transaction 629017977 was requested automatically', $this->order->notes[0] );
	}

	public function test_a_failed_order_with_an_undecided_payment_is_checked_again_later() {
		$this->failed_for_owner_reason();
		$this->api->transaction['state'] = 'PROCESSING';

		$this->scheduler()->run( 501 );

		$this->assertSame( array( 'getTransaction' ), $this->api->methods(), 'no void while PROCESSING' );
		$this->assertSame( array( array( 501, 10, false ) ), $this->scheduled );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_a_failed_order_with_captured_money_asks_for_a_refund_and_stops() {
		$this->failed_for_owner_reason();
		$this->api->transaction['state'] = 'FULFILL';

		$this->scheduler()->run( 501 );

		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
		$this->assertSame( array(), $this->scheduled );
		$this->assertStringContainsString( 'A refund is needed', $this->order->notes[0] );
	}

	public function test_an_unreadable_transaction_of_a_failed_order_is_retried() {
		$this->failed_for_owner_reason();
		$this->api->transaction = new ApiException( 'down', 503 );

		$this->scheduler()->run( 501 );

		$this->assertSame( array( array( 501, 10, false ) ), $this->scheduled );
		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
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
