<?php
/**
 * Links an express checkout order to the wallee main plugin.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Order;

use Wallee\ExpressCheckout\MainPluginAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the main plugin's transaction info row for the order and replaces
 * the provisional payment method by the main plugin's gateway (design 5.5,
 * amendment 1 "Linking to the main plugin"). Idempotent. When and how often
 * it is tried is LinkScheduler's business.
 */
final class OrderLinker {

	const META_UNRESOLVED = '_wallee_ec_gateway_unresolved';

	/**
	 * Transaction states in which the payment connector is known for good:
	 * an unresolved gateway then needs the merchant's attention.
	 *
	 * @var string[]
	 */
	const FINAL_STATES = array( 'AUTHORIZED', 'COMPLETED', 'FULFILL' );

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
	 * Links the order to the transaction.
	 *
	 * An unresolved gateway only gets the admin note and flag once the
	 * transaction is in a final authorised or completed state, or on the last
	 * attempt; before that it means "try again later". A failing read gets
	 * them on the last attempt only.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $transaction_id wallee transaction ID.
	 * @param bool      $last_attempt   Whether no automatic retry follows.
	 * @return bool Whether the order now carries the main plugin's gateway.
	 */
	public function link( \WC_Order $order, int $transaction_id, bool $last_attempt = false ): bool {
		try {
			$transaction = $this->adapter->readTransaction( $transaction_id );
			$this->adapter->linkOrder( $transaction, $order );
			$gateway = $this->adapter->resolveGatewayId( $transaction );
			$state   = $this->adapter->transactionState( $transaction );
		} catch ( \Throwable $e ) {
			$this->adapter->log( sprintf( 'Order %d: linking transaction %d failed (%s).', $order->get_id(), $transaction_id, get_class( $e ) ), 'error' );
			if ( $last_attempt ) {
				$this->markUnresolved( $order, false );
			}

			return false;
		}

		if ( null === $gateway ) {
			$final = in_array( $state, self::FINAL_STATES, true );
			if ( $final || $last_attempt ) {
				$this->markUnresolved( $order, true );
				$this->adapter->log( sprintf( 'Order %d: gateway of transaction %d (state %s) unresolved.', $order->get_id(), $transaction_id, $state ), 'error' );
			} else {
				$this->adapter->log( sprintf( 'Order %d: gateway of transaction %d not known yet (state %s); retried later.', $order->get_id(), $transaction_id, '' === $state ? 'unknown' : $state ) );
			}

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
	 * Flags the order as not linked and adds the admin note, once.
	 *
	 * @param \WC_Order $order            Order.
	 * @param bool      $transaction_read Whether the transaction was read (only its gateway is unknown).
	 */
	public function markUnresolved( \WC_Order $order, bool $transaction_read ): void {
		if ( '' !== (string) $order->get_meta( self::META_UNRESOLVED ) ) {
			return;
		}
		$order->add_order_note(
			$transaction_read
				? __( 'wallee Express Checkout: the payment method of this transaction could not be matched to a wallee payment gateway in WooCommerce. Until it is, please complete and refund this order in the wallee portal.', 'wallee-express-checkout' )
				: __( 'wallee Express Checkout: this order could not be linked to its wallee transaction. Until it is, please complete and refund this order in the wallee portal.', 'wallee-express-checkout' )
		);
		$order->update_meta_data( self::META_UNRESOLVED, 'yes' );
		$order->save();
		$this->adapter->log( sprintf( 'Order %d: marked as not linked to the wallee plugin.', $order->get_id() ), 'error' );
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
