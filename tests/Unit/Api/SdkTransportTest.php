<?php
/**
 * SdkTransport tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Api\SdkTransport;
use Wallee\ExpressCheckout\MainPluginAdapter;

/**
 * Fake SDK response.
 */
class FakeSdkResponse {

	/**
	 * Data.
	 *
	 * @var mixed
	 */
	private $data;

	public function __construct( $data ) {
		$this->data = $data;
	}

	public function getData() {
		return $this->data;
	}
}

/**
 * Fake SDK client recording callApi arguments.
 */
class FakeSdkClient {

	/**
	 * Recorded calls.
	 *
	 * @var array[]
	 */
	public $calls = array();

	/**
	 * Data to return, or a throwable to throw.
	 *
	 * @var mixed
	 */
	public $result;

	public function callApi( $path, $method, $query, $body, $headers ) {
		$this->calls[] = func_get_args();
		if ( $this->result instanceof \Throwable ) {
			throw $this->result;
		}
		return new FakeSdkResponse( $this->result );
	}
}

/**
 * Adapter returning the fake client.
 */
class SdkFakeAdapter extends MainPluginAdapter {

	/**
	 * Client.
	 *
	 * @var FakeSdkClient
	 */
	public $client;

	public function sdkClient(): object {
		return $this->client;
	}

	public function spaceId(): int {
		return 777;
	}
}

/**
 * Tests the SDK bridge.
 */
class SdkTransportTest extends TestCase {

	/**
	 * Client.
	 *
	 * @var FakeSdkClient
	 */
	private $client;

	/**
	 * Transport.
	 *
	 * @var SdkTransport
	 */
	private $transport;

	protected function setUp(): void {
		$this->client         = new FakeSdkClient();
		$adapter              = new SdkFakeAdapter();
		$adapter->client      = $this->client;
		$this->transport      = new SdkTransport( $adapter );
	}

	public function test_passes_path_method_query_body_and_headers(): void {
		$this->client->result = (object) array( 'ok' => true );

		$this->transport->request( 'POST', '/v2.0/x', array( 'a' => 1 ), array( 'b' => 2 ) );

		$this->assertCount( 1, $this->client->calls );
		list( $path, $method, $query, $body, $headers ) = $this->client->calls[0];
		$this->assertSame( '/v2.0/x', $path );
		$this->assertSame( 'POST', $method );
		$this->assertSame( array( 'a' => 1 ), $query );
		$this->assertSame( array( 'b' => 2 ), $body );
		$this->assertSame(
			array(
				'space'        => '777',
				'Content-Type' => 'application/json;charset=utf-8',
				'Accept'       => 'application/json',
			),
			$headers
		);
	}

	public function test_get_passes_null_body(): void {
		$this->client->result = (object) array();

		$this->transport->request( 'GET', '/v2.0/x', array(), null );

		$this->assertNull( $this->client->calls[0][3] );
	}

	public function test_converts_nested_std_class_to_arrays(): void {
		$this->client->result = json_decode( '{"id":5,"lineItems":[{"sku":"a","taxes":[{"rate":8.1}]}],"shippingAddress":{"city":"Bern"}}' );

		$result = $this->transport->request( 'GET', '/v2.0/x', array(), null );

		$this->assertSame(
			array(
				'id'              => 5,
				'lineItems'       => array( array( 'sku' => 'a', 'taxes' => array( array( 'rate' => 8.1 ) ) ) ),
				'shippingAddress' => array( 'city' => 'Bern' ),
			),
			$result
		);
	}

	public function test_non_json_response_is_an_error(): void {
		$this->client->result = '<html>oops</html>';

		$this->expectException( ApiException::class );
		$this->transport->request( 'GET', '/v2.0/x', array(), null );
	}

	public function test_wraps_sdk_exception_with_status_and_without_leaking_details(): void {
		$this->client->result = new \Exception( 'Error 403 connecting to the API (https://h/x?k=SECRET) : {"body":"SECRET"}', 403 );

		try {
			$this->transport->request( 'POST', '/v2.0/x', array(), array( 'token' => 'SECRET' ) );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getStatusCode() );
			$this->assertStringNotContainsString( 'SECRET', $e->getMessage() );
			$this->assertNull( $e->getPrevious() );
		}
	}

	public function test_wraps_any_throwable_with_status_zero(): void {
		$this->client->result = new \Error( 'boom with secret', 7 );

		try {
			$this->transport->request( 'GET', '/v2.0/x', array(), null );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 0, $e->getStatusCode() );
			$this->assertStringNotContainsString( 'secret', $e->getMessage() );
		}
	}
}
