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
}
