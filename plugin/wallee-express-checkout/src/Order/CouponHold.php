<?php
/**
 * WooCommerce's tentative coupon hold, taken for the order's own coupons.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the tentative hold WooCommerce's checkout takes (WooCommerce 4.3 and
 * later), but for the given coupon codes only.
 *
 * `WC_Order::hold_applied_coupons()` cannot be used here: it holds the coupons
 * of the session cart (`WC()->cart`), which in the return request is not the
 * order (a product-page purchase has no coupons; the cart may have changed
 * since the session was created). It also reports "limit reached" and a
 * database error with the same exception, told apart only by a translated
 * message. This class makes the same data-store calls for the snapshot's
 * coupons and keeps their results apart.
 */
final class CouponHold {

	const NONE   = 'none';
	const HELD   = 'held';
	const LIMIT  = 'limit_reached';
	const FAILED = 'failed';

	/**
	 * Holds the coupons for the order's customer.
	 *
	 * @param string[]  $codes Coupon codes of the order (the snapshot's).
	 * @param \WC_Order $order Order with the customer's e-mail applied.
	 * @return array{0: string, 1: string} Outcome (one of the constants) and, for FAILED, a detail
	 *                                     (class names or a step, never data).
	 */
	public static function hold( array $codes, \WC_Order $order ): array {
		if ( array() === $codes || ! class_exists( 'WC_Coupon' ) ) {
			return array( self::NONE, '' );
		}
		$order_store = method_exists( $order, 'get_data_store' ) ? $order->get_data_store() : null;
		if ( ! is_object( $order_store ) || ! is_callable( array( $order_store, 'set_coupon_held_keys' ) ) ) {
			// WooCommerce before 4.3 has no tentative hold.
			return array( self::NONE, '' );
		}

		$held     = array();
		$for_user = array();
		$outcome  = array( self::NONE, '' );
		try {
			foreach ( $codes as $code ) {
				$coupon = new \WC_Coupon( (string) $code );
				$store  = $coupon->get_data_store();
				if ( ! $coupon->get_id() || ! is_object( $store ) ) {
					continue;
				}

				if ( 0 < (int) $coupon->get_usage_limit() ) {
					$result = $store->check_and_hold_coupon( $coupon );
					$stop   = self::stop( $result );
					if ( null !== $stop ) {
						$outcome = $stop;
						break;
					}
					if ( is_string( $result ) && '' !== $result ) {
						$held[ $coupon->get_id() ] = $result;
					}
				}

				if ( 0 < (int) $coupon->get_usage_limit_per_user() ) {
					$aliases = self::aliases( $order );
					$result  = $store->check_and_hold_coupon_for_user( $coupon, $aliases[0], $aliases[1] );
					$stop    = self::stop( $result );
					if ( null !== $stop ) {
						$outcome = $stop;
						break;
					}
					if ( is_string( $result ) && '' !== $result ) {
						$for_user[ $coupon->get_id() ] = $result;
					}
				}
			}
		} catch ( \Throwable $e ) {
			$outcome = array( self::FAILED, get_class( $e ) );
		}

		// As WooCommerce does: the keys of every hold taken are saved, also
		// after a refusal, so the holds are released with the order.
		if ( array() !== $held || array() !== $for_user ) {
			try {
				$order_store->set_coupon_held_keys( $order, $held, $for_user );
			} catch ( \Throwable $e ) {
				if ( self::LIMIT !== $outcome[0] ) {
					$outcome = array( self::FAILED, 'keys not saved (' . get_class( $e ) . ')' );
				}
			}
			if ( self::NONE === $outcome[0] ) {
				$outcome = array( self::HELD, '' );
			}
		}

		return $outcome;
	}

	/**
	 * Result of a data-store hold call that stops the loop: 0 is "usage limit
	 * reached", false a database error; a key or null (no limit) go on.
	 *
	 * @param mixed $result Data-store result.
	 * @return array{0: string, 1: string}|null
	 */
	private static function stop( $result ): ?array {
		if ( 0 === $result || '0' === $result ) {
			return array( self::LIMIT, '' );
		}
		if ( false === $result ) {
			return array( self::FAILED, 'hold not stored' );
		}

		return null;
	}

	/**
	 * The customer's aliases as WooCommerce builds them for the per-user hold:
	 * user IDs and e-mail addresses, and the alias the hold is taken for.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{0: array, 1: string|int}
	 */
	private static function aliases( \WC_Order $order ): array {
		$user_id = (int) $order->get_customer_id();
		$emails  = array( (string) $order->get_billing_email() );
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( is_object( $user ) && isset( $user->user_email ) ) {
				$emails[] = (string) $user->user_email;
			}
		}
		$emails = array_values( array_unique( array_map( 'strtolower', array_map( 'sanitize_email', $emails ) ) ) );

		$user_ids = array();
		if ( class_exists( 'WC_Data_Store' ) ) {
			$customers = \WC_Data_Store::load( 'customer' );
			if ( is_callable( array( $customers, 'get_user_ids_for_billing_email' ) ) ) {
				$user_ids = (array) $customers->get_user_ids_for_billing_email( $emails );
			}
		}

		return array(
			array_merge( $user_ids, $emails ),
			$user_id > 0 ? $user_id : sanitize_email( (string) $order->get_billing_email() ),
		);
	}
}
