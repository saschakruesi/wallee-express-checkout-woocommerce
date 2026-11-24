<?php
/**
 * Currency amount helpers.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Rounding and comparison of amounts at currency precision.
 */
final class Money {

	/**
	 * Rounds an amount.
	 *
	 * @param int|float|string $amount   Amount.
	 * @param int              $decimals Decimal places.
	 */
	public static function round( $amount, int $decimals ): float {
		return round( (float) $amount, $decimals );
	}

	/**
	 * Compares two amounts after rounding both to the currency precision.
	 *
	 * @param int|float|string $a        First amount.
	 * @param int|float|string $b        Second amount.
	 * @param int              $decimals Decimal places.
	 */
	public static function equals( $a, $b, int $decimals ): bool {
		return abs( self::round( $a, $decimals ) - self::round( $b, $decimals ) ) < 0.5 * pow( 10, -$decimals );
	}

	/**
	 * Minor unit digits of a currency, independent of the shop display setting.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function decimals( string $currency ): int {
		$currency = strtoupper( $currency );

		if ( in_array( $currency, array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK' ), true ) ) {
			return 0;
		}
		if ( in_array( $currency, array( 'BHD', 'KWD', 'OMR', 'JOD', 'TND' ), true ) ) {
			return 3;
		}

		return 2;
	}
}
