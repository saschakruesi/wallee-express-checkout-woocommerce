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
	 * Sanitises variation attributes: scalar keys and values only.
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
				$clean[ sanitize_text_field( (string) $attribute ) ] = sanitize_text_field( (string) $selected );
			}
		}

		return $clean;
	}

	/**
	 * Applies the global and the per-IP rate limit. The global bucket protects
	 * the paid wallee call against forged forwarding headers.
	 *
	 * @param string $ip Client IP address.
	 */
	public function rateAllows( string $ip ): bool {
		return $this->limiter->allow( 'session|global', self::GLOBAL_MAX, self::RATE_WINDOW )
			&& $this->limiter->allow( 'session|' . $ip, self::RATE_MAX, self::RATE_WINDOW );
	}

	/**
	 * Handles a session request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$adapter = Plugin::adapter();

		if ( ! $this->rateAllows( \WC_Geolocation::get_ip_address() ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$context  = (string) $request->get_param( 'context' );
		$settings = Plugin::settings();
		$enabled  = CartSnapshot::CONTEXT_CART === $context ? $settings->cartEnabled() : $settings->productEnabled();
		if ( ! $enabled ) {
			return self::refusal( 'disabled' );
		}
		if ( ! Plugin::isActive() ) {
			return self::refusal( 'not_configured' );
		}

		// REST requests do not load the cart, customer and session by default.
		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		try {
			$snapshot = $this->snapshotFor( $context, $request );
			$hash     = $snapshot->hash();
		} catch ( \InvalidArgumentException $e ) {
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
		if ( ! WC()->session ) {
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
	 * Remembers the token in the customer's WooCommerce session.
	 *
	 * @param string $context Context.
	 * @param string $hash    Snapshot hash.
	 * @param array  $result  Result of SessionService::create().
	 */
	private function remember( string $context, string $hash, array $result ): void {
		if ( ! WC()->session ) {
			return;
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
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
