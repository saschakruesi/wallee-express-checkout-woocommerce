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
		$this->countries = new class() {
			/**
			 * Country names by code, as WC_Countries::$countries.
			 *
			 * @var array<string, string>
			 */
			public $countries = array(
				'CH' => 'Switzerland',
				'DE' => 'Germany',
				'AX' => '&#197;land Islands',
			);

			/**
			 * State lists by country code; countries without an entry have none.
			 *
			 * @var array<string, array<string, string>>
			 */
			public $states = array(
				'US' => array(
					'CA' => 'California',
					'NY' => 'New York',
				),
			);

			/**
			 * States of a country, as WC_Countries::get_states().
			 *
			 * @param string $country Country code.
			 * @return array|false
			 */
			public function get_states( $country ) {
				return isset( $this->states[ $country ] ) ? $this->states[ $country ] : array();
			}
		};
	}
}
