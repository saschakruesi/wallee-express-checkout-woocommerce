<?php
/**
 * Customer-facing messages of the express payment flow.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns internal reason codes into what the customer
 * reads: what happened, whether they were charged, what to do next. Reason
 * codes themselves only go to logs and private order notes.
 *
 * A message states "You have not been charged." only where the caller knows
 * no payment happened (CHARGE_NONE); where that is uncertain (CHARGE_UNKNOWN)
 * it says "If an amount was reserved, it will be released." instead; where a
 * reservation's void was requested (CHARGE_RELEASED) it says the reservation
 * is being released. Money already reserved or taken that is not released
 * has its own message (paymentTaken()).
 */
final class Notices {

	/**
	 * No payment happened.
	 */
	const CHARGE_NONE = 'none';

	/**
	 * It is not known whether an amount was reserved.
	 */
	const CHARGE_UNKNOWN = 'unknown';

	/**
	 * An amount was reserved and its release (void) was requested.
	 */
	const CHARGE_RELEASED = 'released';

	/**
	 * Reason code for a link used after its lifetime.
	 */
	const LINK_EXPIRED = 'link_expired';

	/**
	 * Reason code when express checkout cannot run (main plugin unusable or
	 * SDK compatibility shim unavailable).
	 */
	const UNAVAILABLE = 'express_unavailable';

	/**
	 * Reason code for the result handler's failure URL.
	 */
	const PAYMENT_NOT_COMPLETED = 'payment_not_completed';

	/**
	 * Reason codes by message.
	 */
	const REASONS = array(
		'expired'     => array( 'not_approved', self::LINK_EXPIRED ),
		'destination' => array( 'shipping_missing', 'shipping_changed', 'destination_changed' ),
		'stock'       => array( 'out_of_stock' ),
		'coupon'      => array( 'coupon_invalid' ),
		'cart'        => array( 'amount_mismatch', 'tax_rate_changed', 'order_creation_failed', 'currency_mismatch', 'rounding', 'rounding_mismatch' ),
		'email'       => array( 'no_email' ),
		'payment'     => array( self::PAYMENT_NOT_COMPLETED ),
		'unavailable' => array( self::UNAVAILABLE ),
	);

	/**
	 * Message for a failure with a reason code. Anything not listed in
	 * REASONS is a technical problem.
	 *
	 * @param string $reason  Reason code; anything after the leading code (` (detail)`) is ignored.
	 * @param string $charge  CHARGE_NONE, CHARGE_UNKNOWN or CHARGE_RELEASED.
	 * @param string $country ISO country code of the delivery address, '' if unknown (destination reasons only).
	 */
	public static function forReason( string $reason, string $charge = self::CHARGE_NONE, string $country = '' ): string {
		if ( self::CHARGE_NONE === $charge ) {
			$charged = __( 'You have not been charged.', 'wallee-express-checkout' );
		} elseif ( self::CHARGE_RELEASED === $charge ) {
			$charged = __( 'The reservation on your payment method is being released. You will not be charged.', 'wallee-express-checkout' );
		} else {
			$charged = __( 'If an amount was reserved, it will be released.', 'wallee-express-checkout' );
		}

		switch ( self::kind( $reason ) ) {
			case 'expired':
				$lead   = __( 'Your express payment session has expired.', 'wallee-express-checkout' );
				$action = __( 'Please start the express payment again.', 'wallee-express-checkout' );
				break;
			case 'destination':
				if ( '' === self::code( $country ) ) {
					$lead = __( 'Express checkout is not available for the selected delivery address.', 'wallee-express-checkout' );
				} else {
					/* translators: %s: country name. */
					$lead = sprintf( __( 'Express checkout is not available for deliveries to %s.', 'wallee-express-checkout' ), self::countryName( $country ) );
				}
				$action = __( 'Please use the regular checkout to complete your order.', 'wallee-express-checkout' );
				break;
			case 'stock':
				$lead   = __( 'An item in your order is no longer available in the requested quantity.', 'wallee-express-checkout' );
				$action = __( 'Please review your cart.', 'wallee-express-checkout' );
				break;
			case 'coupon':
				$lead   = __( 'The coupon in your cart cannot be used for this order.', 'wallee-express-checkout' );
				$action = __( 'Please remove the coupon or use the regular checkout.', 'wallee-express-checkout' );
				break;
			case 'cart':
				$lead   = __( 'Your cart changed while you were paying.', 'wallee-express-checkout' );
				$action = __( 'Please review your cart and try again.', 'wallee-express-checkout' );
				break;
			case 'email':
				$lead   = __( 'The wallet did not provide an e-mail address, which we need for your order.', 'wallee-express-checkout' );
				$action = __( 'Please use the regular checkout.', 'wallee-express-checkout' );
				break;
			case 'payment':
				$lead   = __( 'The payment was not completed.', 'wallee-express-checkout' );
				$action = __( 'Please try again or choose another payment method.', 'wallee-express-checkout' );
				break;
			case 'unavailable':
				$lead   = __( 'Express checkout is currently not available.', 'wallee-express-checkout' );
				$action = __( 'Please use the regular checkout.', 'wallee-express-checkout' );
				break;
			default:
				$lead   = __( 'The express payment could not be completed because of a technical problem.', 'wallee-express-checkout' );
				$action = __( 'Please try again or use the regular checkout.', 'wallee-express-checkout' );
		}

		return $lead . ' ' . $charged . ' ' . $action;
	}

