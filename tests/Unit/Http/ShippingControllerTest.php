<?php
/**
 * ShippingController tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Http\ShippingController;
use Wallee\ExpressCheckout\Security\RateLimiter;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Shipping\ShippingOptionsProvider;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

/**
 * @covers \Wallee\ExpressCheckout\Http\ShippingController
 */
class ShippingControllerTest extends TestCase {

	const UUID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** @var FakeWpdb */
	private $db;

	/** @var UrlSigner */
	private $signer;

	/** @var ShippingOptionsProvider&\PHPUnit\Framework\MockObject\MockObject */
	private $provider;

	/** @var ShippingController */
	private $controller;

	/** @var array Transient store. */
	private $transients = array();

	/** @var string[] Log lines. */
	private $logs = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return isset( $this->transients[ $key ] ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		$this->transients = array();
		$this->logs       = array();
		$this->db         = new FakeWpdb();
		$this->signer     = new UrlSigner( 'test-key' );
		$this->provider   = $this->createMock( ShippingOptionsProvider::class );
		$this->controller = new ShippingController(
			new SessionRepository( $this->db ),
			$this->signer,
			$this->provider,
			new RateLimiter(),
			function ( $message ) {
				$this->logs[] = $message;
			}
		);
		$this->stored_row( 'created' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stored_row( string $state ): void {
		$this->db->next_row = array(
			'id'                => 1,
			'session_uuid'      => self::UUID,
			'wallee_session_id' => 99,
			'space_id'          => 4711,
			'snapshot'          => json_encode( CartSnapshotTest::data() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'state'             => $state,
			'order_id'          => null,
			'transaction_id'    => null,
			'created_at'        => '2026-11-24 10:00:00',
		);
	}

	private function body( array $over = array() ): array {
		return array_merge(
			array(
				'expressCheckoutId' => 99,
				'spaceId'           => 4711,
				'requestId'         => 'r1',
				'countryCode'       => 'CH',
				'currency'          => 'CHF',
				'language'          => 'en-US',
				'postalCode'        => '8000',
				'city'              => 'Zurich',
				'street'            => 'Somestreet 1',
				'unknownKey'        => 'ignored',
			),
			$over
		);
	}

	private function handle( array $body, ?string $sig = null, string $uuid = self::UUID ): array {
		$sig = null === $sig ? $this->signer->sign( $uuid, 'shipping' ) : $sig;

		return $this->controller->handle( $uuid, $sig, $body );
	}

	private function rate(): array {
		return array(
			'id'        => 'flat_rate:1',
			'label'     => 'Flat rate',
			'cost'      => 7.5,
			'tax'       => 0.61,
			'tax_rates' => array(
				array(
					'label' => 'VAT',
					'rate'  => 8.1,
				),
			),
		);
	}

	public function test_wrong_signature_is_403_and_nothing_is_calculated() {
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$result = $this->handle( $this->body(), 'bad' );

		$this->assertSame( 403, $result['status'] );
		$this->assertSame( array(), $this->db->queries, 'The row is not even looked up.' );
	}

	public function test_signature_of_another_purpose_is_refused() {
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 403, $this->handle( $this->body(), $this->signer->sign( self::UUID, 'return' ) )['status'] );
	}

	public function test_unknown_session_is_404() {
		$this->db->next_row = null;
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 404, $this->handle( $this->body() )['status'] );
	}

	public function test_used_session_is_409() {
		$this->stored_row( 'processed' );
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 409, $this->handle( $this->body() )['status'] );
	}

