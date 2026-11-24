<?php
/**
 * Creates wallee Express Checkout sessions.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Session;

use Wallee\ExpressCheckout\Admin\Settings;
use Wallee\ExpressCheckout\Api\ApiClient;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\LineItemBuilder;
use Wallee\ExpressCheckout\MainPluginAdapter;
use Wallee\ExpressCheckout\Security\UrlSigner;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the wallee session for a snapshot and stores the row.
 */
class SessionService {

	const PURPOSE_RETURN   = 'return';
	const PURPOSE_SHIPPING = 'shipping';
	const PURPOSE_RESULT   = 'result';

	/**
	 * API client.
	 *
	 * @var ApiClient
	 */
	private $api;

	/**
	 * Session storage.
	 *
	 * @var SessionRepository
	 */
	private $repository;

	/**
	 * Line item mapper.
	 *
	 * @var LineItemBuilder
	 */
	private $line_items;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Main plugin adapter.
	 *
	 * @var MainPluginAdapter
	 */
	private $adapter;

	/**
	 * Constructor.
	 *
	 * @param ApiClient         $api        API client.
	 * @param SessionRepository $repository Session storage.
	 * @param LineItemBuilder   $line_items Line item mapper.
	 * @param Settings          $settings   Settings.
	 * @param MainPluginAdapter $adapter    Main plugin adapter.
	 */
	public function __construct( ApiClient $api, SessionRepository $repository, LineItemBuilder $line_items, Settings $settings, MainPluginAdapter $adapter ) {
		$this->api        = $api;
		$this->repository = $repository;
		$this->line_items = $line_items;
		$this->settings   = $settings;
		$this->adapter    = $adapter;
	}

	/**
	 * Creates a wallee session for a snapshot and stores it.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $language Language tag such as de-CH.
	 * @return array{sessionToken: string, uuid: string}
	 * @throws \Wallee\ExpressCheckout\Api\ApiException If wallee refuses or is unreachable.
	 */
	public function create( CartSnapshot $snapshot, string $language ): array {
		$uuid = UrlSigner::newUuid();

		$payload = array(
			'currency'                 => $snapshot->currency(),
			'authorizationEnvironment' => $this->settings->environment(),
			'language'                 => $language,
			'lineItems'                => $this->line_items->build( $snapshot ),
			'merchantRedirectUrl'      => self::returnUrl( $uuid ),
		);
		if ( $snapshot->needsShipping() ) {
			$payload['merchantShippingCallbackUrl'] = self::shippingUrl( $uuid );
		}

		$session = $this->api->createSession( $payload );
		$this->repository->insert( $uuid, $session['sessionId'], $this->adapter->spaceId(), $snapshot );
		$this->adapter->log( sprintf( 'Created express checkout session %d for context %s.', $session['sessionId'], $snapshot->context() ) );

		return array(
			'sessionToken' => $session['sessionToken'],
			'uuid'         => $uuid,
		);
	}

	/**
	 * Return URL handled by the `wallee_ec_return` WooCommerce API endpoint.
	 *
	 * @param string $uuid Session UUID.
	 */
	public static function returnUrl( string $uuid ): string {
		return add_query_arg(
			array(
				'wc-api'     => 'wallee_ec_return',
				'ec_session' => $uuid,
				'sig'        => UrlSigner::fromWordPress()->sign( $uuid, self::PURPOSE_RETURN ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Shipping callback URL.
	 *
	 * @param string $uuid Session UUID.
	 */
	public static function shippingUrl( string $uuid ): string {
		return add_query_arg(
			array(
				'ec_session' => $uuid,
				'sig'        => UrlSigner::fromWordPress()->sign( $uuid, self::PURPOSE_SHIPPING ),
			),
			rest_url( 'wallee-ec/v1/shipping-options' )
		);
	}

	/**
	 * Result URL (the status is part of the signed purpose) handled by the `wallee_ec_result` WooCommerce API endpoint.
	 *
	 * @param string $uuid   Session UUID.
	 * @param string $status `success` or `failure`.
	 * @throws \InvalidArgumentException On any other status.
	 */
	public static function resultUrl( string $uuid, string $status ): string {
		if ( 'success' !== $status && 'failure' !== $status ) {
			throw new \InvalidArgumentException( 'Unknown result status.' );
		}

		return add_query_arg(
			array(
				'wc-api'     => 'wallee_ec_result',
				'status'     => $status,
				'ec_session' => $uuid,
				'sig'        => UrlSigner::fromWordPress()->sign( $uuid, self::PURPOSE_RESULT . ':' . $status ),
			),
			home_url( '/' )
		);
	}
}
