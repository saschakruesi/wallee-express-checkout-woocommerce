<?php
/**
 * Decides whether express buttons may be shown for a snapshot.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the prototype's refusal rules (design 5.7).
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
	 * Constructor.
	 *
	 * @param bool $configured             Main plugin is configured.
	 * @param bool $guest_checkout_allowed Guest checkout is enabled.
	 * @param bool $logged_in              Customer is logged in.
	 */
	public function __construct( bool $configured, bool $guest_checkout_allowed, bool $logged_in ) {
		$this->configured             = $configured;
		$this->guest_checkout_allowed = $guest_checkout_allowed;
		$this->logged_in              = $logged_in;
	}

	/**
	 * Reads the outside facts from WooCommerce and WordPress.
	 *
	 * @param bool $configured Main plugin is configured.
	 */
	public static function fromEnvironment( bool $configured ): self {
		$guest_checkout = ( 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ) );

		return new self( $configured, $guest_checkout, is_user_logged_in() );
	}

	/**
	 * Why buttons must not be shown, or null when they may be.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @return string|null One of empty, subscription, multiple_packages, guest_checkout_disabled, not_configured.
	 */
	public function reason( CartSnapshot $snapshot ): ?string {
		if ( array() === $snapshot->items() ) {
			return 'empty';
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