	public function test_different_express_checkout_id_is_403() {
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 403, $this->handle( $this->body( array( 'expressCheckoutId' => 100 ) ) )['status'] );
	}

	public function test_different_or_missing_space_id_is_403() {
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 403, $this->handle( $this->body( array( 'spaceId' => 1 ) ) )['status'] );
		$body = $this->body();
		unset( $body['spaceId'] );
		$this->assertSame( 403, $this->handle( $body )['status'] );
	}

	public function test_ids_may_arrive_as_numeric_strings() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertSame( 200, $this->handle( $this->body( array( 'expressCheckoutId' => '99', 'spaceId' => '4711' ) ) )['status'] );
	}

	/**
	 * @dataProvider bad_countries
	 *
	 * @param mixed $country Country value.
	 */
	public function test_country_code_must_be_two_letters( $country ) {
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 400, $this->handle( $this->body( array( 'countryCode' => $country ) ) )['status'] );
	}

	public function bad_countries(): array {
		return array(
			'three letters' => array( 'CHE' ),
			'one letter'    => array( 'C' ),
			'digits'        => array( '12' ),
			'empty'         => array( '' ),
			'array'         => array( array( 'CH' ) ),
			'null'          => array( null ),
		);
	}

	public function test_rate_limit_is_per_session_sixty_per_minute() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		for ( $i = 0; $i < 60; $i++ ) {
			$this->assertSame( 200, $this->handle( $this->body() )['status'] );
		}
		$this->assertSame( 429, $this->handle( $this->body() )['status'] );
	}

	public function test_rates_become_options_with_an_error_of_null() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$result = $this->handle( $this->body() );

		$this->assertSame( 200, $result['status'] );
		$this->assertNull( $result['body']['error'] );
		$this->assertSame( 'flat_rate:1', $result['body']['shippingOptions'][0]['id'] );
		$this->assertSame( 8.11, $result['body']['shippingOptions'][0]['amountIncludingTax'] );
		$this->assertSame( 'CHF', $result['body']['shippingOptions'][0]['currency'] );
	}

	public function test_no_rates_is_200_with_the_error_code_of_the_provider() {
		$this->provider->method( 'ratesFor' )->willReturn( array() );
		$this->provider->method( 'errorCodeFor' )->willReturn( 'SHIPPING_METHOD_UNAVAILABLE' );

		$result = $this->handle( $this->body() );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), $result['body']['shippingOptions'] );
		$this->assertSame( 'SHIPPING_METHOD_UNAVAILABLE', $result['body']['error']['error'] );
		$this->assertSame( 'No shipping method is available for this address.', $result['body']['error']['message'] );
	}

	public function test_unavailable_country_has_its_own_message() {
		$this->provider->method( 'ratesFor' )->willReturn( array() );
		$this->provider->method( 'errorCodeFor' )->willReturn( 'COUNTRY_UNAVAILABLE' );

		$result = $this->handle( $this->body( array( 'countryCode' => 'US' ) ) );

		$this->assertSame( 'COUNTRY_UNAVAILABLE', $result['body']['error']['error'] );
		$this->assertSame( 'We cannot ship to this address.', $result['body']['error']['message'] );
	}

	public function test_lower_case_country_is_accepted_and_passed_on_upper_case() {
		$this->provider->expects( $this->once() )
			->method( 'ratesFor' )
			->with(
				$this->anything(),
				$this->callback(
					static function ( $destination ) {
						return 'CH' === $destination['country']
							&& '8000' === $destination['postcode']
							&& 'Zurich' === $destination['city']
							&& 'Somestreet 1' === $destination['address_1']
							&& '' === $destination['state'];
					}
				)
			)
			->willReturn( array( $this->rate() ) );

		$this->assertSame( 200, $this->handle( $this->body( array( 'countryCode' => 'ch' ) ) )['status'] );
	}

	public function test_optional_keys_may_be_absent() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$result = $this->handle(
			array(
				'expressCheckoutId' => 99,
				'spaceId'           => 4711,
				'countryCode'       => 'DE',
			)
		);

		$this->assertSame( 200, $result['status'] );
	}

	public function test_provider_failure_is_a_500_and_the_log_carries_no_address() {
		$this->provider->method( 'ratesFor' )->willThrowException( new \RuntimeException( 'boom Somestreet 1' ) );

		$result = $this->handle( $this->body() );

		$this->assertSame( 500, $result['status'] );
		$this->assertNotEmpty( $this->logs );
		foreach ( $this->logs as $line ) {
			$this->assertStringNotContainsString( 'Somestreet', $line );
			$this->assertStringNotContainsString( '8000', $line );
		}
	}

	public function test_a_successful_calculation_stores_destination_and_rates_as_the_quote() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->handle( $this->body( array( 'state' => 'ZH' ) ) );

		$this->assertSame( 'UPDATE wp_wallee_ec_session', end( $this->db->queries ) );
		$this->assertSame( 1, $this->db->last_update[2]['id'] );
		$this->assertSame(
			json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'destination' => array(
						'country'  => 'CH',
						'state'    => 'ZH',
						'postcode' => '8000',
						'city'     => 'Zurich',
					),
					'rates'       => array( $this->rate() ),
				)
			),
			$this->db->last_update[1]['shipping_quote']
		);
	}

	public function test_no_rates_store_no_quote() {
		$this->provider->method( 'ratesFor' )->willReturn( array() );
		$this->provider->method( 'errorCodeFor' )->willReturn( 'SHIPPING_METHOD_UNAVAILABLE' );

		$this->handle( $this->body() );

		$this->assertNull( $this->db->last_update );
	}

	public function test_a_failed_store_is_logged_and_the_response_is_unchanged() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );
		$this->db->next_update_result = false;

		$result = $this->handle( $this->body() );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'flat_rate:1', $result['body']['shippingOptions'][0]['id'] );
		$this->assertCount( 1, $this->logs );
		$this->assertStringContainsString( 'could not be stored', $this->logs[0] );
		$this->assertStringNotContainsString( 'Zurich', $this->logs[0] );
	}
}
