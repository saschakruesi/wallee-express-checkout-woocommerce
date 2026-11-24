<?php
/**
 * Minimal stand-ins for WooCommerce product classes, so the product and order
 * line checks can be unit tested.
 *
 * @package Wallee\ExpressCheckout
 */

// phpcs:disable Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting.ClassComment,Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing -- Test stubs for WooCommerce classes.

if ( ! class_exists( 'WC_Product', false ) ) {
	class WC_Product {

		public $props = array(
			'id'               => 0,
			'parent_id'        => 0,
			'type'             => 'simple',
			'status'           => 'publish',
			'post_password'    => '',
			'name'             => 'Product',
			'tax_class'        => '',
			'attributes'       => array(),
			'purchasable'      => true,
			'in_stock'         => true,
			'sold_individually' => false,
		);

		public function __construct( array $props = array() ) {
			$this->props = array_merge( $this->props, $props );
		}

		public function get_id() {
			return $this->props['id'];
		}

		public function get_parent_id() {
			return $this->props['parent_id'];
		}

		public function is_type( $type ) {
			return in_array( $this->props['type'], (array) $type, true );
		}

		public function get_status() {
			return $this->props['status'];
		}

		public function get_post_password() {
			return $this->props['post_password'];
		}

		public function get_name() {
			return $this->props['name'];
		}

		public function get_tax_class() {
			return $this->props['tax_class'];
		}

		public function get_attributes() {
			return $this->props['attributes'];
		}

		public function is_purchasable() {
			return $this->props['purchasable'];
		}

		public function is_in_stock() {
			return $this->props['in_stock'];
		}

		public function has_enough_stock( $quantity ) {
			return $this->props['in_stock'] && $quantity > 0;
		}

		public function is_sold_individually() {
			return $this->props['sold_individually'];
		}

		public function get_stock_managed_by_id() {
			return $this->props['id'];
		}
	}
}

if ( ! class_exists( 'WC_Product_Attribute', false ) ) {
	class WC_Product_Attribute {

		public $name;
		public $slugs;
		public $variation;
		public $taxonomy;

		public function __construct( string $name, array $slugs, bool $variation = true, bool $taxonomy = false ) {
			$this->name      = $name;
			$this->slugs     = $slugs;
			$this->variation = $variation;
			$this->taxonomy  = $taxonomy;
		}

		public function get_name() {
			return $this->name;
		}

		public function get_slugs() {
			return $this->slugs;
		}

		public function get_variation() {
			return $this->variation;
		}

		public function is_taxonomy() {
			return $this->taxonomy;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	/**
	 * Mirrors the WooCommerce behaviour that matters here: set_variation()
	 * strips the `attribute_` prefix and stores each entry as item meta, and
	 * meta whose key is an internal property with a setter calls that setter.
	 */
	class WC_Order_Item_Product {

		public $data = array(
			'product_id'   => 0,
			'variation_id' => 0,
			'name'         => '',
			'quantity'     => 1,
			'tax_class'    => '',
			'subtotal'     => 0,
			'total'        => 0,
			'taxes'        => array(),
		);

		public $meta = array();

		public function set_product( $product ) {
			if ( $product->is_type( 'variation' ) ) {
				$this->set_product_id( $product->get_parent_id() );
				$this->set_variation_id( $product->get_id() );
			} else {
				$this->set_product_id( $product->get_id() );
			}
		}

		public function set_product_id( $value ) {
			$this->data['product_id'] = (int) $value;
		}

		public function set_variation_id( $value ) {
			$this->data['variation_id'] = (int) $value;
		}

		public function get_product_id() {
			return $this->data['product_id'];
		}

		public function get_variation_id() {
			return $this->data['variation_id'];
		}

		public function set_name( $value ) {
			$this->data['name'] = $value;
		}

		public function set_quantity( $value ) {
			$this->data['quantity'] = $value;
		}

		public function set_tax_class( $value ) {
			$this->data['tax_class'] = $value;
		}

		public function set_subtotal( $value ) {
			$this->data['subtotal'] = $value;
		}

		public function set_total( $value ) {
			$this->data['total'] = $value;
		}

		public function set_taxes( $value ) {
			$this->data['taxes'] = $value;
		}

		public function set_variation( $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->add_meta_data( str_replace( 'attribute_', '', $key ), $value, true );
			}
		}

		public function add_meta_data( $key, $value, $unique = false ) {
			$setter = 'set_' . ltrim( $key, '_' );
			if ( '_' === substr( $key, 0, 1 ) && in_array( ltrim( $key, '_' ), array( 'product_id', 'variation_id', 'tax_class', 'quantity' ), true ) ) {
				$this->{$setter}( $value );
				return;
			}
			$this->meta[ $key ] = $value;
		}
	}
}
