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
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Order\CouponHold;
use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Order\FailedOrderSettlement;
use Wallee\ExpressCheckout\Order\LinkScheduler;
use Wallee\ExpressCheckout\Order\OrderFactory;
use Wallee\ExpressCheckout\Order\OrderService;
use Wallee\ExpressCheckout\Order\OrderValidator;
use Wallee\ExpressCheckout\Order\TaxDestination;
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
	 * A return link is accepted for 2 hours after the session was created
	 * (a wallee session itself times out after 30 minutes).
	 */
	const MAX_AGE_SECONDS = 7200;

	/**
	 * Transaction states in which money is already reserved or taken.
	 */
	const MONEY_MOVED_STATES = array( 'AUTHORIZED', 'COMPLETED', 'FULFILL' );

	/**
	 * Transaction states in which a payment is under way or done.
	 */
	const PAYMENT_STATES = array( 'PROCESSING', 'AUTHORIZED', 'COMPLETED', 'FULFILL', 'CONFIRMED' );

	/**
	 * Session states in which wallee has started the payment.
	 */
	const SESSION_PAYMENT_STATES = array( 'PROCESSING', 'AUTHORIZED' );

	/**
	 * Reasons that fail the order even when the money is already reserved or
	 * taken (owner decision): the order cannot be fulfilled as approved.
	 * Every other reason found after the process call is technical and never
	 * fails an order whose payment went through.
	 */
	const FAIL_EVEN_IF_PAID = array( 'no_email', OrderValidator::COUPON_INVALID, OrderValidator::DESTINATION_CHANGED );

	const TARGET_WALLEE = 'wallee';
	const TARGET_SHOP   = 'shop';
	const TARGET_OTHER  = 'other';

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
	 * Configured authorization environment, TEST or PRODUCTION.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Host of the configured wallee base URL; the only external host the
	 * customer is sent to.
	 *
	 * @var string
	 */
	private $wallee_host;

	/**
	 * Whether WooCommerce taxes by the customer's address.
	 *
	 * @var bool
	 */
	private $tax_follows_address;

	/**
	 * Settles wallee's money of an order failed for an owner-decided reason.
	 *
	 * @var FailedOrderSettlement
	 */
	private $settlement;

	/**
	 * Whether express checkout may run: function (): bool (Plugin::isActive()).
	 *
	 * @var callable
	 */
	private $available;

	/**
	 * Host of the wallee redirect URL returned by the last handle() call.
	 *
	 * @var string|null
	 */
	private $external_host = null;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository     $repository    Session storage.
	 * @param UrlSigner             $signer        Signer.
	 * @param ApiClient             $api           API client.
	 * @param OrderService          $orders        Order operations.
	 * @param CustomerDetails       $details       Step 10.
	 * @param callable              $schedule_link Schedules the background link: function ( int $order_id ): bool.
	 * @param callable              $log           Logger: function ( string $message, string $level ).
	 * @param string                $environment   Configured authorization environment, TEST or PRODUCTION.
	 * @param string                $wallee_host   Host of the configured wallee base URL.
	 * @param bool                  $tax_follows   Whether WooCommerce taxes by the customer's address.
	 * @param FailedOrderSettlement $settlement    Settles the money of an order failed for an owner-decided reason.
	 * @param callable              $available     Whether express checkout may run: function (): bool. False when the
	 *                                             main plugin is unusable or the SDK compatibility shim is unavailable.
	 */
	public function __construct( SessionRepository $repository, UrlSigner $signer, ApiClient $api, OrderService $orders, CustomerDetails $details, callable $schedule_link, callable $log, string $environment, string $wallee_host, bool $tax_follows, FailedOrderSettlement $settlement, callable $available ) {
		$this->repository          = $repository;
		$this->signer              = $signer;
		$this->api                 = $api;
		$this->orders              = $orders;
		$this->details             = $details;
		$this->schedule_link       = $schedule_link;
		$this->log                 = $log;
		$this->environment         = $environment;
		$this->wallee_host         = strtolower( $wallee_host );
		$this->tax_follows_address = $tax_follows;
		$this->settlement          = $settlement;
		$this->available           = $available;
	}

	/**
	 * Registers the WooCommerce API endpoint.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_api_wallee_ec_return',
			static function () {
				global $wpdb;
				$adapter     = Plugin::adapter();
				$api         = new ApiClient( new SdkTransport( $adapter ) );
				$repository  = new SessionRepository( $wpdb );
				$orders      = new OrderService();
				$log         = array( $adapter, 'log' );
				$host        = wp_parse_url( $adapter->baseUrl(), PHP_URL_HOST );
				$tax_follows = OrderValidator::taxFollowsAddress();
				$handler     = new self(
					$repository,
					UrlSigner::fromWordPress(),
					$api,
					$orders,
					new CustomerDetails( $api, $repository, $orders, $log, $tax_follows ),
					array( LinkScheduler::class, 'schedule' ),
					$log,
					Plugin::settings()->environment(),
					is_string( $host ) ? $host : '',
					$tax_follows,
					new FailedOrderSettlement( $api, $log ),
					array( Plugin::class, 'isActive' )
				);

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

			return $this->toCart( Notices::forReason( 'invalid_link', Notices::CHARGE_UNKNOWN ) );
		}

		$record = $this->repository->findByUuid( $uuid );
		if ( null === $record ) {
			$this->log( 'Return: unknown session.', 'warning' );

			return $this->toCart( Notices::forReason( 'unknown_session', Notices::CHARGE_UNKNOWN ) );
		}
		$this->log( sprintf( 'Return: session row %d in state %s.', $record->id(), $record->state() ) );

		if ( $record->isOlderThan( self::MAX_AGE_SECONDS ) ) {
			$this->log( sprintf( 'Return: session row %d is too old for its return link.', $record->id() ), 'warning' );

			// Only a row still `created` is known to have had no payment started.
			return $this->toCart( Notices::forReason( Notices::LINK_EXPIRED, SessionRecord::CREATED === $record->state() ? Notices::CHARGE_NONE : Notices::CHARGE_UNKNOWN ) );
		}

		if ( SessionRecord::CREATED !== $record->state() ) {
			return $this->settled( $record );
		}
		// Without the SDK shim the main plugin cannot read the transaction this
		// return would start (its webhooks would fail): nothing is claimed,
		// created or sent to wallee. Only a row still `created` gets here, so
		// no payment was started; a reload of a processed row is settled above.
		if ( ! call_user_func( $this->available ) ) {
			$this->log( 'Return: express checkout is not available (main plugin or SDK compatibility shim); session not processed.', 'warning' );

			return $this->toCart( Notices::forReason( Notices::UNAVAILABLE, Notices::CHARGE_NONE ) );
		}
		if ( ! $this->repository->claim( $record->id(), SessionRecord::CREATED, SessionRecord::ORDERING ) ) {
			$current = $this->repository->findByUuid( $uuid );
			$this->log( sprintf( 'Return: session row %d claimed by another request (now %s).', $record->id(), null === $current ? 'gone' : $current->state() ) );

			return null === $current ? $this->toCart( Notices::forReason( 'session_gone', Notices::CHARGE_UNKNOWN ) ) : $this->settled( $current );
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

			return $this->reject( $record, OrderValidator::NOT_APPROVED . ' (' . $state . ')' );
		}
		if ( ! self::sameId( isset( $session['linkedSpaceId'] ) ? $session['linkedSpaceId'] : null, $record->spaceId() ) ) {
			return $this->reject( $record, 'space_mismatch' );
		}
		$reason = OrderValidator::environment( $session, $this->environment );
		if ( null !== $reason ) {
			return $this->reject( $record, $reason );
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
			$reason = $this->orders->stockAndCoupons( $snapshot );
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
		// cleanup leaves it alone from here on, and records the environment.
		try {
			$order->update_meta_data( OrderFactory::META_PROCESS_STARTED, (string) time() );
			$order->update_meta_data( OrderFactory::META_ENVIRONMENT, $this->environment );
			$order->save();
			if ( 'TEST' === $this->environment ) {
				$order->add_order_note( __( 'Test payment (wallee Express Checkout TEST environment). No real money was moved.', 'wallee-express-checkout' ) );
			}
		} catch ( \Throwable $e ) {
			return $this->failOrder( $record, $order, 'order_update_failed', get_class( $e ) );
		}

		$outcome = $this->process( $record, $order );
		if ( self::PROCESS_FAILED === $outcome[0] ) {
			// Not every refusal proves that nothing was reserved.
			return $this->failOrder( $record, $order, 'process_failed', $outcome[1], Notices::CHARGE_UNKNOWN );
		}
		$this->log( sprintf( 'Return: session row %d wallee session %d %s for order %d.', $record->id(), $record->walleeSessionId(), self::PROCESS_DONE === $outcome[0] ? 'processed' : 'probably processed', $order->get_id() ) );

		return $this->afterProcess( $record, $order, self::PROCESS_DONE === $outcome[0] ? $outcome[1] : null );
	}

	/**
	 * Calls process. An answer that leaves it open whether wallee processed
	 * the session (no HTTP status, 5xx, an unusable response) is followed by
	 * one session read: a session that reached wallee's payment step is
	 * `maybe processed`; a session still `APPROVED` gets one more process
	 * call (`merchantReference` is idempotent). Only a clear refusal (4xx) of
	 * the first call or a session that did not move after the retry fails.
	 *
	 * A 4xx answer to the retry can mean that the first, unclear call went
	 * through after all (the session is already processed). It is told apart
	 * from a real refusal by reading the session once more: a session with a
	 * linked transaction or in PROCESSING or AUTHORIZED was processed, so the
	 * order goes on as `maybe processed`; a session still APPROVED (or in any
	 * other state, or unreadable) means the retry was refused.
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
				$status  = $e instanceof ApiException ? $e->getStatusCode() : 0;
				$detail  = get_class( $e ) . ' HTTP ' . $status;
				$refused = $status >= 400 && $status < 500;
				if ( $refused && 1 === $attempt ) {
					return array( self::PROCESS_FAILED, $detail );
				}
				$this->log( sprintf( 'Return: session row %d order %d: process attempt %d %s (%s); reading the session.', $record->id(), $order->get_id(), $attempt, $refused ? 'refused' : 'uncertain', $detail ), 'warning' );
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
	 * Once the transaction's customer data is on the order, the order must
	 * still be valid for it before the customer is sent to wallee's payment
	 * step: an e-mail address exists (owner decision, design amendment 2),
	 * the coupons are valid for that e-mail, and the address is in the
	 * country the shipping was calculated for and, where taxes follow the
	 * customer's address, has the tax rates the order was calculated with;
	 * then WooCommerce's tentative coupon hold is taken.
	 * A transaction that cannot be read is not a failure: the customer goes
	 * on, the background link retries, and the checks are repeated once the
	 * transaction is read after payment (CustomerDetails::collectAfterPayment()).
	 *
	 * Every failure decided here goes through failAfterProcess(), which keeps
	 * an order whose money is already reserved or taken unless the reason is
	 * one the owner decided must fail.
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
		if ( null === $collected ) {
			// The customer checks need the transaction: repeated once it is read after payment.
			try {
				$this->details->deferChecks( $order );
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Return: order %d: pending checks not recorded (%s).', $order->get_id(), get_class( $e ) ), 'error' );
			}
		}
		$transaction_id = null === $collected ? 0 : $collected['transaction_id'];
		$state          = null === $collected ? '' : $collected['state'];

		try {
			if ( null !== $collected ) {
				$failure = $this->customerFailure( $record, $order, $collected['email'] );
				if ( null !== $failure ) {
					return $this->failAfterProcess( $record, $order, $failure, $transaction_id, $state );
				}
			}

			return $this->forward( $record, $order, $redirect, $transaction_id, $state );
		} catch ( \Throwable $e ) {
			return $this->failAfterProcess( $record, $order, 'after_process_error (' . get_class( $e ) . ')', $transaction_id, $state );
		}
	}

	/**
	 * Step 11: sends the customer on. wallee's redirect URL is classified by
	 * its host: the configured wallee host is followed; a shop URL is followed
	 * only if it is one of the two result URLs given to process, any other
	 * shop URL is replaced by the success result URL; any other host is never
	 * followed (the wallet may have completed the payment itself).
	 *
	 * @param SessionRecord $record         Row.
	 * @param \WC_Order     $order          Order.
	 * @param string|null   $redirect       wallee's redirect URL, null if none.
	 * @param int           $transaction_id wallee transaction ID, 0 if unknown.
	 * @param string        $state          Transaction state, '' if unknown.
	 */
	private function forward( SessionRecord $record, \WC_Order $order, ?string $redirect, int $transaction_id, string $state ): string {
		if ( null === $redirect ) {
			$this->scheduleLink( $order );
			$this->log( sprintf( 'Return: session row %d: no wallee redirect, order received page.', $record->id() ) );

			return $order->get_checkout_order_received_url();
		}

		$target = $this->redirectTarget( $redirect );
		$this->log( sprintf( 'Return: session row %d redirect target: %s.', $record->id(), self::TARGET_OTHER === $target[0] ? self::TARGET_OTHER . ':' . $target[1] : $target[0] ) );

		if ( self::TARGET_WALLEE === $target[0] ) {
			$this->scheduleLink( $order );
			$this->external_host = $this->wallee_host;
			$this->log( sprintf( 'Return: session row %d redirected to wallee.', $record->id() ) );

			return $redirect;
		}

		if ( self::TARGET_SHOP === $target[0] ) {
			$this->scheduleLink( $order );
			$own = array(
				SessionService::resultUrl( $record->uuid(), 'success' ),
				SessionService::resultUrl( $record->uuid(), 'failure' ),
			);
			if ( in_array( $redirect, $own, true ) ) {
				$this->log( sprintf( 'Return: session row %d redirected to its result URL.', $record->id() ) );

				return $redirect;
			}
			$this->log( sprintf( 'Return: session row %d: wallee redirect to another shop URL; success result URL instead.', $record->id() ), 'warning' );

			return $this->successUrl( $record );
		}

		if ( $this->paymentUnderway( $record, $state ) ) {
			$this->log( sprintf( 'Return: session row %d order %d: redirect_host_unexpected (host %s, transaction %d, state %s); order kept, success result URL.', $record->id(), $order->get_id(), $target[1], $transaction_id, '' === $state ? 'unknown' : $state ), 'warning' );
			$this->scheduleLink( $order );

			return $this->successUrl( $record );
		}

		return $this->failAfterProcess( $record, $order, 'redirect_host_unexpected', $transaction_id, $state );
	}

	/**
	 * Classifies a redirect URL: the configured wallee host, the shop's own
	 * host, or another host.
	 *
	 * @param string $url Redirect URL from wallee.
	 * @return array{0: string, 1: string} One of the TARGET_* constants and the URL's host
	 *                                     (lower case, host characters only, 'none' if absent).
	 */
	private function redirectTarget( string $url ): array {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = is_string( $host ) ? substr( (string) preg_replace( '/[^a-z0-9.\-]/', '', strtolower( $host ) ), 0, 100 ) : '';
		if ( '' === $host ) {
			return array( self::TARGET_OTHER, 'none' );
		}
		if ( '' !== $this->wallee_host && $host === $this->wallee_host ) {
			return array( self::TARGET_WALLEE, $host );
		}
		$shop = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( is_string( $shop ) && '' !== $shop && strtolower( $shop ) === $host ) {
			return array( self::TARGET_SHOP, $host );
		}

		return array( self::TARGET_OTHER, $host );
	}

	/**
	 * Whether wallee shows that the payment is under way or done: the
	 * transaction state read in this request, else a fresh read of the
	 * session. A session that cannot be read leaves it open, which counts as
	 * under way: an order is only failed when nothing indicates a payment.
	 *
	 * @param SessionRecord $record Row.
	 * @param string        $state  Transaction state read in this request, '' if unknown.
	 */
	private function paymentUnderway( SessionRecord $record, string $state ): bool {
		if ( in_array( $state, self::PAYMENT_STATES, true ) ) {
			return true;
		}
		try {
			$session = $this->api->getSession( $record->walleeSessionId() );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: session row %d: session unreadable after process (%s).', $record->id(), get_class( $e ) ), 'warning' );

			return true;
		}
		$session_state = isset( $session['state'] ) && is_string( $session['state'] ) ? (string) preg_replace( '/[^A-Z_]/', '', $session['state'] ) : '';

		return in_array( $session_state, self::SESSION_PAYMENT_STATES, true );
	}

	/**
	 * Signed success result URL of the row: the order received page once the
	 * result handler has run.
	 *
	 * @param SessionRecord $record Row.
	 */
	private function successUrl( SessionRecord $record ): string {
		return SessionService::resultUrl( $record->uuid(), 'success' );
	}

	/**
	 * Schedules the background link, logs a failure to do so.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function scheduleLink( \WC_Order $order ): void {
		if ( ! call_user_func( $this->schedule_link, $order->get_id() ) ) {
			$this->log( sprintf( 'Return: order %d: background link not scheduled.', $order->get_id() ), 'warning' );
		}
	}

	/**
	 * Checks the order against the customer data the transaction provided.
	 *
	 * @param SessionRecord $record    Row.
	 * @param \WC_Order     $order     Order with the customer data applied.
	 * @param bool          $has_email Whether the order has an e-mail address.
	 * @return string|null Reason code, null if the order may be paid.
	 */
	private function customerFailure( SessionRecord $record, \WC_Order $order, bool $has_email ): ?string {
		if ( ! $has_email ) {
			return 'no_email';
		}

		$snapshot = $record->snapshot();
		try {
			$reason = $this->orders->customerCoupons( $snapshot, (string) $order->get_billing_email() );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d: coupon check failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );

			return 'coupon_check_failed';
		}
		if ( null !== $reason ) {
			return OrderValidator::COUPON_INVALID;
		}

		$tax_address = $this->tax_follows_address ? TaxDestination::orderAddress( $order ) : null;
		$destination = OrderValidator::destination( $snapshot, $record->shippingQuote(), self::orderCountry( $order ), $tax_address );
		if ( OrderValidator::DESTINATION_CHANGED === $destination ) {
			return OrderValidator::DESTINATION_CHANGED;
		}
		if ( OrderValidator::TAX_REGION_UNMATCHED === $destination ) {
			// wallee's region format is free text: an unmatched region is not a refusal.
			$this->orders->addNoteOnce( $order, TaxDestination::regionNote() );
			$this->log( sprintf( 'Return: order %d: tax region not matched; order kept with a note.', $order->get_id() ), 'warning' );
		}

		return $this->holdCoupons( $snapshot, $order );
	}

	/**
	 * Takes WooCommerce's tentative hold on the order's coupons (the
	 * snapshot's, never the session cart's) for its customer, as its checkout
	 * does, so parallel orders cannot use up a limited coupon until the usage
	 * is recorded. An order without coupons takes no hold.
	 *
	 * A usage limit reached is the owner reason `coupon_invalid`. A hold that
	 * fails for a technical reason is the technical reason `coupon_hold_failed`,
	 * which never fails an order whose money is reserved or taken; the coupon
	 * checks after payment still apply.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param \WC_Order    $order    Order with the customer data applied.
	 * @return string|null Reason code, null if the hold was taken or not needed.
	 */
	private function holdCoupons( CartSnapshot $snapshot, \WC_Order $order ): ?string {
		try {
			$hold = $this->orders->holdCoupons( $snapshot, $order );
		} catch ( \Throwable $e ) {
			$hold = array( CouponHold::FAILED, get_class( $e ) );
		}
		if ( CouponHold::LIMIT === $hold[0] ) {
			$this->log( sprintf( 'Return: order %d: coupon hold refused (usage limit reached).', $order->get_id() ), 'warning' );

			return OrderValidator::COUPON_INVALID;
		}
		if ( CouponHold::FAILED === $hold[0] ) {
			$this->log( sprintf( 'Return: order %d: coupon_hold_failed (%s).', $order->get_id(), $hold[1] ), 'error' );

			return 'coupon_hold_failed';
		}

		return null;
	}

	/**
	 * The one place where a failure found after the process call is decided.
	 *
	 * If the transaction read in this request has the money reserved or taken
	 * (AUTHORIZED, COMPLETED, FULFILL), only the reasons the owner decided
	 * must fail (FAIL_EVEN_IF_PAID) fail the order; any other (technical)
	 * reason keeps the order and sends the customer to the success result URL.
	 *
	 * An order failed for an owner-decided reason is marked as such and its
	 * money settled (FailedOrderSettlement): a reservation (AUTHORIZED) is
	 * voided and the customer told it is being released; taken money
	 * (COMPLETED, FULFILL) is never touched, the order gets an ACTION REQUIRED
	 * note that a refund is needed and the customer is told the payment was
	 * received (also when the void failed). A payment not yet decided is
	 * checked again by the background job, which voids it once authorised.
	 *
	 * Otherwise the order must not go on to wallee's payment step: order
	 * `failed`, row `failed`, cart with a notice. The notice says the
	 * customer was not charged only if the transaction was read and is in no
	 * money-moved state; if it was not read, it says that a reserved amount
	 * will be released.
	 *
	 * @param SessionRecord $record         Row.
	 * @param \WC_Order     $order          Order.
	 * @param string        $reason         Reason code (order note and log).
	 * @param int           $transaction_id wallee transaction ID, 0 if unknown.
	 * @param string        $state          Transaction state, '' if unknown.
	 */
	private function failAfterProcess( SessionRecord $record, \WC_Order $order, string $reason, int $transaction_id, string $state ): string {
		$paid  = $transaction_id > 0 && in_array( $state, self::MONEY_MOVED_STATES, true );
		$owner = in_array( $reason, self::FAIL_EVEN_IF_PAID, true );
		if ( $paid && ! $owner ) {
			$this->log( sprintf( 'Return: session row %d order %d: %s, but transaction %d is %s; order kept, success result URL.', $record->id(), $order->get_id(), $reason, $transaction_id, $state ), 'warning' );
			$this->scheduleLink( $order );

			return $this->successUrl( $record );
		}

		$this->log( sprintf( 'Return: session row %d order %d failed: %s (transaction %d, state %s).', $record->id(), $order->get_id(), $reason, $transaction_id, '' === $state ? 'unknown' : $state ), 'error' );
		try {
			$this->orders->fail( $order, $reason );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d could not be set to failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		$this->markFailed( $record );

		$settled = $owner ? $this->settle( $order, $reason, $transaction_id, $state ) : FailedOrderSettlement::NONE;
		if ( FailedOrderSettlement::OPEN === $settled ) {
			// The payment is not decided yet: the background job voids it if it gets authorised.
			$this->scheduleLink( $order );
		}

		$country = OrderValidator::noticeCountry( $record->snapshot(), $record->shippingQuote(), self::orderCountry( $order ) );
		if ( FailedOrderSettlement::RELEASED === $settled ) {
			return $this->toCart( Notices::forReason( $reason, Notices::CHARGE_RELEASED, $country ) );
		}
		if ( $paid ) {
			return $this->toCart( Notices::paymentTaken( (string) $order->get_order_number() ) );
		}

		return $this->toCart( Notices::forReason( $reason, $transaction_id > 0 && '' !== $state ? Notices::CHARGE_NONE : Notices::CHARGE_UNKNOWN, $country ) );
	}

	/**
	 * Marks an order failed for an owner-decided reason and settles its money.
	 * Any error is logged; the fallback is the paid-order notice when the
	 * money is reserved or taken, else an open payment for the background job.
	 *
	 * @param \WC_Order $order          Order.
	 * @param string    $reason         Reason code.
	 * @param int       $transaction_id wallee transaction ID, 0 if unknown.
	 * @param string    $state          Transaction state, '' if unknown.
	 * @return string FailedOrderSettlement outcome.
	 */
	private function settle( \WC_Order $order, string $reason, int $transaction_id, string $state ): string {
		try {
			FailedOrderSettlement::markFailed( $order, $reason );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d: failure reason not saved (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		try {
			return $this->settlement->settle( $order, $transaction_id, $state );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d: payment of the failed order not settled (%s).', $order->get_id(), get_class( $e ) ), 'error' );

			// Only money already reserved or taken may be reported as received;
			// an undecided payment is left to the background job.
			return in_array( $state, self::MONEY_MOVED_STATES, true ) ? FailedOrderSettlement::RESERVED : FailedOrderSettlement::OPEN;
		}
	}

	/**
	 * Country of the order's delivery address: shipping, else billing.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function orderCountry( \WC_Order $order ): string {
		return OrderValidator::deliveryCountry( $order );
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
			return $this->toCart( Notices::processing() );
		}

		// A failed row may belong to an order whose payment went through.
		return $this->toCart( Notices::forReason( 'not_processed_again', Notices::CHARGE_UNKNOWN ) );
	}

	/**
	 * Steps 3 to 7 failed: row `failed`, no order, no process, so nothing
	 * was charged.
	 *
	 * @param SessionRecord $record Row.
	 * @param string        $reason Reason code for the log and the notice.
	 */
	private function reject( SessionRecord $record, string $reason ): string {
		$this->log( sprintf( 'Return: session row %d rejected: %s.', $record->id(), $reason ), 'warning' );
		$this->markFailed( $record );

		return $this->toCart( Notices::forReason( $reason, Notices::CHARGE_NONE ) );
	}

	/**
	 * An order exists but must not be paid: order `failed`, row `failed`.
	 *
	 * @param SessionRecord $record Row.
	 * @param \WC_Order     $order  Order.
	 * @param string        $reason Reason code (goes to the order note, the log and the notice).
	 * @param string        $detail Exception class, if any.
	 * @param string        $charge Notices::CHARGE_NONE before the process call, else CHARGE_UNKNOWN.
	 */
	private function failOrder( SessionRecord $record, \WC_Order $order, string $reason, string $detail = '', string $charge = Notices::CHARGE_NONE ): string {
		$this->log( sprintf( 'Return: session row %d order %d failed: %s%s.', $record->id(), $order->get_id(), $reason, '' === $detail ? '' : ' (' . $detail . ')' ), 'error' );
		try {
			$this->orders->fail( $order, $reason );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: order %d could not be set to failed (%s).', $order->get_id(), get_class( $e ) ), 'error' );
		}
		$this->markFailed( $record );

		return $this->toCart( Notices::forReason( $reason, $charge ) );
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
	 * @param string $notice Notice.
	 */
	private function toCart( string $notice ): string {
		Notices::add( $notice );

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
