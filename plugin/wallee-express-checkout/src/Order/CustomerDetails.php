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
	 * Order meta: the customer checks of the return (coupons for the e-mail,
	 * destination) were skipped because the transaction could not be read.
	 */
	const META_CHECKS_PENDING = '_wallee_ec_checks_pending';

	/**
	 * Reason when the coupons cannot be re-checked because WooCommerce has
	 * already counted this order's own use of them.
	 */
	const COUPONS_NOT_RECHECKED = 'coupons_not_rechecked';

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
	 * Whether WooCommerce taxes by the customer's address.
	 *
	 * @var bool
	 */
	private $tax_follows_address;

	/**
	 * Constructor.
	 *
	 * @param ApiClient         $api        API client.
	 * @param SessionRepository $repository Session storage.
	 * @param OrderService      $orders     Order operations.
	 * @param callable          $log        Logger: function ( string $message, string $level ).
	 * @param bool              $tax_follows Whether WooCommerce taxes by the customer's address (OrderValidator::taxFollowsAddress()).
	 */
	public function __construct( ApiClient $api, SessionRepository $repository, OrderService $orders, callable $log, bool $tax_follows ) {
		$this->api                 = $api;
		$this->repository          = $repository;
		$this->orders              = $orders;
		$this->log                 = $log;
		$this->tax_follows_address = $tax_follows;
	}

	/**
	 * Reads the session for its transaction, stores the transaction ID on row
	 * and order as soon as it is known, then applies the transaction's
	 * customer data to the order.
	 *
	 * The result tells the caller whether an e-mail address was found: the
	 * return handler fails the order without one, later steps keep it (owner
	 * decision, design amendment 2).
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order of the row.
	 * @return array{transaction_id: int, state: string, email: bool}|null Null if the session has no transaction yet;
	 *                                                                      else the transaction ID, its state (A-Z and _ only,
	 *                                                                      empty if absent) and whether the order has an e-mail.
	 * @throws \Throwable On any API or storage error (the transaction was not read); the caller decides.
	 */
	public function collect( SessionRecord $record, \WC_Order $order ): ?array {
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
		$email       = $this->orders->applyCustomer( $order, $transaction, $record->snapshot()->customerId() );
		$state       = isset( $transaction['state'] ) && is_string( $transaction['state'] ) ? (string) preg_replace( '/[^A-Z_]/', '', $transaction['state'] ) : '';
		call_user_func(
			$this->log,
			sprintf(
				'Session row %d: customer data of transaction %d (state %s) applied to order %d (e-mail %s).',
				$record->id(),
				$transaction_id,
				'' === $state ? 'unknown' : $state,
				$order->get_id(),
				$email ? 'present' : 'missing'
			)
		);

		return array(
			'transaction_id' => $transaction_id,
			'state'          => $state,
			'email'          => $email,
		);
	}

	/**
	 * Like collect(), for an order whose customer may already have paid: a missing
	 * e-mail never fails the order here, it only gets a private note (once).
	 *
	 * If the return skipped the customer checks (see deferChecks()), they run
	 * here once the transaction is read. A failure never fails the paid
	 * order: it gets one private "ACTION REQUIRED" note. The checks run once;
	 * later calls do not repeat them.
	 *
	 * With $missing_email_fails (the background job), a missing e-mail gets
	 * no note and the checks are not run: the caller fails the order for
	 * `no_email` instead.
	 *
	 * @param SessionRecord $record              Row.
	 * @param \WC_Order     $order               Order of the row.
	 * @param bool          $missing_email_fails Whether the caller fails the order when the e-mail is missing.
	 * @return array{transaction_id: int, state: string, email: bool}|null See collect().
	 * @throws \Throwable See collect().
	 */
	public function collectAfterPayment( SessionRecord $record, \WC_Order $order, bool $missing_email_fails = false ): ?array {
		$result = $this->collect( $record, $order );
		if ( null !== $result && ! $result['email'] && $missing_email_fails ) {
			return $result;
		}
		if ( null !== $result && ! $result['email'] ) {
			$this->orders->addNoteOnce( $order, __( 'The payment is approved, but wallee returned no valid e-mail address for the customer.', 'wallee-express-checkout' ) );
			call_user_func( $this->log, sprintf( 'Order %d: no e-mail address from wallee; order kept with a note.', $order->get_id() ), 'warning' );
		}
		if ( null !== $result && self::checksPending( $order ) ) {
			$this->repeatChecks( $record, $order, $result['email'] );
		}

		return $result;
	}

	/**
	 * Records that the customer checks of the return were skipped, so
	 * collectAfterPayment() runs them.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function deferChecks( \WC_Order $order ): void {
		$order->update_meta_data( self::META_CHECKS_PENDING, 'yes' );
		$order->save();
	}

	/**
	 * Whether the customer checks of the return are still to be run.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function checksPending( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::META_CHECKS_PENDING );
	}

	/**
	 * Runs the skipped checks on a paid order and records that they ran.
	 *
	 * @param SessionRecord $record    Row.
	 * @param \WC_Order     $order     Order with the customer data applied.
	 * @param bool          $has_email Whether the order has an e-mail address.
	 */
	private function repeatChecks( SessionRecord $record, \WC_Order $order, bool $has_email ): void {
		$reason = $this->checkFailure( $record, $order, $has_email );
		if ( null === $reason ) {
			call_user_func( $this->log, sprintf( 'Order %d: checks after payment passed.', $order->get_id() ) );
		} else {
			$this->orders->addNoteOnce(
				$order,
				sprintf(
					/* translators: %s: reason code. */
					__( 'ACTION REQUIRED: check before shipping (%s).', 'wallee-express-checkout' ),
					$reason
				)
			);
			call_user_func( $this->log, sprintf( 'Order %d: check after payment failed (%s); order kept with a note.', $order->get_id(), $reason ), 'warning' );
		}
		$order->delete_meta_data( self::META_CHECKS_PENDING );
		$order->save();
	}

	/**
	 * The return's customer checks for a paid order: coupons valid for the
	 * e-mail (if there is one) and the destination (country, tax rates).
	 *
	 * @param SessionRecord $record    Row.
	 * @param \WC_Order     $order     Order with the customer data applied.
	 * @param bool          $has_email Whether the order has an e-mail address.
	 * @return string|null Reason code, null if the checks pass.
	 */
	private function checkFailure( SessionRecord $record, \WC_Order $order, bool $has_email ): ?string {
		$snapshot = $record->snapshot();
		$coupons  = null;
		if ( $has_email && array() !== $snapshot->coupons() ) {
			if ( $this->orders->couponUsageRecorded( $order ) ) {
				$coupons = self::COUPONS_NOT_RECHECKED;
			} else {
				try {
					if ( null !== $this->orders->customerCoupons( $snapshot, (string) $order->get_billing_email() ) ) {
						return OrderValidator::COUPON_INVALID;
					}
				} catch ( \Throwable $e ) {
					call_user_func( $this->log, sprintf( 'Order %d: coupon check after payment failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );

					return 'coupon_check_failed';
				}
			}
		}

		$tax_address = $this->tax_follows_address ? TaxDestination::orderAddress( $order ) : null;
		$destination = OrderValidator::destination( $snapshot, $record->shippingQuote(), OrderValidator::deliveryCountry( $order ), $tax_address );
		if ( OrderValidator::TAX_REGION_UNMATCHED === $destination ) {
			$this->orders->addNoteOnce( $order, TaxDestination::regionNote() );
			call_user_func( $this->log, sprintf( 'Order %d: tax region not matched; order kept with a note.', $order->get_id() ), 'warning' );
			$destination = null;
		}

		return null !== $destination ? $destination : $coupons;
	}

	/**
	 * Fallback when the session row is gone (purged): reads the transaction ID
	 * from the session ID stored on the order and stores it as order meta.
	 * No customer data is applied.
	 *
	 * @param \WC_Order $order Order.
	 * @return int|null Transaction ID, null if unknown.
	 * @throws \Throwable On any API error.
	 */
	public function transactionFromSessionId( \WC_Order $order ): ?int {
		$session_id = (int) $order->get_meta( OrderFactory::META_SESSION_ID );
		if ( $session_id <= 0 ) {
			return null;
		}
		$transaction_id = self::transactionId( $this->api->getSession( $session_id ) );
		if ( null !== $transaction_id ) {
			$order->update_meta_data( self::META_TRANSACTION, $transaction_id );
			$order->save();
			call_user_func( $this->log, sprintf( 'Order %d has transaction %d (read from wallee session %d).', $order->get_id(), $transaction_id, $session_id ) );
		}

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
}
