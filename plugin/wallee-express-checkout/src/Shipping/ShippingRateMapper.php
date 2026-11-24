<?php
/**
 * Maps WooCommerce shipping rates to the wallee shipping callback response.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Shipping;

use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping, no WordPress calls.
 */
final class ShippingRateMapper {

	/**
	 * Maps one rate to a wallee shipping option.
	 *
	 * @param array  $rate     Rate: id, label, cost, tax, tax_rates (list of label and rate in percent).
	 * @param string $currency ISO 4217 code.
	 * @return array
	 */
	public static function toOption( array $rate, string $currency ): array {
		$decimals = Money::decimals( $currency );
		$tax      = (float) $rate['tax'];
		$taxes    = array();

		// A rate without tax amount lists no tax lines, so a free rate reads as free.
		if ( $tax > 0 ) {
			foreach ( $rate['tax_rates'] as $tax_rate ) {
				$taxes[] = array(
					'title' => (string) $tax_rate['label'],
					'rate'  => (float) $tax_rate['rate'],
				);
			}
		}

		return array(
			'id'                 => (string) $rate['id'],
			'label'              => (string) $rate['label'],
			'description'        => '',
			'amountIncludingTax' => Money::round( (float) $rate['cost'] + $tax, $decimals ),
			'taxes'              => $taxes,
			'currency'           => $currency,
		);
	}

	/**
	 * Successful response.
	 *
	 * @param array $options Options made by toOption().
	 */
	public static function success( array $options ): array {
		return array(
			'shippingOptions' => $options,
			'error'           => null,
		);
	}

	/**
	 * Business error response (still HTTP 200).
	 *
	 * @param string $code    wallee error code, e.g. COUNTRY_UNAVAILABLE.
	 * @param string $message Customer-facing message.
	 */
	public static function error( string $code, string $message ): array {
		return array(
			'shippingOptions' => array(),
			'error'           => array(
				'error'     => $code,
				'message'   => $message,
				'timestamp' => gmdate( 'c' ),
			),
		);
	}
}
