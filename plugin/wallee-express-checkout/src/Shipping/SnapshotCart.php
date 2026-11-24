<?php
/**
 * Read-only stand-in for WooCommerce's cart, built from a cart snapshot.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Shipping;

use Wallee\ExpressCheckout\Cart\CartSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Some shipping code reads `WC()->cart` while it calculates: the free shipping
 * method (coupons, minimum amount) and the shipping tax class. The callback
 * request has no cart, so this object answers those questions from the
 * snapshot. It is never persisted: no session, no hooks, no totals calculation.
 */
final class SnapshotCart extends \WC_Cart {

	/**
	 * Snapshot.
	 *
	 * @var CartSnapshot
	 */
	private $snapshot;

	/**
	 * Cart items in WooCommerce's shape.
	 *
	 * @var array
	 */
	private $lines = array();

	/**
	 * Coupons by code.
	 *
	 * @var \WC_Coupon[]
	 */
	private $coupon_objects = array();

	/**
	 * Intentionally does not call the parent constructor: that one registers
	 * hooks and a session handler.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 */
	public function __construct( CartSnapshot $snapshot ) {
		$this->snapshot = $snapshot;

		foreach ( $snapshot->items() as $item ) {
			$product = wc_get_product( $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$this->lines[ $item['key'] ] = array(
				'key'               => $item['key'],
				'product_id'        => $item['product_id'],
				'variation_id'      => $item['variation_id'],
				'variation'         => $item['variation'],
				'quantity'          => $item['quantity'],
				'data'              => $product,
				'line_tax_data'     => array(
					'subtotal' => array(),
					'total'    => array(),
				),
				'line_subtotal'     => $item['line_subtotal'],
				'line_subtotal_tax' => $item['line_subtotal_tax'],
				'line_total'        => $item['line_total'],
				'line_tax'          => $item['line_tax'],
				'needs_shipping'    => $item['needs_shipping'],
				'tax_class'         => $item['tax_class'],
			);
		}

		foreach ( $snapshot->coupons() as $code ) {
			$this->coupon_objects[ wc_format_coupon_code( $code ) ] = new \WC_Coupon( $code );
		}
	}

	/**
	 * Items that need shipping, in cart item shape.
	 *
	 * @return array
	 */
	public function shippableLines(): array {
		return array_filter(
			$this->lines,
			static function ( $line ) {
				return $line['needs_shipping'];
			}
		);
	}

	/**
	 * Cart items.
	 */
	public function get_cart() {
		return $this->lines;
	}

	/**
	 * Cart items.
	 */
	public function get_cart_contents() {
		return $this->lines;
	}

	/**
	 * Applied coupons.
	 *
	 * @param mixed $deprecated Unused.
	 */
	public function get_coupons( $deprecated = null ) {
		return $this->coupon_objects;
	}

	/**
	 * No customer data is known here; an empty, unsaved customer.
	 */
	public function get_customer() {
		return WC()->customer;
	}

	/**
	 * Whether prices are displayed including tax in the cart.
	 */
	public function display_prices_including_tax() {
		return 'incl' === get_option( 'woocommerce_tax_display_cart' );
	}

	/**
	 * Subtotal as displayed (before discounts).
	 */
	public function get_displayed_subtotal() {
		$total = 0.0;
		foreach ( $this->lines as $line ) {
			$total += $line['line_subtotal'];
			if ( $this->display_prices_including_tax() ) {
				$total += $line['line_subtotal_tax'];
			}
		}

		return $total;
	}

	/**
	 * Discount, excluding tax.
	 */
	public function get_discount_total() {
		$total = 0.0;
		foreach ( $this->lines as $line ) {
			$total += $line['line_subtotal'] - $line['line_total'];
		}

		return $total;
	}

	/**
	 * Tax of the discount.
	 */
	public function get_discount_tax() {
		$total = 0.0;
		foreach ( $this->lines as $line ) {
			$total += $line['line_subtotal_tax'] - $line['line_tax'];
		}

		return $total;
	}

	/**
	 * Tax classes of the taxable items that need shipping, as WooCommerce's
	 * cart reports them.
	 */
	public function get_cart_item_tax_classes_for_shipping() {
		$classes = array();
		foreach ( $this->shippableLines() as $line ) {
			if ( $line['data']->is_taxable() ) {
				$classes[] = $line['tax_class'];
			}
		}

		return array_values( array_unique( $classes ) );
	}
}
