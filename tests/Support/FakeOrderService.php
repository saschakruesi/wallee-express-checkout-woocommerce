<?php
/**
 * Recording order service.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Order\OrderService;

require_once __DIR__ . '/wc-order-stub.php';

/**
 * Creates stub orders and records what was done to them.
 */
class FakeOrderService extends OrderService {

	/**
	 * Orders by ID.
	 *
	 * @var \WC_Order[]
	 */
	public $orders = array();

	/**
	 * ID of the next created order.
	 *
	 * @var int
	 */
	public $next_id = 501;

	/**
	 * Total of the next created order; null means the snapshot total plus the rate.
	 *
	 * @var float|null
	 */
	public $next_total = null;

	/**
	 * Thrown by create() when set.
	 *
	 * @var \Throwable|null
	 */
	public $create_error = null;

	/**
	 * Result of stockAndCoupons().
	 *
	 * @var string|null
	 */
	public $stock_reason = null;

	/**
	 * Calls of create(): snapshot, rate, uuid, wallet.
	 *
	 * @var array[]
	 */
	public $created = array();

	/**
	 * Calls of fail(): order ID => reason.
	 *
	 * @var array<int, string>
	 */
	public $failed = array();

	/**
	 * Calls of applyCustomer(): order ID, transaction, customer, quote country.
	 *
	 * @var array[]
	 */
	public $applied = array();

	/**
	 * E-mail applyCustomer() writes to the order.
	 *
	 * @var string
	 */
	public $applied_email = 'buyer@example.test';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id Order ID.
	 */
	public function find( int $order_id ): ?\WC_Order {
		return isset( $this->orders[ $order_id ] ) ? $this->orders[ $order_id ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CartSnapshot $snapshot   Snapshot.
	 * @param array|null   $rate       Rate.
	 * @param string       $uuid       UUID.
	 * @param string       $wallet     Wallet.
	 * @param int          $session_id wallee session ID.
	 * @throws \Throwable The scripted error.
	 */
	public function create( CartSnapshot $snapshot, ?array $rate, string $uuid, string $wallet, int $session_id ): \WC_Order {
		$this->created[] = array( $snapshot, $rate, $uuid, $wallet, $session_id );
		if ( null !== $this->create_error ) {
			throw $this->create_error;
		}
		$total = null !== $this->next_total ? $this->next_total : $snapshot->total() + ( null === $rate ? 0 : $rate['cost'] + $rate['tax'] );
		$order = new \WC_Order( $this->next_id, round( $total, 2 ) );

		$order->meta['_wallee_ec_session_uuid'] = $uuid;
		$order->meta['_wallee_ec_session_id']   = $session_id;
		$this->orders[ $order->get_id() ]        = $order;

		return $order;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason.
	 */
	public function fail( \WC_Order $order, string $reason ): void {
		$this->failed[ $order->get_id() ] = $reason;
		$order->status                    = 'failed';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order   $order         Order.
	 * @param array       $transaction   Transaction.
	 * @param int         $customer_id   Customer.
	 * @param string|null $quote_country Country.
	 */
	public function applyCustomer( \WC_Order $order, array $transaction, int $customer_id, ?string $quote_country ): bool {
		$this->applied[]      = array( $order->get_id(), $transaction, $customer_id, $quote_country );
		$order->billing_email = $this->applied_email;

		return '' !== $this->applied_email;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $note  Note.
	 */
	public function addNoteOnce( \WC_Order $order, string $note ): void {
		if ( ! in_array( $note, $order->notes, true ) ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $email    E-mail.
	 */
	public function stockAndCoupons( CartSnapshot $snapshot, string $email ): ?string {
		return $this->stock_reason;
	}
}
