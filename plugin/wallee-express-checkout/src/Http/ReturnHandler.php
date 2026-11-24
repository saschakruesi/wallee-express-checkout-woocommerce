<?php
/**
 * The browser's return from the wallet: creates the order and starts the payment.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\SdkTransport;
use Wallee\ExpressCheckout\Order\CustomerDetails;
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
	 * @param SessionRepository $repository Session storage.
	 * @param UrlSigner         $signer     Signer.
	 * @param ApiClient         $api        API client.
	 * @param OrderService      $orders     Order operations.
	 * @param CustomerDetails   $details    Step 10.
	 * @param callable          $log        Logger: function ( string $message, string $level ).
	 */
	public function __construct( SessionRepository $repository, UrlSigner $signer, ApiClient $api, OrderService $orders, CustomerDetails $details, callable $log ) {
		$this->repository = $repository;
		$this->signer     = $signer;
		$this->api        = $api;
		$this->orders     = $orders;
		$this->details    = $details;
		$this->log        = $log;
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
				$handler    = new self( $repository, UrlSigner::fromWordPress(), $api, $orders, new CustomerDetails( $api, $repository, $orders, $log ), $log );

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
	 * Filter callback for `allowed_redirect_hosts`: adds the wallee host of
	 * this request's redirect.
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return array
	 */
	public function allowRedirectHost( $hosts ): array {
		$hosts = is_array( $hosts ) ? $hosts : array();
		if ( null !== $this->external_host ) {
			$hosts[] = $this->external_host;
		}

		return $hosts;
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
			$order = $this->orders->create( $snapshot, $rate, $record->uuid(), self::walletName( $session ) );
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

		try {
			$redirect = $this->api->processSession(
				$record->walleeSessionId(),
				(string) $order->get_id(),
				SessionService::resultUrl( $record->uuid(), 'success' ),
				SessionService::resultUrl( $record->uuid(), 'failure' )
			);
		} catch ( \Throwable $e ) {
			return $this->failOrder( $record, $order, 'process_failed', get_class( $e ) );
		}
		$this->log( sprintf( 'Return: session row %d wallee session %d processed for order %d.', $record->id(), $record->walleeSessionId(), $order->get_id() ) );

		// From here on the customer is sent to wallee whatever happens. The
		// row is marked first, so a reload during step 10 cannot strand it.
		try {
			$this->repository->update( $record->id(), array( 'state' => SessionRecord::PROCESSED ) );
			$this->log( sprintf( 'Return: session row %d ordering -> processed.', $record->id() ) );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: session row %d could not be set to processed (%s).', $record->id(), get_class( $e ) ), 'error' );
		}
		try {
			$this->details->collect( $record, $order );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'Return: session row %d order %d: transaction details not read (%s); the result handler retries.', $record->id(), $order->get_id(), get_class( $e ) ), 'warning' );
		}

		$host                = wp_parse_url( $redirect, PHP_URL_HOST );
		$this->external_host = is_string( $host ) ? $host : null;
		$this->log( sprintf( 'Return: session row %d redirected to wallee.', $record->id() ) );

		return $redirect;
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
