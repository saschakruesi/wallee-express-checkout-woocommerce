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
	 * Snapshot of a single product purchase, priced in a throwaway cart.
	 *
	 * The throwaway cart is never the session cart and is never persisted.
	 *
	 * @param int   $product_id   Product ID (the parent for variations).
	 * @param int   $variation_id Variation ID, 0 for none.
	 * @param array $variation   Variation attributes, attribute_* => value.
	 * @param int   $quantity    Quantity.
	 * @throws \InvalidArgumentException If the product cannot be bought like this.
	 */
	public function fromProduct( int $product_id, int $variation_id, array $variation, int $quantity ): CartSnapshot {
		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( 'Quantity must be at least 1.' );
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

		$attributes = array();
		foreach ( $variation as $name => $value ) {
			$attributes[ sanitize_title( (string) $name ) ] = (string) $value;
		}

		$cart = new \WC_Cart();
		$this->detach_cart_hooks( $cart );

		$key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );
		if ( false === $key ) {
			throw new \InvalidArgumentException( 'Product could not be priced.' );
		}
		$cart->calculate_totals();

		return $this->build( $cart, CartSnapshot::CONTEXT_PRODUCT, $cart->needs_shipping() ? 1 : 0 );
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
		$currency = get_woocommerce_currency();
		$decimals = Money::decimals( $currency );

		$address = WC()->customer->get_taxable_address();

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
				'tax_rates'         => $product->is_taxable() ? $this->tax_rates( (string) $product->get_tax_class() ) : array(),
				'needs_shipping'    => (bool) $product->needs_shipping(),
				'is_subscription'   => class_exists( 'WC_Subscriptions_Product' ) && \WC_Subscriptions_Product::is_subscription( $product ),
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

		return CartSnapshot::fromArray(
			array(
				'context'                => $context,
				'currency'               => $currency,
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
				'coupons'                => CartSnapshot::CONTEXT_CART === $context ? array_values( array_map( 'strval', $cart->get_applied_coupons() ) ) : array(),
				'shipping_package_count' => $package_count,
			)
		);
	}

	/**
	 * Tax rates for a tax class at the customer's taxable address.
	 *
	 * @param string $tax_class Tax class slug, empty for standard.
	 */
	private function tax_rates( string $tax_class ): array {
		$rates = array();
		foreach ( \WC_Tax::get_rates( $tax_class ) as $rate ) {
			$rates[] = array(
				'label' => (string) $rate['label'],
				'rate'  => (float) $rate['rate'],
			);
		}

		return $rates;
	}

	/**
	 * Removes every hook a fresh WC_Cart registers on construction.
	 *
	 * The cart's session helper listens to cart events and would otherwise
	 * write the throwaway cart into the customer's session and saved cart.
	 *
	 * @param \WC_Cart $cart Throwaway cart.
	 */
	private function detach_cart_hooks( \WC_Cart $cart ): void {
		$owners = array( $cart );

		// The session helper is a private member of the cart.
		$property = new \ReflectionProperty( \WC_Cart::class, 'session' );
		$property->setAccessible( true );
		$session = $property->getValue( $cart );
		if ( is_object( $session ) ) {
			$owners[] = $session;
		}

		global $wp_filter;
		foreach ( $wp_filter as $tag => $hook ) {
			if ( ! $hook instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && isset( $function[0] ) && in_array( $function[0], $owners, true ) ) {
						remove_filter( $tag, $function, $priority );
					}
				}
			}
		}
	}
}
