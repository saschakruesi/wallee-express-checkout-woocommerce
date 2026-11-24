<?php
/**
 * The browser's return from wallee's payment step.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\SdkTransport;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Order\LinkScheduler;
use Wallee\ExpressCheckout\Order\OrderLinker;
use Wallee\ExpressCheckout\Order\OrderService;
use Wallee\ExpressCheckout\Order\OrderValidator;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Session\SessionService;

defined( 'ABSPATH' ) || exit;

/**
 * `?wc-api=wallee_ec_result&status=success|failure&ec_session=&sig=` (design
 * amendment 1, result handler). The signature is bound to the status.
 *
 * Arrival on the success URL is not proof of payment: the order stays as it
 * is (normally `pending`) until the main plugin's webhook moves it. The only
 * status this handler sets is `failed`, for a still pending order on the
 * failure URL.
 */
final class ResultHandler {

	const STATUS_SUCCESS = 'success';
	const STATUS_FAILURE = 'failure';

	/**
	 * A result link is accepted for 7 days after the session was created.
	 */
	const MAX_AGE_SECONDS = 604800;

	/**
	 * Session storage.
	 *
	 * @var SessionRepository
	 */
	private $repository;

	/**
	 * Signer.
	 *
	 * @var UrlSigner
	 */
	private $signer;

	/**
	 * Order operations.
	 *
	 * @var OrderService
	 */
	private $orders;

	/**
	 * Step 10 of the return flow, repeated when incomplete.
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
	 * Schedules the background link: function ( int $order_id ): bool.
	 *
	 * @var callable
	 */
	private $schedule_link;

	/**
	 * Logger, called with a message and a level.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $repository    Session storage.
	 * @param UrlSigner         $signer        Signer.
	 * @param OrderService      $orders        Order operations.
	 * @param CustomerDetails   $details       Step 10.
	 * @param OrderLinker       $linker        Link to the main plugin.
	 * @param callable          $schedule_link Schedules the background link: function ( int $order_id ): bool.
	 * @param callable          $log           Logger: function ( string $message, string $level ).
	 */
	public function __construct( SessionRepository $repository, UrlSigner $signer, OrderService $orders, CustomerDetails $details, OrderLinker $linker, callable $schedule_link, callable $log ) {
		$this->repository    = $repository;
		$this->signer        = $signer;
		$this->orders        = $orders;
		$this->details       = $details;
		$this->linker        = $linker;
		$this->schedule_link = $schedule_link;
		$this->log           = $log;
	}

	/**
	 * Registers the WooCommerce API endpoint.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_api_wallee_ec_result',
			static function () {
				global $wpdb;
				$adapter    = Plugin::adapter();
				$api        = new ApiClient( new SdkTransport( $adapter ) );
				$repository = new SessionRepository( $wpdb );
				$orders     = new OrderService();
				$log        = array( $adapter, 'log' );
				$handler    = new self( $repository, UrlSigner::fromWordPress(), $orders, new CustomerDetails( $api, $repository, $orders, $log, OrderValidator::taxFollowsAddress() ), new OrderLinker( $adapter ), array( LinkScheduler::class, 'schedule' ), $log );

				Redirects::send( $handler->handle( Redirects::query( array( 'status', 'ec_session', 'sig' ) ) ), null );
			}
		);
	}

	/**
	 * Handles a result.
	 *
	 * @param array $query Query parameters (`status`, `ec_session`, `sig`).
	 * @return string URL to redirect the browser to.
	 */
	public function handle( array $query ): string {
		$status = isset( $query['status'] ) && is_string( $query['status'] ) ? $query['status'] : '';
		$uuid   = isset( $query['ec_session'] ) && is_string( $query['ec_session'] ) ? $query['ec_session'] : '';
		$sig    = isset( $query['sig'] ) && is_string( $query['sig'] ) ? $query['sig'] : '';

		if ( self::STATUS_SUCCESS !== $status && self::STATUS_FAILURE !== $status ) {
			$this->log( 'Result: unknown status.', 'warning' );

			return $this->toCart( Notices::forReason( 'invalid_link', Notices::CHARGE_UNKNOWN ) );
		}
		if ( '' === $uuid || ! $this->signer->verify( $uuid, SessionService::PURPOSE_RESULT . ':' . $status, $sig ) ) {
			$this->log( sprintf( 'Result: invalid or missing signature for status %s.', $status ), 'warning' );

			return $this->toCart( Notices::forReason( 'invalid_link', Notices::CHARGE_UNKNOWN ) );
		}

		$record = $this->repository->findByUuid( $uuid );
		if ( null !== $record && $record->isOlderThan( self::MAX_AGE_SECONDS ) ) {
			$this->log( sprintf( 'Result: session row %d is too old for its result link.', $record->id() ), 'warning' );

			return $this->toCart( Notices::forReason( Notices::LINK_EXPIRED, Notices::CHARGE_UNKNOWN ) );
		}
		$order = null === $record || null === $record->orderId() ? null : $this->orders->find( $record->orderId() );
		if ( null === $record || null === $order ) {
			$this->log( sprintf( 'Result: %s for %s.', $status, null === $record ? 'an unknown session' : sprintf( 'session row %d without order', $record->id() ) ), 'warning' );

			return $this->toCart( Notices::forReason( 'unknown_session', Notices::CHARGE_UNKNOWN ) );
		}
		$this->log( sprintf( 'Result: %s for session row %d in state %s, order %d.', $status, $record->id(), $record->state(), $order->get_id() ) );

		return self::STATUS_SUCCESS === $status ? $this->success( $record, $order ) : $this->failure( $record, $order );
	}

