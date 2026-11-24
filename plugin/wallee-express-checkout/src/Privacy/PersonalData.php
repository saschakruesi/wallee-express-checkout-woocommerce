<?php
/**
 * Personal data exporter, eraser and privacy policy text.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Privacy;

use Wallee\ExpressCheckout\Order\CustomerDetails;
use Wallee\ExpressCheckout\Order\FailedOrderSettlement;
use Wallee\ExpressCheckout\Order\OrderFactory;
use Wallee\ExpressCheckout\Session\Installer;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the add-on's own data into WordPress' privacy tools (Tools > Export
 * Personal Data / Erase Personal Data).
 *
 * A person is found through their orders, as WooCommerce does it: orders with
 * their billing e-mail or of the user account with that e-mail. Of these,
 * only express orders carry add-on data: rows of the session table and the
 * add-on's order meta. The order itself (addresses, e-mail) is WooCommerce's
 * business and handled by WooCommerce's own exporter and eraser.
 *
 * The eraser deletes session rows that are finished (completed, failed). A
 * row of a payment that may still be in progress is kept, with a message; the
 * daily cleanup deletes it with every other row 7 days after its creation.
 * Orders and order meta are never deleted here.
 */
final class PersonalData {

	const ID        = 'wallee-express-checkout';
	const PAGE_SIZE = 10;

	/**
	 * Session states in which a row is no longer needed by any flow.
	 *
	 * @var string[]
	 */
	const ERASABLE_STATES = array( SessionRecord::COMPLETED, SessionRecord::FAILED );

	/**
	 * Session storage.
	 *
	 * @var SessionRepository
	 */
	private $repository;

	/**
	 * Finds a person's orders: function ( string $email, int $page ): \WC_Order[].
	 *
	 * @var callable
	 */
	private $find_orders;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $repository  Session storage.
	 * @param callable          $find_orders function ( string $email, int $page ): \WC_Order[], PAGE_SIZE per page.
	 */
	public function __construct( SessionRepository $repository, callable $find_orders ) {
		$this->repository  = $repository;
		$this->find_orders = $find_orders;
	}

