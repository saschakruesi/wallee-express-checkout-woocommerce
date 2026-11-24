<?php
/**
 * Creates the WooCommerce order for an approved express checkout.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\LineItemBuilder;
use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to WooCommerce. Not unit tested, verified on the staging shop.
 *
 * Amounts come from the snapshot and the stored shipping rate, never from the
 * current prices: every line total and tax is set explicitly and the order is
 * totalled with `calculate_totals( false )`, which keeps stored taxes.
 */
final class OrderFactory {

	const CREATED_VIA    = 'wallee_express_checkout';
	const GATEWAY_ID     = 'wallee_express_checkout';
	const GATEWAY_TITLE  = 'wallee Express Checkout';
	const META_UNIQUE_ID = '_wallee_unique_line_item_id';
	const META_SESSION   = '_wallee_ec_session_uuid';
	const META_WALLET    = '_wallee_ec_wallet';

	/**
	 * Creates the order, status `pending`, without addresses.
	 *
	 * The caller compares `$order->get_total()` with the approved amount. If
	 * WooCommerce arrives at another total, the order is returned as it is.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param array|null   $rate     Stored rate of the selected shipping option, null if none.
	 * @param string       $uuid     Express checkout session UUID.
	 * @param string       $wallet   Wallet name.
	 * @throws \RuntimeException If a product no longer exists or the order cannot be created.
	 */
	public static function create( CartSnapshot $snapshot, ?array $rate, string $uuid, string $wallet ): \WC_Order {
		$products = array();
		foreach ( $snapshot->items() as $index => $item ) {
			$product = wc_get_product( $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product instanceof \WC_Product ) {
				throw new \RuntimeException( 'A product of the cart no longer exists.' );
			}
			$products[ $index ] = $product;
		}

		$order = wc_create_order(
			array(
				'status'      => 'pending',
				'created_via' => self::CREATED_VIA,
				'customer_id' => $snapshot->customerId(),
				'currency'    => $snapshot->currency(),
			)
		);
		if ( ! $order instanceof \WC_Order ) {
			throw new \RuntimeException( 'The order could not be created.' );
		}

		$decimals = Money::decimals( $snapshot->currency() );
		$location = $snapshot->taxLocation();

		$order->set_prices_include_tax( $snapshot->pricesIncludeTax() );
		$order->set_payment_method( self::GATEWAY_ID );
		$order->set_payment_method_title( self::GATEWAY_TITLE );

		$discount = 0.0;
		foreach ( $snapshot->items() as $index => $item ) {
			$discount += $item['line_subtotal'] - $item['line_total'];
			$order->add_item( self::product_item( $products[ $index ], $item, $location, $decimals ) );
		}
		foreach ( $snapshot->fees() as $fee ) {
			$order->add_item( self::fee_item( $fee, $location, $decimals ) );
		}
		foreach ( self::coupon_items( $snapshot, Money::round( $discount, $decimals ) ) as $coupon_item ) {
			$order->add_item( $coupon_item );
		}
		if ( null !== $rate ) {
			$order->add_item( self::shipping_item( $rate, $location, $decimals ) );
		}

		$order->update_meta_data( self::META_SESSION, $uuid );
		$order->update_meta_data( self::META_WALLET, sanitize_text_field( $wallet ) );

		$order->update_taxes();
		$order->calculate_totals( false );

		return $order;
	}

