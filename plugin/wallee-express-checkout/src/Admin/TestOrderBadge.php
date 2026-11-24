<?php
/**
 * Marks express orders of the TEST environment in the admin.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Admin;

use Wallee\ExpressCheckout\Order\OrderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * An "Express TEST" badge in a column next to the status of the order list
 * (HPOS and the legacy posts list) and on the order edit screen, for express
 * orders whose session ran in wallee's TEST environment (order meta
 * `_wallee_ec_environment`). The payment method title is not touched.
 */
final class TestOrderBadge {

	const COLUMN = 'wallee_ec_express';

	/**
	 * Registers the list column and the edit screen notice.
	 */
	public static function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'addColumn' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'renderColumn' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( self::class, 'addColumn' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'renderColumn' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'renderEditNotice' ) );
	}

	/**
	 * Adds the column right after the status column (at the end without one).
	 *
	 * @param mixed $columns Columns of the order list.
	 * @return mixed
	 */
	public static function addColumn( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$label  = __( 'Express checkout', 'wallee-express-checkout' );
		$result = array();
		foreach ( $columns as $key => $title ) {
			$result[ $key ] = $title;
			if ( 'order_status' === $key ) {
				$result[ self::COLUMN ] = $label;
			}
		}
		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = $label;
		}

		return $result;
	}

	/**
	 * Prints the column: the TEST badge, "Express" for other express orders,
	 * nothing for any other order.
	 *
	 * @param mixed $column Column key.
	 * @param mixed $order  Order (HPOS list) or post ID (legacy list).
	 */
	public static function renderColumn( $column, $order ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = self::order( $order );
		if ( null === $order || OrderFactory::CREATED_VIA !== $order->get_created_via() ) {
			return;
		}
		if ( self::isTest( $order ) ) {
			echo self::badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

			return;
		}
		echo esc_html( __( 'Express', 'wallee-express-checkout' ) );
	}

	/**
	 * Prints the badge and a warning on the edit screen of a TEST order.
	 *
	 * @param mixed $order Order.
	 */
	public static function renderEditNotice( $order ): void {
		$order = self::order( $order );
		if ( null === $order || ! self::isTest( $order ) ) {
			return;
		}
		printf(
			'<p class="form-field form-field-wide wallee-ec-test-notice">%1$s %2$s</p>',
			self::badge(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			esc_html( __( 'Test payment in the wallee TEST environment. No real money was moved. Do not ship this order.', 'wallee-express-checkout' ) )
		);
	}

	/**
	 * Whether an order is an express order made in the TEST environment.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function isTest( \WC_Order $order ): bool {
		return OrderFactory::CREATED_VIA === $order->get_created_via()
			&& 'TEST' === $order->get_meta( OrderFactory::META_ENVIRONMENT );
	}

	/**
	 * Badge markup in WooCommerce's order status style.
	 */
	private static function badge(): string {
		return sprintf(
			'<mark class="order-status status-on-hold wallee-ec-test" title="%1$s"><span>%2$s</span></mark>',
			esc_attr( __( 'Test payment in the wallee TEST environment. No real money was moved.', 'wallee-express-checkout' ) ),
			esc_html( __( 'Express TEST', 'wallee-express-checkout' ) )
		);
	}

	/**
	 * The order for a hook argument: an order, or the ID of one.
	 *
	 * @param mixed $order Order or ID.
	 */
	private static function order( $order ): ?\WC_Order {
		if ( is_numeric( $order ) && (int) $order > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order );
		}

		return $order instanceof \WC_Order ? $order : null;
	}
}
