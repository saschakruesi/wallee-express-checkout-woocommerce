<?php
/**
 * The browser's return from the wallet: creates the order and starts the payment.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\SdkTransport;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Order\LinkScheduler;
use Wallee\ExpressCheckout\Order\OrderFactory;
use Wallee\ExpressCheckout\Order\OrderService;
use Wallee\ExpressCheckout\Order\OrderValidator;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Session\SessionService;
use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * `?wc-api=wallee_ec_return&ec_session=&sig=` (design amendment 1, return
 * handler steps 1 to 11).
 *
 * Everything before the process call is strict: any doubt ends with the cart
 * and no process call. Only the request that wins the claim `created ->
 * ordering` may create an order or call process. What follows a successful
 * process call is best effort, since the customer has approved the payment.
 */
final class ReturnHandler {

	const PROCESS_DONE   = 'done';
	const PROCESS_MAYBE  = 'maybe';
	const PROCESS_FAILED = 'failed';

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
	 * API client.
	 *
	 * @var ApiClient
	 */
	private $api;

	/**
	 * Order operations.
	 *
	 * @var OrderService
	 */
	private $orders;

	/**
	 * Step 10.
	 *
	 * @var CustomerDetails
	 */
	private $details;

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
	 * Host of the wallee redirect URL returned by the last handle() call.
	 *
	 * @var string|null
	 */
	private $external_host = null;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $repository    Session storage.
	 * @param UrlSigner         $signer        Signer.
	 * @param ApiClient         $api           API client.
	 * @param OrderService      $orders        Order operations.
	 * @param CustomerDetails   $details       Step 10.
	 * @param callable          $schedule_link Schedules the background link: function ( int $order_id ): bool.
	 * @param callable          $log           Logger: function ( string $message, string $level ).
	 */
	public function __construct( SessionRepository $repository, UrlSigner $signer, ApiClient $api, OrderService $orders, CustomerDetails $details, callable $schedule_link, callable $log ) {
		$this->repository    = $repository;
		$this->signer        = $signer;
		$this->api           = $api;
		$this->orders        = $orders;
		$this->details       = $details;
		$this->schedule_link = $schedule_link;
		$this->log           = $log;
	}

	/**
	 * Registers the WooCommerce API endpoint.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_api_wallee_ec_return',
			static function () {
				global $wpdb;
				$adapter    = Plugin::adapter();
				$api        = new ApiClient( new SdkTransport( $adapter ) );
				$repository = new SessionRepository( $wpdb );
				$orders     = new OrderService();
				$log        = array( $adapter, 'log' );
				$handler    = new self( $repository, UrlSigner::fromWordPress(), $api, $orders, new CustomerDetails( $api, $repository, $orders, $log ), array( LinkScheduler::class, 'schedule' ), $log );

				$url = $handler->handle( Redirects::query( array( 'ec_session', 'sig' ) ) );
				Redirects::send( $url, $handler->externalHost() );
			}
		);
	}

	/**
	 * Handles a return.
	 *
	 * @param array $query Query parameters (`ec_session`, `sig`).
	 * @return string URL to redirect the browser to.
	 */
	public function handle( array $query ): string {
		$this->external_host = null;

		$uuid = isset( $query['ec_session'] ) && is_string( $query['ec_session'] ) ? $query['ec_session'] : '';
		$sig  = isset( $query['sig'] ) && is_string( $query['sig'] ) ? $query['sig'] : '';
		if ( '' === $uuid || ! $this->signer->verify( $uuid, SessionService::PURPOSE_RETURN, $sig ) ) {
			$this->log( 'Return: invalid or missing signature.', 'warning' );

			return $this->toCart();
		}

		$record = $this->repository->findByUuid( $uuid );
		if ( null === $record ) {
			$this->log( 'Return: unknown session.', 'warning' );

			return $this->toCart();
		}
		$this->log( sprintf( 'Return: session row %d in state %s.', $record->id(), $record->state() ) );

		if ( SessionRecord::CREATED !== $record->state() ) {
			return $this->settled( $record );
		}
		if ( ! $this->repository->claim( $record->id(), SessionRecord::CREATED, SessionRecord::ORDERING ) ) {
			$current = $this->repository->findByUuid( $uuid );
			$this->log( sprintf( 'Return: session row %d claimed by another request (now %s).', $record->id(), null === $current ? 'gone' : $current->state() ) );

			return null === $current ? $this->toCart() : $this->settled( $current );
		}
		$this->log( sprintf( 'Return: session row %d created -> ordering.', $record->id() ) );

		return $this->order( $record );
	}

