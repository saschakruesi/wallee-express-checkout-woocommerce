<?php
/**
 * Plugin Name:       wallee Express Checkout
 * Description:       Express checkout buttons for WooCommerce, powered by wallee.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Text Domain:       wallee-express-checkout
 *
 * @package Wallee\ExpressCheckout
 */

defined( 'ABSPATH' ) || exit;

define( 'WALLEE_EC_VERSION', '0.1.0' );
define( 'WALLEE_EC_FILE', __FILE__ );
define( 'WALLEE_EC_PATH', plugin_dir_path( __FILE__ ) );

require_once WALLEE_EC_PATH . 'src/Autoloader.php';

\Wallee\ExpressCheckout\Autoloader::register( WALLEE_EC_PATH . 'src' );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WALLEE_EC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WALLEE_EC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\Wallee\ExpressCheckout\Plugin' ) ) {
			\Wallee\ExpressCheckout\Plugin::boot();
		}
	},
	21
);
