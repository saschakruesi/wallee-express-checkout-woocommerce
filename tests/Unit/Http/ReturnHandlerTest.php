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
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;
use Wallee\ExpressCheckout\Tests\Support\FakeOrderService;
use Wallee\ExpressCheckout\Tests\Support\FakeSessionRepository;
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
		Functions\when( 'wc_add_notice' )->alias(
			function ( $message, $type ) {
				$this->notices[] = $type . ': ' . $message;
			}
		);

		$this->notices    = array();
		$this->logs       = array();
		$this->repository = new FakeSessionRepository(
			CartSnapshotTest::data(),
			array( 'shipping_quote' => self::quote() )
		);
		$this->api        = new FakeApiClient();
		$this->orders     = new FakeOrderService();

		$this->api->sessions    = array( self::approved(), self::processing() );
		$this->api->transaction = array(
			'id'              => 629017977,
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
				'id'                  => 335,
				'state'               => 'APPROVED',
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
			$log
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
		$this->assertSame( array( 501, $this->api->transaction, 0, 'CH' ), $this->orders->applied[0] );
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
	}

	public function test_session_not_approved_fails_row_with_expiry_notice() {
		$this->api->sessions = array( self::approved( array( 'state' => 'PROCESSING' ) ) );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertNothingCreated();
		$this->assertStringContainsString( 'expired', $this->notices[0] );
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
		$this->assertStringNotContainsString( 'amount_mismatch', $this->notices[0] );
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

	public function test_process_error_fails_order_and_returns_cart() {
		$this->api->process = new ApiException( 'wallee API returned an invalid redirect URL.' );

		$url = $this->handler()->handle( $this->query() );

		$this->assertSame( self::CART, $url );
		$this->assertSame( array( 501 => 'process_failed' ), $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertCount( 1, $this->notices );
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
		$this->assertNull( $this->orders->applied[0][3] );
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
}
