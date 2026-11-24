<?php
/**
 * Checks that what the customer approved is what the shop delivers and bills.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The comparison methods (`check`, `selectedOption`, `matchedRate`, `email`)
 * make no WooCommerce calls. Everything read from a wallee response is
 * treated as untrusted and possibly absent: a missing key is a failed check,
 * never a PHP notice. `stockAndCoupons` talks to WooCommerce and is verified
 * on the staging shop.
 */
final class OrderValidator {

	const NOT_APPROVED      = 'not_approved';
	const CURRENCY_MISMATCH = 'currency_mismatch';
	const SHIPPING_MISSING  = 'shipping_missing';
	const SHIPPING_CHANGED  = 'shipping_changed';
	const AMOUNT_MISMATCH   = 'amount_mismatch';
	const OUT_OF_STOCK      = 'out_of_stock';
	const COUPON_INVALID    = 'coupon_invalid';

	/**
	 * The one shipping option the customer selected.
	 *
	 * @param array $session Session as returned by the wallee API.
	 * @return array|null Null if no option or more than one option is selected.
	 */
	public static function selectedOption( array $session ): ?array {
		$selected = self::selectedOptions( $session );

		return 1 === count( $selected ) ? $selected[0] : null;
	}

	/**
	 * The stored rate the selected option refers to, matched by ID.
	 *
	 * @param array      $session Session as returned by the wallee API.
	 * @param array|null $quote   Stored shipping quote: destination and rates.
	 * @return array|null Null if nothing is selected, there is no quote or the ID is not in it.
	 */
	public static function matchedRate( array $session, ?array $quote ): ?array {
		$option = self::selectedOption( $session );
		if ( null === $option || null === $quote || ! isset( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			return null;
		}
		if ( ! isset( $option['id'] ) || ! is_string( $option['id'] ) || '' === $option['id'] ) {
			return null;
		}

		foreach ( $quote['rates'] as $rate ) {
			if ( is_array( $rate ) && isset( $rate['id'] ) && (string) $rate['id'] === $option['id'] ) {
				return $rate;
			}
		}

		return null;
	}

	/**
	 * Compares the approved session with the snapshot and the stored quote.
	 *
	 * Order of the checks: state, currency, shipping presence, shipping
	 * identity and price, total.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param array        $session  Session as returned by the wallee API.
	 * @param array|null   $quote    Stored shipping quote.
	 * @return string|null Null if everything matches, else a reason code.
	 */
	public static function check( CartSnapshot $snapshot, array $session, ?array $quote ): ?string {
		if ( ! isset( $session['state'] ) || 'APPROVED' !== $session['state'] ) {
			return self::NOT_APPROVED;
		}

		if ( ! isset( $session['currency'] ) || $session['currency'] !== $snapshot->currency() ) {
			return self::CURRENCY_MISMATCH;
		}

		$decimals = Money::decimals( $snapshot->currency() );
		$expected = $snapshot->total();

		if ( $snapshot->needsShipping() ) {
			$option = self::selectedOption( $session );
			if ( null === $option || null === $quote || ! isset( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
				return self::SHIPPING_MISSING;
			}

			$rate = self::matchedRate( $session, $quote );
			if ( null === $rate ) {
				return self::SHIPPING_CHANGED;
			}
			$stored = self::rateAmount( $rate, $decimals );
			if ( null === $stored || ! isset( $option['amountIncludingTax'] ) || ! self::isNumber( $option['amountIncludingTax'] ) || ! Money::equals( $option['amountIncludingTax'], $stored, $decimals ) ) {
				return self::SHIPPING_CHANGED;
			}

			$expected += $stored;
		} elseif ( array() !== self::selectedOptions( $session ) ) {
			return self::SHIPPING_CHANGED;
		}

		if ( ! isset( $session['authorizationAmount'] ) || ! self::isNumber( $session['authorizationAmount'] ) || ! Money::equals( $session['authorizationAmount'], $expected, $decimals ) ) {
			return self::AMOUNT_MISMATCH;
		}

		return null;
	}

	/**
	 * The customer's e-mail: billing address, shipping address, the
	 * transaction's customer e-mail, the logged-in customer. Each must be a
	 * valid address, an invalid one is skipped.
	 *
	 * @param array $transaction Transaction read with billingAddress and shippingAddress expanded.
	 * @param int   $customer_id WordPress user ID, 0 for guests.
	 */
	public static function email( array $transaction, int $customer_id ): ?string {
		$candidates = array(
			self::nested( $transaction, 'billingAddress', 'emailAddress' ),
			self::nested( $transaction, 'shippingAddress', 'emailAddress' ),
			isset( $transaction['customerEmailAddress'] ) ? $transaction['customerEmailAddress'] : null,
		);
		if ( $customer_id > 0 ) {
			$user         = get_userdata( $customer_id );
			$candidates[] = $user && isset( $user->user_email ) ? $user->user_email : null;
		}

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && false !== is_email( trim( $candidate ) ) ) {
				return trim( $candidate );
			}
		}

		return null;
	}

