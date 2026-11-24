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
use Wallee\ExpressCheckout\Order\FailedOrderSettlement;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionService;
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;
use Wallee\ExpressCheckout\Tests\Support\FakeOrderService;
use Wallee\ExpressCheckout\Tests\Support\FakeSessionRepository;
use Wallee\ExpressCheckout\Tests\Support\FakeWc;
use Wallee\ExpressCheckout\Tests\Support\HoldingOrder;
use Wallee\ExpressCheckout\Tests\Support\ThrowingMetaOrder;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;
use Wallee\ExpressCheckout\Tests\Unit\Order\CustomerDetailsTest;

require_once dirname( __DIR__, 2 ) . '/Support/wc-tax-stub.php';
require_once dirname( __DIR__, 2 ) . '/Support/wc-coupon-stub.php';

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
	const RELEASING   = 'The reservation on your payment method is being released. You will not be charged.';

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

	/** @var string WooCommerce's "Calculate tax based on". */
	private $tax_based_on = 'shipping';

	/** @var string Host of the configured wallee base URL. */
	private $wallee_host = 'app-wallee.com';

	/** @var FakeWc */
	private $wc;

	/** @var bool Whether express checkout may run (main plugin usable, SDK shim available). */
	private $available = true;

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
		$this->available   = true;
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
			new CustomerDetails( $this->api, $this->repository, $this->orders, $log, $this->tax_follows ),
			function ( $order_id ) {
				$this->scheduled[] = $order_id;
				return true;
			},
			$log,
			$this->environment,
			$this->wallee_host,
			$this->tax_follows,
			new FailedOrderSettlement( $this->api, $log ),
			function () {
				return $this->available;
			}
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

	public function test_without_the_sdk_shim_the_return_is_refused_before_any_wallee_call() {
		$this->available = false;

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertNothingCreated();
		$this->assertSame( array(), $this->api->calls, 'no wallee call at all' );
		$this->assertSame( 'created', $this->repository->row['state'], 'not claimed' );
		$this->assertSame( array( 'error: Express checkout is currently not available. You have not been charged. Please use the regular checkout.' ), $this->notices );
		$this->assertContains( 'warning: Return: express checkout is not available (main plugin or SDK compatibility shim); session not processed.', $this->logs );
	}

	/**
	 * @dataProvider processed_states
	 *
	 * @param string $state Row state.
	 */
	public function test_a_reload_of_a_processed_return_without_the_shim_goes_to_the_order( string $state ) {
		$this->available                   = false;
		$this->repository->row['state']    = $state;
		$this->repository->row['order_id'] = 501;
		$this->orders->orders[501]         = new \WC_Order( 501, 60.11 );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->api->calls );
		$this->assertSame( array(), $this->notices, 'no "not charged" message' );
	}

	public function processed_states(): array {
		return array(
			'processed' => array( 'processed' ),
			'completed' => array( 'completed' ),
		);
	}

	/**
	 * @dataProvider moved_on_states
	 *
	 * @param string $state Row state.
	 */
	public function test_a_return_that_moved_on_without_the_shim_never_says_not_charged( string $state ) {
		$this->available                   = false;
		$this->repository->row['state']    = $state;
		$this->repository->row['order_id'] = 501;
		$this->orders->orders[501]         = new \WC_Order( 501, 60.11 );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->api->calls );
		$this->assertCount( 1, $this->notices );
		$this->assertStringNotContainsString( 'not been charged', $this->notices[0] );
	}

	public function moved_on_states(): array {
		return array(
			'ordering' => array( 'ordering' ),
			'failed'   => array( 'failed' ),
		);
	}

	public function test_a_bad_signature_is_still_reported_as_such_without_the_shim() {
		$this->available = false;

		$this->handler()->handle( $this->query( 'bad' ) );

		$this->assertContains( 'warning: Return: invalid or missing signature.', $this->logs );
	}

	public function test_a_refusal_of_the_retry_for_a_session_that_moved_on_counts_as_already_processed() {
		$this->api->process  = array( new ApiException( 'timeout' ), new ApiException( 'already processed', 409 ) );
		$this->api->sessions = array( self::approved(), self::approved(), self::processing(), self::processing() );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::RECEIVED, $url );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( array( 'getSession', 'processSession', 'getSession', 'processSession', 'getSession', 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( 629017977, $this->orders->orders[501]->get_meta( '_wallee_ec_transaction_id' ) );
		$this->assertSame( array( 501 ), $this->scheduled );
		$this->assertStringContainsString( 'process attempt 2 refused (Wallee\ExpressCheckout\Api\ApiException HTTP 409); reading the session', implode( "\n", $this->logs ) );
	}

	public function test_a_refusal_of_the_retry_for_a_session_still_approved_is_a_real_refusal() {
		$this->api->process  = array( new ApiException( 'timeout' ), new ApiException( 'invalid', 422 ) );
		$this->api->sessions = array( self::approved(), self::approved(), self::approved() );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertSame( 2, count( array_keys( $this->api->methods(), 'processSession', true ) ) );
		$this->assertStringContainsString( 'HTTP 422, session APPROVED', implode( "\n", $this->logs ) );
	}

	public function test_a_refusal_of_the_retry_with_an_unreadable_session_fails_the_order() {
		$this->api->process  = array( new ApiException( 'timeout' ), new ApiException( 'conflict', 409 ) );
		$this->api->sessions = array( self::approved(), self::approved(), new ApiException( 'down', 503 ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertStringContainsString( 'session unreadable', implode( "\n", $this->logs ) );
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
		$this->assertSame( array( 501 ), $this->scheduled, 'payment still undecided: checked again in the background' );
		$this->assertSame( 'no_email', FailedOrderSettlement::failedReason( $this->orders->orders[501] ) );
		$this->assertNotContains( 'voidTransaction', $this->api->methods(), 'nothing to void while PROCESSING' );
		$this->assertNull( $this->handler()->externalHost() );
		$this->assertSame( array( 'error: The wallet did not provide an e-mail address, which we need for your order. You have not been charged. Please use the regular checkout.' ), $this->notices );
		$this->assertSame( array( self::TEST_NOTE ), $this->orders->orders[501]->notes, 'nothing reserved yet: no portal action needed' );
	}

	/**
	 * @dataProvider captured_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_no_email_with_money_taken_asks_for_a_refund( string $state ) {
		$this->orders->applied_email         = '';
		$this->api->transaction['state'] = $state;

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertNotContains( 'voidTransaction', $this->api->methods(), 'captured money is never voided' );
		$notes = $this->orders->orders[501]->notes;
		$this->assertCount( 2, $notes );
		$this->assertSame( self::TEST_NOTE, $notes[0] );
		$this->assertStringContainsString( 'ACTION REQUIRED', $notes[1] );
		$this->assertStringContainsString( 'A refund is needed', $notes[1] );
		$this->assertStringContainsString( '629017977', $notes[1] );
		$this->assertStringContainsString( 'no_email', $notes[1] );
		$this->assertStringContainsString( 'error: Order 501: transaction 629017977 is ' . $state . ' but the order failed (no_email); a refund is needed in the wallee portal.', implode( "\n", $this->logs ) );
		$this->assertSame( array( 'error: ' . self::PAID_NOTICE ), $this->notices, 'the customer is told the payment was received' );
		$this->assertSame( array(), $this->scheduled );
	}

	public function captured_states(): array {
		return array(
			'completed' => array( 'COMPLETED' ),
			'fulfill'   => array( 'FULFILL' ),
		);
	}

	public function test_no_email_with_an_authorization_voids_it_and_tells_the_customer() {
		$this->orders->applied_email         = '';
		$this->api->transaction['state'] = 'AUTHORIZED';

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );

		$this->assertSame( array( 501 => 'no_email' ), $this->orders->failed );
		$this->assertContains( array( 'voidTransaction', 629017977 ), $this->api->calls );
		$notes = $this->orders->orders[501]->notes;
		$this->assertCount( 2, $notes );
		$this->assertStringContainsString( 'void of transaction 629017977 was requested automatically', $notes[1] );
		$this->assertStringNotContainsString( 'ACTION REQUIRED', $notes[1] );
		$this->assertSame( array( 'error: The wallet did not provide an e-mail address, which we need for your order. ' . self::RELEASING . ' Please use the regular checkout.' ), $this->notices );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_failed_void_keeps_the_payment_received_notice_and_asks_for_a_manual_void() {
		$this->orders->applied_email     = '';
		$this->api->transaction['state'] = 'AUTHORIZED';
		$this->api->void                 = new ApiException( 'refused', 409 );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );

		$notes = $this->orders->orders[501]->notes;
		$this->assertCount( 2, $notes );
		$this->assertStringStartsWith( 'ACTION REQUIRED:', $notes[1] );
		$this->assertStringContainsString( 'void failed', $notes[1] );
		$this->assertSame( array( 'error: ' . self::PAID_NOTICE ), $this->notices );
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

	public function test_checks_skipped_because_the_transaction_was_not_read_are_left_for_after_payment() {
		$this->api->transaction = new ApiException( 'timeout' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );

		$this->assertTrue( CustomerDetails::checksPending( $this->orders->orders[501] ) );
		$this->assertSame( array(), $this->orders->coupon_checks );
	}

	public function test_checks_skipped_because_the_session_has_no_transaction_yet_are_left_for_after_payment() {
		$this->api->sessions = array( self::approved(), array_merge( self::processing(), array( 'linkedTransaction' => null ) ) );

		$this->handler()->handle( $this->query() );

		$this->assertTrue( CustomerDetails::checksPending( $this->orders->orders[501] ) );
	}

	public function test_checks_done_at_return_are_not_left_for_after_payment() {
		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );

		$this->assertFalse( CustomerDetails::checksPending( $this->orders->orders[501] ) );
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
		$owner     = in_array( $reason, array( 'no_email', 'coupon_invalid', 'destination_changed' ), true );
		$state     = is_array( $this->api->transaction ) && isset( $this->api->transaction['state'] ) ? $this->api->transaction['state'] : '';
		$undecided = in_array( $state, array( 'PROCESSING', 'CONFIRMED', 'PENDING', 'CREATE', '' ), true );
		$this->assertSame( $owner && $undecided ? array( 501 ) : array(), $this->scheduled, 'only an undecided payment of an order failed for an owner reason is checked again' );
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

	public function test_a_settlement_error_does_not_tell_an_unpaid_customer_that_the_payment_was_received() {
		$this->orders->coupon_reason = 'coupon_invalid';
		$this->orders->order_class   = ThrowingMetaOrder::class;

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 501 => 'coupon_invalid' ), $this->orders->failed );
		$this->assertSame( 'error: The coupon in your cart cannot be used for this order. You have not been charged. Please remove the coupon or use the regular checkout.', $this->notices[0], 'same notice as without the error' );
		$this->assertSame( array( 501 ), $this->scheduled, 'the background job settles the payment later' );
		$this->assertStringContainsString( 'Return: order 501: payment of the failed order not settled (RuntimeException).', implode( "\n", $this->logs ) );
	}

	public function test_a_settlement_error_with_money_reserved_tells_the_customer_the_payment_was_received() {
		$this->orders->coupon_reason     = 'coupon_invalid';
		$this->orders->order_class       = ThrowingMetaOrder::class;
		$this->api->transaction['state'] = 'AUTHORIZED';

		$this->assertSame( self::CART, $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'error: ' . self::PAID_NOTICE, $this->notices[0] );
	}

	public function test_a_coupon_failure_with_money_reserved_voids_the_authorization() {
		$this->orders->coupon_reason     = 'coupon_invalid';
		$this->api->transaction['state'] = 'AUTHORIZED';

		$this->assertFailedBeforePayment( 'coupon_invalid', $this->handler()->handle( $this->query() ) );
		$this->assertContains( array( 'voidTransaction', 629017977 ), $this->api->calls );
		$this->assertStringContainsString( 'coupon_invalid', $this->orders->orders[501]->notes[1] );
		$this->assertSame( 'error: The coupon in your cart cannot be used for this order. ' . self::RELEASING . ' Please remove the coupon or use the regular checkout.', $this->notices[0] );
	}

	/**
	 * Orders get WooCommerce's coupon hold as WooCommerce implements it (the
	 * session cart's coupons) and a data store that records the held keys;
	 * the snapshot coupon `save5` has a global and a per-user limit.
	 *
	 * @param array $save5 Registry overrides of the snapshot coupon (`hold`, `hold_for_user`).
	 */
	private function holding_orders( array $save5 = array() ): void {
		Functions\when( 'sanitize_email' )->returnArg();
		\WC_Coupon_Data_Store_Stub::$holds = array();
		\WC_Coupon::$registry              = array(
			'save5' => array_merge(
				array(
					'id'                   => 7,
					'usage_limit'          => 10,
					'usage_limit_per_user' => 1,
				),
				$save5
			),
			'other' => array(
				'id'                   => 8,
				'usage_limit'          => 1,
				'usage_limit_per_user' => 1,
			),
		);
		$this->orders->order_class = HoldingOrder::class;
		$this->orders->on_create   = static function ( HoldingOrder $order ) {
			$order->data_store = new class() {
				/**
				 * Saved keys: array( held, held for user ).
				 *
				 * @var array[]
				 */
				public $keys = array();

				/**
				 * Records the held keys.
				 *
				 * @param \WC_Order $order    Order.
				 * @param array     $held     Global hold keys by coupon ID.
				 * @param array     $for_user Per-user hold keys by coupon ID.
				 */
				public function set_coupon_held_keys( $order, $held, $for_user ) {
					$this->keys[] = array( $held, $for_user );
				}
			};
		};
	}

	/**
	 * Coupon codes the coupon data store was asked to hold.
	 */
	private static function held_codes(): array {
		return array_map(
			static function ( array $call ) {
				return $call[0] . ':' . $call[1];
			},
			\WC_Coupon_Data_Store_Stub::$holds
		);
	}

	public function test_a_valid_order_holds_the_snapshots_coupons_for_the_wallet_email() {
		$this->holding_orders();

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 'check_and_hold_coupon:save5', 'check_and_hold_coupon_for_user:save5' ), self::held_codes() );
		$this->assertSame( array( 'buyer@example.test' ), \WC_Coupon_Data_Store_Stub::$holds[1][2] );
		$this->assertSame( 'buyer@example.test', \WC_Coupon_Data_Store_Stub::$holds[1][3] );
		$this->assertSame( array( array( array( 7 => 'held_save5' ), array( 7 => 'held_save5' ) ) ), $this->orders->orders[501]->data_store->keys );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_cart_coupons_that_differ_from_the_snapshots_are_not_held() {
		$this->holding_orders();
		$this->wc->cart->applied = array( 'other' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array( 'check_and_hold_coupon:save5', 'check_and_hold_coupon_for_user:save5' ), self::held_codes() );
		$this->assertSame( array(), $this->orders->orders[501]->holds, 'the cart coupon is not held' );
	}

	public function test_a_product_page_purchase_ignores_an_unrelated_cart_coupon_at_its_limit() {
		$this->holding_orders();
		$data            = CartSnapshotTest::data();
		$data['coupons'] = array();
		$this->repository = new FakeSessionRepository( $data, array( 'shipping_quote' => self::quote() ) );
		$this->wc->cart->applied = array( 'other' );
		$previous                = $this->orders->on_create;
		$this->orders->on_create = static function ( HoldingOrder $order ) use ( $previous ) {
			$previous( $order );
			$order->limit_reached = array( 'other' );
		};

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( array(), self::held_codes() );
		$this->assertSame( array(), $this->orders->orders[501]->holds );
	}

	public function test_the_hold_needs_no_session_cart() {
		$this->holding_orders();
		$this->wc->cart = null;

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertCount( 2, self::held_codes() );
	}

	public function test_a_snapshot_coupon_at_its_limit_fails_the_order_as_coupon_invalid() {
		$this->holding_orders( array( 'hold_for_user' => 0 ) );

		$this->assertFailedBeforePayment( 'coupon_invalid', $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'error: The coupon in your cart cannot be used for this order. You have not been charged. Please remove the coupon or use the regular checkout.', $this->notices[0] );
		$this->assertStringContainsString( 'Return: order 501: coupon hold refused (usage limit reached).', implode( "\n", $this->logs ) );
		$this->assertSame( array( array( array( 7 => 'held_save5' ), array() ) ), $this->orders->orders[501]->data_store->keys, 'the hold already taken is saved, so it is released with the order' );
	}

	/**
	 * @dataProvider captured_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_a_snapshot_coupon_at_its_limit_with_money_taken_asks_for_a_refund( string $state ) {
		$this->holding_orders( array( 'hold' => 0 ) );
		$this->api->transaction['state'] = $state;

		$this->assertFailedBeforePayment( 'coupon_invalid', $this->handler()->handle( $this->query() ) );
		$this->assertStringContainsString( 'ACTION REQUIRED', $this->orders->orders[501]->notes[1] );
		$this->assertStringContainsString( 'coupon_invalid', $this->orders->orders[501]->notes[1] );
		$this->assertSame( 'error: ' . self::PAID_NOTICE, $this->notices[0] );
	}

	/**
	 * A hold that fails for a technical reason (the data store returns false
	 * or throws) never fails a paid order.
	 *
	 * @dataProvider technical_hold_failures
	 *
	 * @param mixed  $result Scripted data-store result.
	 * @param string $state  Transaction state.
	 */
	public function test_a_technical_hold_failure_keeps_a_paid_order( $result, string $state ) {
		$this->holding_orders( array( 'hold' => $result ) );
		$this->api->transaction['state'] = $state;

		$this->assertSame( SessionService::resultUrl( self::UUID, 'success' ), $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
		$this->assertStringContainsString( 'coupon_hold_failed', implode( "\n", $this->logs ) );
	}

	public function technical_hold_failures(): array {
		return array(
			'false, authorized'     => array( false, 'AUTHORIZED' ),
			'exception, completed'  => array( new \RuntimeException( 'db' ), 'COMPLETED' ),
			'false, fulfill'        => array( false, 'FULFILL' ),
		);
	}

	public function test_a_technical_hold_failure_before_payment_is_a_technical_failure() {
		$this->holding_orders( array( 'hold' => false ) );

		$this->assertFailedBeforePayment( 'coupon_hold_failed', $this->handler()->handle( $this->query() ) );
		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
		$this->assertSame( 'error: ' . self::TECHNICAL . ' ' . self::NOT_CHARGED . ' Please try again or use the regular checkout.', $this->notices[0] );
	}

	public function test_no_coupon_hold_is_taken_when_the_coupon_check_fails() {
		$this->holding_orders();
		$this->orders->coupon_reason = 'coupon_invalid';

		$this->assertFailedBeforePayment( 'coupon_invalid', $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), self::held_codes() );
	}

	public function test_no_coupon_hold_is_taken_when_the_destination_changed() {
		$this->holding_orders();
		$this->api->transaction['shippingAddress'] = array( 'country' => 'DE' );

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), self::held_codes() );
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

	/**
	 * Asserts a cart without shipping was kept at the offered price: order
	 * not failed, nothing voided or flagged for a refund, one tax note.
	 *
	 * @param string $url Returned URL.
	 */
	private function assertKeptAtOfferedPrice( string $url ): void {
		$order = $this->orders->orders[501];
		$this->assertSame( self::WALLEE, $url );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertNotSame( 'failed', $this->repository->row['state'] );
		$this->assertSame( array(), $this->notices );
		$this->assertSame( '', FailedOrderSettlement::failedReason( $order ) );
		$this->assertSame( '', $order->get_meta( '_wallee_ec_void' ) );
		$this->assertSame( '', $order->get_meta( '_wallee_ec_refund_required' ) );
		$this->assertNotContains( array( 'voidTransaction', 629017977 ), $this->api->calls );
		$this->assertSame( 1, count( array_keys( $order->notes, CustomerDetailsTest::TAX_KEPT_NOTE, true ) ) );
		$this->assertSame( 'yes', $order->get_meta( CustomerDetails::META_TAX_KEPT ) );
		$this->assertStringContainsString( 'Return: order 501: tax destination differs for a cart without shipping; order kept at the offered price with a note.', implode( "\n", $this->logs ) );
	}

	public function test_an_address_outside_the_tax_country_keeps_a_cart_without_shipping_at_the_offered_price() {
		$this->tax_follows = true;
		$this->virtual_purchase( 'US' );

		$this->assertKeptAtOfferedPrice( $this->handler()->handle( $this->query() ) );
	}

	/**
	 * @dataProvider money_moved_states
	 *
	 * @param string $state Transaction state.
	 */
	public function test_a_paid_cart_without_shipping_and_another_tax_address_is_neither_voided_nor_refunded( string $state ) {
		$this->tax_follows = true;
		$this->virtual_purchase( 'US' );
		$this->api->transaction['state'] = $state;

		$this->assertKeptAtOfferedPrice( $this->handler()->handle( $this->query() ) );
	}

	public function test_a_coupon_invalid_for_the_email_still_fails_a_cart_without_shipping() {
		$this->tax_follows = true;
		$this->virtual_purchase( 'US' );
		$this->orders->coupon_reason = 'coupon_invalid';

		$this->assertFailedBeforePayment( 'coupon_invalid', $this->handler()->handle( $this->query() ) );
		$this->assertNotContains( CustomerDetailsTest::TAX_KEPT_NOTE, $this->orders->orders[501]->notes );
	}

	public function test_a_missing_email_still_fails_a_cart_without_shipping() {
		$this->tax_follows = true;
		$this->virtual_purchase( 'US' );
		$this->orders->applied_email = '';

		$this->assertFailedBeforePayment( 'no_email', $this->handler()->handle( $this->query() ) );
	}

	public function test_a_tax_address_with_other_rates_still_fails_a_cart_that_needs_shipping() {
		$this->tax_follows                         = true;
		$this->tax_based_on                        = 'billing';
		$this->api->transaction['shippingAddress'] = array( 'country' => 'CH' );
		$this->api->transaction['billingAddress']  = array( 'country' => 'US' );

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
		$this->assertNotContains( CustomerDetailsTest::TAX_KEPT_NOTE, $this->orders->orders[501]->notes );
		$this->assertSame( '', $this->orders->orders[501]->get_meta( CustomerDetails::META_TAX_KEPT ) );
	}

	public function test_a_cart_without_shipping_goes_to_payment_with_only_a_billing_address() {
		$this->virtual_purchase( 'CH' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertNull( $this->orders->created[0][1], 'no shipping rate, so no shipping line' );
		$this->assertFalse( $this->orders->applied_needs_shipping[0], 'the order is told it needs no shipping' );
		$order = $this->orders->orders[501];
		$this->assertSame( '', $order->get_shipping_country(), 'shipping fields stay empty' );
		$this->assertSame( 'CH', $order->get_billing_country() );
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

	public function test_taxes_by_billing_address_compare_the_billing_address_and_do_not_name_the_country() {
		$this->tax_follows                         = true;
		$this->tax_based_on                        = 'billing';
		$this->api->transaction['shippingAddress'] = array( 'country' => 'CH' );
		$this->api->transaction['billingAddress']  = array( 'country' => 'DE' );

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
		$this->assertSame( 'error: Express checkout is not available for the selected delivery address. You have not been charged. Please use the regular checkout to complete your order.', $this->notices[0] );
	}

	public function test_taxes_by_shipping_address_ignore_another_billing_country() {
		$this->tax_follows                         = true;
		$this->api->transaction['shippingAddress'] = array( 'country' => 'CH' );
		$this->api->transaction['billingAddress']  = array( 'country' => 'DE' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
	}

	public function test_a_postcode_with_other_rates_fails_the_order_without_naming_the_country() {
		$this->tax_follows                         = true;
		\WC_Tax::$rows[]                           = \WC_Tax::row( 40, 'CH', '', 1.0, array( 'postcode' => '8000' ) );
		$this->api->transaction['shippingAddress'] = array(
			'country'  => 'CH',
			'postcode' => '3000',
		);

		$this->assertFailedBeforePayment( 'destination_changed', $this->handler()->handle( $this->query() ) );
		$this->assertStringNotContainsString( 'Switzerland', $this->notices[0] );
		$this->assertStringContainsString( 'the selected delivery address', $this->notices[0] );
	}

	public function test_another_state_with_national_rates_is_accepted() {
		$this->tax_follows                         = true;
		$this->api->transaction['shippingAddress'] = array(
			'country'     => 'CH',
			'postalState' => 'BE',
			'postcode'    => '8000',
		);

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
	}

	/**
	 * Virtual purchase taxed for California (state rates for California and
	 * New York), taxes by billing address, wallet region as given.
	 *
	 * @param string $region wallee's `postalState`.
	 */
	private function california_purchase( string $region ): void {
		$this->virtual_purchase( 'US' );
		$data                               = CartSnapshotTest::data();
		$data['items'][0]['needs_shipping'] = false;
		$data['tax_location']               = array(
			'country'  => 'US',
			'state'    => 'CA',
			'postcode' => '',
			'city'     => '',
		);
		$this->repository                   = new FakeSessionRepository( $data );
		$this->tax_follows                  = true;
		$this->tax_based_on                 = 'billing';
		\WC_Tax::$rows[]                   = \WC_Tax::row( 20, 'US', '', 7.25, array( 'state' => 'CA' ) );
		\WC_Tax::$rows[]                   = \WC_Tax::row( 21, 'US', '', 4.0, array( 'state' => 'NY' ) );

		$this->api->transaction['billingAddress']['postalState'] = $region;
	}

	/**
	 * @dataProvider california_names
	 *
	 * @param string $region wallee's region.
	 */
	public function test_wallees_region_name_matches_the_tax_state( string $region ) {
		$this->california_purchase( $region );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertNotContains( 'ACTION REQUIRED: check tax (region could not be matched).', $this->orders->orders[501]->notes );
	}

	public function california_names(): array {
		return array(
			'name'     => array( 'California' ),
			'ISO code' => array( 'US-CA' ),
			'code'     => array( 'CA' ),
		);
	}

	public function test_another_state_with_other_rates_keeps_a_cart_without_shipping_at_the_offered_price() {
		$this->california_purchase( 'New York' );

		$this->assertKeptAtOfferedPrice( $this->handler()->handle( $this->query() ) );
	}

	public function test_an_unmatched_region_keeps_the_order_and_asks_to_check_the_tax() {
		$this->california_purchase( 'Calif.' );

		$this->assertSame( self::WALLEE, $this->handler()->handle( $this->query() ) );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertContains( 'ACTION REQUIRED: check tax (region could not be matched).', $this->orders->orders[501]->notes );
		$this->assertStringContainsString( 'Return: order 501: tax region not matched; order kept with a note.', implode( "\n", $this->logs ) );
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
		$this->assertNotContains( 'voidTransaction', $this->api->methods(), 'a kept order is never voided' );
		$this->assertSame( '', FailedOrderSettlement::failedReason( $this->orders->orders[501] ) );
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
		$this->assertStringContainsString( 'A refund is needed', $this->orders->orders[501]->notes[1] );
	}

	public function test_a_technical_failure_of_an_unpaid_order_is_not_checked_again() {
		$this->orders->coupon_error = new \RuntimeException( 'db' );

		$this->handler()->handle( $this->query() );

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( '', FailedOrderSettlement::failedReason( $this->orders->orders[501] ) );
	}

	public function test_an_owner_failure_with_a_failed_payment_is_not_checked_again() {
		$this->orders->coupon_reason     = 'coupon_invalid';
		$this->api->transaction['state'] = 'FAILED';

		$this->handler()->handle( $this->query() );

		$this->assertSame( array(), $this->scheduled );
		$this->assertNotContains( 'voidTransaction', $this->api->methods() );
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

