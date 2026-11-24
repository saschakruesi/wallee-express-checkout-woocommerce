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
use Wallee\ExpressCheckout\Session\SessionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the result handler a fast path instead of the only path (final
 * review I1): after the process call an Action Scheduler job links the order
 * even when the customer's browser never comes back. It retries with back-off
 * and, after the last attempt, leaves the admin note. Viewing a not yet
 * linked express order in the admin tries once more.
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
	 * Constructor.
	 *
	 * @param OrderService      $orders     Order operations.
	 * @param CustomerDetails   $details    Transaction details.
	 * @param OrderLinker       $linker     Link to the main plugin.
	 * @param SessionRepository $repository Session storage.
	 * @param callable          $schedule   Scheduler, see self::scheduleAttempt().
	 * @param callable          $log        Logger: function ( string $message, string $level ).
	 */
	public function __construct( OrderService $orders, CustomerDetails $details, OrderLinker $linker, SessionRepository $repository, callable $schedule, callable $log ) {
		$this->orders     = $orders;
		$this->details    = $details;
		$this->linker     = $linker;
		$this->repository = $repository;
		$this->schedule   = $schedule;
		$this->log        = $log;
	}

	/**
	 * Registers the job and the admin order view retry. Not limited to the
	 * admin: Action Scheduler also runs from WP-Cron and async requests.
	 */
	public static function register(): void {
		add_action(
			self::HOOK,
			static function ( $order_id ) {
				self::fromWordPress()->run( (int) $order_id );
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

		return new self( $orders, new CustomerDetails( $api, $repository, $orders, $log ), new OrderLinker( $adapter ), $repository, array( self::class, 'scheduleAttempt' ), $log );
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
	 * order is not linked; the last attempt flags the order instead.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run( int $order_id ): void {
		$order = $this->orders->find( $order_id );
		if ( null === $order || ! self::awaitsLink( $order ) ) {
			return;
		}

		$attempt = max( 0, (int) $order->get_meta( self::META_ATTEMPTS ) ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, $attempt );
		$order->save();
		$last = $attempt >= count( self::DELAYS );

		if ( $this->resolve( $order, $last ) ) {
			return;
		}
		if ( $last ) {
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
	 * Finds the transaction if needed, then links.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $last  Whether this is the last attempt.
	 * @return bool Whether the order is linked.
	 */
	private function resolve( \WC_Order $order, bool $last ): bool {
		$transaction_id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		if ( $transaction_id <= 0 ) {
			try {
				$record = $this->repository->findByUuid( (string) $order->get_meta( OrderFactory::META_SESSION ) );
				if ( null !== $record ) {
					$this->details->collectAfterPayment( $record, $order );
				} else {
					$this->details->transactionFromSessionId( $order );
				}
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Order %d: transaction not read (%s).', $order->get_id(), get_class( $e ) ), 'warning' );
			}
			$transaction_id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		}

		if ( $transaction_id <= 0 ) {
			if ( $last ) {
				$this->linker->markUnresolved( $order, false );
			}

			return false;
		}

		return $this->linker->link( $order, $transaction_id, $last );
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
