<?php
/**
 * Express checkout container on the cart page (classic and block cart).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Frontend;

use Wallee\ExpressCheckout\Admin\Settings;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Cart page output.
 */
final class CartButtons {

	const BLOCK = 'woocommerce/proceed-to-checkout-block';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Returns whether the add-on can work.
	 *
	 * @var callable
	 */
	private $is_active;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings  Settings.
	 * @param callable $is_active Returns whether the add-on can work.
	 */
	public function __construct( Settings $settings, callable $is_active ) {
		$this->settings  = $settings;
		$this->is_active = $is_active;
	}

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		$instance = new self( Plugin::settings(), array( Plugin::class, 'isActive' ) );
		add_action( 'wp_enqueue_scripts', array( $instance, 'enqueue' ) );
		add_action( 'woocommerce_proceed_to_checkout', array( $instance, 'render' ), 20 );
		add_filter( 'render_block', array( $instance, 'filterBlock' ), 10, 2 );
	}

	/**
	 * Whether the cart buttons are on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->settings->cartEnabled() && (bool) call_user_func( $this->is_active );
	}

	/**
	 * Enqueues the scripts on the cart page.
	 */
	public function enqueue(): void {
		if ( $this->enabled() && is_cart() ) {
			Assets::enqueue( CartSnapshot::CONTEXT_CART );
		}
	}

	/**
	 * Prints the container (classic cart).
	 */
	public function render(): void {
		if ( $this->enabled() && is_cart() && Assets::claim( CartSnapshot::CONTEXT_CART ) ) {
			echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Assets::containerHtml.
		}
	}

	/**
	 * Appends the container to the proceed-to-checkout block (block cart).
	 *
	 * @param mixed $content Rendered block.
	 * @param mixed $block   Parsed block.
	 * @return string
	 */
	public function filterBlock( $content, $block = array() ): string {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || self::BLOCK !== $block['blockName'] || ! $this->enabled() || ! is_cart() ) {
			return (string) $content;
		}
		$markup = $this->markup();
		$result = Assets::appendToBlock( $content, $block, self::BLOCK, $markup, CartSnapshot::CONTEXT_CART );
		if ( $result !== (string) $content ) {
			if ( ! Assets::claim( CartSnapshot::CONTEXT_CART ) ) {
				return (string) $content;
			}
		}

		return $result;
	}

	/**
	 * The container markup.
	 *
	 * @return string
	 */
	private function markup(): string {
		return Assets::containerHtml( CartSnapshot::CONTEXT_CART, Assets::termsPageId() );
	}
}
