<?php
/**
 * What happens to wallee's money for an express order that failed for an
 * owner-decided reason.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Api\ApiClient;

defined( 'ABSPATH' ) || exit;

/**
 * An express order fails for an owner-decided reason (no e-mail, invalid
 * coupon, changed destination) even when wallee already holds the money.
 * This class settles that money:
 *
 * - AUTHORIZED (reserved): a void is requested once per order, through the
 *   add-on's API client (`POST /v2.0/payment/transactions/{id}/void-online`).
 *   A request wallee accepts gets a private note; a failure gets an "ACTION
 *   REQUIRED" note to void the transaction manually.
 * - COMPLETED or FULFILL (taken): never touched; one "ACTION REQUIRED" note
 *   says a refund is needed.
 * - PROCESSING, CONFIRMED, PENDING, CREATE or unknown (undecided): nothing
 *   now; the caller checks again later.
 * - FAILED, DECLINE, VOIDED: nothing to do.
 *
 * Notes and logs carry order and transaction IDs, states, reason codes and
 * exception classes only.
 */
final class FailedOrderSettlement {

	/**
	 * Order meta: the owner-decided reason the order failed for.
	 */
	const META_REASON = '_wallee_ec_failed_reason';

	/**
	 * Order meta: outcome of the one void request, `requested` or `failed`.
	 */
	const META_VOID = '_wallee_ec_void';

	/**
	 * Order meta: the refund note was added.
	 */
	const META_REFUND = '_wallee_ec_refund_required';

	/**
	 * A void of the reservation was requested; the customer is not charged.
	 */
	const RELEASED = 'released';

	/**
	 * Money is reserved and the void failed: the merchant voids manually.
	 */
	const RESERVED = 'reserved';

	/**
	 * Money is taken: the merchant refunds.
	 */
	const TAKEN = 'taken';

	/**
	 * The payment ended without money moved, or the order is not one of these.
	 */
	const NONE = 'none';

	/**
	 * The payment is not decided yet (or the transaction is not known).
	 */
	const OPEN = 'open';

	const STATES_TAKEN = array( 'COMPLETED', 'FULFILL' );
	const STATES_ENDED = array( 'FAILED', 'DECLINE', 'VOIDED' );

	/**
	 * API client.
	 *
	 * @var ApiClient
	 */
	private $api;

	/**
	 * Logger, called with a message and a level.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param ApiClient $api API client.
	 * @param callable  $log Logger: function ( string $message, string $level ).
	 */
	public function __construct( ApiClient $api, callable $log ) {
		$this->api = $api;
		$this->log = $log;
	}

	/**
	 * Registers the note for a failed express order that a payment update
	 * revives (the main plugin's webhook sets on-hold or processing).
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'noteRevival' ), 10, 4 );
	}

	/**
	 * Records the owner-decided reason an order failed for.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason code.
	 */
	public static function markFailed( \WC_Order $order, string $reason ): void {
		$order->update_meta_data( self::META_REASON, $reason );
		$order->save();
	}

	/**
	 * The owner-decided reason an order failed for, '' if none.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function failedReason( \WC_Order $order ): string {
		$reason = $order->get_meta( self::META_REASON );

		return is_string( $reason ) ? $reason : '';
	}

	/**
	 * Settles the money of a failed order with a known transaction state.
	 *
	 * @param \WC_Order $order          Order marked with markFailed().
	 * @param int       $transaction_id wallee transaction ID.
	 * @param string    $state          Transaction state as read from wallee, '' if unknown.
	 * @return string One of RELEASED, RESERVED, TAKEN, NONE, OPEN.
	 */
	public function settle( \WC_Order $order, int $transaction_id, string $state ): string {
		$reason = self::failedReason( $order );
		if ( '' === $reason || $transaction_id <= 0 ) {
			return '' === $reason ? self::NONE : self::OPEN;
		}
		$void = (string) $order->get_meta( self::META_VOID );

		if ( 'AUTHORIZED' === $state ) {
			if ( '' !== $void ) {
				return 'requested' === $void ? self::RELEASED : self::RESERVED;
			}

			return $this->void( $order, $transaction_id, $reason );
		}
		if ( in_array( $state, self::STATES_TAKEN, true ) ) {
			$this->noteRefund( $order, $transaction_id, $state, $reason );

			return self::TAKEN;
		}
		if ( in_array( $state, self::STATES_ENDED, true ) ) {
			return 'requested' === $void ? self::RELEASED : self::NONE;
		}

		return self::OPEN;
	}

	/**
	 * Reads the transaction of a failed order and settles it.
	 *
	 * @param \WC_Order $order Order marked with markFailed().
	 * @return string See settle(); OPEN without a known transaction.
	 * @throws \Throwable When the transaction cannot be read.
	 */
	public function settleFailedOrder( \WC_Order $order ): string {
		if ( '' === self::failedReason( $order ) ) {
			return self::NONE;
		}
		$transaction_id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		if ( $transaction_id <= 0 ) {
			return self::OPEN;
		}
		$transaction = $this->api->getTransaction( $transaction_id );
		$state       = isset( $transaction['state'] ) && is_string( $transaction['state'] ) ? (string) preg_replace( '/[^A-Z_]/', '', $transaction['state'] ) : '';

		return $this->settle( $order, $transaction_id, $state );
	}

