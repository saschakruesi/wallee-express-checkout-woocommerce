<?php
/**
 * Builds cart snapshots from WooCommerce.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Cart;

use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to WooCommerce. Not unit tested, verified on the staging shop.
 */
final class SnapshotFactory {

	/**
	 * Snapshot of a live cart.
	 *
	 * @param \WC_Cart $cart Cart, normally the customer's session cart.
	 * @throws \InvalidArgumentException If the snapshot data is invalid.
	 */
	public function fromCart( \WC_Cart $cart ): CartSnapshot {
		$cart->calculate_totals();

		$package_count = $cart->needs_shipping() ? count( $cart->get_shipping_packages() ) : 0;

		return $this->build( $cart, CartSnapshot::CONTEXT_CART, $package_count );
	}

	/**
	 * Snapshot of a single product purchase.
	 *
	 * The line is priced directly with WooCommerce's price helpers for the
	 * current customer. No cart object is created and no cart hook runs, so
	 * the customer's cart and session are not touched.
	 *
	 * @param int   $product_id   Product ID (the parent for variations).
	 * @param int   $variation_id Variation ID, 0 for none.
	 * @param array $variation    Variation attributes, attribute_* => value.
	 * @param int   $quantity     Quantity.
	 * @throws \InvalidArgumentException If the product cannot be bought like this.
	 */
	public function fromProduct( int $product_id, int $variation_id, array $variation, int $quantity ): CartSnapshot {
		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( 'Quantity must be at least 1.' );
		}
		if ( ! WC()->customer ) {
			throw new \InvalidArgumentException( 'No customer available to price the product.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			throw new \InvalidArgumentException( 'Product not found.' );
		}
		if ( $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			throw new \InvalidArgumentException( 'Product type is not supported.' );
		}

		$buyable = $product;
		if ( $product->is_type( 'variable' ) ) {
			if ( $variation_id < 1 ) {
				throw new \InvalidArgumentException( 'A variation is required.' );
			}
			$buyable = wc_get_product( $variation_id );
			if ( ! $buyable || $buyable->get_parent_id() !== $product_id ) {
				throw new \InvalidArgumentException( 'Variation does not belong to the product.' );
			}
		} elseif ( $variation_id > 0 ) {
			throw new \InvalidArgumentException( 'Product has no variations.' );
		}

		if ( ! $buyable->is_purchasable() ) {
			throw new \InvalidArgumentException( 'Product is not purchasable.' );
		}
		if ( ! $buyable->is_in_stock() || ! $buyable->has_enough_stock( $quantity ) ) {
			throw new \InvalidArgumentException( 'Product is not in stock for that quantity.' );
		}
		if ( $buyable->is_sold_individually() && $quantity > 1 ) {
			throw new \InvalidArgumentException( 'Product can only be bought once.' );
		}

		$decimals  = Money::decimals( get_woocommerce_currency() );
		$excl      = Money::round( wc_get_price_excluding_tax( $buyable, array( 'qty' => $quantity ) ), $decimals );
		$incl      = Money::round( wc_get_price_including_tax( $buyable, array( 'qty' => $quantity ) ), $decimals );
		$tax       = Money::round( $incl - $excl, $decimals );
		$tax_class = (string) $buyable->get_tax_class();
		$shipping  = (bool) $buyable->needs_shipping();

		$attributes = array();
		foreach ( $variation as $name => $value ) {
			$attributes[ sanitize_title( (string) $name ) ] = (string) $value;
		}

		$item = array(
			'key'               => md5( $product_id . ':' . $variation_id . ':' . wp_json_encode( $attributes ) ),
			'product_id'        => $product_id,
			'variation_id'      => $variation_id,
			'variation'         => $attributes,
			'name'              => $buyable->get_name(),
			'sku'               => (string) $buyable->get_sku(),
			'quantity'          => $quantity,
			'line_subtotal'     => $excl,
			'line_subtotal_tax' => $tax,
			'line_total'        => $excl,
			'line_tax'          => $tax,
			'tax_class'         => $tax_class,
			'tax_rates'         => $this->rates_for( $buyable ),
			'needs_shipping'    => $shipping,
			'is_subscription'   => $this->is_subscription( $buyable ),
		);

		return $this->assemble( CartSnapshot::CONTEXT_PRODUCT, array( $item ), array(), array(), $shipping ? 1 : 0 );
	}

