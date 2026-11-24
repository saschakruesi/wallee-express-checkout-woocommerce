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
use Wallee\ExpressCheckout\Security\UrlSigner;
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
	const REUSE_SECONDS  = 900;
	const RATE_MAX       = 20;
	const RATE_WINDOW    = 60;

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function () {
				( new self() )->registerRoute();
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
						'sanitize_callback' => 'absint',
					),
					'variationId' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'variation'   => array(
						'type'              => 'object',
						'default'           => array(),
						'sanitize_callback' => array( self::class, 'sanitizeVariation' ),
					),
					'quantity'    => array(
						'type'              => 'integer',
						'default'           => 1,
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
	 * Handles a session request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$adapter = Plugin::adapter();

		$limiter = new RateLimiter();
		if ( ! $limiter->allow( 'session|' . \WC_Geolocation::get_ip_address(), self::RATE_MAX, self::RATE_WINDOW ) ) {
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
		} catch ( \InvalidArgumentException $e ) {
			return self::refusal( 'unavailable' );
		}

		$reason = Eligibility::fromEnvironment( true )->reason( $snapshot );
		if ( null !== $reason ) {
			return self::refusal( $reason );
		}

		$reused = $this->reusable( $context, $snapshot->hash() );
		if ( null !== $reused ) {
			return new \WP_REST_Response( array( 'sessionToken' => $reused ), 200 );
		}

		global $wpdb;
		$service = new SessionService(
			new ApiClient( new SdkTransport( $adapter ) ),
			new SessionRepository( $wpdb ),
			new LineItemBuilder(),
			UrlSigner::fromWordPress(),
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

		$this->remember( $context, $snapshot->hash(), $result );

		return new \WP_REST_Response( array( 'sessionToken' => $result['sessionToken'] ), 200 );
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
	 *
	 * @param string $context Context.
	 * @param string $hash    Snapshot hash.
	 * @return string|null
	 */
	private function reusable( string $context, string $hash ): ?string {
		if ( ! WC()->session ) {
			return null;
		}
		$entry = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $entry ) || ! isset( $entry['context'], $entry['hash'], $entry['token'], $entry['created'] ) ) {
			return null;
		}
		if ( $entry['context'] !== $context || ! hash_equals( (string) $entry['hash'], $hash ) ) {
			return null;
		}
		if ( ! is_string( $entry['token'] ) || '' === $entry['token'] || time() - (int) $entry['created'] >= self::REUSE_SECONDS ) {
			return null;
		}

		return $entry['token'];
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
