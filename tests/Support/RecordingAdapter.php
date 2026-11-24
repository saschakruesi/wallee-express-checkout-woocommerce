<?php
/**
 * Main plugin adapter double for linking.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

use Wallee\ExpressCheckout\MainPluginAdapter;

require_once __DIR__ . '/wc-order-stub.php';

/**
 * Scripted transaction read and gateway resolution; records links and logs.
 */
class RecordingAdapter extends MainPluginAdapter {

	/**
	 * Gateway resolveGatewayId() returns.
	 *
	 * @var string|null
	 */
	public $gateway = 'wallee_7';

	/**
	 * State transactionState() returns.
	 *
	 * @var string
	 */
	public $state = 'AUTHORIZED';

	/**
	 * Thrown by readTransaction() when set.
	 *
	 * @var \Throwable|null
	 */
	public $read_error = null;

	/**
	 * Transaction IDs read.
	 *
	 * @var int[]
	 */
	public $reads = array();

	/**
	 * Order IDs linked.
	 *
	 * @var int[]
	 */
	public $links = array();

	/**
	 * Log lines as "level: message".
	 *
	 * @var string[]
	 */
	public $logs = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int $transaction_id Transaction ID.
	 * @throws \Throwable The scripted error.
	 */
	public function readTransaction( int $transaction_id ): object {
		$this->reads[] = $transaction_id;
		if ( null !== $this->read_error ) {
			throw $this->read_error;
		}

		return (object) array( 'id' => $transaction_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object $transaction Transaction.
	 */
	public function transactionState( object $transaction ): string {
		return $this->state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object    $transaction Transaction.
	 * @param \WC_Order $order       Order.
	 */
	public function linkOrder( object $transaction, \WC_Order $order ): void {
		$this->links[] = $order->get_id();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object $transaction Transaction.
	 */
	public function resolveGatewayId( object $transaction ): ?string {
		return $this->gateway;
	}

	/**
	 * {@inheritDoc}
	 */
	public function spaceId(): int {
		return 4711;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	public function log( string $message, string $level = 'info' ): void {
		$this->logs[] = $level . ': ' . $message;
	}
}
