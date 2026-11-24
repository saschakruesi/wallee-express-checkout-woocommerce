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
 * Talks to WooCommerce. The input checks are unit tested with stand-ins;
 * pricing is verified on the staging shop.
 */
final class SnapshotFactory {

	/**
	 * Exception message when the snapshot does not reproduce the cart total.
	 */
	const TOTAL_MISMATCH = 'total_mismatch';

	/**
	 * Exception message when WooCommerce's cart or add-to-cart validation refuses.
	 */
	const VALIDATION_FAILED = 'validation_failed';

	/**
	 * Snapshot of a live cart.
	 *
	 * @param \WC_Cart $cart Cart, normally the customer's session cart.
	 * @throws \InvalidArgumentException If the snapshot data is invalid, or with message `total_mismatch` if the
	 *                                   snapshot (lines rounded one by one) differs from WooCommerce's cart total
	 *                                   without shipping, for example with "round tax at subtotal".
	 */
	public function fromCart( \WC_Cart $cart ): CartSnapshot {
		// The checks the cart page runs: WooCommerce's own item checks
		// (WC_Cart::check_cart_items, hooked at priority 1), its coupon check
		// and any shop rules hooked to the same action.
		if ( ! self::passesValidation(
			static function () {
				do_action( 'woocommerce_check_cart_items' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, run on purpose to validate the cart.

				return true;
			}
		) ) {
			throw new \InvalidArgumentException( self::VALIDATION_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		$cart->calculate_totals();

		foreach ( $cart->get_cart() as $values ) {
			self::checkCartVariation(
				isset( $values['product_id'] ) ? (int) $values['product_id'] : 0,
				isset( $values['variation_id'] ) ? (int) $values['variation_id'] : 0,
				isset( $values['variation'] ) && is_array( $values['variation'] ) ? $values['variation'] : array()
			);
		}

		$package_count = $cart->needs_shipping() ? count( $cart->get_shipping_packages() ) : 0;

		$snapshot = $this->build( $cart, CartSnapshot::CONTEXT_CART, $package_count );
		if ( ! self::matchesCartTotal( $snapshot, $cart->get_total( 'edit' ), $cart->get_shipping_total(), $cart->get_shipping_tax() ) ) {
			throw new \InvalidArgumentException( self::TOTAL_MISMATCH ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return $snapshot;
	}

	/**
	 * Whether the snapshot total equals the cart total without the cart's own
	 * shipping (the wallet picks shipping), at the currency's precision.
	 *
	 * @param CartSnapshot     $snapshot       Snapshot.
	 * @param int|float|string $cart_total     WooCommerce cart total.
	 * @param int|float|string $shipping_total Shipping in that total, without tax.
	 * @param int|float|string $shipping_tax   Shipping tax in that total.
	 */
	public static function matchesCartTotal( CartSnapshot $snapshot, $cart_total, $shipping_total, $shipping_tax ): bool {
		$decimals = Money::decimals( $snapshot->currency() );

		return Money::equals( $snapshot->total(), (float) $cart_total - (float) $shipping_total - (float) $shipping_tax, $decimals );
	}

	/**
	 * Snapshot of a single product purchase.
	 *
	 * The line is priced directly with WooCommerce's price helpers for the
	 * current customer. No cart object is created, so the customer's cart is
	 * not touched. WooCommerce's add-to-cart validation filter runs as it
	 * would for this product; the notices it adds are removed again.
	 *
	 * The variation map is checked against the product (see
	 * VariationAttributes): a simple product takes none, a variation only the
	 * parent's variation attributes with values valid for that variation.
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
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			throw new \InvalidArgumentException( 'Product not found.' );
		}
		if ( '' !== (string) $product->get_post_password() ) {
			throw new \InvalidArgumentException( 'Product is password protected.' );
		}
		if ( $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			throw new \InvalidArgumentException( 'Product type is not supported.' );
		}

		$buyable    = $product;
		$attributes = array();
		if ( $product->is_type( 'variable' ) ) {
			if ( $variation_id < 1 ) {
				throw new \InvalidArgumentException( 'A variation is required.' );
			}
			$buyable = wc_get_product( $variation_id );
			if ( ! $buyable instanceof \WC_Product || $buyable->get_parent_id() !== $product_id ) {
				throw new \InvalidArgumentException( 'Variation does not belong to the product.' );
			}
			$parent     = self::variation_attributes_of( $product );
			$attributes = VariationAttributes::resolve(
				$parent['options'],
				wc_get_product_variation_attributes( $variation_id ),
				self::requested_attributes( $variation, $parent['taxonomy'] )
			);
		} elseif ( $variation_id > 0 ) {
			throw new \InvalidArgumentException( 'Product has no variations.' );
		} elseif ( array() !== $variation ) {
			throw new \InvalidArgumentException( 'Product has no variation attributes.' );
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

		$is_variable = $product->is_type( 'variable' );
		$valid       = self::passesValidation(
			static function () use ( $is_variable, $product_id, $quantity, $variation_id, $attributes ) {
				// The same argument lists WooCommerce's add-to-cart handlers use.
				return $is_variable
					? apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $attributes ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, run on purpose.
					: apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, run on purpose.
			}
		);
		if ( ! $valid ) {
			throw new \InvalidArgumentException( self::VALIDATION_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		$decimals  = Money::decimals( get_woocommerce_currency() );
		$excl      = Money::round( wc_get_price_excluding_tax( $buyable, array( 'qty' => $quantity ) ), $decimals );
		$incl      = Money::round( wc_get_price_including_tax( $buyable, array( 'qty' => $quantity ) ), $decimals );
		$tax       = Money::round( $incl - $excl, $decimals );
		$tax_class = (string) $buyable->get_tax_class();
		$shipping  = (bool) $buyable->needs_shipping();

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
	 * Runs a WooCommerce validation step and tells whether it passed: the
	 * step must not return false and must not add an error notice. Every
	 * notice is put back as it was afterwards, so nothing this check added
	 * shows on the customer's next page.
	 *
	 * @param callable $check Validation step; returns false to refuse.
	 */
	public static function passesValidation( callable $check ): bool {
		$saved  = wc_get_notices();
		$before = wc_notice_count( 'error' );
		try {
			$result = call_user_func( $check );
			$after  = wc_notice_count( 'error' );
		} finally {
			wc_set_notices( $saved );
		}

		return false !== $result && $after <= $before;
	}

	/**
	 * The parent's variation attributes: allowed values (term slugs or custom
	 * options, as WooCommerce's add to cart compares them) and whether each
	 * is a taxonomy attribute, keyed `attribute_<slug>`.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array{options: array<string, string[]>, taxonomy: array<string, bool>}
	 */
	private static function variation_attributes_of( \WC_Product $product ): array {
		$options  = array();
		$taxonomy = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key              = 'attribute_' . sanitize_title( $attribute->get_name() );
			$options[ $key ]  = array_map( 'strval', (array) $attribute->get_slugs() );
			$taxonomy[ $key ] = (bool) $attribute->is_taxonomy();
		}

		return array(
			'options'  => $options,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Checks the variation data of a cart line the way the product context
	 * does for its keys: a line without a variation carries none, a
	 * variation's keys are variation attributes of its parent. Values were
	 * checked by WooCommerce when the line was added to the cart.
	 *
	 * @param int   $product_id   Product ID of the line (the parent for variations).
	 * @param int   $variation_id Variation ID, 0 for none.
	 * @param array $variation    Variation data of the line.
	 * @throws \InvalidArgumentException If a key is not a variation attribute of the parent.
	 */
	private static function checkCartVariation( int $product_id, int $variation_id, array $variation ): void {
		if ( array() === $variation ) {
			return;
		}
		$parent  = $variation_id > 0 ? wc_get_product( $product_id ) : null;
		$allowed = $parent instanceof \WC_Product ? self::variation_attributes_of( $parent )['options'] : array();
		foreach ( array_keys( $variation ) as $key ) {
			if ( ! VariationAttributes::isAttributeKey( $key ) || ! array_key_exists( $key, $allowed ) ) {
				throw new \InvalidArgumentException( 'Cart line has variation data that is no variation attribute of the product.' );
			}
		}
	}

	/**
	 * Requested attribute values in WooCommerce's form: keys as slugs, values
	 * of taxonomy attributes as slugs, custom values cleaned and decoded.
	 * Keys that are no attribute of the parent stay as they are, so the
	 * resolver refuses them.
	 *
	 * @param array               $variation Requested attribute => value.
	 * @param array<string, bool> $taxonomy  `attribute_<slug>` => taxonomy attribute.
	 * @return array<string, string>
	 */
	private static function requested_attributes( array $variation, array $taxonomy ): array {
		$requested = array();
		foreach ( $variation as $name => $value ) {
			$key   = sanitize_title( (string) $name );
			$value = (string) $value;
			if ( isset( $taxonomy[ $key ] ) ) {
				$value = $taxonomy[ $key ] ? sanitize_title( $value ) : html_entity_decode( wc_clean( $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
			}
			$requested[ $key ] = $value;
		}

		return $requested;
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
