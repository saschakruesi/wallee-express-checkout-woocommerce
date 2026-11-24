<?php
/**
 * Links express orders to the main plugin in the background.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\SdkTransport;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the result handler a fast path instead of the only path (final
 * review I1): after the process call an Action Scheduler job links the order
 * even when the customer's browser never comes back. It retries with back-off
 * and, after the last attempt, leaves the admin note. Viewing a not yet
 * linked express order in the admin tries once more. For an order that
 * failed for an owner-decided reason the same job settles the money instead
 * of linking (FailedOrderSettlement).
 */
final class LinkScheduler {

	const HOOK  = 'wallee_ec_link_order';
	const GROUP = 'wallee-ec';

	/**
	 * Delay before each attempt, in minutes. After the last one the order is
	 * flagged for the merchant.
	 *
	 * @var int[]
	 */
	const DELAYS = array( 5, 10, 20, 40, 80, 160 );

	const META_ATTEMPTS = '_wallee_ec_link_attempts';

	/**
	 * Order operations.
	 *
	 * @var OrderService
	 */
	private $orders;

	/**
	 * Transaction details.
	 *
	 * @var CustomerDetails
	 */
	private $details;

	/**
	 * Link to the main plugin.
	 *
	 * @var OrderLinker
	 */
	private $linker;

	/**
	 * Session storage.
	 *
	 * @var SessionRepository
	 */
	private $repository;

	/**
	 * Schedules an attempt: function ( int $order_id, int $delay_minutes, bool $deduplicate ): bool.
	 *
	 * @var callable
	 */
	private $schedule;

	/**
	 * Logger, called with a message and a level.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Settles the money of orders failed for an owner-decided reason.
	 *
	 * @var FailedOrderSettlement
	 */
	private $settlement;

	/**
	 * Constructor.
	 *
	 * @param OrderService          $orders     Order operations.
	 * @param CustomerDetails       $details    Transaction details.
	 * @param OrderLinker           $linker     Link to the main plugin.
	 * @param SessionRepository     $repository Session storage.
	 * @param callable              $schedule   Scheduler, see self::scheduleAttempt().
	 * @param callable              $log        Logger: function ( string $message, string $level ).
	 * @param FailedOrderSettlement $settlement Settles the money of orders failed for an owner-decided reason.
	 */
	public function __construct( OrderService $orders, CustomerDetails $details, OrderLinker $linker, SessionRepository $repository, callable $schedule, callable $log, FailedOrderSettlement $settlement ) {
		$this->orders     = $orders;
		$this->details    = $details;
		$this->linker     = $linker;
		$this->repository = $repository;
		$this->schedule   = $schedule;
		$this->log        = $log;
		$this->settlement = $settlement;
	}

	/**
	 * Registers the job and the admin order view retry. Not limited to the
	 * admin: Action Scheduler also runs from WP-Cron and async requests.
	 */
	public static function register(): void {
		add_action(
			self::HOOK,
			static function ( $order_id ) {
				try {
					self::fromWordPress()->run( (int) $order_id );
				} catch ( \Throwable $e ) {
					Plugin::adapter()->log( sprintf( 'Order %d: background link job failed (%s).', (int) $order_id, get_class( $e ) ), 'error' );
				}
			}
		);
		add_action(
			'woocommerce_admin_order_data_after_order_details',
			static function ( $order ) {
				try {
					self::fromWordPress()->retryNow( $order );
				} catch ( \Throwable $e ) {
					Plugin::adapter()->log( sprintf( 'Admin retry of the order link failed (%s).', get_class( $e ) ), 'error' );
				}
			}
		);
	}

