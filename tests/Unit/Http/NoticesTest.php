<?php
/**
 * Notices tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Http\Notices;
use Wallee\ExpressCheckout\Tests\Support\FakeWc;

/**
 * @covers \Wallee\ExpressCheckout\Http\Notices
 */
class NoticesTest extends TestCase {

	const NOT_CHARGED = 'You have not been charged.';
	const RELEASED    = 'If an amount was reserved, it will be released.';

	/** @var FakeWc */
	private $wc;

	/** @var string[] */
	private $notices = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->wc      = new FakeWc();
		$this->notices = array();
		Functions\when( 'WC' )->justReturn( $this->wc );
		Functions\when( 'wc_add_notice' )->alias(
			function ( $message, $type ) {
				$this->notices[] = $type . ': ' . $message;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider reasons
	 *
	 * @param string $reason Reason code.
	 * @param string $lead   Expected first sentence.
	 * @param string $action Expected last sentence.
	 */
	public function test_each_reason_maps_to_its_message( string $reason, string $lead, string $action ) {
		$this->assertSame( $lead . ' ' . self::NOT_CHARGED . ' ' . $action, Notices::forReason( $reason ) );
		$this->assertSame( $lead . ' ' . self::RELEASED . ' ' . $action, Notices::forReason( $reason, Notices::CHARGE_UNKNOWN ) );
	}

	public function reasons(): array {
		$expired     = array( 'Your express payment session has expired.', 'Please start the express payment again.' );
		$destination = array( 'Express checkout is not available for the selected delivery address.', 'Please use the regular checkout to complete your order.' );
		$cart        = array( 'Your cart changed while you were paying.', 'Please review your cart and try again.' );
		$technical   = array( 'The express payment could not be completed because of a technical problem.', 'Please try again or use the regular checkout.' );

		return array(
			'not approved'          => array_merge( array( 'not_approved (PROCESSING)' ), $expired ),
			'link expired'          => array_merge( array( 'link_expired' ), $expired ),
			'shipping missing'      => array_merge( array( 'shipping_missing' ), $destination ),
			'shipping changed'      => array_merge( array( 'shipping_changed' ), $destination ),
			'destination changed'   => array_merge( array( 'destination_changed' ), $destination ),
			'out of stock'          => array( 'out_of_stock', 'An item in your order is no longer available in the requested quantity.', 'Please review your cart.' ),
			'coupon'                => array( 'coupon_invalid', 'The coupon in your cart cannot be used for this order.', 'Please remove the coupon or use the regular checkout.' ),
			'amount'                => array_merge( array( 'amount_mismatch' ), $cart ),
			'tax rate'              => array_merge( array( 'tax_rate_changed' ), $cart ),
			'order creation'        => array_merge( array( 'order_creation_failed (RuntimeException: tax_rate_changed)' ), $cart ),
			'currency'              => array_merge( array( 'currency_mismatch' ), $cart ),
			'rounding'              => array_merge( array( 'rounding' ), $cart ),
			'no e-mail'             => array( 'no_email', 'The wallet did not provide an e-mail address, which we need for your order.', 'Please use the regular checkout.' ),
			'payment not completed' => array( 'payment_not_completed', 'The payment was not completed.', 'Please try again or choose another payment method.' ),
			'session unavailable'   => array_merge( array( 'session_unavailable (ApiException)' ), $technical ),
			'environment'           => array_merge( array( 'environment_mismatch' ), $technical ),
			'process failed'        => array_merge( array( 'process_failed' ), $technical ),
			'redirect host'         => array_merge( array( 'redirect_host_unexpected' ), $technical ),
			'empty'                 => array_merge( array( '' ), $technical ),
		);
	}

	public function test_a_released_reservation_says_the_customer_will_not_be_charged() {
		$this->assertSame(
			'The coupon in your cart cannot be used for this order. The reservation on your payment method is being released. You will not be charged. Please remove the coupon or use the regular checkout.',
			Notices::forReason( 'coupon_invalid', Notices::CHARGE_RELEASED )
		);
	}

	public function test_the_destination_message_names_the_country() {
		$this->assertSame(
			'Express checkout is not available for deliveries to Germany. You have not been charged. Please use the regular checkout to complete your order.',
			Notices::forReason( 'destination_changed', Notices::CHARGE_NONE, 'de' )
		);
	}

	public function test_a_country_unknown_to_woocommerce_is_shown_by_its_code_and_an_invalid_one_is_not_shown() {
		$this->assertStringContainsString( 'deliveries to US.', Notices::forReason( 'destination_changed', Notices::CHARGE_NONE, 'US' ) );
		$this->assertStringContainsString( 'the selected delivery address.', Notices::forReason( 'destination_changed', Notices::CHARGE_NONE, '<b>' ) );
	}

	public function test_country_names_are_plain_text() {
		$this->assertSame( "\u{00C5}land Islands", Notices::countryName( 'AX' ) );
	}

	public function test_the_country_is_ignored_for_other_reasons() {
		$this->assertSame( Notices::forReason( 'out_of_stock' ), Notices::forReason( 'out_of_stock', Notices::CHARGE_NONE, 'DE' ) );
	}

	public function test_money_taken_has_its_own_message_with_the_order_number() {
		$message = Notices::paymentTaken( '1042' );

		$this->assertSame( 'Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order 1042.', $message );
		$this->assertStringNotContainsString( 'not been charged', $message );
	}

	public function test_wallet_messages_never_mention_a_charge() {
		$this->assertSame( 'Express checkout is not available for deliveries to Switzerland. Please choose another address or use the regular checkout.', Notices::walletDestination( 'CH' ) );
		$this->assertSame( 'We cannot deliver to the selected address with express checkout. Please choose another address or use the regular checkout.', Notices::walletNoShippingMethod() );
		$this->assertSame( 'Express checkout is not available for this delivery address. Please choose another address or use the regular checkout.', Notices::walletDestination( '' ), 'no country to name' );
	}

	public function test_processing_message_is_unchanged() {
		$this->assertSame( 'Your express payment is already being processed.', Notices::processing() );
	}

	public function test_adding_a_notice_starts_a_session_for_a_guest() {
		Notices::add( 'Message.' );

		$this->assertSame( array( true ), $this->wc->session->cookies );
		$this->assertSame( array( 'error: Message.' ), $this->notices );
	}

	public function test_adding_a_notice_keeps_an_existing_session() {
		$this->wc->session->has = true;

		Notices::add( 'Message.' );

		$this->assertSame( array(), $this->wc->session->cookies );
		$this->assertCount( 1, $this->notices );
	}
}
