<?php
/**
 * Removes all plugin data on uninstall. Does not rely on the plugin's autoloader.
 *
 * @package Wallee\ExpressCheckout
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- Schema removal on uninstall; table name is the prefix plus a constant.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wallee_ec_session' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- Schema removal on uninstall; table name is the prefix plus a constant.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wallee_ec_rate' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes every option of this plugin on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'wallee_ec_' ) . '%',
		$wpdb->esc_like( '_transient_wallee_ec_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wallee_ec_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'wallee_ec_cleanup' );

// Pending background links of orders (Action Scheduler, bundled with WooCommerce).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wallee_ec_link_order', array(), 'wallee-ec' );
}