	/**
	 * Reads a priced cart into a snapshot.
	 *
	 * @param \WC_Cart $cart          Cart with calculated totals.
	 * @param string   $context       Snapshot context.
	 * @param int      $package_count Number of shipping packages.
	 * @throws \InvalidArgumentException If the snapshot data is invalid.
	 */
	private function build( \WC_Cart $cart, string $context, int $package_count ): CartSnapshot {
		$decimals = Money::decimals( get_woocommerce_currency() );

		$items = array();
		foreach ( $cart->get_cart() as $key => $values ) {
			$product = $values['data'];
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$items[] = array(
				'key'               => (string) $key,
				'product_id'        => (int) $values['product_id'],
				'variation_id'      => (int) $values['variation_id'],
				'variation'         => isset( $values['variation'] ) && is_array( $values['variation'] ) ? $values['variation'] : array(),
				'name'              => $product->get_name(),
				'sku'               => (string) $product->get_sku(),
				'quantity'          => (int) $values['quantity'],
				'line_subtotal'     => Money::round( $values['line_subtotal'], $decimals ),
				'line_subtotal_tax' => Money::round( $values['line_subtotal_tax'], $decimals ),
				'line_total'        => Money::round( $values['line_total'], $decimals ),
				'line_tax'          => Money::round( $values['line_tax'], $decimals ),
				'tax_class'         => (string) $product->get_tax_class(),
				'tax_rates'         => $this->rates_for( $product ),
				'needs_shipping'    => (bool) $product->needs_shipping(),
				'is_subscription'   => $this->is_subscription( $product ),
			);
		}

		$fees = array();
		foreach ( $cart->get_fees() as $fee ) {
			$fees[] = array(
				'id'        => (string) $fee->id,
				'name'      => (string) $fee->name,
				'total'     => Money::round( $fee->total, $decimals ),
				'tax'       => Money::round( $fee->tax, $decimals ),
				'tax_rates' => $fee->taxable ? $this->tax_rates( (string) $fee->tax_class ) : array(),
			);
		}

		$coupons = array_values( array_map( 'strval', $cart->get_applied_coupons() ) );

		return $this->assemble( $context, $items, $fees, $coupons, $package_count );
	}

	/**
	 * Wraps items, fees and the customer's facts into a snapshot.
	 *
	 * @param string $context       Snapshot context.
	 * @param array  $items         Items.
	 * @param array  $fees          Fees.
	 * @param array  $coupons       Coupon codes.
	 * @param int    $package_count Number of shipping packages.
	 * @throws \InvalidArgumentException If the snapshot data is invalid.
	 */
	private function assemble( string $context, array $items, array $fees, array $coupons, int $package_count ): CartSnapshot {
		$address = WC()->customer->get_taxable_address();

		return CartSnapshot::fromArray(
			array(
				'context'                => $context,
				'currency'               => get_woocommerce_currency(),
				'customer_id'            => (int) get_current_user_id(),
				'prices_include_tax'     => (bool) wc_prices_include_tax(),
				'tax_location'           => array(
					'country'  => (string) ( $address[0] ?? '' ),
					'state'    => (string) ( $address[1] ?? '' ),
					'postcode' => (string) ( $address[2] ?? '' ),
					'city'     => (string) ( $address[3] ?? '' ),
				),
				'items'                  => $items,
				'fees'                   => $fees,
				'coupons'                => $coupons,
				'shipping_package_count' => $package_count,
			)
		);
	}

	/**
	 * Tax rates that apply to a product for the current customer.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function rates_for( \WC_Product $product ): array {
		if ( ! wc_tax_enabled() || ! $product->is_taxable() ) {
			return array();
		}

		return $this->tax_rates( (string) $product->get_tax_class() );
	}

	/**
	 * Whether a product is a subscription product.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function is_subscription( \WC_Product $product ): bool {
		return class_exists( 'WC_Subscriptions_Product' ) && \WC_Subscriptions_Product::is_subscription( $product );
	}

	/**
	 * Tax rates for a tax class at the customer's taxable address.
	 *
	 * @param string $tax_class Tax class slug, empty for standard.
	 */
	private function tax_rates( string $tax_class ): array {
		$rates = array();
		foreach ( \WC_Tax::get_rates( $tax_class, WC()->customer ) as $rate ) {
			$rates[] = array(
				'label' => (string) $rate['label'],
				'rate'  => (float) $rate['rate'],
			);
		}

		return $rates;
	}
}
