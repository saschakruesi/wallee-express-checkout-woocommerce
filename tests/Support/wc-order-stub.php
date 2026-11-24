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
		public $payment_method       = 'wallee_express_checkout';
		public $payment_method_title = 'wallee Express Checkout';
		public $meta                 = array();
		public $notes                = array();
		public $saves                = 0;

		public function __construct( int $id = 0, $total = 0.0 ) {
			$this->id    = $id;
			$this->total = $total;
		}

		public function get_id() {
			return $this->id;
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
