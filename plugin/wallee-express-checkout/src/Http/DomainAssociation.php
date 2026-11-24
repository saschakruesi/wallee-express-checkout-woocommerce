<?php
/**
 * Serves the Apple Pay domain verification file.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Http;

use Wallee\ExpressCheckout\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Answers `/.well-known/apple-developer-merchantid-domain-association` with the
 * content stored in the settings: 200, text/plain, no redirect. Hooked on
 * `parse_request` at priority 0, so it runs before any canonical redirect and
 * does not depend on the permalink settings.
 */
final class DomainAssociation {

	const PATH = '/.well-known/apple-developer-merchantid-domain-association';

	/**
	 * Hooks the route.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 0 );
	}

	/**
	 * Whether a request URI is exactly the verification path (query string ignored).
	 *
	 * @param string $request_uri Request URI as sent by the client.
	 * @return bool
	 */
	public static function matches( string $request_uri ): bool {
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';

		return $home_path . self::PATH === $path;
	}

	/**
	 * Builds the response for a request, or null when WordPress should handle it.
	 *
	 * @param string $method      HTTP method.
	 * @param string $request_uri Request URI.
	 * @param string $content     Stored verification content.
	 * @return array|null [ int status, array headers, string body ] or null.
	 */
	public static function respond( string $method, string $request_uri, string $content ): ?array {
		if ( '' === $content || ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			return null;
		}
		if ( ! self::matches( $request_uri ) ) {
			return null;
		}

		return array(
			200,
			array(
				'Content-Type'           => 'text/plain; charset=utf-8',
				'X-Robots-Tag'           => 'noindex',
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store',
			),
			$content,
		);
	}

	/**
	 * `parse_request` callback: emits the response and ends the request.
	 */
	public static function serve(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// Raw on purpose: it is only compared for strict equality with a constant and never output; sanitising would strip %XX octets and widen the match.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = is_string( $request_uri ) ? $request_uri : '';

		$response = self::respond( $method, $request_uri, Plugin::settings()->appleDomainAssociation() );
		if ( null === $response ) {
			return;
		}

		status_header( $response[0] );
		foreach ( $response[1] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( 'HEAD' !== strtoupper( $method ) ) {
			// text/plain content that a shop manager pasted from the wallee portal; it must be served verbatim, HTML escaping would corrupt it.
			echo $response[2]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}
}