	/**
	 * An instance wired to WordPress.
	 */
	public static function fromWordPress(): self {
		global $wpdb;
		$adapter    = Plugin::adapter();
		$api        = new ApiClient( new SdkTransport( $adapter ) );
		$repository = new SessionRepository( $wpdb );
		$orders     = new OrderService();
		$log        = array( $adapter, 'log' );

		return new self(
			$orders,
			new CustomerDetails( $api, $repository, $orders, $log, OrderValidator::taxFollowsAddress() ),
			new OrderLinker( $adapter ),
			$repository,
			array( self::class, 'scheduleAttempt' ),
			$log,
			new FailedOrderSettlement( $api, $log )
		);
	}

	/**
	 * Schedules the first attempt for an order unless one is pending or
	 * running. Called after the process call and by the result handler.
	 *
	 * @param int $order_id Order ID.
	 * @return bool Whether an attempt is scheduled now.
	 */
	public static function schedule( int $order_id ): bool {
		return self::scheduleAttempt( $order_id, self::DELAYS[0], true );
	}

	/**
	 * Schedules one attempt through WooCommerce's Action Scheduler.
	 *
	 * @param int  $order_id      Order ID.
	 * @param int  $delay_minutes Delay.
	 * @param bool $deduplicate   Skip when an attempt for the order is pending or running.
	 * @return bool Whether an attempt is scheduled now.
	 */
	public static function scheduleAttempt( int $order_id, int $delay_minutes, bool $deduplicate ): bool {
		if ( $order_id <= 0 || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}
		$args = array( $order_id );
		if ( $deduplicate && false !== as_next_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return true;
		}

		return 0 !== (int) as_schedule_single_action( time() + $delay_minutes * 60, self::HOOK, $args, self::GROUP );
	}

	/**
	 * One scheduled attempt. Schedules the next one with back-off while the
	 * order is not linked; the last attempt flags the order instead. An
	 * unexpected error inside the attempt is logged (class only) and counts
	 * as a failed attempt, so the chain goes on within the attempt limit.
	 * Without a saved attempt counter there is no limit, so the chain stops
	 * then, with an error log.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run( int $order_id ): void {
		$order = $this->orders->find( $order_id );
		if ( null === $order || ! self::awaitsLink( $order ) || OrderLinker::paymentEnded( $order ) ) {
			return;
		}

		$attempt = max( 0, (int) $order->get_meta( self::META_ATTEMPTS ) ) + 1;
		$counted = true;
		try {
			$order->update_meta_data( self::META_ATTEMPTS, $attempt );
			$order->save();
		} catch ( \Throwable $e ) {
			$counted = false;
			$this->log( sprintf( 'Order %d: link attempt counter not saved (%s); no further automatic attempt.', $order_id, get_class( $e ) ), 'error' );
		}
		$last = ! $counted || $attempt >= count( self::DELAYS );

		try {
			$done = $this->resolve( $order, $last );
		} catch ( \Throwable $e ) {
			$done = false;
			$this->log( sprintf( 'Order %d: link attempt %d stopped by an error (%s).', $order_id, $attempt, get_class( $e ) ), 'error' );
		}
		if ( $done || ! $counted ) {
			return;
		}
		if ( $last ) {
			if ( '' !== FailedOrderSettlement::failedReason( $order ) ) {
				$this->log( sprintf( 'Order %d: payment of the failed order still undecided after %d attempts.', $order_id, $attempt ), 'warning' );

				return;
			}
			$this->log( sprintf( 'Order %d: not linked after %d attempts.', $order_id, $attempt ), 'error' );

			return;
		}
		$this->log( sprintf( 'Order %d: link attempt %d failed, next in %d minutes.', $order_id, $attempt, self::DELAYS[ $attempt ] ) );
		call_user_func( $this->schedule, $order_id, self::DELAYS[ $attempt ], false );
	}

	/**
	 * Admin order view: one attempt now for an express order that is not yet
	 * linked. Does nothing (and no request) for any other order.
	 *
	 * @param mixed $order Order passed by the hook.
	 */
	public function retryNow( $order ): void {
		if ( $order instanceof \WC_Order && self::awaitsLink( $order ) ) {
			$this->resolve( $order, false );
		}
	}

