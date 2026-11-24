<?php
/**
 * Links an express checkout order to the wallee main plugin.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\MainPluginAdapter;
use Wallee\ExpressCheckout\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the main plugin's transaction info row for the order and replaces
 * the provisional payment method by the main plugin's gateway (design 5.5,
 * amendment 1 "Linking to the main plugin"). Idempotent.
 */
final class OrderLinker {

	const META_UNRESOLVED = '_wallee_ec_gateway_unresolved';

	/**
	 * Main plugin adapter.
	 *
	 * @var MainPluginAdapter
	 */
	private $adapter;

	/**
	 * Constructor.
	 *
	 * @param MainPluginAdapter $adapter Main plugin adapter.
	 */
	public function __construct( MainPluginAdapter $adapter ) {
		$this->adapter = $adapter;
	}

	/**
	 * Retries an unresolved gateway when an order is viewed in the admin.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_admin_order_data_after_order_details',
			static function ( $order ) {
				try {
					( new self( Plugin::adapter() ) )->retry( $order );
				} catch ( \Throwable $e ) {
					Plugin::adapter()->log( sprintf( 'Admin retry of the gateway link failed (%s).', get_class( $e ) ), 'error' );
				}
			}
		);
	}

	/**
	 * Links the order to the transaction.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $transaction_id wallee transaction ID.
	 * @return bool Whether the order now carries the main plugin's gateway.
	 */
	public function link( \WC_Order $order, int $transaction_id ): bool {
		try {
			$transaction = $this->adapter->readTransaction( $transaction_id );
			$this->adapter->linkOrder( $transaction, $order );
			$gateway = $this->adapter->resolveGatewayId( $transaction );
		} catch ( \Throwable $e ) {
			$this->adapter->log( sprintf( 'Order %d: linking transaction %d failed (%s).', $order->get_id(), $transaction_id, get_class( $e ) ), 'error' );

			return false;
		}

		if ( null === $gateway ) {
			if ( '' === (string) $order->get_meta( self::META_UNRESOLVED ) ) {
				$order->add_order_note( __( 'wallee Express Checkout: the payment method of this transaction could not be matched to a wallee payment gateway in WooCommerce. Until it is, please complete and refund this order in the wallee portal.', 'wallee-express-checkout' ) );
				$order->update_meta_data( self::META_UNRESOLVED, 'yes' );
				$order->save();
			}
			$this->adapter->log( sprintf( 'Order %d: gateway of transaction %d unresolved.', $order->get_id(), $transaction_id ), 'error' );

			return false;
		}

		$title   = $this->gatewayTitle( $gateway, $order->get_payment_method_title() );
		$changed = false;
		if ( $order->get_payment_method() !== $gateway || $order->get_payment_method_title() !== $title ) {
			$order->set_payment_method( $gateway );
			$order->set_payment_method_title( $title );
			$changed = true;
		}
		if ( '' !== (string) $order->get_meta( self::META_UNRESOLVED ) ) {
			$order->delete_meta_data( self::META_UNRESOLVED );
			$order->add_order_note(
				sprintf(
					/* translators: %s: payment gateway title. */
					__( 'wallee Express Checkout: the payment method is now matched to "%s"; the order can be handled as usual.', 'wallee-express-checkout' ),
					$title
				)
			);
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
		$this->adapter->log( sprintf( 'Order %d: linked to transaction %d, gateway %s.', $order->get_id(), $transaction_id, $gateway ) );

		return true;
	}

	/**
	 * Admin order view: retries the link for an order whose gateway was
	 * unresolved. Does nothing (and no request) for any other order.
	 *
	 * @param mixed $order Order passed by the hook.
	 */
	public function retry( $order ): void {
		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( self::META_UNRESOLVED ) ) {
			return;
		}
		$transaction_id = (int) $order->get_meta( CustomerDetails::META_TRANSACTION );
		if ( $transaction_id <= 0 ) {
			return;
		}
		$this->link( $order, $transaction_id );
	}

	/**
	 * Title of a registered gateway; the fallback when the gateway is not in
	 * this request's registry (it was registered by the synchronisation).
	 *
	 * @param string $gateway  Gateway ID.
	 * @param string $fallback Title to keep otherwise.
	 */
	private function gatewayTitle( string $gateway, string $fallback ): string {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways[ $gateway ] ) && is_object( $gateways[ $gateway ] ) && method_exists( $gateways[ $gateway ], 'get_title' ) ) {
			$title = (string) $gateways[ $gateway ]->get_title();
			if ( '' !== $title ) {
				return $title;
			}
		}

		return $fallback;
	}
}
