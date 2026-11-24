<?php
/**
 * Minimal stand-in for WooCommerce's WC_Order, so handlers can be unit tested.
 *
 * @package Wallee\ExpressCheckout
 */

if ( ! class_exists( 'WC_Order', false ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting.ClassComment,Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test stub for a WooCommerce class.
	class WC_Order {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing

		public $id;
		public $total;
		public $status               = 'pending';
		public $billing_email        = '';
		public $billing_country      = '';
		public $shipping_country     = '';
		public $billing_state        = '';
		public $billing_postcode     = '';
		public $billing_city         = '';
		public $shipping_state       = '';
		public $shipping_postcode    = '';
		public $shipping_city        = '';
		public $payment_method       = 'wallee_express_checkout';
		public $payment_method_title = 'wallee Express Checkout';
		public $created_via          = 'wallee_express_checkout';
		public $meta                 = array();
		public $notes                = array();
		public $saves                = 0;
		public $currency             = '';
		public $customer_id          = 0;
		public $products             = array();

		public function __construct( int $id = 0, $total = 0.0 ) {
			$this->id    = $id;
			$this->total = $total;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_order_number() {
			return (string) $this->id;
		}

		public function get_total() {
			return $this->total;
		}

		public function get_status() {
			return $this->status;
		}

		public function has_status( $status ) {
			return in_array( $this->status, (array) $status, true );
		}

		public function get_billing_email() {
			return $this->billing_email;
		}

		public function set_billing_email( $email ) {
			$this->billing_email = $email;
		}

		public function set_currency( $currency ) {
			$this->currency = $currency;
		}

		public function set_customer_id( $id ) {
			$this->customer_id = $id;
		}

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function add_product( $product, $quantity = 1, $args = array() ) {
			$this->products[] = array( $product, $quantity, $args );
			return count( $this->products );
		}

		public function get_billing_country() {
			return $this->billing_country;
		}

		public function get_shipping_country() {
			return $this->shipping_country;
		}

		public function get_billing_state() {
			return $this->billing_state;
		}

		public function get_billing_postcode() {
			return $this->billing_postcode;
		}

		public function get_billing_city() {
			return $this->billing_city;
		}

		public function get_shipping_state() {
			return $this->shipping_state;
		}

		public function get_shipping_postcode() {
			return $this->shipping_postcode;
		}

		public function get_shipping_city() {
			return $this->shipping_city;
		}

		public function get_created_via() {
			return $this->created_via;
		}

		public function get_payment_method() {
			return $this->payment_method;
		}

		public function set_payment_method( $id ) {
			$this->payment_method = $id;
		}

		public function get_payment_method_title() {
			return $this->payment_method_title;
		}

		public function set_payment_method_title( $title ) {
			$this->payment_method_title = $title;
		}

		public function get_meta( $key ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ) {
			unset( $this->meta[ $key ] );
		}

		public function add_order_note( $note ) {
			$this->notes[] = $note;
			return count( $this->notes );
		}

		public function save() {
			++$this->saves;
			return $this->id;
		}

		public function get_checkout_order_received_url() {
			return 'https://shop.test/checkout/order-received/' . $this->id . '/?key=wc_order_k';
		}
	}
}
