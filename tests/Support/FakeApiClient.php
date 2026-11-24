<?php
/**
 * Scripted API client.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

use Wallee\ExpressCheckout\Api\ApiClient;

/**
 * Replays scripted answers; a \Throwable in a script is thrown.
 */
class FakeApiClient extends ApiClient {

	/**
	 * Answers to getSession, consumed in order; the last one repeats.
	 *
	 * @var array[]|\Throwable[]
	 */
	public $sessions = array();

	/**
	 * Answer to processSession.
	 *
	 * @var string|\Throwable
	 */
	public $process = 'https://app-wallee.com/s/4711/payment/chargeattempt/redirect/1?securityToken=t';

	/**
	 * Answer to getTransaction.
	 *
	 * @var array|\Throwable
	 */
	public $transaction = array();

	/**
	 * Recorded calls: method name and arguments.
	 *
	 * @var array[]
	 */
	public $calls = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( new RecordingTransport() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $session_id Session ID.
	 */
	public function getSession( int $session_id ): array {
		$this->calls[] = array( 'getSession', $session_id );
		$answer        = count( $this->sessions ) > 1 ? array_shift( $this->sessions ) : $this->sessions[0];

		return self::answer( $answer );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $session_id         Session ID.
	 * @param string $merchant_reference Reference.
	 * @param string $success_url        Success URL.
	 * @param string $failure_url        Failure URL.
	 */
	public function processSession( int $session_id, string $merchant_reference, string $success_url, string $failure_url ): string {
		$this->calls[] = array( 'processSession', $session_id, $merchant_reference, $success_url, $failure_url );

		return self::answer( $this->process );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $transaction_id Transaction ID.
	 */
	public function getTransaction( int $transaction_id ): array {
		$this->calls[] = array( 'getTransaction', $transaction_id );

		return self::answer( $this->transaction );
	}

	/**
	 * Names of the called methods, in order.
	 *
	 * @return string[]
	 */
	public function methods(): array {
		return array_column( $this->calls, 0 );
	}

	/**
	 * Returns or throws.
	 *
	 * @param mixed $answer Answer.
	 * @return mixed
	 * @throws \Throwable The scripted exception.
	 */
	private static function answer( $answer ) {
		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}

		return $answer;
	}
}
