<?php
/**
 * WooCommerce state code for wallee's free-text region.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Maps wallee's region to a WooCommerce state code.
 *
 * The `postalState` of a wallee address (and the shipping callback's `state`)
 * is the name of the region as the wallet sends it ("California", "US-CA", "CA"), while
 * WooCommerce's addresses, shipping zones and tax rates use its own state
 * codes. This maps one to the other against WooCommerce's state list of the
 * country: the exact code, then the name and then the code case-insensitively,
 * then all of this again with an `XX-` ISO prefix of that country removed.
 */
final class StateCode {

	/**
	 * The WooCommerce state code of a region.
	 *
	 * @param string $country Two-letter country code.
	 * @param string $region  Region as sent by wallee.
	 * @return string|null The code; '' for an empty region; the region itself when WooCommerce
	 *                     has no state list for the country; null when the list has no match.
	 */
	public static function resolve( string $country, string $region ): ?string {
		$region = trim( $region );
		if ( '' === $region ) {
			return '';
		}
		$states = self::states( $country );
		if ( array() === $states ) {
			return $region;
		}

		$candidates = array( $region );
		if ( 1 === preg_match( '/^([A-Za-z]{2})-(.+)$/', $region, $parts ) && 0 === strcasecmp( $parts[1], $country ) ) {
			$candidates[] = trim( $parts[2] );
		}
		foreach ( $candidates as $candidate ) {
			if ( isset( $states[ $candidate ] ) ) {
				return (string) $candidate;
			}
			$wanted = self::lower( $candidate );
			foreach ( $states as $code => $name ) {
				if ( is_string( $name ) && self::lower( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) === $wanted ) {
					return (string) $code;
				}
			}
			foreach ( array_keys( $states ) as $code ) {
				if ( self::lower( (string) $code ) === $wanted ) {
					return (string) $code;
				}
			}
		}

		return null;
	}

	/**
	 * The code of a region when it resolves, else the region as sent.
	 *
	 * @param string $country Two-letter country code.
	 * @param string $region  Region as sent by wallee.
	 */
	public static function normalise( string $country, string $region ): string {
		$code = self::resolve( $country, $region );

		return null === $code ? $region : $code;
	}

	/**
	 * WooCommerce's states of a country, code => name; empty when it has none
	 * or WooCommerce is not loaded.
	 *
	 * @param string $country Two-letter country code.
	 */
	private static function states( string $country ): array {
		if ( '' === $country || ! function_exists( 'WC' ) ) {
			return array();
		}
		try {
			$wc        = WC();
			$countries = is_object( $wc ) && isset( $wc->countries ) ? $wc->countries : null;
			$states    = is_object( $countries ) && is_callable( array( $countries, 'get_states' ) ) ? $countries->get_states( strtoupper( $country ) ) : null;
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $states ) ? $states : array();
	}

	/**
	 * Lower case, multibyte-safe when possible.
	 *
	 * @param string $text Text.
	 */
	private static function lower( string $text ): string {
		$text = trim( $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
