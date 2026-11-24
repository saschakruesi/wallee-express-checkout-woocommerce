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
 * Thin instance wrapper around OrderFactory, the WooCommerce checks of
 * OrderValidator
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
	 * @param CartSnapshot $snapshot   Snapshot.
	 * @param array|null   $rate       Stored rate of the selected shipping option.
	 * @param string       $uuid       Session UUID.
	 * @param string       $wallet     Wallet name.
	 * @param int          $session_id wallee session ID.
	 * @throws \RuntimeException If the order cannot be created.
	 */
	public function create( CartSnapshot $snapshot, ?array $rate, string $uuid, string $wallet, int $session_id ): \WC_Order {
		return OrderFactory::create( $snapshot, $rate, $uuid, $wallet, $session_id );
	}

	/**
	 * See OrderFactory::addNoteOnce().
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $note  Private note.
	 */
	public function addNoteOnce( \WC_Order $order, string $note ): void {
		OrderFactory::addNoteOnce( $order, $note );
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
	 * @param \WC_Order $order          Order.
	 * @param array     $transaction    Transaction with addresses expanded.
	 * @param int       $customer_id    WordPress user ID, 0 for guests.
	 * @param bool      $needs_shipping Whether anything in the order needs shipping.
	 * @return bool Whether the order now has a billing e-mail.
	 */
	public function applyCustomer( \WC_Order $order, array $transaction, int $customer_id, bool $needs_shipping = true ): bool {
		return OrderFactory::applyCustomer( $order, $transaction, $customer_id, $needs_shipping );
	}

	/**
	 * See OrderValidator::stockAndCoupons().
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 */
	public function stockAndCoupons( CartSnapshot $snapshot ): ?string {
		return OrderValidator::stockAndCoupons( $snapshot );
	}

	/**
	 * Whether WooCommerce has already counted the order's coupon usage (it
	 * does when the order becomes on-hold, processing or completed). A coupon
	 * check after that counts the order's own use.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function couponUsageRecorded( \WC_Order $order ): bool {
		$store = method_exists( $order, 'get_data_store' ) ? $order->get_data_store() : null;
		if ( ! is_object( $store ) || ! is_callable( array( $store, 'get_recorded_coupon_usage_counts' ) ) ) {
			return false;
		}

		return (bool) $store->get_recorded_coupon_usage_counts( $order );
	}

	/**
	 * See CouponHold::hold(), for the snapshot's coupons.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param \WC_Order    $order    Order with the customer's e-mail applied.
	 * @return array{0: string, 1: string} CouponHold outcome and detail.
	 */
	public function holdCoupons( CartSnapshot $snapshot, \WC_Order $order ): array {
		return CouponHold::hold( $snapshot->coupons(), $order );
	}

	/**
	 * See OrderValidator::customerCoupons().
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @param string       $email    Billing e-mail of the order.
	 */
	public function customerCoupons( CartSnapshot $snapshot, string $email ): ?string {
		return OrderValidator::customerCoupons( $snapshot, $email );
	}
}