	/**
	 * Success URL: complete the link and show the order received page.
	 * A reload (row `completed`) only shows the page again.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order.
	 */
	private function success( SessionRecord $record, \WC_Order $order ): string {
		$state = $record->state();
		if ( SessionRecord::COMPLETED === $state ) {
			return $order->get_checkout_order_received_url();
		}
		if ( SessionRecord::PROCESSED !== $state && SessionRecord::ORDERING !== $state ) {
			$this->log( sprintf( 'Result: success ignored for session row %d in state %s.', $record->id(), $state ), 'warning' );

			return $this->toCart( Notices::forReason( 'success_ignored', Notices::CHARGE_UNKNOWN ) );
		}

		// The customer may have paid: a missing e-mail only gets a note here.
		$transaction_id = $this->transactionId( $record, $order );
		if ( null === $transaction_id || '' === $order->get_billing_email() || CustomerDetails::checksPending( $order ) ) {
			try {
				$this->details->collectAfterPayment( $record, $order );
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Result: session row %d order %d: transaction details not read (%s).', $record->id(), $order->get_id(), get_class( $e ) ), 'warning' );
			}
			$transaction_id = $this->transactionId( $record, $order );
		}

		$linked = false;
		if ( null === $transaction_id ) {
			$this->log( sprintf( 'Result: session row %d order %d: no transaction ID, not linked yet.', $record->id(), $order->get_id() ), 'warning' );
		} else {
			try {
				$linked = $this->linker->link( $order, $transaction_id );
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Result: order %d: link failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
			}
		}
		if ( ! $linked && ! call_user_func( $this->schedule_link, $order->get_id() ) ) {
			$this->log( sprintf( 'Result: order %d: background link not scheduled.', $order->get_id() ), 'warning' );
		}

		try {
			if ( CartSnapshot::CONTEXT_CART === $record->snapshot()->context() ) {
				$this->emptyCart();
				$this->log( sprintf( 'Result: session row %d: cart emptied.', $record->id() ) );
			}
			SessionController::forget();
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Result: session row %d: cart or customer session not cleared (%s).', $record->id(), get_class( $e ) ), 'error' );
		}

		$this->setState( $record, SessionRecord::COMPLETED );

		return $order->get_checkout_order_received_url();
	}

	/**
	 * Failure URL: a still pending order fails, the customer goes back to the
	 * cart. Nothing changes after completion.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order.
	 */
	private function failure( SessionRecord $record, \WC_Order $order ): string {
		$state = $record->state();
		if ( SessionRecord::COMPLETED === $state ) {
			$this->log( sprintf( 'Result: failure ignored for completed session row %d.', $record->id() ), 'warning' );

			return $order->get_checkout_order_received_url();
		}

		if ( SessionRecord::FAILED !== $state ) {
			if ( $order->has_status( 'pending' ) ) {
				try {
					$this->orders->fail( $order, Notices::PAYMENT_NOT_COMPLETED );
					$this->log( sprintf( 'Result: order %d pending -> failed.', $order->get_id() ) );
				} catch ( \Throwable $e ) {
					$this->log( sprintf( 'Result: order %d could not be set to failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
				}
			} else {
				$this->log( sprintf( 'Result: order %d left in status %s.', $order->get_id(), $order->get_status() ) );
			}
			$this->setState( $record, SessionRecord::FAILED );
		}

		// An order the webhook has moved on (paid) keeps the uncertain wording.
		$charge = $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ? Notices::CHARGE_NONE : Notices::CHARGE_UNKNOWN;

		return $this->toCart( Notices::forReason( Notices::PAYMENT_NOT_COMPLETED, $charge ) );
	}

	/**
	 * The transaction ID known for the order: order meta, else the row.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order.
	 */
	private function transactionId( SessionRecord $record, \WC_Order $order ): ?int {
		$id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		if ( $id <= 0 && null !== $record->transactionId() ) {
			$id = $record->transactionId();
		}

		return $id > 0 ? $id : null;
	}

	/**
	 * Empties the WooCommerce cart.
	 */
	private function emptyCart(): void {
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Row to a new state, logged.
	 *
	 * @param SessionRecord $record Row.
	 * @param string        $state  New state.
	 */
	private function setState( SessionRecord $record, string $state ): void {
		try {
			$this->repository->update( $record->id(), array( 'state' => $state ) );
			$this->log( sprintf( 'Result: session row %d %s -> %s.', $record->id(), $record->state(), $state ) );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Result: session row %d could not be set to %s (%s).', $record->id(), $state, get_class( $e ) ), 'error' );
		}
	}

	/**
	 * Cart URL, with an error notice.
	 *
	 * @param string $notice Notice.
	 */
	private function toCart( string $notice ): string {
		Notices::add( $notice );

		return wc_get_cart_url();
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