	/**
	 * `woocommerce_order_status_changed`: a failed express order that failed
	 * for an owner-decided reason and is then set to any status other than
	 * failed, cancelled or refunded (for example on-hold or processing by the main
	 * plugin's webhook) gets an "ACTION REQUIRED" note.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $from     Previous status.
	 * @param mixed $to       New status.
	 * @param mixed $order    Order.
	 */
	public static function noteRevival( $order_id, $from, $to, $order = null ): void {
		if ( ! $order instanceof \WC_Order || 'failed' !== $from || ! is_string( $to ) || in_array( $to, array( 'failed', 'cancelled', 'refunded' ), true ) ) {
			return;
		}
		if ( OrderFactory::CREATED_VIA !== $order->get_created_via() ) {
			return;
		}
		$reason = self::failedReason( $order );
		if ( '' === $reason ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: reason code, 2: new order status. */
				__( 'ACTION REQUIRED: this express order failed (%1$s), but its status was then set to %2$s (for example by a payment update). Check the wallee transaction before shipping; void or refund it in the wallee portal if the order must not be delivered.', 'wallee-express-checkout' ),
				$reason,
				preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $to ) )
			)
		);
	}

	/**
	 * Requests the void, at most once: the flag is saved before the request,
	 * and a flag that cannot be saved means no request.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $transaction_id Transaction ID.
	 * @param string    $reason         Reason code.
	 * @return string RELEASED or RESERVED.
	 */
	private function void( \WC_Order $order, int $transaction_id, string $reason ): string {
		$failure = '';
		try {
			$order->update_meta_data( self::META_VOID, 'failed' );
			$order->save();
		} catch ( \Throwable $e ) {
			$failure = 'flag not saved, ' . get_class( $e );
		}

		$void_state = '';
		if ( '' === $failure ) {
			try {
				$void       = $this->api->voidTransaction( $transaction_id );
				$void_state = isset( $void['state'] ) && is_string( $void['state'] ) ? (string) preg_replace( '/[^A-Z_]/', '', $void['state'] ) : '';
				if ( 'FAILED' === $void_state ) {
					$failure = 'void state FAILED';
				}
			} catch ( \Throwable $e ) {
				$failure = get_class( $e );
			}
		}

		if ( '' !== $failure ) {
			$this->log( sprintf( 'Order %d: void of transaction %d failed (%s).', $order->get_id(), $transaction_id, $failure ), 'error' );
			$this->note(
				$order,
				sprintf(
					/* translators: 1: wallee transaction ID, 2: reason code. */
					__( 'ACTION REQUIRED: wallee transaction %1$d is AUTHORIZED (money reserved), but this order failed (%2$s) and the automatic void failed. Void transaction %1$d manually in the wallee portal.', 'wallee-express-checkout' ),
					$transaction_id,
					$reason
				)
			);

			return self::RESERVED;
		}

		try {
			$order->update_meta_data( self::META_VOID, 'requested' );
			$order->save();
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Order %d: void outcome not saved (%s).', $order->get_id(), get_class( $e ) ), 'warning' );
		}
		$this->log( sprintf( 'Order %d: void of transaction %d requested (void state %s, order failed: %s).', $order->get_id(), $transaction_id, '' === $void_state ? 'unknown' : $void_state, $reason ) );
		$this->note(
			$order,
			sprintf(
				/* translators: 1: wallee transaction ID, 2: reason code. */
				__( 'wallee Express Checkout: a void of transaction %1$d was requested automatically because this order failed (%2$s). The reserved amount is released; the customer is not charged.', 'wallee-express-checkout' ),
				$transaction_id,
				$reason
			)
		);

		return self::RELEASED;
	}

	/**
	 * The refund note for taken money, once per order.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $transaction_id Transaction ID.
	 * @param string    $state          COMPLETED or FULFILL.
	 * @param string    $reason         Reason code.
	 */
	private function noteRefund( \WC_Order $order, int $transaction_id, string $state, string $reason ): void {
		if ( '' !== (string) $order->get_meta( self::META_REFUND ) ) {
			return;
		}
		$this->log( sprintf( 'Order %d: transaction %d is %s but the order failed (%s); a refund is needed in the wallee portal.', $order->get_id(), $transaction_id, $state, $reason ), 'error' );
		$this->note(
			$order,
			sprintf(
				/* translators: 1: wallee transaction ID, 2: transaction state, 3: reason code. */
				__( 'ACTION REQUIRED: wallee transaction %1$d is already %2$s (money taken), but this order failed (%3$s). A refund is needed: refund transaction %1$d in the wallee portal.', 'wallee-express-checkout' ),
				$transaction_id,
				$state,
				$reason
			)
		);
		try {
			$order->update_meta_data( self::META_REFUND, 'yes' );
			$order->save();
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Order %d: refund note flag not saved (%s).', $order->get_id(), get_class( $e ) ), 'warning' );
		}
	}

	/**
	 * Adds a private note; a failure is logged.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $note  Note.
	 */
	private function note( \WC_Order $order, string $note ): void {
		try {
			$order->add_order_note( $note );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Order %d: note not added (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
	}

	/**
	 * Logs a line.
	 *
	 * @param string $message Message: IDs, states and reason codes only.
	 * @param string $level   Level.
	 */
	private function log( string $message, string $level = 'info' ): void {
		call_user_func( $this->log, $message, $level );
	}
}
