<?php
/**
 * Whether an address is taxed like the snapshot's tax location.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Cart\CartSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * The snapshot's taxes were calculated for its tax location. Another address
 * is acceptable when WooCommerce finds the same tax rates for it as for that
 * location, for every tax class the snapshot's taxes depend on. Rates are
 * WooCommerce's own (`WC_Tax::find_rates()` with country, state, postcode
 * and city), so national, state, postcode and city rates are all covered and
 * a different state with national rates is not refused.
 */
final class TaxDestination {

	/**
	 * Taxes do not depend on the customer's address (taxes off, or shop base).
	 */
	const BASIS_NONE = 'none';

	/**
	 * Taxes follow the shipping address (billing when there is none).
	 */
	const BASIS_SHIPPING = 'shipping';

	/**
	 * Taxes follow the billing address.
	 */
	const BASIS_BILLING = 'billing';

	/**
	 * Which address WooCommerce taxes by.
	 */
	public static function basis(): string {
		if ( ! wc_tax_enabled() ) {
			return self::BASIS_NONE;
		}
		$based_on = get_option( 'woocommerce_tax_based_on', 'shipping' );
		if ( 'base' === $based_on ) {
			return self::BASIS_NONE;
		}

		return 'billing' === $based_on ? self::BASIS_BILLING : self::BASIS_SHIPPING;
	}

	/**
	 * The address WooCommerce taxes an order by: billing when taxes follow the
	 * billing address, else shipping, falling back to billing when the order
	 * has no shipping country (as WooCommerce does for orders).
	 *
	 * @param \WC_Order $order Order with the customer data applied.
	 * @return array{country: string, state: string, postcode: string, city: string}
	 */
	public static function orderAddress( \WC_Order $order ): array {
		$type = 'billing' === get_option( 'woocommerce_tax_based_on', 'shipping' ) ? 'billing' : 'shipping';
		if ( 'shipping' === $type && '' === (string) $order->get_shipping_country() ) {
			$type = 'billing';
		}

		if ( 'billing' === $type ) {
			return array(
				'country'  => (string) $order->get_billing_country(),
				'state'    => (string) $order->get_billing_state(),
				'postcode' => (string) $order->get_billing_postcode(),
				'city'     => (string) $order->get_billing_city(),
			);
		}

		return array(
			'country'  => (string) $order->get_shipping_country(),
			'state'    => (string) $order->get_shipping_state(),
			'postcode' => (string) $order->get_shipping_postcode(),
			'city'     => (string) $order->get_shipping_city(),
		);
	}

	/**
	 * Whether the address has the same tax rates as the snapshot's tax
	 * location for every class in classes(). Rates are compared by rate ID,
	 * percentage, compound and shipping flag.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param array        $address  Keys country, state, postcode, city (missing keys count as empty).
	 */
	public static function sameRates( CartSnapshot $snapshot, array $address ): bool {
		$location = $snapshot->taxLocation();
		foreach ( self::classes( $snapshot ) as $tax_class ) {
			if ( self::rates( $tax_class, $address ) !== self::rates( $tax_class, $location ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The tax classes the snapshot's taxes depend on: the classes of its
	 * lines; the standard class when it has fees (the snapshot does not keep
	 * a fee's class; fees are added with the standard class by default); the
	 * shipping tax class when the cart needs shipping and the class is fixed
	 * (with "based on cart items" shipping is taxed with a line's class).
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @return string[] Class slugs, '' for standard.
	 */
	public static function classes( CartSnapshot $snapshot ): array {
		$classes = array();
		foreach ( $snapshot->items() as $item ) {
			$classes[] = (string) $item['tax_class'];
		}
		if ( array() !== $snapshot->fees() ) {
			$classes[] = '';
		}
		if ( $snapshot->needsShipping() ) {
			$shipping = (string) get_option( 'woocommerce_shipping_tax_class', 'inherit' );
			if ( 'inherit' !== $shipping ) {
				$classes[] = 'standard' === $shipping ? '' : $shipping;
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * WooCommerce's matching rates for a class and an address, reduced to
	 * what decides the tax: ID, percentage, compound and shipping flag.
	 *
	 * @param string $tax_class Class slug, '' for standard.
	 * @param array  $address   Keys country, state, postcode, city.
	 * @return array<string, string> Rate ID => `rate|compound|shipping`, sorted by ID.
	 */
	private static function rates( string $tax_class, array $address ): array {
		$found = \WC_Tax::find_rates(
			array(
				'country'   => self::field( $address, 'country' ),
				'state'     => self::field( $address, 'state' ),
				'postcode'  => self::field( $address, 'postcode' ),
				'city'      => self::field( $address, 'city' ),
				'tax_class' => $tax_class,
			)
		);

		$rates = array();
		foreach ( is_array( $found ) ? $found : array() as $id => $rate ) {
			$rate = is_array( $rate ) ? $rate : array();

			$rates[ (string) $id ] = implode(
				'|',
				array(
					isset( $rate['rate'] ) && is_numeric( $rate['rate'] ) ? (string) (float) $rate['rate'] : '',
					isset( $rate['compound'] ) ? (string) $rate['compound'] : '',
					isset( $rate['shipping'] ) ? (string) $rate['shipping'] : '',
				)
			);
		}
		ksort( $rates, SORT_STRING );

		return $rates;
	}

	/**
	 * A string field of an address, '' when absent or not a string.
	 *
	 * @param array  $address Address.
	 * @param string $key     Key.
	 */
	private static function field( array $address, string $key ): string {
		return isset( $address[ $key ] ) && is_string( $address[ $key ] ) ? $address[ $key ] : '';
	}
}
