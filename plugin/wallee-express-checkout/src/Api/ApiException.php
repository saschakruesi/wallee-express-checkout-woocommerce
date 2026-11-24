<?php
/**
 * Error raised by the wallee Express Checkout API client.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status (0 when unknown). Messages never contain request
 * bodies, headers or credentials.
 */
class ApiException extends \RuntimeException {

	/**
	 * HTTP status code, 0 when unknown.
	 *
	 * @var int
	 */
	private $status_code;

	/**
	 * Constructor.
	 *
	 * @param string $message     Safe message.
	 * @param int    $status_code HTTP status code, 0 when unknown.
	 */
	public function __construct( string $message, int $status_code = 0 ) {
		parent::__construct( $message, $status_code );
		$this->status_code = $status_code;
	}

	/**
	 * HTTP status code, 0 when unknown.
	 *
	 * @return int
	 */
	public function getStatusCode(): int {
		return $this->status_code;
	}
}
