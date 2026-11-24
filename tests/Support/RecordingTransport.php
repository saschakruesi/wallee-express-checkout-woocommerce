<?php
/**
 * Recording fake transport.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

use Wallee\ExpressCheckout\Api\Transport;

/**
 * Records every request and replays a canned response.
 */
class RecordingTransport implements Transport {

	/**
	 * Recorded calls.
	 *
	 * @var array[]
	 */
	public $calls = array();

	/**
	 * Canned response.
	 *
	 * @var array
	 */
	public $response = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path.
	 * @param array      $query  Query.
	 * @param array|null $body   Body.
	 * @return array
	 */
	public function request( string $method, string $path, array $query, ?array $body ): array {
		$this->calls[] = compact( 'method', 'path', 'query', 'body' );
		return $this->response;
	}
}
