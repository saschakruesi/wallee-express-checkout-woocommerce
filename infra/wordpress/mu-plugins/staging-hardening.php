<?php
/**
 * Plugin Name: Staging hardening
 * Description: Staging shop only. Turns off XML-RPC, hides user listings from anonymous visitors and disables the file editor.
 *
 * Installed into wp-content/mu-plugins/ by infra/wordpress/provision-shop.sh.
 *
 * @package Wallee\ExpressCheckout
 */

defined( 'ABSPATH' ) || exit;

// No plugin or theme file editing in the admin.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// XML-RPC: refuse the endpoint outright and offer no methods.
if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
	status_header( 403 );
	exit;
}
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter( 'pings_open', '__return_false' );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
remove_action( 'wp_head', 'rsd_link' );

// User listings only for logged-in users.
add_filter(
	'rest_endpoints',
	static function ( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}
);
add_filter(
	'wp_sitemaps_add_provider',
	static function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	10,
	2
);

// No author lookup by ID or author archives for anonymous visitors.
add_action(
	'template_redirect',
	static function () {
		if ( is_user_logged_in() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
		if ( isset( $_GET['author'] ) || is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	},
	1
);
