<?php
/**
 * FailedOrderSettlement tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Order\FailedOrderSettlement;
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\FailedOrderSettlement
 */
class FailedOrderSettlementTest extends TestCase {

	const TRANSACTION = 629017977;

	/** @var FakeApiClient */
	private $api;

	/** @var \WC_Order */
	private $order;

	/** @var string[] */
	private $logs = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->logs  = array();
		$this->api   = new FakeApiClient();
		$this->order = new \WC_Order( 501, 60.11 );
		$this->order->status = 'failed';
		FailedOrderSettlement::markFailed( $this->order, 'coupon_invalid' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @var bool|null|\Throwable Answer of the void claim. */
	private $claim = true;

	/** @var int[] Order IDs the void claim was asked for. */
	private $claims = array();

	private function settlement(): FailedOrderSettlement {
		return new FailedOrderSettlement(
			$this->api,
			function ( $message, $level = 'info' ) {
				$this->logs[] = $level . ': ' . $message;
			},
			function ( int $order_id ) {
				$this->claims[] = $order_id;
				if ( $this->claim instanceof \Throwable ) {
					throw $this->claim;
				}

				return $this->claim;
			}
		);
	}

	private function voids(): array {
		return array_values(
			array_filter(
				$this->api->calls,
				static function ( $call ) {
					return 'voidTransaction' === $call[0];
				}
			)
		);
	}

	public function test_the_failure_reason_is_kept_on_the_order() {
		$this->assertSame( 'coupon_invalid', FailedOrderSettlement::failedReason( $this->order ) );
		$this->assertSame( '', FailedOrderSettlement::failedReason( new \WC_Order( 502 ) ) );
	}

	public function test_an_authorization_is_voided_once_with_a_private_note() {
		$settlement = $this->settlement();

		$this->assertSame( FailedOrderSettlement::RELEASED, $settlement->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( FailedOrderSettlement::RELEASED, $settlement->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ), 'repeated call' );

		$this->assertSame( array( array( 'voidTransaction', self::TRANSACTION ) ), $this->voids(), 'one void request' );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'void of transaction 629017977 was requested automatically', $this->order->notes[0] );
		$this->assertStringContainsString( 'coupon_invalid', $this->order->notes[0] );
		$this->assertStringNotContainsString( 'ACTION REQUIRED', $this->order->notes[0] );
		$this->assertSame( 'failed', $this->order->status, 'order stays failed' );
	}

