<?php
/**
 * Transport on top of the main plugin's SDK client (MAC authentication).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Api;

use Wallee\ExpressCheckout\MainPluginAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Calls `callApi` of the SDK client with the `space` header.
 */
class SdkTransport implements Transport {

	/**
	 * Adapter.
	 *
	 * @var MainPluginAdapter
	 */
	private $adapter;

	/**
	 * Constructor.
	 *
	 * @param MainPluginAdapter $adapter Adapter.
	 */
	public function __construct( MainPluginAdapter $adapter ) {
		$this->adapter = $adapter;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Resource path.
	 * @param array      $query  Query parameters.
	 * @param array|null $body   JSON body, or null.
	 * @return array
	 * @throws ApiException On any failure.
	 */
	public function request( string $method, string $path, array $query, ?array $body ): array {
		try {
			$response = $this->adapter->sdkClient()->callApi(
				$path,
				$method,
				$query,
				$body,
				array(
					'space'        => (string) $this->adapter->spaceId(),
					'Content-Type' => 'application/json;charset=utf-8',
					'Accept'       => 'application/json',
				)
			);
			$data     = $response->getData();
		} catch ( \Throwable $e ) {
			// The SDK message embeds the URL and the response body; keep only the status.
			$code    = (int) $e->getCode();
			$status  = ( $code >= 100 && $code <= 599 ) ? $code : 0;
			$message = sprintf( 'wallee API request %s %s failed (HTTP %d).', $method, $path, $status );
			throw new ApiException( $message, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		$json    = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plain SDK data; no WordPress needed.
		$decoded = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			$message = sprintf( 'wallee API returned an unexpected response for %s %s.', $method, $path );
			throw new ApiException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
		return $decoded;
	}
}
