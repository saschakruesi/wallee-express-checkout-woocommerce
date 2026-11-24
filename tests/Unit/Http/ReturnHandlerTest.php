<?php
/**
 * ReturnHandler tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Http\ReturnHandler;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionService;
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;
use Wallee\ExpressCheckout\Tests\Support\FakeOrderService;
use Wallee\ExpressCheckout\Tests\Support\FakeSessionRepository;
use Wallee\ExpressCheckout\Tests\Support\FakeWc;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

/**
 * @covers \Wallee\ExpressCheckout\Http\ReturnHandler
 * @covers \Wallee\ExpressCheckout\Order\CustomerDetails
 */
class ReturnHandlerTest extends TestCase {

	const UUID     = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const CART     = 'https://shop.test/cart/';
	const WALLEE   = 'https://app-wallee.com/s/4711/payment/chargeattempt/redirect/1?securityToken=t';
	const RECEIVED = 'https://shop.test/checkout/order-received/501/?key=wc_order_k';
	const TEST_NOTE = 'Test payment (wallee Express Checkout TEST environment). No real money was moved.';
	const NOT_CHARGED = 'You have not been charged.';
	const RELEASED    = 'If an amount was reserved, it will be released.';
	const TECHNICAL   = 'The express payment could not be completed because of a technical problem.';
	const PAID_NOTICE = 'Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order 501.';

	/** @var FakeSessionRepository */
	private $repository;

	/** @var FakeApiClient */
	private $api;

	/** @var FakeOrderService */
	private $orders;

	/** @var string[] */
	private $notices = array();

	/** @var string[] */
	private $logs = array();

	/** @var int[] Order IDs the background link was scheduled for. */
	private $scheduled = array();

	/** @var string Configured environment. */
	private $environment = 'TEST';

	/** @var bool Whether taxes follow the customer's address. */
	private $tax_follows = false;

	/** @var string Host of the configured wallee base URL. */
	private $wallee_host = 'app-wallee.com';

	/** @var FakeWc */
	private $wc;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wc_get_cart_url' )->justReturn( self::CART );
		$this->wc = new FakeWc();
		Functions\when( 'WC' )->justReturn( $this->wc );
		Functions\when( 'wc_add_notice' )->alias(
			function ( $message, $type ) {
				$this->notices[] = $type . ': ' . $message;
			}
		);

		$this->notices     = array();
		$this->logs        = array();
		$this->scheduled   = array();
		$this->environment = 'TEST';
		$this->tax_follows = false;
		$this->wallee_host = 'app-wallee.com';
		$this->repository = new FakeSessionRepository(
			CartSnapshotTest::data(),
			array( 'shipping_quote' => self::quote() )
		);
		$this->api        = new FakeApiClient();
		$this->orders     = new FakeOrderService();

