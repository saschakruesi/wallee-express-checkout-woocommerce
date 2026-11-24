<?php
/**
 * Shared front-end pieces: container markup, terms line, script registration.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Frontend;

use Wallee\ExpressCheckout\Http\SessionController;
use Wallee\ExpressCheckout\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Used by CartButtons and ProductButtons.
 */
final class Assets {

	/**
	 * Contexts whose container this request already printed.
	 *
	 * @var array
	 */
	private static $emitted = array();

	const HANDLE         = 'wallee-ec';
	const HANDLER_HANDLE = 'wallee-ec-handler';

	/**
	 * Path of wallee's handler script below the main plugin's base URL.
	 * Confirmed by the API spike (spike-findings.md item 2).
	 */
	const HANDLER_PATH = 'assets/payment/express-checkout-handler.js';

	/**
	 * URL of wallee's handler script. The one place that knows it.
	 *
	 * @param string $base_url Base gateway URL of the wallee main plugin.
	 * @return string
	 */
	public static function handlerScriptUrl( string $base_url ): string {
		$url = rtrim( $base_url, '/' ) . '/' . self::HANDLER_PATH;

		/**
		 * Filters the URL of wallee's express checkout handler script.
		 *
		 * @param string $url Script URL.
		 */
		return (string) apply_filters( 'wallee_ec_handler_script_url', $url );
	}

	/**
	 * The container markup.
	 *
	 * @param string $context      "cart" or "product".
	 * @param int    $terms_page_id Terms page ID, 0 for none.
	 * @param array  $data         Extra data attributes, name without the "data-" prefix.
	 * @return string
	 */
	public static function containerHtml( string $context, int $terms_page_id, array $data = array() ): string {
		$attributes = ' data-context="' . esc_attr( $context ) . '"';
		foreach ( $data as $name => $value ) {
			$attributes .= ' data-' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
		}

		return '<div class="wallee-ec"' . $attributes . '>'
			. '<div id="' . esc_attr( self::containerId( $context ) ) . '"></div>'
			. self::termsLine( $terms_page_id )
			. '</div>';
	}

	/**
	 * DOM ID of the element the wallee widget renders into.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public static function containerId( string $context ): string {
		return 'wallee-ec-container-' . $context;
	}

	/**
	 * The terms line, empty when the shop has no terms page.
	 *
	 * @param int $terms_page_id Terms page ID, 0 for none.
	 * @return string
	 */
	public static function termsLine( int $terms_page_id ): string {
		if ( $terms_page_id <= 0 ) {
			return '';
		}
		$permalink = get_permalink( $terms_page_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}
		$link = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $permalink ),
			esc_html__( 'terms and conditions', 'wallee-express-checkout' )
		);

		return '<p class="wallee-ec-terms">' . wp_kses_post(
			sprintf(
				/* translators: %s: link with the text "terms and conditions". */
				__( 'By paying you accept our %s.', 'wallee-express-checkout' ),
				$link
			)
		) . '</p>';
	}

	/**
	 * Claims the container of a context for this request.
	 *
	 * @param string $context Context.
	 * @return bool True the first time, false after.
	 */
	public static function claim( string $context ): bool {
		if ( isset( self::$emitted[ $context ] ) ) {
			return false;
		}
		self::$emitted[ $context ] = true;

		return true;
	}

	/**
	 * Forgets the claimed contexts (tests).
	 */
	public static function resetEmitted(): void {
		self::$emitted = array();
	}

	/**
	 * Appends the container to the output of one block, once.
	 *
	 * @param string $content    Rendered block.
	 * @param array  $block      Parsed block.
	 * @param string $block_name Block to extend.
	 * @param string $container  Container markup.
	 * @param string $context    Context, to recognise a container already present.
	 * @return string
	 */
	public static function appendToBlock( $content, $block, string $block_name, string $container, string $context ): string {
		$content = (string) $content;
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || $block_name !== $block['blockName'] ) {
			return $content;
		}
		if ( false !== strpos( $content, 'id="' . self::containerId( $context ) . '"' ) ) {
			return $content;
		}

		return $content . $container;
	}

	/**
	 * Registers and enqueues the handler script, the add-on script and the styles.
	 *
	 * @param string $context "cart" or "product".
	 */
	public static function enqueue( string $context ): void {
		$settings = Plugin::settings();
		$version  = defined( 'WALLEE_EC_VERSION' ) ? WALLEE_EC_VERSION : '0';
		$url      = plugins_url( 'assets/', WALLEE_EC_FILE );

		wp_enqueue_script( self::HANDLER_HANDLE, self::handlerScriptUrl( Plugin::adapter()->baseUrl() ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Third-party script, versioned by wallee.
		wp_enqueue_script( self::HANDLE, $url . 'js/express-checkout.js', array( self::HANDLER_HANDLE ), $version, true );
		wp_enqueue_style( self::HANDLE, $url . 'css/express-checkout.css', array(), $version );

		$data = array(
			'endpoint'   => esc_url_raw( rest_url( SessionController::NAMESPACE_NAME . '/session' ) ),
			'nonce'      => wp_create_nonce( SessionController::NONCE_ACTION ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'maxColumns' => $settings->maxColumns(),
			'context'    => $context,
			// Overlay messages are only trusted from wallee's origin.
			'origin'     => self::origin( Plugin::adapter()->baseUrl() ),
		);
		if ( null !== $settings->maxRows() ) {
			$data['maxRows'] = $settings->maxRows();
		}
		wp_localize_script( self::HANDLE, 'walleeEc', $data );
	}

	/**
	 * Origin (`scheme://host[:port]`) of a URL, as browsers report it in
	 * `MessageEvent.origin`; empty when the URL has no scheme or host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme  = strtolower( $parts['scheme'] );
		$default = 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : 0 );
		$port    = isset( $parts['port'] ) && (int) $parts['port'] !== $default ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	/**
	 * The shop's terms page ID, 0 when none.
	 *
	 * @return int
	 */
	public static function termsPageId(): int {
		return function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
	}
}
