<?php
/**
 * Express checkout container on single product pages.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Frontend;

use Wallee\ExpressCheckout\Admin\Settings;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Product page output.
 */
final class ProductButtons {

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
		add_action( 'woocommerce_after_add_to_cart_button', array( $instance, 'render' ) );
	}

	/**
	 * Whether the product buttons are on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->settings->productEnabled() && (bool) call_user_func( $this->is_active );
	}

	/**
	 * Enqueues the scripts on product pages.
	 */
	public function enqueue(): void {
		if ( $this->enabled() && is_product() ) {
			Assets::enqueue( CartSnapshot::CONTEXT_PRODUCT );
		}
	}

	/**
	 * Prints the container for simple and variable products.
	 */
	public function render(): void {
		global $product;
		if ( ! $this->enabled() || ! $product instanceof \WC_Product ) {
			return;
		}
		$type = $product->get_type();
		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			return;
		}

		$html = Assets::containerHtml(
			CartSnapshot::CONTEXT_PRODUCT,
			Assets::termsPageId(),
			array(
				'product-id'   => $product->get_id(),
				'product-type' => $type,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Assets::containerHtml.
	}
}
