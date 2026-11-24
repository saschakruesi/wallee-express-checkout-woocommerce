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
	 * Constructor.
	 *
	 * @param bool $has_session Whether the customer already has a session.
	 */
	public function __construct( bool $has_session = false ) {
		$this->session   = new FakeWcSession( $has_session );
		$this->countries = (object) array(
			'countries' => array(
				'CH' => 'Switzerland',
				'DE' => 'Germany',
				'AX' => '&#197;land Islands',
			),
		);
	}
}
