<?php
/**
 * Plugin Name:       wallee Express Checkout
 * Plugin URI:        https://wallee.com
 * Description:       Express checkout buttons (Apple Pay, PayPal) for WooCommerce, powered by wallee.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            wallee AG
 * Author URI:        https://www.wallee.com
 * Requires Plugins:  woocommerce, woo-wallee
 * WC requires at least: 8.0
 * WC tested up to:   11.0.1
 * Text Domain:       wallee-express-checkout
 * License:           Apache-2.0
 * License URI:       http://www.apache.org/licenses/LICENSE-2.0
 *
 * @package Wallee\ExpressCheckout
 */

defined( 'ABSPATH' ) || exit;

define( 'WALLEE_EC_VERSION', '1.0.1' );
define( 'WALLEE_EC_FILE', __FILE__ );
define( 'WALLEE_EC_PATH', plugin_dir_path( __FILE__ ) );

require_once WALLEE_EC_PATH . 'src/Autoloader.php';

\Wallee\ExpressCheckout\Autoloader::register( WALLEE_EC_PATH . 'src' );

// Load the SDK compatibility shim now, before the main plugin registers its SDK autoloader.
\Wallee\ExpressCheckout\Compat\SdkShim::install();

register_activation_hook(
	WALLEE_EC_FILE,
	static function () {
		\Wallee\ExpressCheckout\Session\Installer::install();
	}
);

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