	/**
	 * Message for a failed order whose money is already reserved or taken.
	 *
	 * @param string $order_number Order number as shown to the customer.
	 */
	public static function paymentTaken( string $order_number ): string {
		return sprintf(
			/* translators: %s: order number. */
			__( 'Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order %s.', 'wallee-express-checkout' ),
			$order_number
		);
	}

	/**
	 * Message for a return while another request is placing the order.
	 */
	public static function processing(): string {
		return __( 'Your express payment is already being processed.', 'wallee-express-checkout' );
	}

	/**
	 * Wallet message (shipping callback) for a destination express checkout
	 * does not serve. Nothing was paid at that point.
	 *
	 * @param string $country ISO country code of the destination, '' when the refusal is not about
	 *                        the country (another state, postcode or city with other tax rates).
	 */
	public static function walletDestination( string $country ): string {
		if ( '' === self::code( $country ) ) {
			return __( 'Express checkout is not available for this delivery address. Please choose another address or use the regular checkout.', 'wallee-express-checkout' );
		}

		return sprintf(
			/* translators: %s: country name. */
			__( 'Express checkout is not available for deliveries to %s. Please choose another address or use the regular checkout.', 'wallee-express-checkout' ),
			self::countryName( $country )
		);
	}

	/**
	 * Wallet message (shipping callback) when no shipping method serves the address.
	 */
	public static function walletNoShippingMethod(): string {
		return __( 'We cannot deliver to the selected address with express checkout. Please choose another address or use the regular checkout.', 'wallee-express-checkout' );
	}

	/**
	 * WooCommerce's name of a country, the code itself if unknown.
	 *
	 * @param string $country ISO country code.
	 */
	public static function countryName( string $country ): string {
		$code = self::code( $country );
		$wc   = function_exists( 'WC' ) ? WC() : null;
		$list = is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) && isset( $wc->countries->countries ) && is_array( $wc->countries->countries )
			? $wc->countries->countries
			: array();
		if ( '' !== $code && isset( $list[ $code ] ) && is_string( $list[ $code ] ) && '' !== $list[ $code ] ) {
			return html_entity_decode( $list[ $code ], ENT_QUOTES, 'UTF-8' );
		}

		return $code;
	}

	/**
	 * Adds an error notice for the next page the customer sees. The customer
	 * is redirected to the cart right after, so a guest without a WooCommerce
	 * session gets one (session cookie); otherwise the notice would be lost.
	 *
	 * @param string $message Message.
	 */
	public static function add( string $message ): void {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( is_object( $wc ) ) {
			if ( ! isset( $wc->session ) && method_exists( $wc, 'initialize_session' ) ) {
				$wc->initialize_session();
			}
			$session = isset( $wc->session ) ? $wc->session : null;
			if ( is_object( $session ) && method_exists( $session, 'has_session' ) && method_exists( $session, 'set_customer_session_cookie' ) && ! $session->has_session() ) {
				$session->set_customer_session_cookie( true );
			}
		}
		wc_add_notice( $message, 'error' );
	}

	/**
	 * The message group of a reason code.
	 *
	 * @param string $reason Reason code, possibly followed by a detail.
	 */
	private static function kind( string $reason ): string {
		$code = 1 === preg_match( '/^[a-z_]+/', $reason, $match ) ? $match[0] : '';
		foreach ( self::REASONS as $kind => $codes ) {
			if ( in_array( $code, $codes, true ) ) {
				return $kind;
			}
		}

		return 'technical';
	}

	/**
	 * A two-letter country code in upper case, '' if the value is not one.
	 *
	 * @param string $country Value.
	 */
	private static function code( string $country ): string {
		$country = strtoupper( trim( $country ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}
}
