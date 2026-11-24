<?php
/**
 * Express Checkout API v2.0 client for wallee.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The three Express Checkout calls, with response validation.
 */
class ApiClient {

	const PATH_CREATE      = '/v2.0/express-checkout/session/create';
	const PATH_SESSION     = '/v2.0/express-checkout/session';
	const PATH_PROCESS     = '/v2.0/express-checkout/session/process';
	const PATH_TRANSACTION = '/v2.0/payment/transactions';

	/**
	 * Transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param Transport $transport Transport.
	 */
	public function __construct( Transport $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Creates a session.
	 *
	 * @param array $payload `ExpressCheckoutSession.Create` payload.
	 * @return array{sessionId: int, sessionToken: string}
	 * @throws ApiException When the response lacks a valid ID or token.
	 */
	public function createSession( array $payload ): array {
		$response = $this->transport->request( 'POST', self::PATH_CREATE, array(), $payload );

		$id    = isset( $response['sessionId'] ) ? $response['sessionId'] : null;
		$token = isset( $response['sessionToken'] ) ? $response['sessionToken'] : null;

		$valid_id = ( is_int( $id ) && $id > 0 ) || ( is_string( $id ) && ctype_digit( $id ) && (int) $id > 0 );
		if ( ! $valid_id || ! is_string( $token ) || '' === $token ) {
			throw new ApiException( 'wallee API returned an incomplete session.' );
		}

		return array(
			'sessionId'    => (int) $id,
			'sessionToken' => $token,
		);
	}

	/**
	 * Reads a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array `ExpressCheckoutSession`.
	 * @throws ApiException On failure.
	 */
	public function getSession( int $session_id ): array {
		return $this->transport->request( 'GET', self::PATH_SESSION, array( 'sessionId' => $session_id ), null );
	}

	/**
	 * Reads a transaction with its billing and shipping address.
	 *
	 * @param int $transaction_id Transaction ID.
	 * @return array `Transaction`, every key may be absent.
	 * @throws ApiException On failure or a non-positive ID.
	 */
	public function getTransaction( int $transaction_id ): array {
		if ( $transaction_id <= 0 ) {
			throw new ApiException( 'Invalid transaction ID.' );
		}

		return $this->transport->request(
			'GET',
			self::PATH_TRANSACTION . '/' . $transaction_id,
			array( 'expand' => 'billingAddress,shippingAddress' ),
			null
		);
	}

	/**
	 * Voids the authorisation of a transaction online
	 * (`POST /v2.0/payment/transactions/{id}/void-online`, no body). wallee
	 * accepts it only for a transaction in state AUTHORIZED.
	 *
	 * @param int $transaction_id Transaction ID.
	 * @return array `TransactionVoid`, every key may be absent.
	 * @throws ApiException On failure or a non-positive ID.
	 */
	public function voidTransaction( int $transaction_id ): array {
		if ( $transaction_id <= 0 ) {
			throw new ApiException( 'Invalid transaction ID.' );
		}

		return $this->transport->request( 'POST', self::PATH_TRANSACTION . '/' . $transaction_id . '/void-online', array(), null );
	}

	/**
	 * Processes an approved session.
	 *
	 * @param int    $session_id         Session ID.
	 * @param string $merchant_reference Numeric WooCommerce order ID.
	 * @param string $success_url        Success URL.
	 * @param string $failure_url        Failure URL.
	 * @return string The HTTPS URL to redirect the browser to.
	 * @throws ApiException When the response has no valid HTTPS redirect URL.
	 */
	public function processSession( int $session_id, string $merchant_reference, string $success_url, string $failure_url ): string {
		$response = $this->transport->request(
			'POST',
			self::PATH_PROCESS,
			array(),
			array(
				'sessionId'         => $session_id,
				'merchantReference' => $merchant_reference,
				'successUrl'        => $success_url,
				'failureUrl'        => $failure_url,
			)
		);

		$url   = isset( $response['redirectUrl'] ) ? $response['redirectUrl'] : null;
		$parts = is_string( $url ) ? parse_url( $url ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class; scheme must be exact.
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== $parts['scheme'] || '' === $parts['host'] ) {
			throw new ApiException( 'wallee API returned an invalid redirect URL.' );
		}

		return $url;
	}
}