		$this->api->sessions    = array( self::approved(), self::processing() );
		$this->api->transaction = array(
			'id'              => 629017977,
			'state'           => 'PROCESSING',
			'shippingAddress' => array( 'country' => 'CH' ),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function quote(): array {
		return array(
			'destination' => array(
				'country'  => 'CH',
				'state'    => '',
				'postcode' => '8000',
				'city'     => 'Zurich',
			),
			'rates'       => array(
				array(
					'id'        => 'flat_rate:1',
					'label'     => 'Flat rate',
					'cost'      => 7.5,
					'tax'       => 0.61,
					'tax_rates' => array(),
				),
			),
		);
	}

	public static function approved( array $over = array() ): array {
		return array_merge(
			array(
				'id'                       => 335,
				'state'                    => 'APPROVED',
				'authorizationEnvironment' => 'TEST',
				'currency'            => 'CHF',
				'linkedSpaceId'       => 4711,
				'authorizationAmount' => 60.11,
				'shippingOptions'     => array(
					array(
						'id'                 => 'flat_rate:1',
						'amountIncludingTax' => 8.11,
						'currency'           => 'CHF',
						'selected'           => true,
					),
				),
				'walletType'          => array(
					'id'   => 1,
					'name' => array( 'en-US' => 'Bogus Pay (Test)' ),
				),
			),
			$over
		);
	}

	public static function processing(): array {
		return array_merge(
			self::approved(),
			array(
				'state'             => 'PROCESSING',
				'linkedTransaction' => 629017977,
			)
		);
	}

	private function handler(): ReturnHandler {
		$log = function ( $message, $level = 'info' ) {
			$this->logs[] = $level . ': ' . $message;
		};

		return new ReturnHandler(
			$this->repository,
			UrlSigner::fromWordPress(),
			$this->api,
			$this->orders,
			new CustomerDetails( $this->api, $this->repository, $this->orders, $log ),
			function ( $order_id ) {
				$this->scheduled[] = $order_id;
				return true;
			},
			$log,
			$this->environment,
			$this->wallee_host,
			$this->tax_follows
		);
	}

	private function query( ?string $sig = null ): array {
		return array(
			'ec_session' => self::UUID,
			'sig'        => null === $sig ? UrlSigner::fromWordPress()->sign( self::UUID, 'return' ) : $sig,
		);
	}

	private function assertNothingCreated(): void {
		$this->assertSame( array(), $this->orders->created, 'no order' );
		$this->assertNotContains( 'processSession', $this->api->methods(), 'no process' );
	}

	public function test_happy_path_creates_order_processes_with_order_id_and_redirects_to_wallee() {
		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::WALLEE, $url );
		$this->assertCount( 1, $this->orders->created );
		$this->assertSame( 'flat_rate:1', $this->orders->created[0][1]['id'] );
		$this->assertSame( self::UUID, $this->orders->created[0][2] );
		$this->assertSame( 'Bogus Pay (Test)', $this->orders->created[0][3] );
		$this->assertSame( 335, $this->orders->created[0][4], 'wallee session ID stored with the order' );
		$this->assertNotSame( '', $this->orders->orders[501]->get_meta( '_wallee_ec_process_started' ) );
		$this->assertSame( array( 501 ), $this->scheduled, 'background link scheduled' );

		$process = $this->api->calls[1];
		$this->assertSame( 'processSession', $process[0] );
		$this->assertSame( 335, $process[1] );
		$this->assertSame( '501', $process[2] );

		$signer = UrlSigner::fromWordPress();
		foreach ( array( 3 => 'success', 4 => 'failure' ) as $index => $status ) {
			parse_str( (string) parse_url( $process[ $index ], PHP_URL_QUERY ), $args ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$this->assertSame( 'wallee_ec_result', $args['wc-api'] );
			$this->assertSame( $status, $args['status'] );
			$this->assertSame( self::UUID, $args['ec_session'] );
			$this->assertTrue( $signer->verify( self::UUID, 'result:' . $status, $args['sig'] ) );
			$this->assertFalse( $signer->verify( self::UUID, 'return', $args['sig'] ) );
			$other = 'success' === $status ? 'failure' : 'success';
			$this->assertFalse( $signer->verify( self::UUID, 'result:' . $other, $args['sig'] ) );
		}

		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( 501, $this->repository->row['order_id'] );
		$this->assertSame( 629017977, $this->repository->row['transaction_id'] );
		$this->assertSame( 629017977, $this->orders->orders[501]->get_meta( '_wallee_ec_transaction_id' ) );
		$this->assertSame( array( 501, $this->api->transaction, 0 ), $this->orders->applied[0] );
		$this->assertSame( array( 'buyer@example.test' ), $this->orders->coupon_checks, 'coupons re-checked with the e-mail' );
		$this->assertSame( array(), $this->notices );
	}

	public function test_order_id_is_stored_before_process() {
		$this->handler()->handle( $this->query() );

		$order_update = array_search( array( 'update', array( 'order_id' => 501 ) ), $this->repository->calls, true );
		$this->assertNotFalse( $order_update );
		$this->assertSame( array( 'getSession', 'processSession', 'getSession', 'getTransaction' ), $this->api->methods() );
	}

	public function test_the_redirect_host_is_allowed_for_this_request_only() {
		$handler = $this->handler();
		$url     = $handler->handle( $this->query() );

		$this->assertSame( self::WALLEE, $url );
		$this->assertSame( 'app-wallee.com', $handler->externalHost() );

		$this->repository->row['state'] = 'failed';
		$handler->handle( $this->query() );
		$this->assertNull( $handler->externalHost(), 'own URLs are not external' );
	}

	public function test_bad_signature_returns_cart_and_touches_nothing() {
		$url = $this->handler()->handle( $this->query( str_repeat( '0', 64 ) ) );

		$this->assertSame( self::CART, $url );
		$this->assertSame( array(), $this->repository->calls );
		$this->assertSame( array(), $this->api->calls );
		$this->assertNothingCreated();
		$this->assertCount( 1, $this->notices );
	}

	public function test_missing_parameters_return_cart() {
		$this->assertSame( self::CART, $this->handler()->handle( array() ) );
		$this->assertSame( array(), $this->api->calls );
	}

	public function test_signature_of_another_purpose_is_rejected() {
		$sig = UrlSigner::fromWordPress()->sign( self::UUID, 'result:success' );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query( $sig ) ) );
		$this->assertSame( array(), $this->repository->calls );
	}

	public function test_unknown_row_returns_cart() {
		$this->repository->row = null;

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->api->calls );
	}

	public function test_second_call_for_processed_row_returns_order_received_and_creates_nothing() {
		$this->repository->row['state']    = 'processed';
		$this->repository->row['order_id'] = 501;
		$this->orders->orders[501]         = new \WC_Order( 501, 60.11 );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::RECEIVED, $url );
		$this->assertSame( array(), $this->repository->calls );
		$this->assertSame( array(), $this->api->calls );
		$this->assertNothingCreated();
	}

	public function test_completed_row_returns_order_received() {
		$this->repository->row['state']    = 'completed';
		$this->repository->row['order_id'] = 501;
		$this->orders->orders[501]         = new \WC_Order( 501, 60.11 );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query() ) );
	}

	public function test_failed_or_ordering_row_returns_cart_with_notice() {
		foreach ( array( 'failed', 'ordering' ) as $state ) {
			$this->repository->row['state']    = $state;
			$this->repository->row['order_id'] = 501;
			$this->orders->orders[501]         = new \WC_Order( 501, 60.11 );

			$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		}
		$this->assertCount( 2, $this->notices );
		$this->assertStringNotContainsString( 'already being processed', $this->notices[0] );
		$this->assertSame( 'error: Your express payment is already being processed.', $this->notices[1] );
		$this->assertSame( array(), $this->api->calls );
	}

	public function test_lost_claim_rereads_the_row_and_creates_nothing() {
		$this->orders->orders[501]    = new \WC_Order( 501, 60.11 );
		$this->repository->concurrent = array(
			'state'    => 'processed',
			'order_id' => 501,
		);

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::RECEIVED, $url );
		$this->assertSame( array(), $this->api->calls );
		$this->assertNothingCreated();
	}

	public function test_lost_claim_while_other_request_is_ordering_returns_cart() {
		$this->repository->concurrent = array( 'state' => 'ordering' );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertNothingCreated();
		$this->assertSame( 'ordering', $this->repository->row['state'] );
		$this->assertSame( array( 'error: Your express payment is already being processed.' ), $this->notices );
	}

	public function test_session_not_approved_fails_row_with_expiry_notice() {
		$this->api->sessions = array( self::approved( array( 'state' => 'PROCESSING' ) ) );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
		$this->assertSame( array( 'error: Your express payment session has expired. You have not been charged. Please start the express payment again.' ), $this->notices );
		$this->assertSame( array( true ), $this->wc->session->cookies, 'a guest gets a session so the notice survives the redirect' );
	}

	public function test_session_read_error_fails_row() {
		$this->api->sessions = array( new ApiException( 'down', 503 ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
	}

	public function test_space_mismatch_fails_row() {
		$this->api->sessions = array( self::approved( array( 'linkedSpaceId' => 9999 ) ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
	}

	public function test_validator_reason_fails_row_without_order_or_process() {
		$this->api->sessions = array( self::approved( array( 'authorizationAmount' => 61.11 ) ) );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
		$this->assertCount( 1, $this->notices );
		$this->assertSame( 'error: Your cart changed while you were paying. You have not been charged. Please review your cart and try again.', $this->notices[0] );
		$this->assertStringContainsString( 'amount_mismatch', implode( "\n", $this->logs ) );
	}

	public function test_changed_shipping_price_fails_row() {
		$options                                   = self::approved()['shippingOptions'];
		$options[0]['amountIncludingTax']          = 9.11;
		$this->api->sessions                       = array( self::approved( array( 'shippingOptions' => $options, 'authorizationAmount' => 61.11 ) ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
	}

	public function test_stock_reason_fails_row_without_order_or_process() {
		$this->orders->stock_reason = 'out_of_stock';

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
	}

	public function test_order_creation_error_fails_row_without_process() {
		$this->orders->create_error = new \RuntimeException( 'A product of the cart no longer exists.' );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNotContains( 'processSession', $this->api->methods() );
	}

	public function test_order_total_differing_from_authorization_amount_fails_order_without_process() {
		$this->orders->next_total = 60.12;

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'amount_mismatch' ), $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNotContains( 'processSession', $this->api->methods() );
	}

	public function test_order_id_that_cannot_be_stored_fails_order_without_process() {
		$this->repository->failing_keys = array( 'order_id' );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'session_update_failed' ), $this->orders->failed );
		$this->assertNotContains( 'processSession', $this->api->methods() );
	}

	public function test_clear_process_refusal_fails_order_without_reread() {
		$this->api->process = new ApiException( 'refused', 422 );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertSame( array( 'getSession', 'processSession' ), $this->api->methods() );
		$this->assertCount( 1, $this->notices );
		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * @dataProvider uncertain_errors
	 *
	 * @param \Throwable $error Error thrown by process.
	 */
	public function test_uncertain_process_error_with_processing_session_keeps_the_order( \Throwable $error ) {
		$this->api->process = $error;

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::RECEIVED, $url, 'no redirect URL: order received page' );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( array( 'getSession', 'processSession', 'getSession', 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( 629017977, $this->orders->orders[501]->get_meta( '_wallee_ec_transaction_id' ) );
		$this->assertSame( array( 501 ), $this->scheduled );
		$this->assertSame( array(), $this->notices );
	}

	public function uncertain_errors(): array {
		return array(
			'no status'        => array( new ApiException( 'timeout' ) ),
			'server error'     => array( new ApiException( 'bad gateway', 502 ) ),
			'invalid response' => array( new ApiException( 'wallee API returned an invalid redirect URL.' ) ),
			'other throwable'  => array( new \RuntimeException( 'socket' ) ),
		);
	}

	public function test_uncertain_process_error_with_authorized_session_keeps_the_order() {
		$this->api->process  = new ApiException( 'timeout' );
		$this->api->sessions = array( self::approved(), self::approved( array( 'state' => 'AUTHORIZED' ) ), self::processing() );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_uncertain_process_error_with_approved_session_retries_process_once() {
		$this->api->process  = array( new ApiException( 'timeout' ), self::WALLEE );
		$this->api->sessions = array( self::approved(), self::approved(), self::processing() );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 'getSession', 'processSession', 'getSession', 'processSession', 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( '501', $this->api->calls[3][2], 'same merchant reference' );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'processed', $this->repository->row['state'] );
	}

	public function test_session_still_approved_after_the_retry_fails_the_order() {
		$this->api->process  = new ApiException( 'down', 503 );
		$this->api->sessions = array( self::approved() );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertSame( 2, count( array_keys( $this->api->methods(), 'processSession', true ) ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertStringContainsString( 'session APPROVED', implode( "\n", $this->logs ) );
	}

	public function test_failed_session_after_uncertain_error_fails_without_retry() {
		$this->api->process  = new ApiException( 'timeout' );
		$this->api->sessions = array( self::approved(), self::approved( array( 'state' => 'FAILED' ) ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertSame( 1, count( array_keys( $this->api->methods(), 'processSession', true ) ) );
	}

	public function test_unreadable_session_after_uncertain_error_fails_the_order() {
		$this->api->process  = new ApiException( 'timeout' );
		$this->api->sessions = array( self::approved(), new ApiException( 'down', 503 ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertStringContainsString( 'session unreadable', implode( "\n", $this->logs ) );
	}

	public function test_no_email_fails_the_order_and_does_not_send_the_customer_to_wallee() {
		$this->orders->applied_email = '';

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertSame( array(), $this->scheduled );
		$this->assertNull( $this->handler()->externalHost() );
		$this->assertSame( array( 'error: The wallet did not provide an e-mail address, which we need for your order. You have not been charged. Please use the regular checkout.' ), $this->notices );
		$this->assertSame( array( self::TEST_NOTE ), $this->orders->orders[501]->notes, 'nothing reserved yet: no portal action needed' );
	}

	/**
	 * @dataProvider money_moved_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_no_email_with_money_reserved_or_taken_asks_for_void_or_refund( string $state ) {
		$this->orders->applied_email         = '';
		$this->api->transaction['state'] = $state;

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$notes = $this->orders->orders[501]->notes;
		$this->assertCount( 2, $notes );
		$this->assertSame( self::TEST_NOTE, $notes[0] );
		$this->assertStringContainsString( 'ACTION REQUIRED', $notes[1] );
		$this->assertStringContainsString( '629017977', $notes[1] );
		$this->assertStringContainsString( 'no_email', $notes[1] );
		$this->assertStringContainsString( 'error: Return: order 501: wallee transaction 629017977 must be voided or refunded in the wallee portal', implode( "\n", $this->logs ) );
		$this->assertSame( array( 'error: ' . self::PAID_NOTICE ), $this->notices, 'the customer is told the payment was received' );
	}

	public function money_moved_states(): array {
		return array(
			'authorized' => array( 'AUTHORIZED' ),
			'completed'  => array( 'COMPLETED' ),
			'fulfill'    => array( 'FULFILL' ),
		);
	}

	public function test_no_email_applies_after_an_uncertain_process_too() {
		$this->orders->applied_email = '';
		$this->api->process          = new ApiException( 'timeout' );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
	}

	public function test_follow_up_read_error_still_redirects_to_wallee() {
		$this->api->sessions = array( self::approved(), new ApiException( 'timeout' ) );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::WALLEE, $url );
		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( array(), $this->notices );
	}

	public function test_transaction_read_error_keeps_transaction_id_and_redirects() {
		$this->api->transaction = new ApiException( 'timeout' );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::WALLEE, $url );
		$this->assertSame( 629017977, $this->repository->row['transaction_id'] );
		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( array(), $this->orders->failed, 'an unreadable transaction is not "no e-mail"' );
		$this->assertSame( array( 501 ), $this->scheduled );
	}

	public function test_processed_state_that_cannot_be_stored_still_redirects() {
		$this->repository->failing_keys = array( 'state' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
	}

	public function test_virtual_snapshot_needs_no_shipping_option() {
		$data                                = CartSnapshotTest::data();
		$data['items'][0]['needs_shipping'] = false;
		$this->repository                    = new FakeSessionRepository( $data );
		$session                             = self::approved(
			array(
				'shippingOptions'     => array(),
				'authorizationAmount' => 52.0,
			)
		);
		$this->api->sessions                 = array( $session, array_merge( $session, array( 'linkedTransaction' => 629017977 ) ) );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertNull( $this->orders->created[0][1] );
		$this->assertCount( 1, $this->orders->applied );
	}

	public function test_logs_carry_ids_and_states_only() {
		$this->handler()->handle( $this->query() );

		$all = implode( "\n", $this->logs );
		$this->assertStringContainsString( 'row 7', $all );
		$this->assertStringContainsString( 'order 501', $all );
		$this->assertStringContainsString( 'transaction 629017977', $all );
		$this->assertStringNotContainsString( 'securityToken', $all );
		$this->assertStringNotContainsString( '@', $all );
		$this->assertStringNotContainsString( self::UUID, $all );
	}

	public function test_a_session_approved_in_another_environment_is_rejected() {
		$this->api->sessions = array( self::approved( array( 'authorizationEnvironment' => 'PRODUCTION' ) ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
		$this->assertStringContainsString( 'rejected: environment_mismatch', implode( "\n", $this->logs ) );
	}

	public function test_a_session_without_an_environment_is_rejected() {
		$session = self::approved();
		unset( $session['authorizationEnvironment'] );
		$this->api->sessions = array( $session );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertNothingCreated();
	}

	public function test_a_test_payment_is_recorded_on_the_order() {
		$this->handler()->handle( $this->query() );

		$order = $this->orders->orders[501];
		$this->assertSame( 'TEST', $order->get_meta( '_wallee_ec_environment' ) );
		$this->assertSame( array( self::TEST_NOTE ), $order->notes );
		$this->assertSame( 'wallee Express Checkout', $order->get_payment_method_title(), 'title unchanged' );
	}

	public function test_a_production_payment_is_recorded_without_a_test_note() {
		$this->environment   = 'PRODUCTION';
		$this->api->sessions = array( self::approved( array( 'authorizationEnvironment' => 'PRODUCTION' ) ), self::processing() );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'PRODUCTION', $this->orders->orders[501]->get_meta( '_wallee_ec_environment' ) );
		$this->assertSame( array(), $this->orders->orders[501]->notes );
	}

	/**
	 * Asserts the order failed after process and the customer was not sent to wallee.
	 *
	 * @param string $reason Reason code.
	 * @param string $url    Returned URL.
	 */
	private function assertFailedBeforePayment( string $reason, string $url ): void {
		$this->assertSame( self::CART, $url );
		$this->assertSame( array( 501 => $reason ), $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertSame( array(), $this->scheduled );
		$this->assertCount( 1, $this->notices );
	}

	public function test_a_coupon_invalid_for_the_wallet_email_fails_the_order_before_payment() {
		$this->orders->coupon_reason = 'coupon_invalid';
		$handler                     = $this->handler();

		$this->assertFailedBeforePayment( 'coupon_invalid', $handler->handle( $this->query() ) );
		$this->assertNull( $handler->externalHost() );
		$this->assertSame( array( 'buyer@example.test' ), $this->orders->coupon_checks );
		$this->assertSame( 'error: The coupon in your cart cannot be used for this order. You have not been charged. Please remove the coupon or use the regular checkout.', $this->notices[0] );
	}

	public function test_a_coupon_failure_with_money_reserved_asks_for_void_or_refund() {
		$this->orders->coupon_reason     = 'coupon_invalid';
		$this->api->transaction['state'] = 'AUTHORIZED';

		$this->assertFailedBeforePayment( 'coupon_invalid', $this->handler()->handle( $this->query() ) );
		$this->assertStringContainsString( 'ACTION REQUIRED', $this->orders->orders[501]->notes[1] );
		$this->assertStringContainsString( 'coupon_invalid', $this->orders->orders[501]->notes[1] );
		$this->assertSame( 'error: ' . self::PAID_NOTICE, $this->notices[0] );
	}

	public function test_coupons_are_not_rechecked_without_an_email() {
		$this->orders->applied_email = '';
		$this->orders->coupon_reason = 'coupon_invalid';

		$this->assertFailedBeforePayment( 'no_email', $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->coupon_checks );
	}

	public function test_a_shipping_address_in_another_country_than_the_quote_fails_the_order() {
		$this->api->transaction['shippingAddress'] = array( 'country' => 'DE' );

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'error: Express checkout is not available for deliveries to Germany. You have not been charged. Please use the regular checkout to complete your order.', $this->notices[0] );
	}

	public function test_the_billing_country_counts_when_there_is_no_shipping_address() {
		$this->api->transaction = array(
			'id'             => 629017977,
			'state'          => 'PROCESSING',
			'billingAddress' => array( 'country' => 'AT' ),
		);

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
	}

	public function test_an_unknown_country_does_not_fail_the_order() {
		$this->api->transaction = array(
			'id'    => 629017977,
			'state' => 'PROCESSING',
		);

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
	}

	/**
	 * Snapshot without shipping (no quote) and a transaction in the given country.
	 *
	 * @param string $country Country of the wallet address.
	 */
	private function virtual_purchase( string $country ): void {
		$data                               = CartSnapshotTest::data();
		$data['items'][0]['needs_shipping'] = false;
		$this->repository                   = new FakeSessionRepository( $data );
		$session                            = self::approved(
			array(
				'shippingOptions'     => array(),
				'authorizationAmount' => 52.0,
			)
		);
		$this->api->sessions                = array( $session, array_merge( $session, array( 'linkedTransaction' => 629017977 ) ) );
		$this->api->transaction             = array(
			'id'             => 629017977,
			'state'          => 'PROCESSING',
			'billingAddress' => array( 'country' => $country ),
		);
	}

	public function test_an_address_outside_the_tax_country_fails_the_order_when_taxes_follow_the_address() {
		$this->tax_follows = true;
		$this->virtual_purchase( 'DE' );

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
	}

	public function test_an_address_outside_the_tax_country_is_accepted_when_taxes_do_not_follow_the_address() {
		$this->virtual_purchase( 'DE' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_the_tax_country_is_accepted_when_taxes_follow_the_address() {
		$this->tax_follows = true;
		$this->virtual_purchase( 'CH' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
	}

	private static function successUrl(): string {
		return SessionService::resultUrl( self::UUID, 'success' );
	}

	/**
	 * Asserts the order was kept after process and the customer goes to the success result URL.
	 *
	 * @param string $url Returned URL.
	 */
	private function assertKeptToSuccess( string $url ): void {
		$this->assertSame( self::successUrl(), $url );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( array( 501 ), $this->scheduled, 'background link scheduled' );
		$this->assertSame( array(), $this->notices );
	}

	public function test_a_redirect_to_the_wallee_host_is_followed_and_logged_as_wallee() {
		$handler = $this->handler();

		$this->assertSame( self::WALLEE, $handler->handle( $this->query() ) );
		$this->assertSame( 'app-wallee.com', $handler->externalHost() );
		$this->assertContains( 'info: Return: session row 7 redirect target: wallee.', $this->logs );
	}

	public function test_a_redirect_to_our_success_result_url_is_followed_without_failing_the_order() {
		$this->api->process     = self::successUrl();
		$this->api->transaction['state'] = 'FULFILL';
		$handler                = $this->handler();

		$this->assertKeptToSuccess( $handler->handle( $this->query() ) );
		$this->assertNull( $handler->externalHost(), 'an own URL' );
		$this->assertContains( 'info: Return: session row 7 redirect target: shop.', $this->logs );
	}

	public function test_a_redirect_to_our_failure_result_url_is_followed() {
		$failure            = SessionService::resultUrl( self::UUID, 'failure' );
		$this->api->process = $failure;

		$this->assertSame( $failure, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed, 'the result handler decides' );
	}

	public function test_another_shop_url_is_replaced_by_our_success_result_url() {
		$this->api->process = 'https://shop.test/some/page/?next=elsewhere';
		$handler            = $this->handler();

		$this->assertKeptToSuccess( $handler->handle( $this->query() ) );
		$this->assertNull( $handler->externalHost() );
		$this->assertStringNotContainsString( 'some/page', implode( "\n", $this->logs ) );
	}

	public function test_a_foreign_host_with_a_paid_transaction_keeps_the_order_and_goes_to_the_success_url() {
		$this->api->process              = 'https://www.paypal.example/checkoutnow?token=EC-SECRET';
		$this->api->transaction['state'] = 'FULFILL';
		$handler                         = $this->handler();

		$this->assertKeptToSuccess( $handler->handle( $this->query() ) );
		$this->assertNull( $handler->externalHost(), 'never sent to the foreign host' );
		$all = implode( "\n", $this->logs );
		$this->assertStringContainsString( 'info: Return: session row 7 redirect target: other:www.paypal.example.', $all );
		$this->assertStringContainsString( 'redirect_host_unexpected (host www.paypal.example, transaction 629017977, state FULFILL)', $all );
		$this->assertStringNotContainsString( 'checkoutnow', $all );
		$this->assertStringNotContainsString( 'SECRET', $all );
		$this->assertSame( array( self::TEST_NOTE ), $this->orders->orders[501]->notes, 'no ACTION REQUIRED note' );
	}

	public function test_a_foreign_host_with_a_processing_session_keeps_the_order() {
		$this->api->process     = 'https://wallet.example/done';
		$this->api->transaction = new ApiException( 'timeout' );

		$this->assertKeptToSuccess( $this->handler()->handle( $this->query() ) );
	}

	public function test_a_foreign_host_without_any_sign_of_a_payment_fails_the_order() {
		$this->api->process     = 'https://wallet.example/done';
		$this->api->transaction = new ApiException( 'timeout' );
		$this->api->sessions    = array( self::approved(), array_merge( self::processing(), array( 'state' => 'FAILED' ) ) );
		$handler                = $this->handler();

		$this->assertFailedBeforePayment( 'redirect_host_unexpected', $handler->handle( $this->query() ) );
		$this->assertNull( $handler->externalHost() );
		$this->assertSame( array( self::TEST_NOTE ), $this->orders->orders[501]->notes );
	}

	public function test_a_configured_wallee_host_that_differs_from_the_redirect_never_fails_a_payment_under_way() {
		$this->wallee_host = 'checkout.example.org';
		$handler           = $this->handler();

		$this->assertKeptToSuccess( $handler->handle( $this->query() ) );
		$this->assertNull( $handler->externalHost() );
		$this->assertStringContainsString( 'redirect target: other:app-wallee.com', implode( "\n", $this->logs ) );
	}

	/**
	 * @dataProvider money_moved_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_a_technical_error_after_process_never_fails_a_paid_order( string $state ) {
		$this->orders->coupon_error      = new \RuntimeException( 'db' );
		$this->api->transaction['state'] = $state;

		$this->assertKeptToSuccess( $this->handler()->handle( $this->query() ) );
		$this->assertStringContainsString( 'coupon_check_failed, but transaction 629017977 is ' . $state . '; order kept', implode( "\n", $this->logs ) );
	}

	public function test_a_technical_error_after_process_fails_an_unpaid_order() {
		$this->orders->coupon_error = new \RuntimeException( 'db' );

		$this->assertFailedBeforePayment( 'coupon_check_failed', $this->handler()->handle( $this->query() ) );
	}

	public function test_the_redirect_host_is_compared_without_case() {
		$this->wallee_host = 'App-Wallee.com';

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
	}

	public function test_a_return_link_older_than_two_hours_goes_to_the_cart_and_touches_nothing() {
		$this->repository->row['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 7201 );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->repository->calls );
		$this->assertSame( array(), $this->api->calls );
		$this->assertCount( 1, $this->notices );
	}

	public function test_an_old_return_link_of_a_processed_row_does_not_lead_to_the_order() {
		$this->repository->row['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 7201 );
		$this->repository->row['state']      = 'processed';
		$this->repository->row['order_id']   = 501;
		$this->orders->orders[501]           = new \WC_Order( 501, 60.11 );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
	}
	public function test_a_bad_link_tells_the_customer_a_reserved_amount_will_be_released() {
		$this->handler()->handle( $this->query( str_repeat( '0', 64 ) ) );

		$this->assertSame( array( 'error: ' . self::TECHNICAL . ' ' . self::RELEASED . ' Please try again or use the regular checkout.' ), $this->notices );
	}

	public function test_an_expired_return_link_says_not_charged_only_for_a_row_never_processed() {
		$this->repository->row['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 7201 );
		$this->handler()->handle( $this->query() );

		$this->repository->row['state'] = 'processed';
		$this->handler()->handle( $this->query() );

		$this->assertSame(
			array(
				'error: Your express payment session has expired. ' . self::NOT_CHARGED . ' Please start the express payment again.',
				'error: Your express payment session has expired. ' . self::RELEASED . ' Please start the express payment again.',
			),
			$this->notices
		);
	}

	public function test_a_failed_row_returned_to_again_gets_the_uncertain_wording() {
		$this->repository->row['state'] = 'failed';

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: ' . self::TECHNICAL . ' ' . self::RELEASED . ' Please try again or use the regular checkout.' ), $this->notices );
	}

	public function test_out_of_stock_has_its_own_notice() {
		$this->orders->stock_reason = 'out_of_stock';

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: An item in your order is no longer available in the requested quantity. You have not been charged. Please review your cart.' ), $this->notices );
	}

	public function test_a_changed_shipping_option_gets_the_destination_notice_without_a_country() {
		$options                          = self::approved()['shippingOptions'];
		$options[0]['id']                 = 'flat_rate:9';
		$this->api->sessions              = array( self::approved( array( 'shippingOptions' => $options ) ) );

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: Express checkout is not available for the selected delivery address. You have not been charged. Please use the regular checkout to complete your order.' ), $this->notices );
		$this->assertStringContainsString( 'rejected: shipping_changed', implode( "\n", $this->logs ) );
	}

	public function test_a_tax_rate_change_during_order_creation_gets_the_cart_changed_notice() {
		$this->orders->create_error = new \RuntimeException( 'tax_rate_changed' );

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: Your cart changed while you were paying. You have not been charged. Please review your cart and try again.' ), $this->notices );
	}

	public function test_a_technical_problem_before_process_says_not_charged() {
		$this->api->sessions = array( new ApiException( 'down', 503 ) );

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: ' . self::TECHNICAL . ' ' . self::NOT_CHARGED . ' Please try again or use the regular checkout.' ), $this->notices );
	}

	public function test_a_failed_process_call_gets_the_uncertain_wording() {
		$this->api->process = new ApiException( 'refused', 422 );

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: ' . self::TECHNICAL . ' ' . self::RELEASED . ' Please try again or use the regular checkout.' ), $this->notices );
	}

	public function test_a_foreign_host_without_a_readable_transaction_gets_the_uncertain_wording() {
		$this->api->process     = 'https://wallet.example/done';
		$this->api->transaction = new ApiException( 'timeout' );
		$this->api->sessions    = array( self::approved(), array_merge( self::processing(), array( 'state' => 'FAILED' ) ) );

		$this->handler()->handle( $this->query() );

		$this->assertSame( array( 'error: ' . self::TECHNICAL . ' ' . self::RELEASED . ' Please try again or use the regular checkout.' ), $this->notices );
	}

	public function test_a_destination_change_with_money_taken_tells_the_customer_the_payment_was_received() {
		$this->api->transaction['shippingAddress'] = array( 'country' => 'DE' );
		$this->api->transaction['state']           = 'FULFILL';

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'error: ' . self::PAID_NOTICE, $this->notices[0] );
		$this->assertStringContainsString( 'ACTION REQUIRED', $this->orders->orders[501]->notes[1] );
	}

	public function test_an_existing_customer_session_is_not_restarted() {
		$this->wc = new FakeWc( true );
		Functions\when( 'WC' )->justReturn( $this->wc );
		$this->orders->stock_reason = 'out_of_stock';

		$this->handler()->handle( $this->query() );

		$this->assertSame( array(), $this->wc->session->cookies );
		$this->assertCount( 1, $this->notices );
	}
}

