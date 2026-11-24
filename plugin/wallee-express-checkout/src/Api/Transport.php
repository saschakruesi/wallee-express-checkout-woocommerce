<?php
/**
 * Transport for the wallee Express Checkout API.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one authenticated JSON request to wallee.
 */
interface Transport {

	/**
	 * Performs a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Resource path, for example `/v2.0/express-checkout/session`.
	 * @param array      $query  Query parameters.
	 * @param array|null $body   JSON body, or null.
	 * @return array Decoded JSON response as an associative array.
	 * @throws ApiException On a non-2xx response or a transport failure.
	 */
	public function request( string $method, string $path, array $query, ?array $body ): array;
}