	/**
	 * Whether an order is an express order still on the provisional gateway.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function awaitsLink( \WC_Order $order ): bool {
		return OrderFactory::CREATED_VIA === $order->get_created_via() && OrderFactory::GATEWAY_ID === $order->get_payment_method();
	}

	/**
	 * Finds the transaction if needed, then links. An order that failed for
	 * an owner-decided reason is never linked: its money is settled instead
	 * (an authorisation is voided, taken money flagged for a refund).
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $last  Whether this is the last attempt.
	 * @return bool Whether nothing is left to do: the order is linked, or a failed order is settled.
	 */
	private function resolve( \WC_Order $order, bool $last ): bool {
		if ( '' !== FailedOrderSettlement::failedReason( $order ) ) {
			try {
				return FailedOrderSettlement::OPEN !== $this->settlement->settleFailedOrder( $order );
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Order %d: transaction of the failed order not read (%s).', $order->get_id(), get_class( $e ) ), 'warning' );

				return false;
			}
		}

		$transaction_id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		if ( $transaction_id <= 0 || CustomerDetails::checksPending( $order ) ) {
			$record    = null;
			$collected = null;
			try {
				$record = $this->repository->findByUuid( (string) $order->get_meta( OrderFactory::META_SESSION ) );
				if ( null !== $record ) {
					$collected = $this->details->collectAfterPayment( $record, $order, true );
				} else {
					$this->details->transactionFromSessionId( $order );
				}
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Order %d: transaction not read (%s).', $order->get_id(), get_class( $e ) ), 'warning' );
			}
			if ( null !== $record && null !== $collected && ! $collected['email'] ) {
				return $this->failWithoutEmail( $record, $order, $collected['transaction_id'], $collected['state'] );
			}
			$transaction_id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		}

		if ( $transaction_id <= 0 ) {
			if ( $last ) {
				$this->linker->markUnresolved( $order, false );
			}

			return false;
		}

		return $this->linker->link( $order, $transaction_id, $last ) || OrderLinker::paymentEnded( $order );
	}

	/**
	 * The owner-decided rule for a missing e-mail, applied in the background
	 * as at the return: wallee gave no e-mail address once the transaction
	 * could be read (it authorised after the customer's browser left), so
	 * the order fails for `no_email` whatever the payment state, and its
	 * money is settled like at the return (a reservation is voided, taken
	 * money gets the refund note, an undecided payment is checked again).
	 * The order is not linked.
	 *
	 * @param SessionRecord $record         Row.
	 * @param \WC_Order     $order          Order.
	 * @param int           $transaction_id wallee transaction ID.
	 * @param string        $state          Transaction state, '' if unknown.
	 * @return bool Whether nothing is left to do (the payment is settled).
	 */
	private function failWithoutEmail( SessionRecord $record, \WC_Order $order, int $transaction_id, string $state ): bool {
		$reason = 'no_email';
		$this->log( sprintf( 'Order %d: no e-mail address from wallee (transaction %d, state %s); order failed (%s).', $order->get_id(), $transaction_id, '' === $state ? 'unknown' : $state, $reason ), 'error' );
		try {
			$this->orders->fail( $order, $reason );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Order %d could not be set to failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		try {
			FailedOrderSettlement::markFailed( $order, $reason );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Order %d: failure reason not saved (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		try {
			$this->repository->update( $record->id(), array( 'state' => SessionRecord::FAILED ) );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Session row %d could not be set to failed (%s).', $record->id(), get_class( $e ) ), 'error' );
		}

		return FailedOrderSettlement::OPEN !== $this->settlement->settle( $order, $transaction_id, $state );
	}

	/**
	 * Logs a line.
	 *
	 * @param string $message Message: IDs and states only.
	 * @param string $level   Level.
	 */
	private function log( string $message, string $level = 'info' ): void {
		call_user_func( $this->log, $message, $level );
	}
}