	/**
	 * Host of the wallee redirect, null when the last result was an own URL.
	 */
	public function externalHost(): ?string {
		return $this->external_host;
	}

	/**
	 * Steps 3 to 11, for the request that won the claim.
	 *
	 * @param SessionRecord $record Row, now in `ordering`.
	 */
	private function order( SessionRecord $record ): string {
		$snapshot = $record->snapshot();

		try {
			$session = $this->api->getSession( $record->walleeSessionId() );
		} catch ( \Throwable $e ) {
			return $this->reject( $record, 'session_unavailable (' . get_class( $e ) . ')' );
		}
		if ( ! isset( $session['state'] ) || 'APPROVED' !== $session['state'] ) {
			$state = isset( $session['state'] ) && is_string( $session['state'] ) ? preg_replace( '/[^A-Z_]/', '', $session['state'] ) : 'none';

			return $this->reject( $record, OrderValidator::NOT_APPROVED . ' (' . $state . ')', __( 'Your express payment has expired. Please try again.', 'wallee-express-checkout' ) );
		}
		if ( ! self::sameId( isset( $session['linkedSpaceId'] ) ? $session['linkedSpaceId'] : null, $record->spaceId() ) ) {
			return $this->reject( $record, 'space_mismatch' );
		}

		$quote  = $record->shippingQuote();
		$reason = OrderValidator::check( $snapshot, $session, $quote );
		if ( null !== $reason ) {
			return $this->reject( $record, $reason );
		}
		$rate = null;
		if ( $snapshot->needsShipping() ) {
			$rate = OrderValidator::matchedRate( $session, $quote );
			if ( null === $rate ) {
				return $this->reject( $record, OrderValidator::SHIPPING_MISSING );
			}
		}

		try {
			$reason = $this->orders->stockAndCoupons( $snapshot, '' );
		} catch ( \Throwable $e ) {
			$reason = 'validation_error (' . get_class( $e ) . ')';
		}
		if ( null !== $reason ) {
			return $this->reject( $record, $reason );
		}

		try {
			$order = $this->orders->create( $snapshot, $rate, $record->uuid(), self::walletName( $session ), $record->walleeSessionId() );
		} catch ( \Throwable $e ) {
			// OrderFactory reports some causes as a bare reason code (`tax_rate_changed`).
			$code = 1 === preg_match( '/^[a-z_]{1,40}$/', $e->getMessage() ) ? ': ' . $e->getMessage() : '';

			return $this->reject( $record, 'order_creation_failed (' . get_class( $e ) . $code . ')' );
		}
		$this->log( sprintf( 'Return: session row %d order %d created.', $record->id(), $order->get_id() ) );

		try {
			$this->repository->update( $record->id(), array( 'order_id' => $order->get_id() ) );
		} catch ( \Throwable $e ) {
			return $this->failOrder( $record, $order, 'session_update_failed' );
		}

		$decimals = Money::decimals( $snapshot->currency() );
		if ( ! Money::equals( $order->get_total(), $session['authorizationAmount'], $decimals ) ) {
			return $this->failOrder( $record, $order, OrderValidator::AMOUNT_MISMATCH );
		}

		// Marks the order as possibly known to wallee, so the unpaid-order
		// cleanup leaves it alone from here on.
		try {
			$order->update_meta_data( OrderFactory::META_PROCESS_STARTED, (string) time() );
			$order->save();
		} catch ( \Throwable $e ) {
			return $this->failOrder( $record, $order, 'order_update_failed', get_class( $e ) );
		}

		$outcome = $this->process( $record, $order );
		if ( self::PROCESS_FAILED === $outcome[0] ) {
			return $this->failOrder( $record, $order, 'process_failed', $outcome[1] );
		}
		$this->log( sprintf( 'Return: session row %d wallee session %d %s for order %d.', $record->id(), $record->walleeSessionId(), self::PROCESS_DONE === $outcome[0] ? 'processed' : 'probably processed', $order->get_id() ) );

		return $this->afterProcess( $record, $order, self::PROCESS_DONE === $outcome[0] ? $outcome[1] : null );
	}

