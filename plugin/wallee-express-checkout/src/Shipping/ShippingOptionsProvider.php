<?php
/**
 * Shipping rates for a stored cart snapshot and a destination.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Shipping;

use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Asks WooCommerce for the rates of one package. Not final so that the
 * controller tests can replace it.
 *
 * The request comes from wallee, not from the customer, so it has no cart,
 * customer or session. While WooCommerce calculates, `WC()->cart`,
 * `->customer` and `->session` are replaced by local objects that are never
 * persisted, and restored afterwards. The destination lives on the package
 * array only.
 */
class ShippingOptionsProvider {

	const ERROR_COUNTRY = 'COUNTRY_UNAVAILABLE';
	const ERROR_METHOD  = 'SHIPPING_METHOD_UNAVAILABLE';

	/**
	 * Rates for the snapshot's shippable items and a destination.
	 *
	 * Shipping tax is calculated with the snapshot's tax location (design 5.7),
	 * not with the destination: the location is forced through the
	 * `woocommerce_get_tax_location` filter for the duration of the call, so
	 * every `WC_Tax::get_shipping_tax_rates()` in it (ours and the one inside
	 * `WC_Shipping_Method::add_rate()`) sees the snapshot's location.
	 *
	 * @param CartSnapshot $snapshot    Snapshot.
	 * @param array        $destination Keys country, state, postcode, city, address_1.
	 * @return array[] Rates: id, label, cost, tax, tax_rates (list of label and rate in percent).
	 */
	public function ratesFor( CartSnapshot $snapshot, array $destination ): array {
		$wc       = WC();
		$previous = array( $wc->session, $wc->customer, $wc->cart );
		$location = $snapshot->taxLocation();
		$force    = static function () use ( $location ) {
			return array( $location['country'], $location['state'], $location['postcode'], $location['city'] );
		};

		try {
			$wc->session  = self::memorySession();
			$wc->customer = new \WC_Customer( 0, false );
			$wc->cart     = new SnapshotCart( $snapshot );
			add_filter( 'woocommerce_get_tax_location', $force, PHP_INT_MAX );

			return $this->calculate( $snapshot, $destination, $wc->cart );
		} finally {
			remove_filter( 'woocommerce_get_tax_location', $force, PHP_INT_MAX );
			list( $wc->session, $wc->customer, $wc->cart ) = $previous;
		}
	}

	/**
	 * `COUNTRY_UNAVAILABLE` if the shop does not ship to the country, else
	 * `SHIPPING_METHOD_UNAVAILABLE`.
	 *
	 * @param array $destination Destination with key country.
	 */
	public function errorCodeFor( array $destination ): string {
		$countries = WC()->countries->get_shipping_countries();

		return isset( $countries[ $destination['country'] ] ) ? self::ERROR_METHOD : self::ERROR_COUNTRY;
	}

	/**
	 * Runs the calculation. Must be called with the local cart in place.
	 *
	 * @param CartSnapshot $snapshot    Snapshot.
	 * @param array        $destination Destination.
	 * @param SnapshotCart $cart        Local cart.
	 * @return array[]
	 */
	private function calculate( CartSnapshot $snapshot, array $destination, SnapshotCart $cart ): array {
		$contents      = $cart->shippableLines();
		$contents_cost = 0.0;
		foreach ( $contents as $line ) {
			$contents_cost += $line['line_total'];
		}
		if ( array() === $contents ) {
			return array();
		}

		$package = array(
			'contents'        => $contents,
			'contents_cost'   => $contents_cost,
			'applied_coupons' => $snapshot->coupons(),
			'user'            => array( 'ID' => $snapshot->customerId() ),
			'destination'     => array(
				'country'   => $destination['country'],
				'state'     => $destination['state'],
				'postcode'  => $destination['postcode'],
				'city'      => $destination['city'],
				'address'   => $destination['address_1'],
				'address_1' => $destination['address_1'],
				'address_2' => '',
			),
			'cart_subtotal'   => $cart->get_displayed_subtotal(),
		);

		$calculated = WC()->shipping()->calculate_shipping_for_package( $package, 0 );
		if ( ! is_array( $calculated ) || empty( $calculated['rates'] ) ) {
			return array();
		}

		$decimals  = Money::decimals( $snapshot->currency() );
		$tax_rates = \WC_Tax::get_shipping_tax_rates();
		$labelled  = array();
		foreach ( $tax_rates as $tax_rate ) {
			$labelled[] = array(
				'label' => (string) $tax_rate['label'],
				'rate'  => (float) $tax_rate['rate'],
			);
		}

		$rates = array();
		foreach ( $calculated['rates'] as $rate ) {
			$cost = (float) $rate->get_cost();
			$tax  = 0.0;
			if ( $cost > 0 && 'taxable' === $rate->get_tax_status() && wc_tax_enabled() ) {
				$tax = array_sum( \WC_Tax::calc_shipping_tax( $cost, $tax_rates ) );
			}
			$rates[] = array(
				'id'        => (string) $rate->get_id(),
				'label'     => (string) $rate->get_label(),
				'cost'      => Money::round( $cost, $decimals ),
				'tax'       => Money::round( $tax, $decimals ),
				'tax_rates' => $tax > 0 ? $labelled : array(),
			);
		}

		return $rates;
	}

	/**
	 * A session that keeps its data in memory only.
	 * WooCommerce caches calculated rates in the session.
	 *
	 * @return \WC_Session
	 */
	private static function memorySession() {
		return new class() extends \WC_Session {};
	}
}
