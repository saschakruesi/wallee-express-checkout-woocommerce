<?php
/**
 * Stand-in for the WooCommerce main object as far as customer notices need it.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

/**
 * `WC()` with a session spy and a country list.
 */
class FakeWc {

	/**
	 * Session spy.
	 *
	 * @var FakeWcSession
	 */
	public $session;

	/**
	 * Countries: `countries` maps codes to names, as WC_Countries does.
	 *
	 * @var object
	 */
	public $countries;

	/**
	 * Session cart: `applied` holds the applied coupon codes; null when WooCommerce has no cart.
	 *
	 * @var object|null
	 */
	public $cart;

	/**
	 * Constructor.
	 *
	 * @param bool $has_session Whether the customer already has a session.
	 */
	public function __construct( bool $has_session = false ) {
		$this->session   = new FakeWcSession( $has_session );
		$this->cart      = new class() {
			/**
			 * Applied coupon codes.
			 *
			 * @var string[]
			 */
			public $applied = array();

			/**
			 * Applied coupon codes.
			 */
			public function get_applied_coupons() {
				return $this->applied;
			}
		};
		$this->countries = (object) array(
			'countries' => array(
				'CH' => 'Switzerland',
				'DE' => 'Germany',
				'AX' => '&#197;land Islands',
			),
		);
	}
}
