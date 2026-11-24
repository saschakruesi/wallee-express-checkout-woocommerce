<?php
/**
 * Minimal stand-in for WordPress' wpdb class, so the type can be declared in unit tests.
 *
 * @package Wallee\ExpressCheckout
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'wpdb', false ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting.ClassComment -- Test stub for a WordPress core class.
	class wpdb {
	}
}