	/**
	 * Registers exporter, eraser and the policy text.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'addExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'addEraser' ) );
		add_action( 'admin_init', array( self::class, 'addPolicyContent' ) );
	}

	/**
	 * `wp_privacy_personal_data_exporters`.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array
	 */
	public static function addExporter( $exporters ): array {
		$exporters             = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'wallee Express Checkout', 'wallee-express-checkout' ),
			'callback'               => static function ( $email, $page = 1 ) {
				return self::fromWordPress()->export( (string) $email, (int) $page );
			},
		);

		return $exporters;
	}

	/**
	 * `wp_privacy_personal_data_erasers`.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array
	 */
	public static function addEraser( $erasers ): array {
		$erasers             = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'wallee Express Checkout', 'wallee-express-checkout' ),
			'callback'             => static function ( $email, $page = 1 ) {
				return self::fromWordPress()->erase( (string) $email, (int) $page );
			},
		);

		return $erasers;
	}

	/**
	 * `admin_init`: suggests a section for the site's privacy policy.
	 */
	public static function addPolicyContent(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'wallee Express Checkout', 'wallee-express-checkout' ), wp_kses_post( self::policyText() ) );
	}

	/**
	 * Suggested privacy policy text (HTML paragraphs).
	 */
	public static function policyText(): string {
		$paragraphs = array(
			__( 'When you pay with an express checkout button (for example Apple Pay), the payment is processed by wallee. To show the button and to calculate prices, shipping and taxes, we send wallee the contents of your cart and the amounts. The wallet then sends wallee your delivery address, so that we can offer shipping options, and after your approval your name, addresses and e-mail address, which we copy into your order.', 'wallee-express-checkout' ),
			/* translators: %d: number of days. */
			sprintf( __( 'For each express checkout we store a record with the cart contents, the location used to calculate taxes (country, state, postcode, city), the delivery destination chosen in the wallet, the payment session and transaction numbers at wallee and the order number. The record is deleted automatically %d days after it was created. Your order and its payment details are kept like any other order.', 'wallee-express-checkout' ), Installer::RETENTION_DAYS ),
			__( 'To protect the service against abuse we count requests per network address; the counter stores a one-way hash of the address and is removed by the daily cleanup once its one-minute window is over. Technical logs contain order, session and transaction numbers and states only.', 'wallee-express-checkout' ),
		);

		return '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>';
	}

	/**
	 * An instance wired to WordPress.
	 */
	public static function fromWordPress(): self {
		global $wpdb;

		return new self( new SessionRepository( $wpdb ), array( self::class, 'findOrders' ) );
	}

	/**
	 * Orders of a person: billing e-mail, or the user account with that e-mail
	 * (WooCommerce's own exporter uses the same query).
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, from 1.
	 * @return \WC_Order[]
	 */
	public static function findOrders( string $email, int $page ): array {
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$customer = array( $email );
		$user     = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$customer[] = (int) $user->ID;
		}
		$orders = wc_get_orders(
			array(
				'limit'    => self::PAGE_SIZE,
				'page'     => max( 1, $page ),
				'customer' => $customer,
				'type'     => 'shop_order',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( string $email, int $page ): array {
		$orders = call_user_func( $this->find_orders, $email, max( 1, $page ) );
		$data   = array();
		foreach ( self::expressOrders( $orders ) as $order ) {
			foreach ( $this->repository->rowsForOrder( (int) $order->get_id() ) as $row ) {
				$data[] = self::rowItem( $order, $row );
			}
			$meta = self::metaItem( $order );
			if ( null !== $meta ) {
				$data[] = $meta;
			}
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, from 1.
	 * @return array{items_removed: int, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page ): array {
		$orders   = call_user_func( $this->find_orders, $email, max( 1, $page ) );
		$removed  = 0;
		$messages = array();
		foreach ( self::expressOrders( $orders ) as $order ) {
			$order_id = (int) $order->get_id();
			$removed += $this->repository->deleteForOrder( $order_id, self::ERASABLE_STATES );
			if ( array() !== $this->repository->rowsForOrder( $order_id ) ) {
				$messages[] = sprintf(
					/* translators: 1: order number, 2: number of days. */
					__( 'wallee Express Checkout: the session record of order %1$s is kept because its payment may still be in progress. It is deleted automatically %2$d days after it was created.', 'wallee-express-checkout' ),
					$order->get_order_number(),
					Installer::RETENTION_DAYS
				);
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => array() !== $messages,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * The express orders among a person's orders.
	 *
	 * @param mixed $orders Orders.
	 * @return \WC_Order[]
	 */
	private static function expressOrders( $orders ): array {
		$express = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof \WC_Order && OrderFactory::CREATED_VIA === $order->get_created_via() ) {
				$express[] = $order;
			}
		}

		return $express;
	}

	/**
	 * Export item for a session row.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row from SessionRepository::rowsForOrder().
	 */
	private static function rowItem( \WC_Order $order, array $row ): array {
		$fields = array(
			__( 'Order', 'wallee-express-checkout' )   => (string) $order->get_order_number(),
			__( 'Created', 'wallee-express-checkout' ) => $row['created_at'] . ' UTC',
			__( 'State', 'wallee-express-checkout' )   => $row['state'],
			__( 'wallee session ID', 'wallee-express-checkout' ) => (string) $row['wallee_session_id'],
			__( 'wallee transaction ID', 'wallee-express-checkout' ) => null === $row['transaction_id'] ? '' : (string) $row['transaction_id'],
			__( 'Tax location used for the prices', 'wallee-express-checkout' ) => self::place( $row['tax_location'] ),
			__( 'Delivery destination chosen in the wallet', 'wallee-express-checkout' ) => self::place( $row['destination'] ),
		);

		return array(
			'group_id'    => 'wallee-express-checkout-sessions',
			'group_label' => __( 'wallee Express Checkout sessions', 'wallee-express-checkout' ),
			'item_id'     => 'wallee-express-checkout-session-' . $row['id'],
			'data'        => self::pairs( $fields ),
		);
	}

	/**
	 * Export item for the add-on's meta of an order, null if it has none.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function metaItem( \WC_Order $order ): ?array {
		$keys   = array(
			OrderFactory::META_WALLET          => __( 'Wallet', 'wallee-express-checkout' ),
			OrderFactory::META_ENVIRONMENT     => __( 'Environment', 'wallee-express-checkout' ),
			OrderFactory::META_SESSION_ID      => __( 'wallee session ID', 'wallee-express-checkout' ),
			CustomerDetails::META_TRANSACTION  => __( 'wallee transaction ID', 'wallee-express-checkout' ),
			FailedOrderSettlement::META_REASON => __( 'Reason the order failed', 'wallee-express-checkout' ),
			FailedOrderSettlement::META_VOID   => __( 'Void requested', 'wallee-express-checkout' ),
			FailedOrderSettlement::META_REFUND => __( 'Refund required', 'wallee-express-checkout' ),
		);
		$fields = array();
		foreach ( $keys as $key => $label ) {
			$value = $order->get_meta( $key );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$fields[ $label ] = (string) $value;
			}
		}
		if ( array() === $fields ) {
			return null;
		}

		return array(
			'group_id'    => 'wallee-express-checkout-orders',
			'group_label' => __( 'wallee Express Checkout order data', 'wallee-express-checkout' ),
			'item_id'     => 'wallee-express-checkout-order-' . $order->get_id(),
			'data'        => self::pairs( array( __( 'Order', 'wallee-express-checkout' ) => (string) $order->get_order_number() ) + $fields ),
		);
	}

	/**
	 * "CH, ZH, 8001 Zurich" from an address, '' if empty.
	 *
	 * @param array $address Country, state, postcode, city.
	 */
	private static function place( array $address ): string {
		$town  = trim( ( $address['postcode'] ?? '' ) . ' ' . ( $address['city'] ?? '' ) );
		$parts = array_filter(
			array( $address['country'] ?? '', $address['state'] ?? '', $town ),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		return implode( ', ', $parts );
	}

	/**
	 * WordPress' name/value list.
	 *
	 * @param array<string, string> $fields Label => value.
	 */
	private static function pairs( array $fields ): array {
		$data = array();
		foreach ( $fields as $name => $value ) {
			$data[] = array(
				'name'  => (string) $name,
				'value' => $value,
			);
		}

		return $data;
	}
}
