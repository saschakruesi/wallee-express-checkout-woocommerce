<?php
/**
 * Minimal stand-in for WP_REST_Response.
 *
 * @package Wallee\ExpressCheckout
 */

// phpcs:disable Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting.ClassComment,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing -- Test stub for a WordPress class.

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	class WP_REST_Response {

		public $data;
		public $status;
		public $headers = array();

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers() {
			return $this->headers;
		}
	}
}
