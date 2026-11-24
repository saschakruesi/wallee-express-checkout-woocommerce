<?php
/**
 * Order stand-in whose meta read throws for one key.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

require_once __DIR__ . '/wc-order-stub.php';

/**
 * Throws from get_meta() for the key in $throw_on (for example to make the
 * settlement of a failed order fail unexpectedly).
 */
class ThrowingMetaOrder extends \WC_Order {

	/**
	 * Meta key whose read throws.
	 *
	 * @var string
	 */
	public $throw_on = '_wallee_ec_void';

	/**
	 * Reads meta; throws for $throw_on.
	 *
	 * @param string $key Key.
	 * @throws \RuntimeException For $throw_on.
	 */
	public function get_meta( $key ) {
		if ( $key === $this->throw_on ) {
			throw new \RuntimeException( 'meta' );
		}

		return parent::get_meta( $key );
	}
}
