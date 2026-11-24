<?php
/**
 * REST endpoint that hands out Express Checkout session tokens.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Api\ApiException;
use Wallee\ExpressCheckout\Api\SdkTransport;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\Eligibility;
use Wallee\ExpressCheckout\Cart\LineItemBuilder;
use Wallee\ExpressCheckout\Cart\SnapshotFactory;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Security\RateLimiter;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Session\SessionService;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/wallee-ec/v1/session.
 */
final class SessionController {

	const NAMESPACE_NAME = 'wallee-ec/v1';
	const NONCE_ACTION   = 'wallee_ec_session';
	const NONCE_HEADER   = 'X-Wallee-Ec-Nonce';
	const SESSION_KEY    = 'wallee_ec_session';
	const REUSE_SECONDS  = 300;
	const RATE_MAX       = 20;
	const RATE_WINDOW    = 60;
	const GLOBAL_MAX     = 300;

	/**
	 * A wallee session times out 30 minutes after creation (spike finding 6).
	 */
	const LIFETIME_SECONDS = 1800;

	const MAX_QUANTITY        = 999;
	const MAX_VARIATIONS      = 20;
	const MAX_ATTRIBUTE_NAME  = 100;
	const MAX_ATTRIBUTE_VALUE = 200;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Session storage.
	 *
	 * @var SessionRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter       $limiter    Rate limiter.
	 * @param SessionRepository $repository Session storage.
	 */
	public function __construct( RateLimiter $limiter, SessionRepository $repository ) {
		$this->limiter    = $limiter;
		$this->repository = $repository;
	}

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function () {
				global $wpdb;
				( new self( new RateLimiter(), new SessionRepository( $wpdb ) ) )->registerRoute();
			}
		);
		add_filter( 'rest_post_dispatch', array( self::class, 'noCache' ), 10, 3 );
	}

	/**
	 * `rest_post_dispatch` filter: every response of the session route,
	 * refusals and errors included, must not be cached.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function noCache( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_REST_Response || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		if ( '/' . self::NAMESPACE_NAME . '/session' !== $request->get_route() ) {
			return $response;
		}
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( '' !== (string) $value ) {
				$response->header( $name, (string) $value );
			}
		}

		return $response;
	}

	/**
	 * Registers the REST route.
	 */
	public function registerRoute(): void {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'context'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( CartSnapshot::CONTEXT_CART, CartSnapshot::CONTEXT_PRODUCT ),
					),
					'productId'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'variationId' => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'variation'   => array(
						'type'              => 'object',
						'default'           => array(),
						'validate_callback' => array( self::class, 'validateVariation' ),
						'sanitize_callback' => array( self::class, 'sanitizeVariation' ),
					),
					'quantity'    => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'maximum'           => self::MAX_QUANTITY,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Both nonces must be valid: the REST nonce (recognises logged-in
	 * customers) and the add-on's own nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function checkPermission( $request ) {
		$rest_nonce = (string) $request->get_header( 'X-WP-Nonce' );
		$ec_nonce   = (string) $request->get_header( self::NONCE_HEADER );

		if ( '' === $rest_nonce || '' === $ec_nonce
			|| ! wp_verify_nonce( $rest_nonce, 'wp_rest' )
			|| ! wp_verify_nonce( $ec_nonce, self::NONCE_ACTION )
		) {
			return new \WP_Error( 'wallee_ec_forbidden', __( 'Invalid request.', 'wallee-express-checkout' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Bounds for variation attributes: a map of at most 20 scalar values.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function validateVariation( $value ): bool {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_VARIATIONS ) {
			return false;
		}
		foreach ( $value as $attribute => $selected ) {
			if ( ! is_scalar( $selected ) || strlen( (string) $attribute ) > self::MAX_ATTRIBUTE_NAME || strlen( (string) $selected ) > self::MAX_ATTRIBUTE_VALUE ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitises variation attributes: scalar values only. Keys are lower-cased
	 * and keep only the characters of an attribute slug (`a-z`, `0-9`, `_`,
	 * `-` and `%`, since the slug of a name with non-ASCII characters is
	 * URL-encoded); whether a key is an attribute of the product is decided
	 * later, against the product.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitizeVariation( $value ): array {
		$clean = array();
		if ( ! is_array( $value ) ) {
			return $clean;
		}
		foreach ( $value as $attribute => $selected ) {
			if ( is_scalar( $selected ) ) {
				$key           = (string) preg_replace( '/[^a-z0-9_%\-]/', '', strtolower( (string) $attribute ) );
				$clean[ $key ] = sanitize_text_field( (string) $selected );
			}
		}

		return $clean;
	}

	/**
	 * Applies the per-client and the global rate limit. The client's own
	 * bucket comes first: a request it refuses does not count against the
	 * global bucket, so one client cannot use up everyone's allowance.
	 *
	 * @param string $ip Client IP address.
	 */
	public function rateAllows( string $ip ): bool {
		return $this->limiter->allow( 'session|' . self::rateKey( $ip ), self::RATE_MAX, self::RATE_WINDOW )
			&& $this->limiter->allow( 'session|global', self::GLOBAL_MAX, self::RATE_WINDOW );
	}

	/**
	 * The rate limit bucket of a client address. An IPv4 address is its own
	 * bucket. An IPv6 address counts as its /64 network, the block one
	 * subscriber usually gets, so a host cannot rotate addresses within it to
	 * get more requests. An IPv4-mapped IPv6 address counts as its IPv4
	 * address. Anything else is used as it is.
	 *
	 * @param string $ip Client IP address, or 'unknown'.
	 */
	public static function rateKey( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}

		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * The client address for rate limiting: `REMOTE_ADDR`, which a client
	 * cannot choose. Shops behind a trusted proxy or CDN can return the
	 * forwarded address through the filter `wallee_ec_client_ip`; a value
	 * that is not an IP address is ignored.
	 */
	public static function clientIp(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP address.
		$remote = false === $remote ? '' : (string) $remote;

		$filtered = apply_filters( 'wallee_ec_client_ip', $remote );
		if ( is_string( $filtered ) && false !== filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
			return $filtered;
		}

		return '' === $remote ? 'unknown' : $remote;
	}

	/**
	 * Whether the caller has a WooCommerce session (a session cookie or a
	 * logged-in user). Callers without one get none from this endpoint.
	 */
	public static function hasCustomerSession(): bool {
		return WC()->session && WC()->session->has_session();
	}

	/**
	 * Handles a session request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$adapter = Plugin::adapter();

		if ( ! $this->rateAllows( self::clientIp() ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$context  = (string) $request->get_param( 'context' );
		$settings = Plugin::settings();
		$enabled  = CartSnapshot::CONTEXT_CART === $context ? $settings->cartEnabled() : $settings->productEnabled();
		if ( ! $enabled ) {
			return self::refusal( 'disabled' );
		}

		// REST requests do not load the cart, customer and session by default.
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		// Without a WooCommerce session the cart is empty: refuse before any
		// further work (and never start a session for such a caller).
		if ( CartSnapshot::CONTEXT_CART === $context && ! self::hasCustomerSession() ) {
			return self::refusal( 'empty' );
		}
		if ( ! Plugin::isActive() ) {
			return self::refusal( 'not_configured' );
		}

		try {
			$snapshot = $this->snapshotFor( $context, $request );
			$hash     = $snapshot->hash();
		} catch ( \InvalidArgumentException $e ) {
			if ( SnapshotFactory::TOTAL_MISMATCH === $e->getMessage() ) {
				$adapter->log( 'Session refused: the cart snapshot does not reproduce the cart total (total_mismatch).', 'warning' );
			}
			return self::refusal( 'unavailable' );
		} catch ( \RuntimeException $e ) {
			return self::refusal( 'unavailable' );
		}

		$reason = Eligibility::fromEnvironment( true )->reason( $snapshot );
		if ( null !== $reason ) {
			return self::refusal( $reason );
		}

		$reused = $this->reusable( $context, $hash );
		if ( null !== $reused ) {
			return new \WP_REST_Response( $reused, 200 );
		}

		$service = new SessionService(
			new ApiClient( new SdkTransport( $adapter ) ),
			$this->repository,
			new LineItemBuilder(),
			$settings,
			$adapter
		);

		try {
			$result = $service->create( $snapshot, str_replace( '_', '-', determine_locale() ) );
		} catch ( \InvalidArgumentException $e ) {
			// Line items that do not add up to the total (rounding_mismatch): fail closed, no session.
			$adapter->log( sprintf( 'Session refused: line items not built (%s).', LineItemBuilder::ROUNDING_MISMATCH === $e->getMessage() ? LineItemBuilder::ROUNDING_MISMATCH : get_class( $e ) ), 'warning' );
			return self::refusal( 'unavailable' );
		} catch ( ApiException $e ) {
			$adapter->log( sprintf( 'Session creation failed (HTTP status %d).', $e->getStatusCode() ), 'error' );
			return new \WP_REST_Response( array( 'error' => 'unavailable' ), 502 );
		} catch ( \RuntimeException $e ) {
			$adapter->log( 'Session could not be stored.', 'error' );
			return new \WP_REST_Response( array( 'error' => 'unavailable' ), 502 );
		}

		$this->remember( $context, $hash, $result );

		$record = $this->repository->findByUuid( $result['uuid'] );

		return new \WP_REST_Response( self::tokenResponse( $result['sessionToken'], null === $record ? gmdate( 'Y-m-d H:i:s' ) : $record->createdAt() ), 200 );
	}

	/**
	 * Response body for a token: the token and when its wallee session
	 * expires, so the page can renew it in time.
	 *
	 * @param string $token      Session token.
	 * @param string $created_at Creation time of the session row, UTC `Y-m-d H:i:s`.
	 * @return array{sessionToken: string, expiresAt?: string}
	 */
	public static function tokenResponse( string $token, string $created_at ): array {
		$body    = array( 'sessionToken' => $token );
		$expires = self::expiresAt( $created_at );
		if ( null !== $expires ) {
			$body['expiresAt'] = $expires;
		}

		return $body;
	}

	/**
	 * Expiry of a wallee session as an ISO-8601 UTC string: row creation plus
	 * 30 minutes.
	 *
	 * @param string $created_at Creation time of the session row, UTC `Y-m-d H:i:s`.
	 * @return string|null Null when the time cannot be read.
	 */
	public static function expiresAt( string $created_at ): ?string {
		$created = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, new \DateTimeZone( 'UTC' ) );
		if ( false === $created ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $created->getTimestamp() + self::LIFETIME_SECONDS );
	}

	/**
	 * Builds the snapshot for the requested context.
	 *
	 * @param string           $context Context.
	 * @param \WP_REST_Request $request Request.
	 * @throws \InvalidArgumentException If no snapshot can be built.
	 */
	private function snapshotFor( string $context, $request ): CartSnapshot {
		$factory = new SnapshotFactory();
		if ( CartSnapshot::CONTEXT_CART === $context ) {
			return $factory->fromCart( WC()->cart );
		}

		return $factory->fromProduct(
			(int) $request->get_param( 'productId' ),
			(int) $request->get_param( 'variationId' ),
			(array) $request->get_param( 'variation' ),
			(int) $request->get_param( 'quantity' )
		);
	}

	/**
	 * A token from the customer's WooCommerce session for the same content.
	 * A stale or used entry is forgotten.
	 *
	 * @param string $context Context.
	 * @param string $hash    Snapshot hash.
	 * @return array|null Response body, see tokenResponse().
	 */
	private function reusable( string $context, string $hash ): ?array {
		if ( ! self::hasCustomerSession() ) {
			return null;
		}
		$entry = WC()->session->get( self::SESSION_KEY );
		if ( null === $entry || '' === $entry ) {
			return null;
		}
		$reused = $this->reusableSession( $entry, $context, $hash );
		if ( null === $reused ) {
			self::forget();
		}

		return $reused;
	}

	/**
	 * The response for a remembered entry when it may still be used: same
	 * context and content, younger than 5 minutes (so at least 25 of wallee's
	 * 30 minutes are left), and its stored session has not been used yet.
	 * The expiry comes from the stored row.
	 *
	 * @param mixed  $entry   Remembered entry.
	 * @param string $context Context.
	 * @param string $hash    Snapshot hash.
	 * @return array|null Response body, see tokenResponse().
	 */
	public function reusableSession( $entry, string $context, string $hash ): ?array {
		if ( ! is_array( $entry ) || ! isset( $entry['context'], $entry['hash'], $entry['token'], $entry['uuid'], $entry['created'] ) ) {
			return null;
		}
		if ( $entry['context'] !== $context || ! hash_equals( (string) $entry['hash'], $hash ) ) {
			return null;
		}
		if ( ! is_string( $entry['token'] ) || '' === $entry['token'] || time() - (int) $entry['created'] >= self::REUSE_SECONDS ) {
			return null;
		}
		$record = $this->repository->findByUuid( (string) $entry['uuid'] );
		if ( null === $record || SessionRecord::CREATED !== $record->state() ) {
			return null;
		}

		return self::tokenResponse( $entry['token'], $record->createdAt() );
	}

	/**
	 * Forgets the remembered session of the current customer. Called when an
	 * order has been created from it.
	 */
	public static function forget(): void {
		if ( WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	/**
	 * Remembers the token in the customer's WooCommerce session, if the
	 * caller has one. No session is started for a caller without one: such
	 * a caller gets a new token on every page load.
	 *
	 * @param string $context Context.
	 * @param string $hash    Snapshot hash.
	 * @param array  $result  Result of SessionService::create().
	 */
	public function remember( string $context, string $hash, array $result ): void {
		if ( ! self::hasCustomerSession() ) {
			return;
		}
		WC()->session->set(
			self::SESSION_KEY,
			array(
				'context' => $context,
				'hash'    => $hash,
				'token'   => $result['sessionToken'],
				'uuid'    => $result['uuid'],
				'created' => time(),
			)
		);
	}

	/**
	 * Response for "no token, and why".
	 *
	 * @param string $reason Reason.
	 */
	private static function refusal( string $reason ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'sessionToken' => null,
				'reason'       => $reason,
			),
			200
		);
	}
}