	public function test_a_failed_void_asks_for_a_manual_void_and_is_not_repeated() {
		$this->api->void = new ApiException( 'wallee API request POST /v2.0/payment/transactions/629017977/void-online failed (HTTP 409).', 409 );
		$settlement      = $this->settlement();

		$this->assertSame( FailedOrderSettlement::RESERVED, $settlement->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( FailedOrderSettlement::RESERVED, $settlement->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );

		$this->assertCount( 1, $this->voids() );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringStartsWith( 'ACTION REQUIRED:', $this->order->notes[0] );
		$this->assertStringContainsString( 'void failed', $this->order->notes[0] );
		$this->assertStringContainsString( 'Void transaction 629017977 manually in the wallee portal', $this->order->notes[0] );
		$log = implode( "\n", $this->logs );
		$this->assertStringContainsString( 'error: Order 501: void of transaction 629017977 failed (Wallee\ExpressCheckout\Api\ApiException).', $log );
		$this->assertStringNotContainsString( 'HTTP 409', $log, 'class only' );
	}

	public function test_the_void_is_claimed_atomically_for_the_order_before_it_is_sent() {
		$this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' );

		$this->assertSame( array( 501 ), $this->claims );
		$this->assertCount( 1, $this->voids() );
	}

	public function test_a_void_claimed_by_a_parallel_request_is_not_sent_again_and_adds_no_note() {
		$this->claim = false;

		$this->assertSame( FailedOrderSettlement::RESERVED, $this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( array(), $this->voids() );
		$this->assertSame( array(), $this->order->notes );
		$this->assertStringContainsString( 'Order 501: void of transaction 629017977 already claimed by another request; not sent again.', implode( "\n", $this->logs ) );
	}

	/**
	 * @dataProvider unusable_claims
	 *
	 * @param mixed $claim Claim answer.
	 */
	public function test_a_void_claim_that_cannot_be_stored_sends_no_void_and_asks_for_a_manual_void( $claim ) {
		$this->claim = $claim;

		$this->assertSame( FailedOrderSettlement::RESERVED, $this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( array(), $this->voids() );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'Void transaction 629017977 manually in the wallee portal', $this->order->notes[0] );
	}

	public function unusable_claims(): array {
		return array(
			'database error' => array( null ),
			'exception'      => array( new \RuntimeException( 'db' ) ),
		);
	}

	public function test_an_authorization_of_an_order_that_is_no_longer_failed_is_not_voided() {
		$this->order->status = 'processing';
		$settlement          = $this->settlement();

		$this->assertSame( FailedOrderSettlement::RESERVED, $settlement->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( FailedOrderSettlement::RESERVED, $settlement->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ), 'repeated call' );

		$this->assertSame( array(), $this->voids() );
		$this->assertSame( array(), $this->claims );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringStartsWith( 'ACTION REQUIRED: wallee transaction 629017977 is AUTHORIZED (money reserved), but this order failed (coupon_invalid) and its status was changed since', $this->order->notes[0] );
		$this->assertStringContainsString( 'not voided automatically', $this->order->notes[0] );
	}

	/**
	 * @dataProvider taken_states
	 *
	 * @param string $state State after the refused void.
	 */
	public function test_a_void_refused_because_the_payment_was_taken_meanwhile_asks_for_a_refund( string $state ) {
		$this->api->void        = new ApiException( 'refused', 409 );
		$this->api->transaction = array(
			'id'    => self::TRANSACTION,
			'state' => $state,
		);

		$this->assertSame( FailedOrderSettlement::TAKEN, $this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( array( 'voidTransaction', 'getTransaction' ), $this->api->methods() );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'A refund is needed', $this->order->notes[0] );
		$this->assertStringNotContainsString( 'manually', $this->order->notes[0] );
	}

	public function taken_states(): array {
		return array(
			'completed' => array( 'COMPLETED' ),
			'fulfill'   => array( 'FULFILL' ),
		);
	}

	public function test_a_refused_void_whose_transaction_cannot_be_read_asks_for_a_manual_void() {
		$this->api->void        = new ApiException( 'refused', 409 );
		$this->api->transaction = new ApiException( 'down', 503 );

		$this->assertSame( FailedOrderSettlement::RESERVED, $this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertStringContainsString( 'Void transaction 629017977 manually in the wallee portal', $this->order->notes[0] );
	}

	public function test_a_void_answered_with_state_failed_counts_as_failed() {
		$this->api->void = array(
			'id'    => 8801,
			'state' => 'FAILED',
		);

		$this->assertSame( FailedOrderSettlement::RESERVED, $this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertStringStartsWith( 'ACTION REQUIRED:', $this->order->notes[0] );
	}

	public function test_a_void_whose_flag_cannot_be_saved_is_not_sent() {
		$order = new class( 501, 60.11 ) extends \WC_Order {
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function save() {
				throw new \RuntimeException( 'db' );
			}
		};
		$order->meta[ FailedOrderSettlement::META_REASON ] = 'no_email';

		$this->assertSame( FailedOrderSettlement::RESERVED, $this->settlement()->settle( $order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( array(), $this->voids() );
		$this->assertStringStartsWith( 'ACTION REQUIRED:', $order->notes[0] );
	}

	/**
	 * @dataProvider captured_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_captured_money_is_never_voided_and_asks_for_a_refund_once( string $state ) {
		$settlement = $this->settlement();

		$this->assertSame( FailedOrderSettlement::TAKEN, $settlement->settle( $this->order, self::TRANSACTION, $state ) );
		$this->assertSame( FailedOrderSettlement::TAKEN, $settlement->settle( $this->order, self::TRANSACTION, $state ) );

		$this->assertSame( array(), $this->voids() );
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringStartsWith( 'ACTION REQUIRED:', $this->order->notes[0] );
		$this->assertStringContainsString( 'A refund is needed', $this->order->notes[0] );
		$this->assertStringContainsString( $state, $this->order->notes[0] );
	}

	public function captured_states(): array {
		return array(
			'completed' => array( 'COMPLETED' ),
			'fulfill'   => array( 'FULFILL' ),
		);
	}

	/**
	 * @dataProvider undecided_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_an_undecided_payment_is_left_open( string $state ) {
		$this->assertSame( FailedOrderSettlement::OPEN, $this->settlement()->settle( $this->order, self::TRANSACTION, $state ) );
		$this->assertSame( array(), $this->voids() );
		$this->assertSame( array(), $this->order->notes );
	}

	public function undecided_states(): array {
		return array(
			'processing' => array( 'PROCESSING' ),
			'pending'    => array( 'PENDING' ),
			'confirmed'  => array( 'CONFIRMED' ),
			'create'     => array( 'CREATE' ),
			'unknown'    => array( '' ),
		);
	}

	public function test_a_payment_that_ended_without_money_needs_nothing_but_one_note() {
		foreach ( array( 'FAILED', 'DECLINE', 'VOIDED' ) as $state ) {
			$order         = new \WC_Order( 503, 1.0 );
			$order->status = 'failed';
			FailedOrderSettlement::markFailed( $order, 'no_email' );

			$this->assertSame( FailedOrderSettlement::NONE, $this->settlement()->settle( $order, self::TRANSACTION, $state ), $state );
			$this->assertSame( FailedOrderSettlement::NONE, $this->settlement()->settle( $order, self::TRANSACTION, $state ), $state . ' again' );
			$this->assertCount( 1, $order->notes, $state );
			$this->assertStringContainsString( 'ended in state ' . $state . ', so no money was taken', $order->notes[0] );
		}
		$this->assertSame( array(), $this->voids() );
	}

	public function test_a_void_that_went_through_gets_no_second_note() {
		$this->order->meta['_wallee_ec_void'] = 'requested';

		$this->assertSame( FailedOrderSettlement::RELEASED, $this->settlement()->settle( $this->order, self::TRANSACTION, 'VOIDED' ) );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_an_order_without_a_failure_reason_is_never_voided() {
		$order = new \WC_Order( 502, 10.0 );

		$this->assertSame( FailedOrderSettlement::NONE, $this->settlement()->settle( $order, self::TRANSACTION, 'AUTHORIZED' ) );
		$this->assertSame( array(), $this->voids() );
	}

	public function test_a_failed_order_is_settled_from_a_fresh_transaction_read() {
		$this->order->meta['_wallee_ec_transaction_id'] = self::TRANSACTION;
		$this->api->transaction                         = array(
			'id'    => self::TRANSACTION,
			'state' => 'AUTHORIZED',
		);

		$this->assertSame( FailedOrderSettlement::RELEASED, $this->settlement()->settleFailedOrder( $this->order ) );
		$this->assertSame( array( 'getTransaction', 'voidTransaction' ), $this->api->methods() );
	}

	public function test_a_failed_order_without_a_known_transaction_stays_open_without_a_call() {
		$this->assertSame( FailedOrderSettlement::OPEN, $this->settlement()->settleFailedOrder( $this->order ) );
		$this->assertSame( array(), $this->api->calls );
	}

	public function test_a_revived_failed_express_order_gets_a_note() {
		FailedOrderSettlement::noteRevival( 501, 'failed', 'on-hold', $this->order );

		$this->assertCount( 1, $this->order->notes );
		$this->assertStringStartsWith( 'ACTION REQUIRED:', $this->order->notes[0] );
		$this->assertStringContainsString( 'coupon_invalid', $this->order->notes[0] );
		$this->assertStringContainsString( 'on-hold', $this->order->notes[0] );
	}

	public function test_a_revival_note_that_cannot_be_added_never_breaks_the_status_change() {
		$order = new class( 501, 60.11 ) extends \WC_Order {
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function add_order_note( $note ) {
				throw new \RuntimeException( 'db' );
			}
		};
		FailedOrderSettlement::markFailed( $order, 'coupon_invalid' );

		FailedOrderSettlement::noteRevival( 501, 'failed', 'processing', $order );

		$this->addToAssertionCount( 1 );
	}

	public function test_other_status_changes_get_no_note() {
		FailedOrderSettlement::noteRevival( 501, 'failed', 'cancelled', $this->order );
		FailedOrderSettlement::noteRevival( 501, 'failed', 'refunded', $this->order );
		FailedOrderSettlement::noteRevival( 501, 'pending', 'processing', $this->order );

		$plain = new \WC_Order( 502 );
		FailedOrderSettlement::noteRevival( 502, 'failed', 'processing', $plain );

		$other              = new \WC_Order( 503 );
		$other->created_via = 'checkout';
		$other->meta[ FailedOrderSettlement::META_REASON ] = 'coupon_invalid';
		FailedOrderSettlement::noteRevival( 503, 'failed', 'processing', $other );
		FailedOrderSettlement::noteRevival( 501, 'failed', 'processing', null );

		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( array(), $plain->notes );
		$this->assertSame( array(), $other->notes );
	}

	public function test_notes_and_logs_carry_no_personal_data() {
		$this->order->billing_email = 'buyer@example.test';
		$this->settlement()->settle( $this->order, self::TRANSACTION, 'AUTHORIZED' );
		$this->api->void = new ApiException( 'x buyer@example.test', 500 );
		$other           = new \WC_Order( 502 );
		FailedOrderSettlement::markFailed( $other, 'no_email' );
		$this->settlement()->settle( $other, self::TRANSACTION, 'AUTHORIZED' );

		foreach ( array_merge( $this->order->notes, $other->notes, $this->logs ) as $line ) {
			$this->assertStringNotContainsString( '@', $line );
		}
	}
}