	/**
	 * Re-validates stock and coupons right before the order is made.
	 *
	 * Coupons are validated against an unsaved order that holds the snapshot's
	 * lines, the customer and the e-mail, so usage limits, minimum and maximum
	 * amounts and product restrictions are WooCommerce's own. A coupon with an
	 * e-mail restriction is invalid while no e-mail is known.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $email    E-mail of the customer, empty if unknown.
	 * @return string|null Null, `out_of_stock` or `coupon_invalid`.
	 */
	public static function stockAndCoupons( CartSnapshot $snapshot, string $email ): ?string {
		$products = array();
		$managed  = array();

		foreach ( $snapshot->items() as $item ) {
			$product = wc_get_product( $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
				return self::OUT_OF_STOCK;
			}
			if ( ! $product->is_in_stock() || ( $product->is_sold_individually() && $item['quantity'] > 1 ) ) {
				return self::OUT_OF_STOCK;
			}
			if ( ! $product->has_enough_stock( $item['quantity'] ) ) {
				return self::OUT_OF_STOCK;
			}

			// Variations can share the stock of their parent: check the sum per stock holder.
			$holder = $product->get_stock_managed_by_id();
			if ( ! isset( $managed[ $holder ] ) ) {
				$managed[ $holder ] = array(
					'product'  => $product,
					'quantity' => 0,
				);
			}
			$managed[ $holder ]['quantity'] += $item['quantity'];
			$products[]                      = array( $product, $item );
		}

		foreach ( $managed as $entry ) {
			if ( ! $entry['product']->has_enough_stock( $entry['quantity'] ) ) {
				return self::OUT_OF_STOCK;
			}
		}

		if ( array() === $snapshot->coupons() ) {
			return null;
		}

		$order = new \WC_Order();
		$order->set_currency( $snapshot->currency() );
		$order->set_customer_id( $snapshot->customerId() );
		$order->set_billing_email( $email );
		foreach ( $products as $pair ) {
			$order->add_product(
				$pair[0],
				$pair[1]['quantity'],
				array(
					'subtotal' => $pair[1]['line_subtotal'],
					'total'    => $pair[1]['line_total'],
				)
			);
		}

		$discounts = new \WC_Discounts( $order );
		foreach ( $snapshot->coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( ! $coupon->get_id() ) {
				return self::COUPON_INVALID;
			}
			$restrictions = $coupon->get_email_restrictions();
			if ( is_array( $restrictions ) && array() !== $restrictions && '' === $email ) {
				return self::COUPON_INVALID;
			}
			if ( true !== $discounts->is_coupon_valid( $coupon ) ) {
				return self::COUPON_INVALID;
			}
		}

		return null;
	}

	/**
	 * Amount of a stored rate: cost plus tax at currency precision. The same
	 * derivation as ShippingRateMapper::toOption().
	 *
	 * @param array $rate     Stored rate.
	 * @param int   $decimals Decimal places.
	 */
	public static function rateAmount( array $rate, int $decimals ): ?float {
		if ( ! isset( $rate['cost'], $rate['tax'] ) || ! self::isNumber( $rate['cost'] ) || ! self::isNumber( $rate['tax'] ) ) {
			return null;
		}

		return Money::round( (float) $rate['cost'] + (float) $rate['tax'], $decimals );
	}

	/**
	 * All options of the session with `selected` exactly true.
	 *
	 * @param array $session Session.
	 * @return array[]
	 */
	private static function selectedOptions( array $session ): array {
		$selected = array();
		if ( ! isset( $session['shippingOptions'] ) || ! is_array( $session['shippingOptions'] ) ) {
			return $selected;
		}
		foreach ( $session['shippingOptions'] as $option ) {
			if ( is_array( $option ) && isset( $option['selected'] ) && true === $option['selected'] ) {
				$selected[] = $option;
			}
		}

		return $selected;
	}

	/**
	 * Reads `$data[$outer][$inner]`, null when absent.
	 *
	 * @param array  $data  Data.
	 * @param string $outer Outer key.
	 * @param string $inner Inner key.
	 * @return mixed
	 */
	private static function nested( array $data, string $outer, string $inner ) {
		if ( ! isset( $data[ $outer ] ) || ! is_array( $data[ $outer ] ) || ! isset( $data[ $outer ][ $inner ] ) ) {
			return null;
		}

		return $data[ $outer ][ $inner ];
	}

	/**
	 * Whether a value is a number or a numeric string, not a boolean or an array.
	 *
	 * @param mixed $value Value.
	 */
	private static function isNumber( $value ): bool {
		return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
	}
}
