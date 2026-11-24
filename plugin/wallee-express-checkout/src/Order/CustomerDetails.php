<?php
/**
 * Reads the transaction of a processed session and writes it to order and row.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Step 10 of the return flow (design amendment 1), repeated by the result
 * handler when it is incomplete: the session's `linkedTransaction` goes to
 * the row and the order, the transaction's addresses and e-mail to the order.
 */
final class CustomerDetails {

	const META_TRANSACTION = '_wallee_ec_transaction_id';

	/**
	 * API client.
	 *
	 * @var ApiClient
	 */
	private $api;

	/**
	 * Session storage.
	 *
	 * @var SessionRepository
	 */
	private $repository;

	/**
	 * Order operations.
	 *
	 * @var OrderService
	 */
	private $orders;

	/**
	 * Logger, called with a message and a level.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param ApiClient         $api        API client.
	 * @param SessionRepository $repository Session storage.
	 * @param OrderService      $orders     Order operations.
	 * @param callable          $log        Logger: function ( string $message, string $level ).
	 */
	public function __construct( ApiClient $api, SessionRepository $repository, OrderService $orders, callable $log ) {
		$this->api        = $api;
		$this->repository = $repository;
		$this->orders     = $orders;
		$this->log        = $log;
	}

	/**
	 * Reads the session for its transaction, stores the transaction ID on row
	 * and order as soon as it is known, then applies the transaction's
	 * customer data to the order.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order of the row.
	 * @return int|null Transaction ID, null if the session has none yet.
	 * @throws \Throwable On any API or storage error; the caller decides.
	 */
	public function collect( SessionRecord $record, \WC_Order $order ): ?int {
		$transaction_id = self::transactionId( $this->api->getSession( $record->walleeSessionId() ) );
		if ( null === $transaction_id ) {
			call_user_func( $this->log, sprintf( 'Session row %d: wallee session %d has no linked transaction yet.', $record->id(), $record->walleeSessionId() ), 'warning' );

			return null;
		}

		$order->update_meta_data( self::META_TRANSACTION, $transaction_id );
		$order->save();
		$this->repository->update( $record->id(), array( 'transaction_id' => $transaction_id ) );
		call_user_func( $this->log, sprintf( 'Session row %d: order %d has transaction %d.', $record->id(), $order->get_id(), $transaction_id ) );

		$transaction = $this->api->getTransaction( $transaction_id );
		$this->orders->applyCustomer( $order, $transaction, $record->snapshot()->customerId(), self::quoteCountry( $record ) );
		call_user_func(
			$this->log,
			sprintf(
				'Session row %d: customer data of transaction %d applied to order %d (e-mail %s).',
				$record->id(),
				$transaction_id,
				$order->get_id(),
				'' === $order->get_billing_email() ? 'missing' : 'present'
			)
		);

		return $transaction_id;
	}

	/**
	 * The transaction ID of a session: `linkedTransaction`, else `transaction.id`.
	 *
	 * @param array $session Session as returned by the API.
	 */
	public static function transactionId( array $session ): ?int {
		$candidates = array(
			isset( $session['linkedTransaction'] ) ? $session['linkedTransaction'] : null,
			isset( $session['transaction'] ) && is_array( $session['transaction'] ) && isset( $session['transaction']['id'] ) ? $session['transaction']['id'] : null,
		);
		foreach ( $candidates as $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/^\d{1,18}$/', $value ) ) {
				$value = (int) $value;
			}
			if ( is_int( $value ) && $value > 0 ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Country of the stored shipping quote, null without one.
	 *
	 * @param SessionRecord $record Row.
	 */
	private static function quoteCountry( SessionRecord $record ): ?string {
		$quote = $record->shippingQuote();
		if ( null === $quote || ! isset( $quote['destination']['country'] ) || ! is_string( $quote['destination']['country'] ) ) {
			return null;
		}

		return $quote['destination']['country'];
	}
}
