<?php
/**
 * Decides whether express buttons may be shown for a snapshot.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the add-on's refusal rules (design 5.7).
 */
final class Eligibility {

	/**
	 * Whether the main plugin is configured.
	 *
	 * @var bool
	 */
	private $configured;

	/**
	 * Whether WooCommerce allows guest checkout.
	 *
	 * @var bool
	 */
	private $guest_checkout_allowed;

	/**
	 * Whether the customer is logged in.
	 *
	 * @var bool
	 */
	private $logged_in;

	/**
	 * Whether buttons may show when nothing needs shipping.
	 *
	 * @var bool
	 */
	private $allow_without_shipping;

	/**
	 * Constructor.
	 *
	 * @param bool $configured             Main plugin is configured.
	 * @param bool $guest_checkout_allowed Guest checkout is enabled.
	 * @param bool $logged_in              Customer is logged in.
	 * @param bool $allow_without_shipping Buttons may show when nothing needs shipping (default true).
	 */
	public function __construct( bool $configured, bool $guest_checkout_allowed, bool $logged_in, bool $allow_without_shipping = true ) {
		$this->configured             = $configured;
		$this->guest_checkout_allowed = $guest_checkout_allowed;
		$this->logged_in              = $logged_in;
		$this->allow_without_shipping = $allow_without_shipping;
	}

	/**
	 * Reads the outside facts from WooCommerce and WordPress.
	 *
	 * @param bool $configured Main plugin is configured.
	 */
	public static function fromEnvironment( bool $configured ): self {
		$guest_checkout = ( 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ) );

		/**
		 * Whether express buttons may show when nothing in the cart (or the
		 * viewed product) needs shipping. On by default (owner decision): the
		 * wallet then asks for no delivery address. Return false to hide the
		 * buttons for such carts (for example while testing with wallee's
		 * Bogus Pay test wallet, whose sheet fails for such sessions).
		 *
		 * @param bool $allow Default true.
		 */
		$allow = (bool) apply_filters( 'wallee_ec_allow_without_shipping', true );

		return new self( $configured, $guest_checkout, is_user_logged_in(), $allow );
	}

	/**
	 * Why buttons must not be shown, or null when they may be.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @return string|null One of empty, no_shipping_needed, subscription, multiple_packages, guest_checkout_disabled, not_configured.
	 */
	public function reason( CartSnapshot $snapshot ): ?string {
		if ( array() === $snapshot->items() ) {
			return 'empty';
		}
		if ( ! $this->allow_without_shipping && ! $snapshot->needsShipping() ) {
			return 'no_shipping_needed';
		}
		foreach ( $snapshot->items() as $item ) {
			if ( $item['is_subscription'] ) {
				return 'subscription';
			}
		}
		if ( $snapshot->shippingPackageCount() > 1 ) {
			return 'multiple_packages';
		}
		if ( ! $this->guest_checkout_allowed && ! $this->logged_in ) {
			return 'guest_checkout_disabled';
		}
		if ( ! $this->configured ) {
			return 'not_configured';
		}

		return null;
	}
}
