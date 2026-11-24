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
use Wallee\ExpressCheckout\Tests\Support\FakeWc;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

require_once dirname( __DIR__, 2 ) . '/Support/wc-tax-stub.php';

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
		\WC_Tax::reset();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'WC' )->justReturn( new FakeWc() );
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
		$this->tax_basis( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stored_row( string $state, ?string $created_at = null, ?array $snapshot = null ): void {
		$this->db->next_row = array(
			'id'                => 1,
			'session_uuid'      => self::UUID,
			'wallee_session_id' => 99,
			'space_id'          => 4711,
			'snapshot'          => json_encode( null === $snapshot ? CartSnapshotTest::data() : $snapshot ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'state'             => $state,
			'order_id'          => null,
			'transaction_id'    => null,
			'created_at'        => null === $created_at ? gmdate( 'Y-m-d H:i:s', time() - 60 ) : $created_at,
		);
	}

	/**
	 * Taxes by the customer's address (or not).
	 *
	 * @param bool   $follows  Whether taxes follow the address.
	 * @param string $based_on WooCommerce's "Calculate tax based on" when they do.
	 */
	private function tax_basis( bool $follows, string $based_on = 'shipping' ): void {
		Functions\when( 'wc_tax_enabled' )->justReturn( $follows );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $based_on ) {
				if ( 'woocommerce_tax_based_on' === $name ) {
					return $based_on;
				}

				return 'woocommerce_shipping_tax_class' === $name ? 'inherit' : $fallback;
			}
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
		$this->assertSame( 'We cannot deliver to the selected address with express checkout. Please choose another address or use the regular checkout.', $result['body']['error']['message'] );
	}

	public function test_unavailable_country_has_its_own_message() {
		$this->provider->method( 'ratesFor' )->willReturn( array() );
		$this->provider->method( 'errorCodeFor' )->willReturn( 'COUNTRY_UNAVAILABLE' );

		$result = $this->handle( $this->body( array( 'countryCode' => 'US' ) ) );

		$this->assertSame( 'COUNTRY_UNAVAILABLE', $result['body']['error']['error'] );
		$this->assertSame( 'Express checkout is not available for deliveries to US. Please choose another address or use the regular checkout.', $result['body']['error']['message'], 'a country unknown to WooCommerce is shown by its code' );
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

	/**
	 * Statements that write the quote.
	 *
	 * @return string[]
	 */
	private function quote_writes(): array {
		return array_values(
			array_filter(
				$this->db->queries,
				static function ( $query ) {
					return 0 === strpos( $query, 'UPDATE wp_wallee_ec_session SET shipping_quote' );
				}
			)
		);
	}

	public function test_a_successful_calculation_stores_destination_and_rates_as_the_quote_while_the_row_is_created() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );
		$this->db->next_rows_affected = 1;

		$this->handle( $this->body( array( 'state' => 'ZH' ) ) );

		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'destination' => array(
					'country'  => 'CH',
					'state'    => 'ZH',
					'postcode' => '8000',
					'city'     => 'Zurich',
				),
				'rates'       => array( $this->rate() ),
			)
		);
		$writes = $this->quote_writes();
		$this->assertCount( 1, $writes );
		$this->assertStringContainsString( "SET shipping_quote = '" . addslashes( $json ) . "'", $writes[0] );
		$this->assertMatchesRegularExpression( "/ WHERE id = 1 AND state = 'created'$/", $writes[0] );
		$this->assertSame( array(), $this->logs );
	}

	public function test_no_rates_store_no_quote() {
		$this->provider->method( 'ratesFor' )->willReturn( array() );
		$this->provider->method( 'errorCodeFor' )->willReturn( 'SHIPPING_METHOD_UNAVAILABLE' );

		$this->handle( $this->body() );

		$this->assertSame( array(), $this->quote_writes() );
	}

	public function test_a_quote_that_matches_no_open_row_is_logged_and_the_response_is_unchanged() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );
		$this->db->next_rows_affected = 0;

		$result = $this->handle( $this->body() );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'flat_rate:1', $result['body']['shippingOptions'][0]['id'] );
		$this->assertCount( 1, $this->logs );
		$this->assertStringContainsString( 'was not stored', $this->logs[0] );
		$this->assertStringNotContainsString( 'Zurich', $this->logs[0] );
	}

	public function test_a_store_that_throws_is_logged_and_the_response_is_unchanged() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$result = $this->handle( $this->body() );

		$this->assertSame( 200, $result['status'] );
		$this->assertCount( 1, $this->logs );
		$this->assertStringContainsString( 'could not be stored', $this->logs[0] );
	}

	public function test_a_link_older_than_two_hours_is_404_and_nothing_is_calculated() {
		$this->stored_row( 'created', gmdate( 'Y-m-d H:i:s', time() - 7201 ) );
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$this->assertSame( 404, $this->handle( $this->body() )['status'] );
		$this->assertSame( array(), $this->quote_writes() );
	}

	public function test_a_link_just_under_two_hours_old_is_served() {
		$this->stored_row( 'created', gmdate( 'Y-m-d H:i:s', time() - 7100 ) );
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertSame( 200, $this->handle( $this->body() )['status'] );
	}

	public function test_a_destination_outside_the_tax_country_gets_no_options_when_taxes_follow_the_address() {
		$this->tax_basis( true );
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$result = $this->handle( $this->body( array( 'countryCode' => 'DE' ) ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), $result['body']['shippingOptions'] );
		$this->assertSame( 'COUNTRY_UNAVAILABLE', $result['body']['error']['error'] );
		$this->assertSame( 'Express checkout is not available for deliveries to Germany. Please choose another address or use the regular checkout.', $result['body']['error']['message'] );
		$this->assertStringNotContainsString( 'charged', $result['body']['error']['message'] );
		$this->assertSame( array(), $this->quote_writes(), 'no quote stored' );
	}

	public function test_the_tax_country_is_served_when_taxes_follow_the_address() {
		$this->tax_basis( true );
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertSame( 'flat_rate:1', $this->handle( $this->body( array( 'countryCode' => 'ch' ) ) )['body']['shippingOptions'][0]['id'] );
	}

	public function test_another_country_is_served_when_taxes_do_not_follow_the_address() {
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertSame( 'flat_rate:1', $this->handle( $this->body( array( 'countryCode' => 'DE' ) ) )['body']['shippingOptions'][0]['id'] );

		$this->tax_basis( true, 'base' );
		$this->stored_row( 'created' );
		$this->assertSame( 'flat_rate:1', $this->handle( $this->body( array( 'countryCode' => 'DE' ) ) )['body']['shippingOptions'][0]['id'], 'taxed at the shop base' );
	}

	public function test_another_state_is_served_when_the_rates_are_national() {
		$this->tax_basis( true );
		$data                          = CartSnapshotTest::data();
		$data['tax_location']['state'] = 'ZH';
		$this->stored_row( 'created', null, $data );
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertNull( $this->handle( $this->body( array( 'state' => 'BE' ) ) )['body']['error'] );
	}

	public function test_another_state_with_its_own_rates_gets_no_options_and_no_country_name() {
		$this->tax_basis( true );
		\WC_Tax::$rows[] = \WC_Tax::row( 20, 'US', '', 7.25, array( 'state' => 'CA' ) );
		\WC_Tax::$rows[] = \WC_Tax::row( 21, 'US', '', 4.0, array( 'state' => 'NY' ) );
		$data            = CartSnapshotTest::data();
		$data['tax_location'] = array(
			'country'  => 'US',
			'state'    => 'CA',
			'postcode' => '',
			'city'     => '',
		);
		$this->stored_row( 'created', null, $data );
		$this->provider->expects( $this->never() )->method( 'ratesFor' );

		$result = $this->handle( $this->body( array( 'countryCode' => 'US', 'state' => 'NY' ) ) );

		$this->assertSame( 'COUNTRY_UNAVAILABLE', $result['body']['error']['error'] );
		$this->assertSame( 'Express checkout is not available for this delivery address. Please choose another address or use the regular checkout.', $result['body']['error']['message'] );
	}

	public function test_a_postcode_with_other_rates_in_the_same_country_gets_no_options() {
		$this->tax_basis( true );
		\WC_Tax::$rows[] = \WC_Tax::row( 40, 'CH', '', 1.0, array( 'postcode' => '8000' ) );
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertSame( 'COUNTRY_UNAVAILABLE', $this->handle( $this->body( array( 'postalCode' => '3000' ) ) )['body']['error']['error'] );
		$this->stored_row( 'created' );
		$this->assertNull( $this->handle( $this->body() )['body']['error'], 'the tax location\'s postcode' );
	}

	public function test_no_default_tax_location_does_not_refuse_a_destination_without_rates() {
		$this->tax_basis( true );
		$data                 = CartSnapshotTest::data();
		$data['tax_location'] = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);
		$this->stored_row( 'created', null, $data );
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertNull( $this->handle( $this->body( array( 'countryCode' => 'US' ) ) )['body']['error'] );
		$this->stored_row( 'created', null, $data );
		$this->assertSame( 'COUNTRY_UNAVAILABLE', $this->handle( $this->body() )['body']['error']['error'], 'CH has rates' );
	}

	public function test_taxes_by_billing_address_are_left_to_the_return_check() {
		$this->tax_basis( true, 'billing' );
		$this->provider->method( 'ratesFor' )->willReturn( array( $this->rate() ) );

		$this->assertSame( 'flat_rate:1', $this->handle( $this->body( array( 'countryCode' => 'DE' ) ) )['body']['shippingOptions'][0]['id'] );
	}
}
