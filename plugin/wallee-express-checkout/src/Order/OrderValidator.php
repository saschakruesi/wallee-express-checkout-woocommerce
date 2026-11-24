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
 * The comparison methods (`check`, `environment`, `destination`,
 * `inTaxLocation`, `selectedOption`, `matchedRate`, `email`) make no
 * WooCommerce calls. Everything read from a wallee response is treated as
 * untrusted and possibly absent: a missing key is a failed check, never a PHP
 * notice. `stockAndCoupons` and `customerCoupons` talk to WooCommerce; they
 * are unit tested with stand-ins and verified on the staging shop.
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
	 * The session was approved in another authorization environment than the
	 * one configured (TEST or PRODUCTION).
	 */
	const ENVIRONMENT_MISMATCH = 'environment_mismatch';

	/**
	 * The wallet's address is in another country than the shipping quote or,
	 * where taxes follow the customer's address, the snapshot's tax location.
	 */
	const DESTINATION_CHANGED = 'destination_changed';

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
	 * The session must have been approved in the configured authorization
	 * environment; a missing value does not match.
	 *
	 * @param array  $session  Session as returned by the wallee API.
	 * @param string $expected Configured environment, TEST or PRODUCTION.
	 * @return string|null Null if it matches, else `environment_mismatch`.
	 */
	public static function environment( array $session, string $expected ): ?string {
		if ( isset( $session['authorizationEnvironment'] ) && $session['authorizationEnvironment'] === $expected ) {
			return null;
		}

		return self::ENVIRONMENT_MISMATCH;
	}

	/**
	 * Whether WooCommerce taxes by the customer's address: taxes are enabled
	 * and the tax basis is not the shop base address.
	 */
	public static function taxFollowsAddress(): bool {
		return wc_tax_enabled() && 'base' !== get_option( 'woocommerce_tax_based_on', 'shipping' );
	}

	/**
	 * Whether an address lies in the snapshot's tax location: same country
	 * and, when the tax location has a state and a state is given, the same
	 * state.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $country  Country code.
	 * @param string|null  $state    State code, null to compare the country only.
	 */
	public static function inTaxLocation( CartSnapshot $snapshot, string $country, ?string $state ): bool {
		$location = $snapshot->taxLocation();
		if ( 0 !== strcasecmp( $country, $location['country'] ) ) {
			return false;
		}

		return null === $state || '' === $location['state'] || 0 === strcasecmp( $state, $location['state'] );
	}

	/**
	 * Compares the country of the wallet's address with the stored shipping
	 * quote and, where taxes follow the address, with the snapshot's tax
	 * location.
	 *
	 * @param CartSnapshot $snapshot            Snapshot.
	 * @param array|null   $quote               Stored shipping quote, null without one.
	 * @param string       $country             Country of the shipping address (billing as fallback), empty if unknown.
	 * @param bool         $tax_follows_address Result of taxFollowsAddress().
	 * @return string|null Null if consistent or the country is unknown, else `destination_changed`.
	 */
	public static function destination( CartSnapshot $snapshot, ?array $quote, string $country, bool $tax_follows_address ): ?string {
		if ( '' === $country ) {
			return null;
		}
		$quoted = null === $quote ? null : self::nested( $quote, 'destination', 'country' );
		if ( is_string( $quoted ) && 0 !== strcasecmp( $quoted, $country ) ) {
			return self::DESTINATION_CHANGED;
		}
		if ( $tax_follows_address && ! self::inTaxLocation( $snapshot, $country, null ) ) {
			return self::DESTINATION_CHANGED;
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
	 * lines and the customer, so usage limits, minimum and maximum amounts and
	 * product restrictions are WooCommerce's own. The e-mail is not known yet:
	 * e-mail restrictions and the guest usage limit per e-mail are checked by
	 * customerCoupons() once it is.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @return string|null Null, `out_of_stock` or `coupon_invalid`.
	 */
	public static function stockAndCoupons( CartSnapshot $snapshot ): ?string {
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

		return self::coupons_valid( $snapshot, $products, '' ) ? null : self::COUPON_INVALID;
	}

	/**
	 * Re-validates the coupons with the customer's e-mail, after the wallet
	 * provided it and before the customer is sent to the payment step:
	 * WooCommerce's coupon validation (global usage limit, usage limit per
	 * user for customers with an account, e-mail restrictions, amounts and
	 * product rules) and, for guests, the usage limit per e-mail address the
	 * way WooCommerce's checkout counts it.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $email    Billing e-mail of the order.
	 * @return string|null Null or `coupon_invalid`.
	 */
	public static function customerCoupons( CartSnapshot $snapshot, string $email ): ?string {
		if ( array() === $snapshot->coupons() ) {
			return null;
		}

		$products = array();
		foreach ( $snapshot->items() as $item ) {
			$product = wc_get_product( $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product instanceof \WC_Product ) {
				return self::COUPON_INVALID;
			}
			$products[] = array( $product, $item );
		}

		return self::coupons_valid( $snapshot, $products, $email ) ? null : self::COUPON_INVALID;
	}

	/**
	 * Validates every coupon of the snapshot with WooCommerce's rules.
	 *
	 * Without an e-mail (`''`), e-mail restrictions are left out (validated
	 * on a copy of the coupon without them) and the guest limit per e-mail is
	 * not counted; with one, both apply.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param array        $products Pairs of product and snapshot item.
	 * @param string       $email    Customer e-mail, '' if not known yet.
	 */
	private static function coupons_valid( CartSnapshot $snapshot, array $products, string $email ): bool {
		if ( array() === $snapshot->coupons() ) {
			return true;
		}

		$email = strtolower( sanitize_email( $email ) );
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
				return false;
			}

			$checked = $coupon;
			if ( '' === $email ) {
				$restrictions = $coupon->get_email_restrictions();
				if ( is_array( $restrictions ) && array() !== $restrictions ) {
					$checked = clone $coupon;
					$checked->set_email_restrictions( array() );
				}
			}
			if ( true !== $discounts->is_coupon_valid( $checked ) ) {
				return false;
			}

			if ( '' !== $email && 0 === $snapshot->customerId() && self::guest_limit_reached( $coupon, $email ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a guest has used up a coupon's usage limit per user, counted by
	 * billing e-mail as WooCommerce's checkout does for guests.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @param string     $email  Lower-case e-mail.
	 */
	private static function guest_limit_reached( \WC_Coupon $coupon, string $email ): bool {
		$limit = (int) $coupon->get_usage_limit_per_user();
		if ( $limit <= 0 ) {
			return false;
		}

		$store = $coupon->get_data_store();
		if ( is_object( $store ) && is_callable( array( $store, 'get_usage_by_email' ) ) ) {
			$used = (int) $store->get_usage_by_email( $coupon, $email );
		} else {
			$used = count( array_keys( array_map( 'strtolower', array_map( 'strval', (array) $coupon->get_used_by() ) ), $email, true ) );
		}

		return $used >= $limit;
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
