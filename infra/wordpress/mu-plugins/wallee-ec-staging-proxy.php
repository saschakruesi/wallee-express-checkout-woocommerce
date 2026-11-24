<?php
/**
 * Plugin Name: wallee Express Checkout staging: client address behind CloudFront
 * Description: Staging shop only. The shop accepts requests from CloudFront only, so REMOTE_ADDR is a CloudFront address; the viewer's address comes from the CloudFront-Viewer-Address header.
 *
 * Installed into wp-content/mu-plugins/ by infra/wordpress/provision-shop.sh.
 *
 * @package Wallee\ExpressCheckout
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wallee_ec_client_ip',
	static function ( $ip ) {
		// "<address>:<port>"; IPv6 addresses are not bracketed, so the port follows the last colon.
		$header = isset( $_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'] ) ? (string) wp_unslash( $_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP address below.
		$colon  = strrpos( $header, ':' );
		$viewer = false === $colon ? $header : substr( $header, 0, $colon );

		return false !== filter_var( $viewer, FILTER_VALIDATE_IP ) ? $viewer : $ip;
	}
);
