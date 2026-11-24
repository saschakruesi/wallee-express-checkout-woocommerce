<?php
/**
 * Request and redirect helpers of the return and result endpoints.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by ReturnHandler and ResultHandler.
 */
final class Redirects {

	/**
	 * Reads string query parameters. The endpoints are reached by a plain
	 * browser redirect from wallee, so there is no nonce: the signed session
	 * reference is the check, done by the handler.
	 *
	 * @param string[] $keys Parameter names.
	 * @return array<string, string>
	 */
	public static function query( array $keys ): array {
		$values = array();
		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed URL, verified by the handler.
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed URL, verified by the handler.
				$values[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		return $values;
	}

	/**
	 * Redirects and ends the request. An external URL (the wallee payment
	 * step) is allowed for this request only, by adding its host to
	 * `allowed_redirect_hosts`; everything else must be an own URL.
	 *
	 * @param string      $url           Target.
	 * @param string|null $external_host Host to allow, null for own URLs.
	 */
	public static function send( string $url, ?string $external_host ): void {
		nocache_headers();
		if ( null !== $external_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $external_host ) {
					$hosts   = is_array( $hosts ) ? $hosts : array();
					$hosts[] = $external_host;

					return $hosts;
				}
			);
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Notice for every failure the customer cannot do anything specific about.
	 */
	public static function genericNotice(): string {
		return __( 'Your express payment could not be completed. Please try again or choose another payment method.', 'wallee-express-checkout' );
	}
}
