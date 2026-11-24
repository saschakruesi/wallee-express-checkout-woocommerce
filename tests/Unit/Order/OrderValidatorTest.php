<?php
/**
 * OrderValidator tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Order\OrderValidator;
use Wallee\ExpressCheckout\Tests\Unit\Cart\CartSnapshotTest;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';
require_once dirname( __DIR__, 2 ) . '/Support/wc-product-stub.php';
require_once dirname( __DIR__, 2 ) . '/Support/wc-coupon-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Order\OrderValidator
 */
class OrderValidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Snapshot with a total of 50.00 CHF.
	 *
	 * @param bool $needs_shipping Whether the item needs shipping.
	 */
	private function snapshot( bool $needs_shipping = true ): CartSnapshot {
		return CartSnapshot::fromArray(
			array(
				'context'                => 'cart',
				'currency'               => 'CHF',
				'customer_id'            => 0,
				'prices_include_tax'     => true,
				'tax_location'           => array(
					'country'  => 'CH',
					'state'    => '',
					'postcode' => '8000',
					'city'     => 'Zurich',
				),
				'items'                  => array(
					array(
						'key'               => 'k1',
						'product_id'        => 10,
						'variation_id'      => 0,
						'variation'         => array(),
						'name'              => 'Notebook',
						'sku'               => 'NB',
						'quantity'          => 1,
						'line_subtotal'     => 46.26,
						'line_subtotal_tax' => 3.74,
						'line_total'        => 46.26,
						'line_tax'          => 3.74,
						'tax_class'         => '',
						'tax_rates'         => array(
							array(
								'label' => 'MWST',
								'rate'  => 8.1,
							),
						),
						'needs_shipping'    => $needs_shipping,
						'is_subscription'   => false,
					),
				),
				'fees'                   => array(),
				'coupons'                => array(),
				'shipping_package_count' => $needs_shipping ? 1 : 0,
			)
		);
	}

	/**
	 * Approved session with one selected option of 8.11.
	 *
	 * @param array $over Top level overrides.
	 */
	private function session( array $over = array() ): array {
		return array_merge(
			array(
				'state'               => 'APPROVED',
				'currency'            => 'CHF',
				'authorizationAmount' => 58.11,
				'shippingOptions'     => array(
					array(
						'id'                 => 'flat_rate:1',
						'label'              => 'Flat rate',
						'amountIncludingTax' => 8.11,
						'currency'           => 'CHF',
						'selected'           => true,
					),
				),
			),
			$over
		);
	}

	/**
	 * Quote with the rate flat_rate:1, 7.50 plus 0.61 tax.
	 */
	private function quote(): array {
		return array(
			'destination' => array(
				'country'  => 'CH',
				'state'    => '',
				'postcode' => '8400',
				'city'     => 'Winterthur',
			),
			'rates'       => array(
				array(
					'id'        => 'flat_rate:1',
					'label'     => 'Flat rate',
					'cost'      => 7.5,
					'tax'       => 0.61,
					'tax_rates' => array(
						array(
							'label' => 'MWST',
							'rate'  => 8.1,
						),
					),
				),
			),
		);
	}

	public function test_the_base_fixture_passes() {
		$this->assertNull( OrderValidator::check( $this->snapshot(), $this->session(), $this->quote() ) );
	}

	public function test_state_other_than_approved() {
		$session = $this->session( array( 'state' => 'PENDING' ) );
		$this->assertSame( 'not_approved', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_other_currency() {
		$session = $this->session( array( 'currency' => 'EUR' ) );
		$this->assertSame( 'currency_mismatch', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_no_selected_option() {
		$session                                       = $this->session();
		$session['shippingOptions'][0]['selected'] = false;
		$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_two_selected_options() {
		$session                      = $this->session();
		$session['shippingOptions'][] = array(
			'id'                 => 'flat_rate:2',
			'amountIncludingTax' => 9.0,
			'selected'           => true,
		);
		$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
		$this->assertNull( OrderValidator::selectedOption( $session ) );
	}

	public function test_no_stored_quote() {
		$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $this->session(), null ) );
	}

	public function test_selected_id_not_in_the_quote() {
		$session                                = $this->session();
		$session['shippingOptions'][0]['id'] = 'flat_rate:9';
		$this->assertSame( 'shipping_changed', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_selected_amount_differs_from_the_stored_rate() {
		$session                                                = $this->session();
		$session['shippingOptions'][0]['amountIncludingTax'] = 7.11;
		$this->assertSame( 'shipping_changed', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_authorization_amount_one_cent_too_low() {
		$session = $this->session( array( 'authorizationAmount' => 58.10 ) );
		$this->assertSame( 'amount_mismatch', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_authorization_amount_one_cent_too_high() {
		$session = $this->session( array( 'authorizationAmount' => 58.12 ) );
		$this->assertSame( 'amount_mismatch', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_float_noise_in_the_amounts_is_tolerated() {
		$session                                                = $this->session( array( 'authorizationAmount' => 58.110000000000001 ) );
		$session['shippingOptions'][0]['amountIncludingTax'] = 8.1100000001;
		$this->assertNull( OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_numeric_strings_are_accepted_as_amounts() {
		$session                                                = $this->session( array( 'authorizationAmount' => '58.11' ) );
		$session['shippingOptions'][0]['amountIncludingTax'] = '8.11';
		$this->assertNull( OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_a_quote_with_an_empty_rate_list_is_shipping_changed_and_one_without_rates_is_missing() {
		$quote          = $this->quote();
		$quote['rates'] = array();
		$this->assertSame( 'shipping_changed', OrderValidator::check( $this->snapshot(), $this->session(), $quote ) );
		$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $this->session(), array( 'destination' => array() ) ) );
	}

	public function test_a_quote_whose_rates_is_not_an_array_is_shipping_missing() {
		$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $this->session(), array( 'rates' => 'oops' ) ) );
	}

	public function test_a_free_rate_selected_at_zero_passes() {
		$session                                                = $this->session( array( 'authorizationAmount' => 50.00 ) );
		$session['shippingOptions'][0]['amountIncludingTax'] = 0;
		$quote                                                  = $this->quote();
		$quote['rates'][0]['cost']                              = 0.0;
		$quote['rates'][0]['tax']                               = 0.0;
		$this->assertNull( OrderValidator::check( $this->snapshot(), $session, $quote ) );
	}

	public function test_a_negative_authorization_amount_is_amount_mismatch() {
		$session = $this->session( array( 'authorizationAmount' => -58.11 ) );
		$this->assertSame( 'amount_mismatch', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_matched_rate_is_the_stored_rate() {
		$rate = OrderValidator::matchedRate( $this->session(), $this->quote() );
		$this->assertSame( 7.5, $rate['cost'] );
		$this->assertSame( 'Flat rate', $rate['label'] );
		$this->assertNull( OrderValidator::matchedRate( $this->session(), null ) );
		$this->assertNull( OrderValidator::matchedRate( $this->session( array( 'shippingOptions' => array() ) ), $this->quote() ) );
	}

	public function test_a_snapshot_without_shipping_passes_with_the_snapshot_total() {
		$session = $this->session(
			array(
				'authorizationAmount' => 50.00,
				'shippingOptions'     => array(),
			)
		);
		$this->assertNull( OrderValidator::check( $this->snapshot( false ), $session, null ) );

		unset( $session['shippingOptions'] );
		$this->assertNull( OrderValidator::check( $this->snapshot( false ), $session, null ) );
	}

	public function test_a_snapshot_without_shipping_refuses_a_selected_option() {
		$this->assertSame( 'shipping_changed', OrderValidator::check( $this->snapshot( false ), $this->session(), $this->quote() ) );
	}

	public function test_a_snapshot_without_shipping_refuses_a_total_that_includes_shipping() {
		$session = $this->session( array( 'shippingOptions' => array() ) );
		$this->assertSame( 'amount_mismatch', OrderValidator::check( $this->snapshot( false ), $session, null ) );
	}

	public function test_a_session_with_only_a_state_does_not_raise_notices() {
		$previous = set_error_handler(
			static function ( $severity, $message ) {
				throw new \ErrorException( $message, 0, $severity ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		);
		try {
			$session = array( 'state' => 'APPROVED' );
			$this->assertSame( 'currency_mismatch', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
			$this->assertSame( 'not_approved', OrderValidator::check( $this->snapshot(), array(), null ) );
			$this->assertNull( OrderValidator::selectedOption( $session ) );
			$this->assertNull( OrderValidator::matchedRate( $session, $this->quote() ) );

			$session['currency'] = 'CHF';
			$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
			$this->assertSame( 'amount_mismatch', OrderValidator::check( $this->snapshot( false ), $session, null ) );
		} finally {
			set_error_handler( $previous ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		}
	}

	public function test_malformed_option_and_quote_values_are_refused_not_fatal() {
		$session                                                = $this->session();
		$session['shippingOptions'][0]['amountIncludingTax'] = 'abc';
		$this->assertSame( 'shipping_changed', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );

		$quote                     = $this->quote();
		$quote['rates'][0]['cost'] = 'x';
		$this->assertSame( 'shipping_changed', OrderValidator::check( $this->snapshot(), $this->session(), $quote ) );

		$session = $this->session( array( 'shippingOptions' => 'oops' ) );
		$this->assertSame( 'shipping_missing', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );

		$session = $this->session( array( 'authorizationAmount' => array( 58.11 ) ) );
		$this->assertSame( 'amount_mismatch', OrderValidator::check( $this->snapshot(), $session, $this->quote() ) );
	}

	public function test_email_prefers_the_billing_address() {
		$transaction = array(
			'billingAddress'       => array( 'emailAddress' => 'billing@example.com' ),
			'shippingAddress'      => array( 'emailAddress' => 'shipping@example.com' ),
			'customerEmailAddress' => 'customer@example.com',
		);
		$this->assertSame( 'billing@example.com', OrderValidator::email( $transaction, 0 ) );
	}

	public function test_email_falls_back_to_the_shipping_address() {
		$transaction = array(
			'billingAddress'       => array( 'givenName' => 'A' ),
			'shippingAddress'      => array( 'emailAddress' => 'shipping@example.com' ),
			'customerEmailAddress' => 'customer@example.com',
		);
		$this->assertSame( 'shipping@example.com', OrderValidator::email( $transaction, 0 ) );
	}

	public function test_email_falls_back_to_the_transaction_customer_address() {
		$transaction = array( 'customerEmailAddress' => 'customer@example.com' );
		$this->assertSame( 'customer@example.com', OrderValidator::email( $transaction, 0 ) );
	}

	public function test_email_falls_back_to_the_logged_in_customer() {
		Functions\expect( 'get_userdata' )->once()->with( 7 )->andReturn( (object) array( 'user_email' => 'user@example.com' ) );
		$this->assertSame( 'user@example.com', OrderValidator::email( array(), 7 ) );
	}

	public function test_email_skips_invalid_addresses() {
		$transaction = array(
			'billingAddress'       => array( 'emailAddress' => 'not-an-email' ),
			'shippingAddress'      => array( 'emailAddress' => '' ),
			'customerEmailAddress' => 'customer@example.com',
		);
		$this->assertSame( 'customer@example.com', OrderValidator::email( $transaction, 0 ) );

		$transaction['customerEmailAddress'] = array( 'x' );
		$this->assertNull( OrderValidator::email( $transaction, 0 ) );
	}

	public function test_email_is_null_when_nothing_is_known_and_a_guest_is_not_looked_up() {
		Functions\expect( 'get_userdata' )->never();
		$this->assertNull( OrderValidator::email( array(), 0 ) );
	}

	public function test_email_ignores_a_customer_without_a_valid_address() {
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->assertNull( OrderValidator::email( array(), 7 ) );
	}

	public function test_environment_must_match_the_configured_one() {
		$this->assertNull( OrderValidator::environment( array( 'authorizationEnvironment' => 'TEST' ), 'TEST' ) );
		$this->assertNull( OrderValidator::environment( array( 'authorizationEnvironment' => 'PRODUCTION' ), 'PRODUCTION' ) );
		$this->assertSame( 'environment_mismatch', OrderValidator::environment( array( 'authorizationEnvironment' => 'TEST' ), 'PRODUCTION' ) );
		$this->assertSame( 'environment_mismatch', OrderValidator::environment( array( 'authorizationEnvironment' => 'PRODUCTION' ), 'TEST' ) );
		$this->assertSame( 'environment_mismatch', OrderValidator::environment( array( 'authorizationEnvironment' => 'test' ), 'TEST' ) );
		$this->assertSame( 'environment_mismatch', OrderValidator::environment( array(), 'TEST' ) );
		$this->assertSame( 'environment_mismatch', OrderValidator::environment( array( 'authorizationEnvironment' => array( 'TEST' ) ), 'TEST' ) );
	}

	public function test_taxes_follow_the_address_unless_disabled_or_based_on_the_shop() {
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		foreach ( array( 'shipping' => true, 'billing' => true, 'base' => false ) as $basis => $expected ) {
			Functions\when( 'get_option' )->justReturn( $basis );
			$this->assertSame( $expected, OrderValidator::taxFollowsAddress(), $basis );
		}
		Functions\when( 'wc_tax_enabled' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		$this->assertFalse( OrderValidator::taxFollowsAddress() );
	}

	public function test_tax_location_compares_country_and_a_set_state() {
		$snapshot = $this->snapshot();
		$this->assertTrue( OrderValidator::inTaxLocation( $snapshot, 'ch', 'ZH' ), 'no state in the tax location' );
		$this->assertFalse( OrderValidator::inTaxLocation( $snapshot, 'DE', null ) );

		$data                          = CartSnapshotTest::data();
		$data['tax_location']['state'] = 'CA';
		$data['tax_location']['country'] = 'US';
		$state                         = CartSnapshot::fromArray( $data );
		$this->assertTrue( OrderValidator::inTaxLocation( $state, 'US', 'ca' ) );
		$this->assertFalse( OrderValidator::inTaxLocation( $state, 'US', 'NY' ) );
		$this->assertFalse( OrderValidator::inTaxLocation( $state, 'US', '' ) );
		$this->assertTrue( OrderValidator::inTaxLocation( $state, 'US', null ), 'country only' );
	}

	public function test_destination_must_match_the_quote_country() {
		$quote = array( 'destination' => array( 'country' => 'CH' ) );

		$this->assertNull( OrderValidator::destination( $this->snapshot(), $quote, 'CH', false ) );
		$this->assertNull( OrderValidator::destination( $this->snapshot(), $quote, 'ch', true ) );
		$this->assertSame( 'destination_changed', OrderValidator::destination( $this->snapshot(), $quote, 'DE', false ) );
		$this->assertNull( OrderValidator::destination( $this->snapshot(), $quote, '', true ), 'unknown country' );
	}

	public function test_destination_must_match_the_tax_country_only_when_taxes_follow_the_address() {
		$this->assertNull( OrderValidator::destination( $this->snapshot(), null, 'DE', false ) );
		$this->assertSame( 'destination_changed', OrderValidator::destination( $this->snapshot(), null, 'DE', true ) );
		$this->assertSame(
			'destination_changed',
			OrderValidator::destination( $this->snapshot(), array( 'destination' => array( 'country' => 'DE' ) ), 'DE', true ),
			'quote and address agree, but both are outside the tax location'
		);
	}

	/**
	 * Snapshot with coupon `once` for a guest or a customer.
	 *
	 * @param int $customer_id Customer.
	 */
	private function coupon_snapshot( int $customer_id = 0 ): CartSnapshot {
		return CartSnapshot::fromArray(
			CartSnapshotTest::data(
				array(
					'coupons'     => array( 'once' ),
					'customer_id' => $customer_id,
				)
			)
		);
	}

	private function coupon_world( array $coupon ): void {
		\WC_Coupon::$registry = array( 'once' => array_merge( array( 'id' => 5 ), $coupon ) );
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) {
				return new \WC_Product( array( 'id' => $id ) );
			}
		);
		Functions\when( 'sanitize_email' )->returnArg();
	}

	public function test_an_email_restricted_coupon_is_deferred_until_the_email_is_known() {
		$this->coupon_world( array( 'email_restrictions' => array( '*@example.test' ) ) );

		$this->assertNull( OrderValidator::stockAndCoupons( $this->coupon_snapshot() ), 'accepted before the e-mail is known' );
		$this->assertNull( OrderValidator::customerCoupons( $this->coupon_snapshot(), 'Buyer@Example.test' ) );
		$this->assertSame( 'coupon_invalid', OrderValidator::customerCoupons( $this->coupon_snapshot(), 'buyer@other.test' ) );
		$this->assertSame( array( '*@example.test' ), ( new \WC_Coupon( 'once' ) )->get_email_restrictions(), 'the stored coupon is not changed' );
	}

	public function test_a_guest_who_used_up_the_per_user_limit_is_refused_by_email() {
		$this->coupon_world(
			array(
				'usage_limit_per_user' => 1,
				'usage_by_email'       => array( 'used@example.test' => 1 ),
			)
		);

		$this->assertNull( OrderValidator::stockAndCoupons( $this->coupon_snapshot() ) );
		$this->assertSame( 'coupon_invalid', OrderValidator::customerCoupons( $this->coupon_snapshot(), 'Used@Example.test' ) );
		$this->assertNull( OrderValidator::customerCoupons( $this->coupon_snapshot(), 'new@example.test' ) );
	}

	public function test_a_customer_with_an_account_is_counted_by_woocommerce_per_user() {
		$this->coupon_world(
			array(
				'usage_limit_per_user' => 1,
				'usage_by_email'       => array( 'used@example.test' => 1 ),
				'usage_by_user'        => array( 42 => 1 ),
			)
		);

		$this->assertSame( 'coupon_invalid', OrderValidator::customerCoupons( $this->coupon_snapshot( 42 ), 'new@example.test' ) );
		$this->assertNull( OrderValidator::customerCoupons( $this->coupon_snapshot( 43 ), 'used@example.test' ), 'accounts are not counted by e-mail' );
	}

	public function test_an_invalid_or_unknown_coupon_is_refused_in_both_checks() {
		$this->coupon_world( array( 'valid' => false ) );
		$this->assertSame( 'coupon_invalid', OrderValidator::stockAndCoupons( $this->coupon_snapshot() ) );
		$this->assertSame( 'coupon_invalid', OrderValidator::customerCoupons( $this->coupon_snapshot(), 'a@example.test' ) );

		$this->coupon_world( array( 'id' => 0 ) );
		$this->assertSame( 'coupon_invalid', OrderValidator::customerCoupons( $this->coupon_snapshot(), 'a@example.test' ) );
	}

	public function test_no_coupons_need_no_lookup() {
		Functions\expect( 'wc_get_product' )->never();

		$this->assertNull( OrderValidator::customerCoupons( CartSnapshot::fromArray( CartSnapshotTest::data( array( 'coupons' => array() ) ) ), 'a@example.test' ) );
	}
}