	/**
	 * Calls process. An answer that leaves it open whether wallee processed
	 * the session (no HTTP status, 5xx, an unusable response) is followed by
	 * one session read: a session that reached wallee's payment step is
	 * `maybe processed`; a session still `APPROVED` gets one more process
	 * call (`merchantReference` is idempotent). Only a clear refusal (4xx) or
	 * a session that did not move after the retry fails.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order.
	 * @return array{0: string, 1: string} Outcome (one of the PROCESS_* constants) and the
	 *                                     redirect URL (done) or the failure detail (failed).
	 */
	private function process( SessionRecord $record, \WC_Order $order ): array {
		for ( $attempt = 1; ; ++$attempt ) {
			try {
				return array(
					self::PROCESS_DONE,
					$this->api->processSession(
						$record->walleeSessionId(),
						(string) $order->get_id(),
						SessionService::resultUrl( $record->uuid(), 'success' ),
						SessionService::resultUrl( $record->uuid(), 'failure' )
					),
				);
			} catch ( \Throwable $e ) {
				$status = $e instanceof ApiException ? $e->getStatusCode() : 0;
				$detail = get_class( $e ) . ' HTTP ' . $status;
				if ( $status >= 400 && $status < 500 ) {
					return array( self::PROCESS_FAILED, $detail );
				}
				$this->log( sprintf( 'Return: session row %d order %d: process attempt %d uncertain (%s); reading the session.', $record->id(), $order->get_id(), $attempt, $detail ), 'warning' );
			}

			try {
				$session = $this->api->getSession( $record->walleeSessionId() );
			} catch ( \Throwable $e ) {
				return array( self::PROCESS_FAILED, $detail . ', session unreadable (' . get_class( $e ) . ')' );
			}
			$state = isset( $session['state'] ) && is_string( $session['state'] ) ? (string) preg_replace( '/[^A-Z_]/', '', $session['state'] ) : '';
			if ( null !== CustomerDetails::transactionId( $session ) || in_array( $state, array( 'PROCESSING', 'AUTHORIZED' ), true ) ) {
				return array( self::PROCESS_MAYBE, '' );
			}
			if ( 1 !== $attempt || 'APPROVED' !== $state ) {
				return array( self::PROCESS_FAILED, $detail . ', session ' . ( '' === $state ? 'unknown' : $state ) );
			}
		}
	}

	/**
	 * Step 10 and 11 once process went through (or probably did). The row is
	 * marked first, so a reload during step 10 cannot strand it.
	 *
	 * Without an e-mail address the order fails (owner decision, design
	 * amendment 2) and the customer is not sent on to wallee. A transaction
	 * that cannot be read is not "no e-mail": the customer goes on and the
	 * background link retries.
	 *
	 * @param SessionRecord $record   Row.
	 * @param \WC_Order     $order    Order.
	 * @param string|null   $redirect wallee's redirect URL, null when process probably went through but sent none.
	 */
	private function afterProcess( SessionRecord $record, \WC_Order $order, ?string $redirect ): string {
		try {
			$this->repository->update( $record->id(), array( 'state' => SessionRecord::PROCESSED ) );
			$this->log( sprintf( 'Return: session row %d ordering -> processed.', $record->id() ) );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: session row %d could not be set to processed (%s).', $record->id(), get_class( $e ) ), 'error' );
		}

		$collected = null;
		try {
			$collected = $this->details->collect( $record, $order );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: session row %d order %d: transaction details not read (%s); retried later.', $record->id(), $order->get_id(), get_class( $e ) ), 'warning' );
		}
		if ( null !== $collected && ! $collected['email'] ) {
			return $this->failWithoutEmail( $record, $order, $collected['transaction_id'], $collected['state'] );
		}

		if ( ! call_user_func( $this->schedule_link, $order->get_id() ) ) {
			$this->log( sprintf( 'Return: order %d: background link not scheduled.', $order->get_id() ), 'warning' );
		}

		if ( null === $redirect ) {
			$this->log( sprintf( 'Return: session row %d: no wallee redirect, order received page.', $record->id() ) );

			return $order->get_checkout_order_received_url();
		}

		$host                = wp_parse_url( $redirect, PHP_URL_HOST );
		$this->external_host = is_string( $host ) ? $host : null;
		$this->log( sprintf( 'Return: session row %d redirected to wallee.', $record->id() ) );

		return $redirect;
	}

