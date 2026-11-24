<?php
/**
 * ResultHandler tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Http\ResultHandler;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Order\OrderLinker;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Tests\Support\FakeApiClient;
use Wallee\ExpressCheckout\Tests\Support\FakeOrderService;
use Wallee\ExpressCheckout\Tests\Support\FakeSessionRepository;
use Wallee\ExpressCheckout\Tests\Support\RecordingAdapter;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

/**
 * @covers \Wallee\ExpressCheckout\Http\ResultHandler
 */
class ResultHandlerTest extends TestCase {

	const UUID     = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const CART     = 'https://shop.test/cart/';
	const RECEIVED = 'https://shop.test/checkout/order-received/501/?key=wc_order_k';

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

	/** @var object Cart spy. */
	private $cart;

	/** @var object WooCommerce session spy. */
	private $session;

	/** @var string[] */
	private $notices = array();

	/** @var int[] Order IDs the background link was scheduled for. */
	private $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wc_get_cart_url' )->justReturn( self::CART );
		Functions\when( 'wc_add_notice' )->alias(
			function ( $message, $type ) {
				$this->notices[] = $type . ': ' . $message;
			}
		);

		$this->cart    = new class() {
			public $emptied = 0;
			public function empty_cart() {
				++$this->emptied;
			}
		};
		$this->session = new class() {
			public $unset = array();
			public function __unset( $key ) {
				$this->unset[] = $key;
			}
		};
		$gateway       = new class() {
			public function get_title() {
				return 'Credit / Debit Card';
			}
		};
		$registry      = new class( $gateway ) {
			private $gateway;
			public function __construct( $gateway ) {
				$this->gateway = $gateway;
			}
			public function payment_gateways() {
				return array( 'wallee_7' => $this->gateway );
			}
		};
		$wc            = new class( $this->cart, $this->session, $registry ) {
			public $cart;
			public $session;
			private $registry;
			public function __construct( $cart, $session, $registry ) {
				$this->cart     = $cart;
				$this->session  = $session;
				$this->registry = $registry;
			}
			public function payment_gateways() {
				return $this->registry;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );

		$this->notices    = array();
		$this->scheduled  = array();
		$this->order      = new \WC_Order( 501, 60.11 );
		$this->repository = $this->row( 'cart' );
		$this->api        = new FakeApiClient();
		$this->orders     = new FakeOrderService();
		$this->adapter    = new RecordingAdapter();

		$this->orders->orders[501]                     = $this->order;
		$this->order->billing_email                    = 'buyer@example.test';
		$this->order->meta['_wallee_ec_transaction_id'] = 629017977;
		$this->api->sessions                           = array( ReturnHandlerTest::processing() );
		$this->api->transaction                        = array( 'id' => 629017977 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function row( string $context, array $over = array() ): FakeSessionRepository {
		return new FakeSessionRepository(
			CartSnapshotTest::data( array( 'context' => $context ) ),
			array_merge(
				array(
					'state'          => 'processed',
					'order_id'       => 501,
					'transaction_id' => 629017977,
					'shipping_quote' => ReturnHandlerTest::quote(),
				),
				$over
			)
		);
	}

	private function handler(): ResultHandler {
		$log = function ( $message, $level = 'info' ) {
			$this->adapter->log( $message, $level );
		};

		return new ResultHandler(
			$this->repository,
			UrlSigner::fromWordPress(),
			$this->orders,
			new CustomerDetails( $this->api, $this->repository, $this->orders, $log ),
			new OrderLinker( $this->adapter ),
			function ( $order_id ) {
				$this->scheduled[] = $order_id;
				return true;
			},
			$log
		);
	}

	private function query( string $status, ?string $signed_for = null ): array {
		return array(
			'status'     => $status,
			'ec_session' => self::UUID,
			'sig'        => UrlSigner::fromWordPress()->sign( self::UUID, 'result:' . ( null === $signed_for ? $status : $signed_for ) ),
		);
	}

	public function test_success_links_empties_cart_forgets_session_and_shows_order_received() {
		$url = $this->handler()->handle( $this->query( 'success' ) );

		$this->assertSame( self::RECEIVED, $url );
		$this->assertSame( array( 501 ), $this->adapter->links );
		$this->assertSame( array( 629017977 ), $this->adapter->reads );
		$this->assertSame( 'wallee_7', $this->order->get_payment_method() );
		$this->assertSame( 'Credit / Debit Card', $this->order->get_payment_method_title() );
		$this->assertSame( 1, $this->cart->emptied );
		$this->assertSame( array( 'wallee_ec_session' ), $this->session->unset );
		$this->assertSame( 'completed', $this->repository->row['state'] );
		$this->assertSame( 'pending', $this->order->get_status(), 'status is left to the webhook' );
		$this->assertSame( array(), $this->api->calls, 'transaction and e-mail known: no repeat of step 10' );
		$this->assertSame( array(), $this->notices );
		$this->assertSame( array(), $this->scheduled, 'linked: nothing left for the background job' );
	}

	public function test_success_without_email_keeps_the_paid_order_and_notes_once() {
		$this->order->billing_email  = '';
		$this->orders->applied_email = '';

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->repository->row['state'] = 'processed';
		$this->handler()->handle( $this->query( 'success' ) );

		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( array( 'The payment is approved, but wallee returned no valid e-mail address for the customer.' ), $this->order->notes );
		$this->assertSame( 'completed', $this->repository->row['state'] );
	}

	public function test_success_that_cannot_link_schedules_the_background_link() {
		$this->adapter->read_error = new \InvalidArgumentException( 'enum' );

		$this->handler()->handle( $this->query( 'success' ) );

		$this->assertSame( array( 501 ), $this->scheduled );
	}

	public function test_success_without_transaction_schedules_the_background_link() {
		$this->repository = $this->row( 'cart', array( 'transaction_id' => null ) );
		unset( $this->order->meta['_wallee_ec_transaction_id'] );
		$this->api->sessions = array( ReturnHandlerTest::approved() );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( array(), $this->adapter->reads );
		$this->assertSame( array( 501 ), $this->scheduled );
	}

	public function test_success_in_product_context_leaves_the_cart_alone() {
		$this->repository = $this->row( 'product' );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( 0, $this->cart->emptied );
		$this->assertSame( array( 'wallee_ec_session' ), $this->session->unset );
		$this->assertSame( 'completed', $this->repository->row['state'] );
	}

	public function test_success_without_transaction_id_repeats_step_ten_then_links() {
		$this->repository = $this->row( 'cart', array( 'transaction_id' => null ) );
		unset( $this->order->meta['_wallee_ec_transaction_id'] );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( array( 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( 629017977, $this->repository->row['transaction_id'] );
		$this->assertSame( array( 629017977 ), $this->adapter->reads );
		$this->assertCount( 1, $this->orders->applied );
	}

	public function test_success_without_billing_email_repeats_step_ten() {
		$this->order->billing_email = '';

		$this->handler()->handle( $this->query( 'success' ) );

		$this->assertSame( array( 'getSession', 'getTransaction' ), $this->api->methods() );
		$this->assertSame( 'buyer@example.test', $this->order->get_billing_email() );
	}

	public function test_success_when_step_ten_fails_still_shows_order_received() {
		$this->order->billing_email = '';
		$this->api->sessions        = array( new \RuntimeException( 'timeout' ) );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( array( 501 ), $this->adapter->links, 'the known transaction ID is still linked' );
		$this->assertSame( 'completed', $this->repository->row['state'] );
	}

	public function test_success_when_linking_throws_still_shows_order_received() {
		$this->adapter->read_error = new \InvalidArgumentException( 'enum' );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( 'completed', $this->repository->row['state'] );
		$this->assertSame( 'wallee_express_checkout', $this->order->get_payment_method() );
	}

	public function test_success_reload_is_idempotent() {
		$handler = $this->handler();
		$first   = $handler->handle( $this->query( 'success' ) );
		$calls   = count( $this->repository->calls );
		$notes   = count( $this->order->notes );

		$second = $this->handler()->handle( $this->query( 'success' ) );

		$this->assertSame( $first, $second );
		$this->assertSame( array( 501 ), $this->adapter->links );
		$this->assertSame( 1, $this->cart->emptied );
		$this->assertCount( $calls, $this->repository->calls );
		$this->assertCount( $notes, $this->order->notes );
	}

	public function test_failure_sets_pending_order_failed_and_returns_cart() {
		$url = $this->handler()->handle( $this->query( 'failure' ) );

		$this->assertSame( self::CART, $url );
		$this->assertSame( 'failed', $this->order->get_status() );
		$this->assertArrayHasKey( 501, $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
		$this->assertSame( array( 'error: The payment was not completed.' ), $this->notices );
		$this->assertSame( 0, $this->cart->emptied );
	}

	public function test_failure_leaves_a_non_pending_order_alone() {
		$this->order->status = 'processing';

		$this->assertSame( self::CART, $this->handler()->handle( $this->query( 'failure' ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( 'failed', $this->repository->row['state'] );
	}

	public function test_failure_after_completion_changes_nothing() {
		$this->repository = $this->row( 'cart', array( 'state' => 'completed' ) );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'failure' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( array(), $this->repository->calls );
	}

	public function test_success_signature_presented_with_failure_status_is_rejected() {
		$url = $this->handler()->handle( $this->query( 'failure', 'success' ) );

		$this->assertSame( self::CART, $url );
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( array(), $this->repository->calls );
		$this->assertSame( array(), $this->adapter->links );
		$this->assertCount( 1, $this->notices );
	}

	public function test_failure_signature_presented_with_success_status_is_rejected() {
		$this->assertSame( self::CART, $this->handler()->handle( $this->query( 'success', 'failure' ) ) );
		$this->assertSame( array(), $this->repository->calls );
		$this->assertSame( 0, $this->cart->emptied );
	}

	public function test_unknown_status_is_rejected() {
		$query = $this->query( 'success' );
		$query['status'] = 'pending';

		$this->assertSame( self::CART, $this->handler()->handle( $query ) );
		$this->assertSame( array(), $this->repository->calls );
	}

	public function test_return_signature_is_rejected() {
		$query        = $this->query( 'success' );
		$query['sig'] = UrlSigner::fromWordPress()->sign( self::UUID, 'return' );

		$this->assertSame( self::CART, $this->handler()->handle( $query ) );
		$this->assertSame( array(), $this->repository->calls );
	}

	public function test_missing_parameters_return_cart() {
		$this->assertSame( self::CART, $this->handler()->handle( array() ) );
		$this->assertSame( array(), $this->repository->calls );
	}

	public function test_success_for_a_failed_row_changes_nothing() {
		$this->repository = $this->row( 'cart', array( 'state' => 'failed' ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( array(), $this->repository->calls );
		$this->assertSame( array(), $this->adapter->links );
	}

	public function test_row_without_order_returns_cart() {
		$this->repository = $this->row( 'cart', array( 'order_id' => null ) );

		$this->assertSame( self::CART, $this->handler()->handle( $this->query( 'success' ) ) );
		$this->assertSame( array(), $this->repository->calls );
	}

	public function test_a_link_older_than_seven_days_goes_to_the_cart_and_changes_nothing() {
		$this->repository->row['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 604801 );

		foreach ( array( 'success', 'failure' ) as $status ) {
			$this->assertSame( self::CART, $this->handler()->handle( $this->query( $status ) ) );
		}
		$this->assertSame( 'processed', $this->repository->row['state'] );
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( array(), $this->orders->failed );
		$this->assertSame( array(), $this->adapter->links );
		$this->assertCount( 2, $this->notices );
	}

	public function test_a_link_six_days_old_still_works() {
		$this->repository->row['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 6 * 86400 );

		$this->assertSame( self::RECEIVED, $this->handler()->handle( $this->query( 'success' ) ) );
	}
}

