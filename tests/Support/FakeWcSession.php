<?php
/**
 * Spy for WooCommerce's session handler.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

/**
 * Records whether a session cookie was set.
 */
class FakeWcSession {

	/**
	 * Whether the customer has a session.
	 *
	 * @var bool
	 */
	public $has = false;

	/**
	 * Calls of set_customer_session_cookie(): the argument passed.
	 *
	 * @var bool[]
	 */
	public $cookies = array();

	/**
	 * Constructor.
	 *
	 * @param bool $has Whether the customer has a session.
	 */
	public function __construct( bool $has ) {
		$this->has = $has;
	}

	/**
	 * Whether the customer has a session.
	 */
	public function has_session(): bool {
		return $this->has;
	}

	/**
	 * Starts the session.
	 *
	 * @param bool $set Whether to set the cookie.
	 */
	public function set_customer_session_cookie( $set ): void {
		$this->cookies[] = $set;
		$this->has       = true;
	}
}
