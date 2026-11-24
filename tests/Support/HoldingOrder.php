<?php
/**
 * Order stand-in with WooCommerce's tentative coupon hold (WC 4.3+).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

require_once __DIR__ . '/wc-order-stub.php';

/**
 * Records hold_applied_coupons() calls; throws when scripted to.
 */
class HoldingOrder extends \WC_Order {

	/**
	 * E-mail addresses holds were taken for.
	 *
	 * @var string[]
	 */
	public $holds = array();

	/**
	 * Thrown by hold_applied_coupons() when set (as WooCommerce does when a limit is reached).
	 *
	 * @var \Throwable|null
	 */
	public $hold_error = null;

	/**
	 * Takes a tentative hold on the order's coupons.
	 *
	 * @param string $billing_email Billing e-mail.
	 * @throws \Throwable The scripted error.
	 */
	public function hold_applied_coupons( $billing_email ) {
		if ( null !== $this->hold_error ) {
			throw $this->hold_error;
		}
		$this->holds[] = $billing_email;
	}
}