	/**
	 * The transaction was read and carries no usable e-mail address: order
	 * `failed`, row `failed`, cart. If the money is already reserved or taken,
	 * the merchant must void or refund it in the wallee portal.
	 *
	 * @param SessionRecord $record         Row.
	 * @param \WC_Order     $order          Order.
	 * @param int           $transaction_id wallee transaction ID.
	 * @param string        $state          Transaction state.
	 */
	private function failWithoutEmail( SessionRecord $record, \WC_Order $order, int $transaction_id, string $state ): string {
		$this->log( sprintf( 'Return: session row %d order %d failed: no_email (transaction %d, state %s).', $record->id(), $order->get_id(), $transaction_id, '' === $state ? 'unknown' : $state ), 'error' );
		try {
			$this->orders->fail( $order, 'no_email' );
			if ( in_array( $state, array( 'AUTHORIZED', 'COMPLETED', 'FULFILL' ), true ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: wallee transaction ID, 2: transaction state. */
						__( 'ACTION REQUIRED: wallee transaction %1$d is already %2$s (money reserved or taken), but this order failed because wallee returned no e-mail address. Void or refund transaction %1$d in the wallee portal.', 'wallee-express-checkout' ),
						$transaction_id,
						$state
					)
				);
				$this->log( sprintf( 'Return: order %d: wallee transaction %d must be voided or refunded in the wallee portal (state %s).', $order->get_id(), $transaction_id, $state ), 'error' );
			}
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d could not be set to failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		$this->markFailed( $record );

		return $this->toCart( __( 'Your wallet did not provide an e-mail address, so the order could not be placed. Please try again or use the regular checkout.', 'wallee-express-checkout' ) );
	}

	/**
	 * Where a row that is not (or no longer) ours to process leads: the order
	 * received page once the payment was started, else the cart.
	 *
	 * @param SessionRecord $record Row.
	 */
	private function settled( SessionRecord $record ): string {
		$state = $record->state();
		if ( null !== $record->orderId() && ( SessionRecord::PROCESSED === $state || SessionRecord::COMPLETED === $state ) ) {
			$order = $this->orders->find( $record->orderId() );
			if ( null !== $order ) {
				$this->log( sprintf( 'Return: session row %d already %s, order %d: order received page.', $record->id(), $state, $order->get_id() ) );

				return $order->get_checkout_order_received_url();
			}
		}
		$this->log( sprintf( 'Return: session row %d in state %s is not processed again.', $record->id(), $state ) );

		if ( SessionRecord::ORDERING === $state ) {
			return $this->toCart( __( 'Your express payment is already being processed.', 'wallee-express-checkout' ) );
		}

		return $this->toCart();
	}

	/**
	 * Steps 3 to 7 failed: row `failed`, no order, no process.
	 *
	 * @param SessionRecord $record Row.
	 * @param string        $reason Reason code for the log.
	 * @param string|null   $notice Customer notice, generic when null.
	 */
	private function reject( SessionRecord $record, string $reason, ?string $notice = null ): string {
		$this->log( sprintf( 'Return: session row %d rejected: %s.', $record->id(), $reason ), 'warning' );
		$this->markFailed( $record );

		return $this->toCart( $notice );
	}

	/**
	 * An order exists but must not be paid: order `failed`, row `failed`.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order.
	 * @param string        $reason Reason code (goes to the order note and the log).
	 * @param string        $detail Exception class, if any.
	 */
	private function failOrder( SessionRecord $record, \WC_Order $order, string $reason, string $detail = '' ): string {
		$this->log( sprintf( 'Return: session row %d order %d failed: %s%s.', $record->id(), $order->get_id(), $reason, '' === $detail ? '' : ' (' . $detail . ')' ), 'error' );
		try {
			$this->orders->fail( $order, $reason );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d could not be set to failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		$this->markFailed( $record );

		return $this->toCart();
	}

	/**
	 * Row to `failed`, logged.
	 *
	 * @param SessionRecord $record Row.
	 */
	private function markFailed( SessionRecord $record ): void {
		try {
			$this->repository->update( $record->id(), array( 'state' => SessionRecord::FAILED ) );
			$this->log( sprintf( 'Return: session row %d ordering -> failed.', $record->id() ) );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: session row %d could not be set to failed (%s).', $record->id(), get_class( $e ) ), 'error' );
		}
	}

	/**
	 * Cart URL, with an error notice.
	 *
	 * @param string|null $notice Notice, generic when null.
	 */
	private function toCart( ?string $notice = null ): string {
		wc_add_notice( null === $notice ? Redirects::genericNotice() : $notice, 'error' );

		return wc_get_cart_url();
	}

	/**
	 * Wallet name: the first value of `walletType.name`, a localised map.
	 *
	 * @param array $session Session.
	 */
	private static function walletName( array $session ): string {
		$name = isset( $session['walletType']['name'] ) ? $session['walletType']['name'] : '';
		if ( is_array( $name ) ) {
			$name = array() === $name ? '' : reset( $name );
		}

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Whether a value is exactly the expected integer ID (int or digit string).
	 *
	 * @param mixed $value    Value.
	 * @param int   $expected Expected ID.
	 */
	private static function sameId( $value, int $expected ): bool {
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,18}$/', $value ) ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value === $expected;
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
