<?php
/**
 * SessionController tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Http\SessionController;
use Wallee\ExpressCheckout\MainPluginAdapter;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Security\RateLimiter;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;
use Wallee\ExpressCheckout\Tests\Support\RecordingAdapter;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-stub.php';

/**
 * Request double with headers only.
 */
class HeaderRequest {

	/** @var array */
	private $headers;

	public function __construct( array $headers ) {
		$this->headers = $headers;
	}

	public function get_header( $name ) {
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
	}
}

/**
 * @covers \Wallee\ExpressCheckout\Http\SessionController
 */
class SessionControllerTest extends TestCase {

	/** @var FakeWpdb */
	private $db;

	/** @var SessionController */
	private $controller;

	/** @var string[] Rate limit buckets consulted. */
	private $buckets = array();

	/** @var array Transient store. */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return $nonce === 'good-' . $action ? 1 : false;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( '__' )->returnArg();

		$this->transients = array();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return isset( $this->transients[ $key ] ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				$this->buckets[]          = $key;
				return true;
			}
		);

		$this->db         = new FakeWpdb();
		$this->controller = new SessionController( new RateLimiter(), new SessionRepository( $this->db ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function permission( array $headers ) {
		return $this->controller->checkPermission( new HeaderRequest( $headers ) );
	}

	public function test_permission_requires_both_nonces() {
		$both = array(
			'X-WP-Nonce'        => 'good-wp_rest',
			'X-Wallee-Ec-Nonce' => 'good-wallee_ec_session',
		);
		$this->assertTrue( $this->permission( $both ) );
	}

	/**
	 * @dataProvider bad_headers
	 *
	 * @param array $headers Headers.
	 */
	public function test_permission_rejects_missing_or_invalid_nonce( array $headers ) {
		Functions\when( 'rest_ensure_response' )->returnArg();
		// WP_Error is not loaded in unit tests; a missing class is the only way to see the 403 path.
		if ( ! class_exists( 'WP_Error' ) ) {
			eval( 'class WP_Error { public $code; public $data; public function __construct( $code = "", $message = "", $data = "" ) { $this->code = $code; $this->data = $data; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$result = $this->permission( $headers );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 403, $result->data['status'] );
	}

	public function bad_headers(): array {
		return array(
			'none'              => array( array() ),
			'only rest nonce'   => array( array( 'X-WP-Nonce' => 'good-wp_rest' ) ),
			'only ec nonce'     => array( array( 'X-Wallee-Ec-Nonce' => 'good-wallee_ec_session' ) ),
			'bad rest nonce'    => array(
				array(
					'X-WP-Nonce'        => 'bad',
					'X-Wallee-Ec-Nonce' => 'good-wallee_ec_session',
				),
			),
			'bad ec nonce'      => array(
				array(
					'X-WP-Nonce'        => 'good-wp_rest',
					'X-Wallee-Ec-Nonce' => 'bad',
				),
			),
			'ec nonce wrong action' => array(
				array(
					'X-WP-Nonce'        => 'good-wp_rest',
					'X-Wallee-Ec-Nonce' => 'good-wp_rest',
				),
			),
		);
	}

	public function test_variation_validation_and_sanitising() {
		$this->assertTrue( SessionController::validateVariation( array( 'attribute_size' => 'S' ) ) );
		$this->assertTrue( SessionController::validateVariation( array() ) );
		$this->assertFalse( SessionController::validateVariation( 'x' ) );
		$this->assertFalse( SessionController::validateVariation( array( 'attribute_size' => array( 'S' ) ) ) );
		$this->assertFalse( SessionController::validateVariation( array( str_repeat( 'k', 101 ) => 'S' ) ) );
		$this->assertFalse( SessionController::validateVariation( array( 'attribute_size' => str_repeat( 'v', 201 ) ) ) );
		$this->assertTrue( SessionController::validateVariation( array( str_repeat( 'k', 100 ) => str_repeat( 'v', 200 ) ) ) );

		$many = array();
		for ( $i = 0; $i < 21; $i++ ) {
			$many[ 'a' . $i ] = 'v';
		}
		$this->assertFalse( SessionController::validateVariation( $many ) );
		array_pop( $many );
		$this->assertTrue( SessionController::validateVariation( $many ) );

		$this->assertSame(
			array( 'attribute_size' => 'S' ),
			SessionController::sanitizeVariation(
				array(
					' attribute_size ' => ' S ',
					'attribute_bad'    => array( 'nested' ),
				)
			)
		);
		$this->assertSame( array(), SessionController::sanitizeVariation( 'x' ) );
	}

	public function test_attribute_keys_keep_url_encoded_names_and_only_slug_characters() {
		// As WordPress does: sanitize_text_field removes percent-encoded octets.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $text ) {
				return trim( (string) preg_replace( '/%[a-f0-9]{2}/i', '', (string) $text ) );
			}
		);

		$this->assertSame(
			array(
				'attribute_gr%c3%b6%c3%9fe' => 'XL',
				'attribute_pa_color'        => 'blue',
				'attribute_scriptx'         => 'v',
			),
			SessionController::sanitizeVariation(
				array(
					'attribute_gr%C3%B6%C3%9Fe' => 'XL',
					'Attribute_PA_Color'        => 'blue',
					'attribute_<script>x'       => 'v',
				)
			)
		);
	}

	public function test_both_rate_limit_buckets_are_consulted() {
		$this->assertTrue( $this->controller->rateAllows( '203.0.113.9' ) );

		$this->assertSame(
			array( 'wallee_ec_rl_' . md5( 'session|203.0.113.9' ), 'wallee_ec_rl_' . md5( 'session|global' ) ),
			$this->buckets,
			'the client bucket first'
		);
	}

	public function test_a_client_that_exhausts_its_own_bucket_does_not_consume_the_global_bucket() {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->controller->rateAllows( '203.0.113.9' );
		}

		$this->assertSame( 20, $this->transients[ 'wallee_ec_rl_' . md5( 'session|global' ) ] );
		$this->assertTrue( $this->controller->rateAllows( '198.51.100.1' ), 'other clients are still served' );
	}

	public function test_per_ip_limit_denies_after_twenty() {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->controller->rateAllows( '203.0.113.9' ) );
		}
		$this->assertFalse( $this->controller->rateAllows( '203.0.113.9' ) );
		$this->assertTrue( $this->controller->rateAllows( '203.0.113.10' ) );
	}

	public function test_ipv6_clients_share_one_bucket_per_64_prefix() {
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->assertTrue( $this->controller->rateAllows( '2001:db8:1:2::' . dechex( $i ) ) );
		}

		$this->assertFalse( $this->controller->rateAllows( '2001:db8:1:2:ffff:ffff:ffff:ffff' ), 'another address in the same /64' );
		$this->assertTrue( $this->controller->rateAllows( '2001:db8:1:3::1' ), 'the next /64 is another client' );
	}

	public function test_rate_key_of_a_client_address() {
		$this->assertSame( '203.0.113.9', SessionController::rateKey( '203.0.113.9' ), 'IPv4 unchanged' );
		$this->assertSame( '2001:db8:1:2::/64', SessionController::rateKey( '2001:DB8:1:2:aaaa:bbbb:cccc:dddd' ) );
		$this->assertSame( '2001:db8::/64', SessionController::rateKey( '2001:db8::1' ) );
		$this->assertSame( '203.0.113.9', SessionController::rateKey( '::ffff:203.0.113.9' ), 'IPv4-mapped is the IPv4 client' );
		$this->assertSame( 'unknown', SessionController::rateKey( 'unknown' ) );
	}

	public function test_global_limit_denies_across_ips() {
		$this->transients[ 'wallee_ec_rl_' . md5( 'session|global' ) ] = 300;

		$this->assertFalse( $this->controller->rateAllows( '198.51.100.1' ) );
	}

	/**
	 * Entry for reuse tests.
	 *
	 * @param array $over Overrides.
	 */
	private function entry( array $over = array() ): array {
		return array_merge(
			array(
				'context' => 'cart',
				'hash'    => 'h1',
				'token'   => 'tok',
				'uuid'    => str_repeat( 'a', 64 ),
				'created' => time() - 60,
			),
			$over
		);
	}

	private function stored_row( string $state ): void {
		$this->db->next_row = array(
			'id'                => 1,
			'session_uuid'      => str_repeat( 'a', 64 ),
			'wallee_session_id' => 99,
			'space_id'          => 4711,
			'snapshot'          => json_encode( CartSnapshotTest::data() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'state'             => $state,
			'order_id'          => null,
			'transaction_id'    => null,
			'created_at'        => '2026-11-24 10:00:00',
		);
	}

	public function test_reuse_returns_token_for_same_content_with_created_row() {
		$this->stored_row( 'created' );

		$this->assertSame(
			array(
				'sessionToken' => 'tok',
				'expiresAt'    => '2026-11-24T10:30:00Z',
			),
			$this->controller->reusableSession( $this->entry(), 'cart', 'h1' ),
			'the expiry comes from the row, not from the reuse'
		);
	}

	public function test_reuse_window_is_five_minutes() {
		$this->stored_row( 'created' );

		$this->assertNotNull( $this->controller->reusableSession( $this->entry( array( 'created' => time() - 290 ) ), 'cart', 'h1' ) );
		$this->assertNull( $this->controller->reusableSession( $this->entry( array( 'created' => time() - 300 ) ), 'cart', 'h1' ) );
	}

	public function test_expires_at_is_creation_plus_thirty_minutes_in_utc() {
		$this->assertSame( '2026-11-24T10:30:00Z', SessionController::expiresAt( '2026-11-24 10:00:00' ) );
		$this->assertSame( '2026-11-25T00:15:59Z', SessionController::expiresAt( '2026-11-24 23:45:59' ) );
		$this->assertNull( SessionController::expiresAt( '' ) );
		$this->assertNull( SessionController::expiresAt( 'garbage' ) );
	}

	public function test_token_response_omits_an_unknown_expiry() {
		$this->assertSame(
			array(
				'sessionToken' => 't',
				'expiresAt'    => '2026-11-24T10:30:00Z',
			),
			SessionController::tokenResponse( 't', '2026-11-24 10:00:00' )
		);
		$this->assertSame( array( 'sessionToken' => 't' ), SessionController::tokenResponse( 't', 'nope' ) );
	}

	public function test_reuse_refuses_changed_hash_context_age_and_used_row() {
		$this->stored_row( 'created' );
		$this->assertNull( $this->controller->reusableSession( $this->entry(), 'cart', 'h2' ), 'hash' );
		$this->assertNull( $this->controller->reusableSession( $this->entry(), 'product', 'h1' ), 'context' );
		$this->assertNull( $this->controller->reusableSession( $this->entry( array( 'created' => time() - 301 ) ), 'cart', 'h1' ), 'expired' );
		$this->assertNull( $this->controller->reusableSession( $this->entry( array( 'token' => '' ) ), 'cart', 'h1' ), 'empty token' );
		$this->assertNull( $this->controller->reusableSession( array( 'context' => 'cart' ), 'cart', 'h1' ), 'incomplete' );
		$this->assertNull( $this->controller->reusableSession( null, 'cart', 'h1' ), 'no entry' );

		$this->stored_row( 'ordering' );
		$this->assertNull( $this->controller->reusableSession( $this->entry(), 'cart', 'h1' ), 'row used' );

		$this->db->next_row = null;
		$this->assertNull( $this->controller->reusableSession( $this->entry(), 'cart', 'h1' ), 'row missing' );
	}

	/**
	 * Sets REMOTE_ADDR for one test.
	 *
	 * @param string|null $address Address, null to unset.
	 */
	private function remote_addr( ?string $address ): void {
		if ( null === $address ) {
			unset( $_SERVER['REMOTE_ADDR'] );
			return;
		}
		$_SERVER['REMOTE_ADDR'] = $address;
	}

	public function test_client_ip_is_the_remote_address() {
		Functions\when( 'wp_unslash' )->returnArg();
		$saved = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;

		$this->remote_addr( '203.0.113.9' );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.8';
		$this->assertSame( '203.0.113.9', SessionController::clientIp(), 'forwarding headers are not trusted' );

		$this->remote_addr( '2001:db8::1' );
		$this->assertSame( '2001:db8::1', SessionController::clientIp() );

		$this->remote_addr( 'not an ip' );
		$this->assertSame( 'unknown', SessionController::clientIp() );
		$this->remote_addr( null );
		$this->assertSame( 'unknown', SessionController::clientIp() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
		$this->remote_addr( $saved );
	}

	public function test_client_ip_can_be_supplied_by_a_trusted_proxy_filter() {
		Functions\when( 'wp_unslash' )->returnArg();
		$saved = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$this->remote_addr( '10.0.0.5' );

		Filters\expectApplied( 'wallee_ec_client_ip' )->once()->with( '10.0.0.5' )->andReturn( '198.51.100.7' );
		$this->assertSame( '198.51.100.7', SessionController::clientIp() );

		Filters\expectApplied( 'wallee_ec_client_ip' )->once()->andReturn( 'garbage' );
		$this->assertSame( '10.0.0.5', SessionController::clientIp(), 'a value that is no IP address is ignored' );

		$this->remote_addr( $saved );
	}

	/**
	 * WooCommerce with a session double.
	 *
	 * @param bool $has_session Whether the caller has a WooCommerce session.
	 * @return object Session double.
	 */
	private function woocommerce( bool $has_session ) {
		$session = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'has_session', 'set', 'get', 'set_customer_session_cookie' ) )->getMock();
		$session->method( 'has_session' )->willReturn( $has_session );
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'session' => $session,
				'cart'    => null,
			)
		);

		return $session;
	}

	public function test_a_caller_without_a_woocommerce_session_gets_none_started() {
		$session = $this->woocommerce( false );
		$session->expects( $this->never() )->method( 'set_customer_session_cookie' );
		$session->expects( $this->never() )->method( 'set' );

		$this->controller->remember( 'product', 'h1', array( 'sessionToken' => 't', 'uuid' => 'u' ) );
	}

	public function test_a_caller_with_a_woocommerce_session_keeps_the_token_for_reuse() {
		$session = $this->woocommerce( true );
		$session->expects( $this->never() )->method( 'set_customer_session_cookie' );
		$session->expects( $this->once() )->method( 'set' )->with( 'wallee_ec_session', $this->callback( static function ( $entry ) {
			return 'product' === $entry['context'] && 't' === $entry['token'];
		} ) );

		$this->controller->remember( 'product', 'h1', array( 'sessionToken' => 't', 'uuid' => 'u' ) );
	}

	public function test_a_cart_request_without_a_woocommerce_session_is_refused_as_empty() {
		$this->woocommerce( false );
		Functions\when( 'wc_load_cart' )->justReturn( null );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'wallee_ec_cart_enabled' === $name ? 'yes' : $default;
			}
		);
		$request = new class() {
			public function get_param( $name ) {
				return 'context' === $name ? 'cart' : null;
			}
		};

		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'sessionToken' => null,
				'reason'       => 'empty',
			),
			$response->get_data()
		);
	}

	/**
	 * Sets the shared adapter of the add-on.
	 *
	 * @param MainPluginAdapter|null $adapter Adapter, null to reset.
	 */
	private function set_adapter( ?MainPluginAdapter $adapter ): void {
		$property = new \ReflectionProperty( Plugin::class, 'adapter' );
		$property->setAccessible( true );
		$property->setValue( null, $adapter );
	}

	public function test_any_error_while_building_the_snapshot_is_a_refusal_and_logs_the_class_only() {
		$adapter = new RecordingAdapter();
		$this->set_adapter( $adapter );
		Functions\when( 'WC' )->justReturn( (object) array( 'customer' => new \stdClass() ) );
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				throw new \Exception( 'third-party validator: secret detail' );
			}
		);
		$request = new class() {
			public function get_param( $name ) {
				return 'productId' === $name || 'quantity' === $name ? 1 : array();
			}
		};

		try {
			$this->assertNull( $this->controller->snapshotAndHash( 'product', $request ) );
		} finally {
			$this->set_adapter( null );
		}
		$this->assertSame( array( 'warning: Session refused: snapshot not built (Exception).' ), $adapter->logs );
	}

	public function test_every_session_route_response_is_marked_not_cacheable() {
		Functions\when( 'wp_get_nocache_headers' )->justReturn(
			array(
				'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
				'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
				'Last-Modified' => false,
			)
		);
		$route = static function ( string $path ) {
			return new class( $path ) {
				private $path;
				public function __construct( $path ) {
					$this->path = $path;
				}
				public function get_route() {
					return $this->path;
				}
			};
		};

		$refused = SessionController::noCache( new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 ), null, $route( '/wallee-ec/v1/session' ) );
		$this->assertSame(
			array(
				'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
				'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
			),
			$refused->get_headers()
		);

		$other = SessionController::noCache( new \WP_REST_Response( array(), 200 ), null, $route( '/wp/v2/posts' ) );
		$this->assertSame( array(), $other->get_headers() );
		$this->assertSame( 'x', SessionController::noCache( 'x', null, $route( '/wallee-ec/v1/session' ) ) );
	}

	public function test_the_no_cache_filter_is_registered() {
		SessionController::register();

		$this->assertSame( 10, has_filter( 'rest_post_dispatch', array( SessionController::class, 'noCache' ) ) );
	}
}

