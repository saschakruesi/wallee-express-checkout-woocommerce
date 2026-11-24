<?php
/**
 * WooCommerce order operations used by the return and result handlers.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\Cart\CartSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Thin instance wrapper around OrderFactory, OrderValidator::stockAndCoupons
 * and wc_get_order, so the handlers can be unit tested with a double. Holds
 * no logic of its own.
 */
class OrderService {

	/**
	 * Loads an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function find( int $order_id ): ?\WC_Order {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * See OrderFactory::create().
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param array|null   $rate     Stored rate of the selected shipping option.
	 * @param string       $uuid     Session UUID.
	 * @param string       $wallet   Wallet name.
	 * @throws \RuntimeException If the order cannot be created.
	 */
	public function create( CartSnapshot $snapshot, ?array $rate, string $uuid, string $wallet ): \WC_Order {
		return OrderFactory::create( $snapshot, $rate, $uuid, $wallet );
	}

	/**
	 * See OrderFactory::fail().
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason code.
	 */
	public function fail( \WC_Order $order, string $reason ): void {
		OrderFactory::fail( $order, $reason );
	}

	/**
	 * See OrderFactory::applyCustomer().
	 *
	 * @param \WC_Order   $order         Order.
	 * @param array       $transaction   Transaction with addresses expanded.
	 * @param int         $customer_id   WordPress user ID, 0 for guests.
	 * @param string|null $quote_country Country the shipping rate was calculated for.
	 */
	public function applyCustomer( \WC_Order $order, array $transaction, int $customer_id, ?string $quote_country ): void {
		OrderFactory::applyCustomer( $order, $transaction, $customer_id, $quote_country );
	}

	/**
	 * See OrderValidator::stockAndCoupons().
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $email    E-mail, empty if unknown.
	 */
	public function stockAndCoupons( CartSnapshot $snapshot, string $email ): ?string {
		return OrderValidator::stockAndCoupons( $snapshot, $email );
	}
}
