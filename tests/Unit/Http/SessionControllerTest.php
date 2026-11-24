<?php
/**
 * SessionController tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Http\SessionController;
use Wallee\ExpressCheckout\Security\RateLimiter;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

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

	public function test_both_rate_limit_buckets_are_consulted() {
		$this->assertTrue( $this->controller->rateAllows( '203.0.113.9' ) );

		$this->assertSame(
			array( 'wallee_ec_rl_' . md5( 'session|global' ), 'wallee_ec_rl_' . md5( 'session|203.0.113.9' ) ),
			$this->buckets
		);
	}

	public function test_per_ip_limit_denies_after_twenty() {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->controller->rateAllows( '203.0.113.9' ) );
		}
		$this->assertFalse( $this->controller->rateAllows( '203.0.113.9' ) );
		$this->assertTrue( $this->controller->rateAllows( '203.0.113.10' ) );
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
}
