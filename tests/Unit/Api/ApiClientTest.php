<?php
/**
 * ApiClient tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Tests\Support\RecordingTransport;

/**
 * Tests the three calls and the response validation.
 */
class ApiClientTest extends TestCase {

	/**
	 * Transport.
	 *
	 * @var RecordingTransport
	 */
	private $transport;

	/**
	 * Client.
	 *
	 * @var ApiClient
	 */
	private $client;

	protected function setUp(): void {
		$this->transport = new RecordingTransport();
		$this->client    = new ApiClient( $this->transport );
	}

	public function test_create_session_posts_payload_unchanged_and_casts_id(): void {
		$payload                   = array( 'currency' => 'CHF', 'lineItems' => array( array( 'sku' => 'a' ) ) );
		$this->transport->response = array( 'sessionId' => '42', 'sessionToken' => 'tok' );

		$result = $this->client->createSession( $payload );

		$this->assertSame( array( 'sessionId' => 42, 'sessionToken' => 'tok' ), $result );
		$this->assertSame(
			array( array( 'method' => 'POST', 'path' => '/v2.0/express-checkout/session/create', 'query' => array(), 'body' => $payload ) ),
			$this->transport->calls
		);
	}

	/**
	 * @dataProvider invalid_create_responses
	 *
	 * @param array $response Response.
	 */
	public function test_create_session_rejects_incomplete_response( array $response ): void {
		$this->transport->response = $response;
		$this->expectException( ApiException::class );
		$this->client->createSession( array() );
	}

	public function invalid_create_responses(): array {
		return array(
			'no token'     => array( array( 'sessionId' => 1 ) ),
			'empty token'  => array( array( 'sessionId' => 1, 'sessionToken' => '' ) ),
			'no id'        => array( array( 'sessionToken' => 't' ) ),
			'zero id'      => array( array( 'sessionId' => 0, 'sessionToken' => 't' ) ),
			'text id'      => array( array( 'sessionId' => 'abc', 'sessionToken' => 't' ) ),
			'array token'  => array( array( 'sessionId' => 1, 'sessionToken' => array( 'x' ) ) ),
			'empty'        => array( array() ),
		);
	}

	public function test_get_session_uses_query_and_returns_response(): void {
		$this->transport->response = array( 'id' => 5, 'state' => 'APPROVED' );

		$this->assertSame( $this->transport->response, $this->client->getSession( 5 ) );
		$this->assertSame(
			array( array( 'method' => 'GET', 'path' => '/v2.0/express-checkout/session', 'query' => array( 'sessionId' => 5 ), 'body' => null ) ),
			$this->transport->calls
		);
	}

	public function test_get_transaction_expands_both_addresses(): void {
		$this->transport->response = array( 'id' => 629017977, 'state' => 'PROCESSING' );

		$this->assertSame( $this->transport->response, $this->client->getTransaction( 629017977 ) );
		$this->assertSame(
			array(
				array(
					'method' => 'GET',
					'path'   => '/v2.0/payment/transactions/629017977',
					'query'  => array( 'expand' => 'billingAddress,shippingAddress' ),
					'body'   => null,
				),
			),
			$this->transport->calls
		);
	}

	/**
	 * @dataProvider non_positive_ids
	 *
	 * @param int $id Transaction ID.
	 */
	public function test_get_transaction_rejects_a_non_positive_id( int $id ): void {
		$this->expectException( ApiException::class );
		try {
			$this->client->getTransaction( $id );
		} finally {
			$this->assertSame( array(), $this->transport->calls );
		}
	}

	public function non_positive_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
		);
	}

	public function test_process_session_posts_exact_body_and_returns_https_url(): void {
		$this->transport->response = array( 'redirectUrl' => 'https://app-wallee.com/pay/1' );

		$url = $this->client->processSession( 5, '812', 'https://s/ok', 'https://s/ko' );

		$this->assertSame( 'https://app-wallee.com/pay/1', $url );
		$this->assertSame(
			array(
				array(
					'method' => 'POST',
					'path'   => '/v2.0/express-checkout/session/process',
					'query'  => array(),
					'body'   => array(
						'sessionId'         => 5,
						'merchantReference' => '812',
						'successUrl'        => 'https://s/ok',
						'failureUrl'        => 'https://s/ko',
					),
				),
			),
			$this->transport->calls
		);
	}

	/**
	 * @dataProvider invalid_redirects
	 *
	 * @param mixed $redirect Redirect value.
	 */
	public function test_process_session_rejects_bad_redirect( $redirect ): void {
		$this->transport->response = array( 'redirectUrl' => $redirect );
		$this->expectException( ApiException::class );
		$this->client->processSession( 5, '812', 'https://s/ok', 'https://s/ko' );
	}

	public function invalid_redirects(): array {
		return array(
			'http'       => array( 'http://x' ),
			'javascript' => array( 'javascript:alert(1)' ),
			'no host'    => array( 'https://' ),
			'relative'   => array( '/pay' ),
			'scheme-rel' => array( '//evil.test/x' ),
			'empty'      => array( '' ),
			'null'       => array( null ),
			'array'      => array( array( 'https://x' ) ),
		);
	}

	public function test_process_session_rejects_missing_redirect(): void {
		$this->transport->response = array();
		$this->expectException( ApiException::class );
		$this->client->processSession( 5, '812', 'https://s/ok', 'https://s/ko' );
	}
}
