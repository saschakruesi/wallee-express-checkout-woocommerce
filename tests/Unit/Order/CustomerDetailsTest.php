<?php
/**
 * CustomerDetails tests: the checks repeated after payment.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;
use Wallee\ExpressCheckout\Tests\Support\FakeOrderService;
use Wallee\ExpressCheckout\Tests\Support\FakeSessionRepository;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;
use Wallee\ExpressCheckout\Tests\Unit\Http\ReturnHandlerTest;

require_once dirname( __DIR__, 2 ) . '/Support/wc-tax-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\CustomerDetails
 */
class CustomerDetailsTest extends TestCase {

	const NOTE_PREFIX = 'ACTION REQUIRED: check before shipping (';

	/** @var FakeSessionRepository */
	private $repository;

	/** @var FakeApiClient */
	private $api;

	/** @var FakeOrderService */
	private $orders;

	/** @var \WC_Order */
	private $order;

	/** @var string[] */
	private $logs = array();

	/** @var bool Whether taxes follow the customer's address. */
	private $tax_follows = false;

	/** @var string WooCommerce's "Calculate tax based on". */
	private $tax_based_on = 'shipping';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\WC_Tax::reset();
		$this->tax_based_on = 'shipping';
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'woocommerce_tax_based_on' === $name ) {
					return $this->tax_based_on;
				}

				return 'woocommerce_shipping_tax_class' === $name ? 'inherit' : $fallback;
			}
		);
		Functions\when( '__' )->returnArg();

		$this->logs        = array();
		$this->tax_follows = false;
		$this->repository  = new FakeSessionRepository(
			CartSnapshotTest::data(),
			array(
				'state'          => 'processed',
				'order_id'       => 501,
				'shipping_quote' => ReturnHandlerTest::quote(),
			)
		);
		$this->api         = new FakeApiClient();
		$this->orders      = new FakeOrderService();
		$this->order       = new \WC_Order( 501, 60.11 );

		$this->api->sessions    = array( ReturnHandlerTest::processing(), ReturnHandlerTest::processing() );
		$this->api->transaction = array(
			'id'              => 629017977,
			'state'           => 'AUTHORIZED',
			'shippingAddress' => array( 'country' => 'CH' ),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function details(): CustomerDetails {
		return new CustomerDetails(
			$this->api,
			$this->repository,
			$this->orders,
			function ( $message, $level = 'info' ) {
				$this->logs[] = $level . ': ' . $message;
			},
			$this->tax_follows
		);
	}

	private function record(): SessionRecord {
		return $this->repository->findByUuid( str_repeat( 'a', 64 ) );
	}

	private function deferred(): void {
		$this->details()->deferChecks( $this->order );
		$this->assertTrue( CustomerDetails::checksPending( $this->order ) );
	}

	public function test_checks_are_not_repeated_after_payment_when_they_ran_at_return() {
		$this->orders->coupon_reason = 'coupon_invalid';

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array(), $this->orders->coupon_checks );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_deferred_checks_that_pass_leave_no_note_and_are_done() {
		$this->deferred();

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array( 'buyer@example.test' ), $this->orders->coupon_checks );
		$this->assertSame( array(), $this->order->notes );
		$this->assertFalse( CustomerDetails::checksPending( $this->order ) );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_a_deferred_coupon_failure_keeps_the_order_with_one_action_required_note() {
		$this->deferred();
		$this->orders->coupon_reason = 'coupon_invalid';

		$this->details()->collectAfterPayment( $this->record(), $this->order );
		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array( self::NOTE_PREFIX . 'coupon_invalid).' ), $this->order->notes );
		$this->assertCount( 1, $this->orders->coupon_checks, 'not repeated on a retry' );
		$this->assertSame( array(), $this->orders->failed, 'a paid order is never failed here' );
		$this->assertFalse( CustomerDetails::checksPending( $this->order ) );
		$this->assertStringContainsString( 'warning: Order 501: check after payment failed (coupon_invalid); order kept with a note.', implode( "\n", $this->logs ) );
	}

	public function test_a_deferred_destination_failure_keeps_the_order_with_a_note() {
		$this->deferred();
		$this->api->transaction['shippingAddress'] = array( 'country' => 'DE' );

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array( self::NOTE_PREFIX . 'destination_changed).' ), $this->order->notes );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_a_deferred_tax_country_failure_counts_when_taxes_follow_the_address() {
		$this->tax_follows      = true;
		$this->repository->row['shipping_quote'] = null;
		$this->deferred();
		$this->api->transaction['shippingAddress'] = array( 'country' => 'AT' );

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array( self::NOTE_PREFIX . 'destination_changed).' ), $this->order->notes );
	}

	public function test_a_deferred_check_compares_the_billing_address_when_taxes_follow_it() {
		$this->tax_follows  = true;
		$this->tax_based_on = 'billing';
		$this->deferred();
		$this->api->transaction['billingAddress'] = array( 'country' => 'DE' );

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array( self::NOTE_PREFIX . 'destination_changed).' ), $this->order->notes );
	}

	public function test_a_deferred_coupon_check_that_throws_asks_for_a_manual_check() {
		$this->deferred();
		$this->orders->coupon_error = new \RuntimeException( 'db' );

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array( self::NOTE_PREFIX . 'coupon_check_failed).' ), $this->order->notes );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_coupons_already_counted_for_the_order_are_not_rechecked_but_flagged() {
		$this->deferred();
		$this->orders->usage_recorded = true;
		$this->orders->coupon_reason  = 'coupon_invalid';

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array(), $this->orders->coupon_checks, 'the count would include this order itself' );
		$this->assertSame( array( self::NOTE_PREFIX . 'coupons_not_rechecked).' ), $this->order->notes );
	}

	public function test_deferred_checks_wait_while_the_transaction_cannot_be_read() {
		$this->deferred();
		$this->api->sessions = array( ReturnHandlerTest::approved() );

		$this->assertNull( $this->details()->collectAfterPayment( $this->record(), $this->order ) );

		$this->assertTrue( CustomerDetails::checksPending( $this->order ), 'still pending for the next attempt' );
		$this->assertSame( array(), $this->orders->coupon_checks );
	}

	public function test_without_an_email_only_the_destination_is_rechecked() {
		$this->deferred();
		$this->orders->applied_email = '';
		$this->orders->coupon_reason = 'coupon_invalid';

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$this->assertSame( array(), $this->orders->coupon_checks );
		$this->assertSame( array( 'The payment is approved, but wallee returned no valid e-mail address for the customer.' ), $this->order->notes );
	}

	public function test_notes_and_logs_carry_no_address_or_email() {
		$this->deferred();
		$this->api->transaction['shippingAddress'] = array(
			'country'      => 'DE',
			'emailAddress' => 'someone@example.test',
			'street'       => 'Hauptstrasse 1',
		);

		$this->details()->collectAfterPayment( $this->record(), $this->order );

		$all = implode( "\n", array_merge( $this->logs, $this->order->notes ) );
		$this->assertStringNotContainsString( '@', $all );
		$this->assertStringNotContainsString( 'Hauptstrasse', $all );
	}
}
