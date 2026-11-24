<?php
/**
 * SessionService tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Session;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Admin\Settings;
use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\LineItemBuilder;
use Wallee\ExpressCheckout\MainPluginAdapter;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Session\SessionService;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;
use Wallee\ExpressCheckout\Tests\Support\RecordingTransport;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

/**
 * Adapter with a fixed space ID.
 */
class SpaceAdapter extends MainPluginAdapter {
	public function spaceId(): int {
		return 4711;
	}
}

/**
 * @covers \Wallee\ExpressCheckout\Session\SessionService
 */
class SessionServiceTest extends TestCase {

	/** @var RecordingTransport */
	private $transport;

	/** @var FakeWpdb */
	private $db;

	/** @var SessionService */
	private $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.test' . $path;
			}
		);
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.test/wp-json/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'wallee_ec_environment' === $name ? 'PRODUCTION' : $default;
			}
		);

		$this->transport           = new RecordingTransport();
		$this->transport->response = array(
			'sessionId'    => 99,
			'sessionToken' => 'tok',
		);
		$this->db                  = new FakeWpdb();
		$this->service             = new SessionService(
			new ApiClient( $this->transport ),
			new SessionRepository( $this->db ),
			new LineItemBuilder(),
			new Settings(),
			new SpaceAdapter()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function query_of( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	public function test_payload_for_shippable_snapshot() {
		$result  = $this->service->create( CartSnapshot::fromArray( CartSnapshotTest::data() ), 'de-CH' );
		$payload = $this->transport->calls[0]['body'];

		$this->assertSame( 'tok', $result['sessionToken'] );
		$this->assertSame( 64, strlen( $result['uuid'] ) );
		$this->assertSame( 'CHF', $payload['currency'] );
		$this->assertSame( 'PRODUCTION', $payload['authorizationEnvironment'] );
		$this->assertSame( 'de-CH', $payload['language'] );
		$this->assertNotEmpty( $payload['lineItems'] );
		$this->assertArrayHasKey( 'merchantShippingCallbackUrl', $payload );
	}

	public function test_payload_without_shipping_callback_for_virtual_snapshot() {
		$snapshot = CartSnapshot::fromArray(
			CartSnapshotTest::data(
				array(
					'items'                  => array( CartSnapshotTest::item( array( 'needs_shipping' => false ) ) ),
					'shipping_package_count' => 0,
				)
			)
		);
		$this->service->create( $snapshot, 'en-US' );

		$this->assertArrayNotHasKey( 'merchantShippingCallbackUrl', $this->transport->calls[0]['body'] );
		$this->assertArrayHasKey( 'merchantRedirectUrl', $this->transport->calls[0]['body'] );
	}

	public function test_urls_share_uuid_and_signatures_verify_for_own_purpose_only() {
		$result  = $this->service->create( CartSnapshot::fromArray( CartSnapshotTest::data() ), 'en-US' );
		$payload = $this->transport->calls[0]['body'];
		$signer  = UrlSigner::fromWordPress();

		$return   = self::query_of( $payload['merchantRedirectUrl'] );
		$shipping = self::query_of( $payload['merchantShippingCallbackUrl'] );

		$this->assertSame( 'wallee_ec_return', $return['wc-api'] );
		$this->assertSame( $result['uuid'], $return['ec_session'] );
		$this->assertSame( $result['uuid'], $shipping['ec_session'] );
		$this->assertStringStartsWith( 'https://shop.test/wp-json/wallee-ec/v1/shipping-options?', $payload['merchantShippingCallbackUrl'] );

		$this->assertTrue( $signer->verify( $result['uuid'], 'return', $return['sig'] ) );
		$this->assertFalse( $signer->verify( $result['uuid'], 'shipping', $return['sig'] ) );
		$this->assertTrue( $signer->verify( $result['uuid'], 'shipping', $shipping['sig'] ) );
		$this->assertFalse( $signer->verify( $result['uuid'], 'return', $shipping['sig'] ) );
	}

	public function test_result_url() {
		$uuid = str_repeat( 'a', 64 );
		$url  = SessionService::resultUrl( $uuid, 'success' );
		$q    = self::query_of( $url );

		$this->assertSame( 'wallee_ec_result', $q['wc-api'] );
		$this->assertSame( 'success', $q['status'] );
		$this->assertSame( $uuid, $q['ec_session'] );
		$this->assertTrue( UrlSigner::fromWordPress()->verify( $uuid, 'result:success', $q['sig'] ) );
		$this->assertFalse( UrlSigner::fromWordPress()->verify( $uuid, 'return', $q['sig'] ) );
		$this->assertFalse( UrlSigner::fromWordPress()->verify( $uuid, 'result:failure', $q['sig'] ) );
		$failure = self::query_of( SessionService::resultUrl( $uuid, 'failure' ) );
		$this->assertTrue( UrlSigner::fromWordPress()->verify( $uuid, 'result:failure', $failure['sig'] ) );
		$this->assertFalse( UrlSigner::fromWordPress()->verify( $uuid, 'result:success', $failure['sig'] ) );
	}

	public function test_result_url_rejects_unknown_status() {
		$this->expectException( \InvalidArgumentException::class );
		SessionService::resultUrl( str_repeat( 'a', 64 ), 'pending' );
	}

	public function test_repository_receives_session_id_from_client() {
		$result = $this->service->create( CartSnapshot::fromArray( CartSnapshotTest::data() ), 'en-US' );
		$insert = $this->db->last_insert;

		$this->assertSame( $result['uuid'], $insert[1]['session_uuid'] );
		$this->assertSame( 99, $insert[1]['wallee_session_id'] );
		$this->assertSame( 4711, $insert[1]['space_id'] );
	}

	public function test_api_exception_is_rethrown_and_nothing_is_stored() {
		$failing = new class() implements \Wallee\ExpressCheckout\Api\Transport {
			public function request( string $method, string $path, array $query, ?array $body ): array {
				throw new ApiException( 'boom', 500 );
			}
		};
		$service = new SessionService(
			new ApiClient( $failing ),
			new SessionRepository( $this->db ),
			new LineItemBuilder(),
			new Settings(),
			new SpaceAdapter()
		);

		try {
			$service->create( CartSnapshot::fromArray( CartSnapshotTest::data() ), 'en-US' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertNull( $this->db->last_insert );
		}
	}
}

