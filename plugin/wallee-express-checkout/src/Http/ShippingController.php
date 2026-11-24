<?php
/**
 * REST endpoint wallee calls to ask for shipping options.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

use Wallee\ExpressCheckout\Order\TaxDestination;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Security\RateLimiter;
use Wallee\ExpressCheckout\Security\UrlSigner;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Session\SessionService;
use Wallee\ExpressCheckout\Shipping\ShippingOptionsProvider;
use Wallee\ExpressCheckout\Shipping\ShippingRateMapper;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/wallee-ec/v1/shipping-options?ec_session=&sig=.
 *
 * Server to server: no cookie, no nonce. The signed URL, the row and the ids in
 * the body are the checks. Business results are HTTP 200.
 */
final class ShippingController {

	const NAMESPACE_NAME = 'wallee-ec/v1';
	const RATE_MAX       = 60;
	const RATE_WINDOW    = 60;
	const MAX_FIELD      = 200;

	/**
	 * A shipping link is accepted for 2 hours after the session was created.
	 */
	const MAX_AGE_SECONDS = 7200;

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
	 * Rate source.
	 *
	 * @var ShippingOptionsProvider
	 */
	private $provider;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Logger, called with a message and a level.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository       $repository Session storage.
	 * @param UrlSigner               $signer     Signer.
	 * @param ShippingOptionsProvider $provider   Rate source.
	 * @param RateLimiter             $limiter    Rate limiter.
	 * @param callable                $log        Logger: function ( string $message, string $level ).
	 */
	public function __construct( SessionRepository $repository, UrlSigner $signer, ShippingOptionsProvider $provider, RateLimiter $limiter, callable $log ) {
		$this->repository = $repository;
		$this->signer     = $signer;
		$this->provider   = $provider;
		$this->limiter    = $limiter;
		$this->log        = $log;
	}

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function () {
				global $wpdb;
				$controller = new self(
					new SessionRepository( $wpdb ),
					UrlSigner::fromWordPress(),
					new ShippingOptionsProvider(),
					new RateLimiter( $wpdb ),
					array( Plugin::adapter(), 'log' )
				);
				$controller->registerRoute();
			}
		);
	}

	/**
	 * Registers the REST route. All checks run inside the handler, the caller is
	 * wallee and has no WordPress identity.
	 */
	public function registerRoute(): void {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/shipping-options',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handleRequest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handleRequest( $request ) {
		$body   = $request->get_json_params();
		$result = $this->handle(
			(string) $request->get_param( 'ec_session' ),
			(string) $request->get_param( 'sig' ),
			is_array( $body ) ? $body : array()
		);

		return new \WP_REST_Response( $result['body'], $result['status'] );
	}

	/**
	 * Handles one callback. Order of checks: signature, row, age, state, body
	 * ids, country, rate limit, tax location, calculation.
	 *
	 * Taxes were fixed for the snapshot's tax location. Where WooCommerce
	 * taxes by the shipping address, a destination with other tax rates than
	 * that location (TaxDestination) gets no shipping options, so the wallet
	 * cannot approve it. Where it taxes by the billing address, the callback
	 * only knows the shipping destination: the return check decides.
	 *
	 * @param string $uuid Session UUID from the query.
	 * @param string $sig  Signature from the query.
	 * @param array  $body Decoded JSON body.
	 * @return array{status: int, body: array}
	 */
	public function handle( string $uuid, string $sig, array $body ): array {
		if ( ! $this->signer->verify( $uuid, SessionService::PURPOSE_SHIPPING, $sig ) ) {
			return self::failure( 403, 'forbidden' );
		}

		$record = $this->repository->findByUuid( $uuid );
		if ( null === $record ) {
			return self::failure( 404, 'unknown_session' );
		}
		if ( $record->isOlderThan( self::MAX_AGE_SECONDS ) ) {
			return self::failure( 404, 'unknown_session' );
		}
		if ( SessionRecord::CREATED !== $record->state() ) {
			return self::failure( 409, 'session_not_open' );
		}

		if ( ! self::idMatches( $body, 'expressCheckoutId', $record->walleeSessionId() ) || ! self::idMatches( $body, 'spaceId', $record->spaceId() ) ) {
			return self::failure( 403, 'forbidden' );
		}

		if ( ! isset( $body['countryCode'] ) || ! is_string( $body['countryCode'] ) || 1 !== preg_match( '/^[A-Za-z]{2}$/', $body['countryCode'] ) ) {
			return self::failure( 400, 'invalid_country' );
		}

		if ( ! $this->limiter->allow( 'shipping|' . $record->uuid(), self::RATE_MAX, self::RATE_WINDOW ) ) {
			return self::failure( 429, 'rate_limited' );
		}

		$destination = array(
			'country'   => strtoupper( $body['countryCode'] ),
			'state'     => self::text( $body, 'state' ),
			'postcode'  => self::text( $body, 'postalCode' ),
			'city'      => self::text( $body, 'city' ),
			'address_1' => self::text( $body, 'street' ),
		);

		if ( TaxDestination::BASIS_SHIPPING === TaxDestination::basis() && ! TaxDestination::sameRates( $record->snapshot(), $destination ) ) {
			call_user_func( $this->log, sprintf( 'No shipping options for session row %d: destination taxed differently from the tax location.', $record->id() ) );
			$named = 0 === strcasecmp( $destination['country'], $record->snapshot()->taxLocation()['country'] ) ? '' : $destination['country'];

			return array(
				'status' => 200,
				'body'   => ShippingRateMapper::error( ShippingOptionsProvider::ERROR_COUNTRY, Notices::walletDestination( $named ) ),
			);
		}

		try {
			$rates = $this->provider->ratesFor( $record->snapshot(), $destination );
		} catch ( \Throwable $e ) {
			call_user_func( $this->log, sprintf( 'Shipping calculation failed for session row %d (%s).', $record->id(), get_class( $e ) ), 'error' );

			return self::failure( 500, 'unavailable' );
		}

		if ( array() === $rates ) {
			$code = $this->provider->errorCodeFor( $destination );
			call_user_func( $this->log, sprintf( 'No shipping options for session row %d: %s.', $record->id(), $code ) );

			return array(
				'status' => 200,
				'body'   => ShippingRateMapper::error( $code, self::messageFor( $code, $destination['country'] ) ),
			);
		}

		$this->storeQuote( $record, $destination, $rates );

		$currency = $record->snapshot()->currency();
		$options  = array();
		foreach ( $rates as $rate ) {
			$options[] = ShippingRateMapper::toOption( $rate, $currency );
		}

		return array(
			'status' => 200,
			'body'   => ShippingRateMapper::success( $options ),
		);
	}

	/**
	 * Stores destination and rates on the row while it is still open; the last callback wins. A failure
	 * is logged and does not affect the response.
	 *
	 * @param SessionRecord $record      Row.
	 * @param array         $destination Destination.
	 * @param array         $rates       Rates as returned by the provider.
	 */
	private function storeQuote( SessionRecord $record, array $destination, array $rates ): void {
		try {
			$stored = $this->repository->storeQuote(
				$record->id(),
				array(
					'destination' => array(
						'country'  => $destination['country'],
						'state'    => $destination['state'],
						'postcode' => $destination['postcode'],
						'city'     => $destination['city'],
					),
					'rates'       => $rates,
				)
			);
			if ( ! $stored ) {
				call_user_func( $this->log, sprintf( 'Shipping quote was not stored for session row %d (state changed).', $record->id() ), 'warning' );
			}
		} catch ( \Throwable $e ) {
			call_user_func( $this->log, sprintf( 'Shipping quote could not be stored for session row %d (%s).', $record->id(), get_class( $e ) ), 'error' );
		}
	}

	/**
	 * Wallet message for an error code.
	 *
	 * @param string $code    wallee error code.
	 * @param string $country ISO country code of the destination.
	 */
	private static function messageFor( string $code, string $country ): string {
		if ( ShippingOptionsProvider::ERROR_COUNTRY === $code ) {
			return Notices::walletDestination( $country );
		}

		return Notices::walletNoShippingMethod();
	}

	/**
	 * Whether a body key holds exactly the expected integer ID (int or digit string).
	 *
	 * @param array  $body     Body.
	 * @param string $key      Key.
	 * @param int    $expected Expected ID.
	 */
	private static function idMatches( array $body, string $key, int $expected ): bool {
		if ( ! isset( $body[ $key ] ) ) {
			return false;
		}
		$value = $body[ $key ];
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,18}$/', $value ) ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value === $expected;
	}

	/**
	 * A bounded plain text value, empty when absent or not a string.
	 *
	 * @param array  $body Body.
	 * @param string $key  Key.
	 */
	private static function text( array $body, string $key ): string {
		if ( ! isset( $body[ $key ] ) || ! is_string( $body[ $key ] ) ) {
			return '';
		}

		return substr( sanitize_text_field( $body[ $key ] ), 0, self::MAX_FIELD );
	}

	/**
	 * Technical failure response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Short code.
	 * @return array{status: int, body: array}
	 */
	private static function failure( int $status, string $code ): array {
		return array(
			'status' => $status,
			'body'   => array( 'error' => $code ),
		);
	}
}