	/**
	 * Writes addresses and e-mail of the transaction to the order.
	 *
	 * Every field is optional. A missing address is copied from the other one.
	 * Without any e-mail the order is kept and gets a private note.
	 *
	 * @param \WC_Order   $order         Order.
	 * @param array       $transaction   Transaction read with billingAddress and shippingAddress expanded.
	 * @param int         $customer_id   WordPress user ID, 0 for guests.
	 * @param string|null $quote_country Country the shipping rate was calculated for.
	 */
	public static function applyCustomer( \WC_Order $order, array $transaction, int $customer_id, ?string $quote_country = null ): void {
		$billing  = self::address( isset( $transaction['billingAddress'] ) ? $transaction['billingAddress'] : null );
		$shipping = self::address( isset( $transaction['shippingAddress'] ) ? $transaction['shippingAddress'] : null );
		if ( ! self::has_values( $billing ) ) {
			$billing = $shipping;
		}
		if ( ! self::has_values( $shipping ) ) {
			$shipping = $billing;
		}

		$order->set_address( $billing, 'billing' );
		$order->set_address( $shipping, 'shipping' );
		if ( method_exists( $order, 'set_shipping_phone' ) ) {
			$order->set_shipping_phone( $shipping['phone'] );
		}

		$email = OrderValidator::email( $transaction, $customer_id );
		if ( null !== $email ) {
			$order->set_billing_email( sanitize_email( $email ) );
		}

		$order->save();

		if ( null === $email ) {
			$order->add_order_note( __( 'The payment is approved, but wallee returned no valid e-mail address for the customer.', 'wallee-express-checkout' ) );
		}
		if ( null !== $quote_country && '' !== $quote_country && '' !== $shipping['country'] && 0 !== strcasecmp( $shipping['country'], $quote_country ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: country the shipping rate was calculated for, 2: country of the shipping address. */
					__( 'The shipping address is in a different country (%2$s) than the one the shipping costs were calculated for (%1$s). Please check the shipping costs.', 'wallee-express-checkout' ),
					strtoupper( $quote_country ),
					$shipping['country']
				)
			);
		}
	}

	/**
	 * Marks an order as failed.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason code.
	 */
	public static function fail( \WC_Order $order, string $reason ): void {
		$order->update_status(
			'failed',
			sprintf(
				/* translators: %s: reason code. */
				__( 'wallee Express Checkout failed: %s.', 'wallee-express-checkout' ),
				$reason
			)
		);
	}

	/**
	 * Product line from a snapshot item.
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param array       $item     Snapshot item.
	 * @param array       $location Tax location.
	 * @param int         $decimals Decimal places.
	 */
	private static function product_item( \WC_Product $product, array $item, array $location, int $decimals ): \WC_Order_Item_Product {
		$line = new \WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_name( $item['name'] );
		$line->set_quantity( $item['quantity'] );
		$line->set_tax_class( $item['tax_class'] );
		$line->set_variation( $item['variation'] );
		$line->set_subtotal( $item['line_subtotal'] );
		$line->set_total( $item['line_total'] );

		$rates = self::rate_percentages( $item['tax_rates'], $location, $item['tax_class'], false );
		$total = self::allocate( $item['line_tax'], $rates, $decimals );
		$sub   = self::allocate( $item['line_subtotal_tax'], $rates, $decimals );
		if ( array() !== $total || array() !== $sub ) {
			$line->set_taxes(
				array(
					'subtotal' => $sub,
					'total'    => $total,
				)
			);
		}
		// Without a rate ID to book them under, the amounts are still kept.
		if ( array() === $total ) {
			$line->set_total_tax( $item['line_tax'] );
		}
		if ( array() === $sub ) {
			$line->set_subtotal_tax( $item['line_subtotal_tax'] );
		}

		$line->add_meta_data( self::META_UNIQUE_ID, LineItemBuilder::uniqueIdFor( 'item', $item['key'] ), true );

		return $line;
	}

	/**
	 * Fee line from a snapshot fee.
	 *
	 * @param array $fee      Snapshot fee.
	 * @param array $location Tax location.
	 * @param int   $decimals Decimal places.
	 */
	private static function fee_item( array $fee, array $location, int $decimals ): \WC_Order_Item_Fee {
		$line = new \WC_Order_Item_Fee();
		$line->set_name( $fee['name'] );
		$line->set_total( $fee['total'] );
		$line->set_tax_status( array() !== $fee['tax_rates'] || $fee['tax'] > 0 ? 'taxable' : 'none' );

		$total = self::allocate( $fee['tax'], self::rate_percentages( $fee['tax_rates'], $location, null, false ), $decimals );
		if ( array() !== $total ) {
			$line->set_taxes( array( 'total' => $total ) );
		} else {
			$line->set_total_tax( $fee['tax'] );
		}

		$line->add_meta_data( self::META_UNIQUE_ID, LineItemBuilder::uniqueIdFor( 'fee', $fee['id'] ), true );

		return $line;
	}

	/**
	 * Coupon lines. The snapshot holds the total discount only, so with several
	 * coupons the whole discount sits on the first one.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param float        $discount Discount without tax.
	 * @return \WC_Order_Item_Coupon[]
	 */
	private static function coupon_items( CartSnapshot $snapshot, float $discount ): array {
		$tax = 0.0;
		foreach ( $snapshot->items() as $item ) {
			$tax += $item['line_subtotal_tax'] - $item['line_tax'];
		}
		$tax = Money::round( $tax, Money::decimals( $snapshot->currency() ) );

		$lines = array();
		foreach ( $snapshot->coupons() as $index => $code ) {
			$line = new \WC_Order_Item_Coupon();
			$line->set_code( $code );
			$line->set_discount( 0 === $index ? $discount : 0 );
			$line->set_discount_tax( 0 === $index ? $tax : 0 );
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Shipping line from a stored rate. Method and instance come from the rate
	 * ID (`flat_rate:1`), title, cost and tax from the stored rate.
	 *
	 * @param array $rate     Stored rate.
	 * @param array $location Tax location.
	 * @param int   $decimals Decimal places.
	 */
	private static function shipping_item( array $rate, array $location, int $decimals ): \WC_Order_Item_Shipping {
		$id       = (string) $rate['id'];
		$position = strpos( $id, ':' );
		$method   = false === $position ? $id : substr( $id, 0, $position );
		$instance = false === $position ? 0 : (int) substr( $id, $position + 1 );

		$line = new \WC_Order_Item_Shipping();
		$line->set_method_title( (string) $rate['label'] );
		$line->set_method_id( $method );
		$line->set_instance_id( $instance );
		$line->set_total( (float) $rate['cost'] );

		$tax_rates = isset( $rate['tax_rates'] ) && is_array( $rate['tax_rates'] ) ? $rate['tax_rates'] : array();
		$total     = self::allocate( (float) $rate['tax'], self::rate_percentages( $tax_rates, $location, null, true ), $decimals );
		if ( array() !== $total ) {
			$line->set_taxes( array( 'total' => $total ) );
		} else {
			$line->set_total_tax( (float) $rate['tax'] );
		}

		$line->add_meta_data( self::META_UNIQUE_ID, 'shipping-' . $id, true );

		return $line;
	}

	/**
	 * Looks up the WooCommerce tax rate IDs of the snapshot's tax rates (label
	 * and percent) at the snapshot's tax location.
	 *
	 * @param array       $tax_rates  List of label and rate.
	 * @param array       $location   Tax location.
	 * @param string|null $tax_class  Tax class tried first, null for none.
	 * @param bool        $shipping   Whether to look at shipping rates.
	 * @return array<int|string, float> Rate ID => percent. Rates that no longer exist are left out.
	 */
	private static function rate_percentages( array $tax_rates, array $location, ?string $tax_class, bool $shipping ): array {
		if ( array() === $tax_rates ) {
			return array();
		}

		$classes = array_merge( array( '' ), \WC_Tax::get_tax_class_slugs() );
		if ( null !== $tax_class ) {
			array_unshift( $classes, $tax_class );
		}

		$candidates = array();
		foreach ( array_unique( $classes ) as $class ) {
			$args  = array(
				'country'   => $location['country'],
				'state'     => $location['state'],
				'postcode'  => $location['postcode'],
				'city'      => $location['city'],
				'tax_class' => $class,
			);
			$found = $shipping ? \WC_Tax::find_shipping_rates( $args ) : \WC_Tax::find_rates( $args );
			foreach ( $found as $rate_id => $found_rate ) {
				if ( ! isset( $candidates[ $rate_id ] ) ) {
					$candidates[ $rate_id ] = $found_rate;
				}
			}
		}

		$matched = array();
		foreach ( $tax_rates as $wanted ) {
			foreach ( $candidates as $rate_id => $candidate ) {
				if ( isset( $matched[ $rate_id ] ) ) {
					continue;
				}
				if ( (string) $candidate['label'] === (string) $wanted['label'] && abs( (float) $candidate['rate'] - (float) $wanted['rate'] ) < 0.0001 ) {
					$matched[ $rate_id ] = (float) $candidate['rate'];
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * Splits a stored tax amount over rates in proportion to their percent. The
	 * parts add up to the amount exactly.
	 *
	 * @param float                    $amount   Tax amount.
	 * @param array<int|string, float> $rates    Rate ID => percent.
	 * @param int                      $decimals Decimal places.
	 * @return array<int|string, float> Rate ID => amount, empty if there is nothing to split.
	 */
	private static function allocate( float $amount, array $rates, int $decimals ): array {
		$sum = array_sum( $rates );
		if ( array() === $rates || $sum <= 0 || abs( $amount ) < 0.5 * pow( 10, -$decimals ) ) {
			return array();
		}

		$parts   = array();
		$largest = null;
		foreach ( $rates as $rate_id => $percent ) {
			$parts[ $rate_id ] = Money::round( $amount * $percent / $sum, $decimals );
			if ( null === $largest || $percent > $rates[ $largest ] ) {
				$largest = $rate_id;
			}
		}
		$parts[ $largest ] = Money::round( $parts[ $largest ] + ( $amount - array_sum( $parts ) ), $decimals );

		return $parts;
	}

	/**
	 * Maps a wallee address to the WooCommerce address fields. Every field is
	 * optional and sanitised.
	 *
	 * @param mixed $address wallee address or null.
	 */
	private static function address( $address ): array {
		$address = is_array( $address ) ? $address : array();
		$postal  = self::field( $address, 'postcode' );
		if ( '' === $postal ) {
			$postal = self::field( $address, 'postCode' );
		}
		$country = strtoupper( self::field( $address, 'country' ) );

		return array(
			'first_name' => self::field( $address, 'givenName' ),
			'last_name'  => self::field( $address, 'familyName' ),
			'company'    => self::field( $address, 'organizationName' ),
			'address_1'  => self::field( $address, 'street' ),
			'address_2'  => '',
			'city'       => self::field( $address, 'city' ),
			'postcode'   => $postal,
			'state'      => self::field( $address, 'postalState' ),
			'country'    => 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'phone'      => self::field( $address, 'phoneNumber' ),
		);
	}

	/**
	 * A sanitised string field, empty when absent or not a string.
	 *
	 * @param array  $address Address.
	 * @param string $key     Key.
	 */
	private static function field( array $address, string $key ): string {
		if ( ! isset( $address[ $key ] ) || ! is_string( $address[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( $address[ $key ] );
	}

	/**
	 * Whether any field of a mapped address has a value.
	 *
	 * @param array $address Mapped address.
	 */
	private static function has_values( array $address ): bool {
		return array() !== array_filter(
			$address,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}
}
