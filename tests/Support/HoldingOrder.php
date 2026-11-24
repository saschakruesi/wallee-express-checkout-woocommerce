<?php
/**
 * Order stand-in with WooCommerce's tentative coupon hold (WC 4.3+).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

require_once __DIR__ . '/wc-order-stub.php';

/**
 * Models `WC_Order::hold_applied_coupons()` as WooCommerce implements it: it
 * holds the coupons of the session cart (`WC()->cart`), not the order's, and
 * throws when one of them has reached its usage limit. Code that relies on it
 * in a request where the cart is not the order is caught by these semantics.
 */
class HoldingOrder extends \WC_Order {

	/**
	 * Coupon codes held through hold_applied_coupons().
	 *
	 * @var string[]
	 */
	public $holds = array();

	/**
	 * Cart coupon codes that have reached their usage limit.
	 *
	 * @var string[]
	 */
	public $limit_reached = array();

	/**
	 * Takes a tentative hold on the cart's coupons, as WooCommerce does.
	 *
	 * @param string $billing_email Billing e-mail.
	 * @throws \Exception When a cart coupon has reached its usage limit.
	 */
	public function hold_applied_coupons( $billing_email ) {
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( in_array( $code, $this->limit_reached, true ) ) {
				throw new \Exception( 'Coupon usage limit is reached.' );
			}
			$this->holds[] = $code;
		}
	}
}
