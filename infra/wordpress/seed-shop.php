<?php
/**
 * Idempotent shop seed, run with `wp eval-file /seed/seed-shop.php`.
 * Staging-only: settings, tax, shipping, products, coupon and pages.
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce is not active\n" );
	exit( 1 );
}

// ---- General settings -----------------------------------------------------
$options = array(
	'woocommerce_currency'                   => 'CHF',
	'woocommerce_default_country'            => 'CH',
	'woocommerce_store_address'              => 'Teststrasse 1',
	'woocommerce_store_city'                 => 'Zuerich',
	'woocommerce_store_postcode'             => '8000',
	'woocommerce_calc_taxes'                 => 'yes',
	'woocommerce_prices_include_tax'         => 'yes',
	'woocommerce_tax_display_shop'           => 'incl',
	'woocommerce_tax_display_cart'           => 'incl',
	'woocommerce_enable_guest_checkout'      => 'yes',
	'woocommerce_enable_coupons'             => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_store_pages_only'           => 'no',
);
foreach ( $options as $key => $value ) {
	update_option( $key, $value );
}

// ---- Tax: Switzerland 8.1 %, also on shipping --------------------------------
if ( empty( WC_Tax::find_rates( array( 'country' => 'CH', 'state' => '', 'postcode' => '', 'city' => '' ) ) ) ) {
	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'CH',
			'tax_rate_state'    => '',
			'tax_rate'          => '8.1000',
			'tax_rate_name'     => 'MWST',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 1,
			'tax_rate_class'    => '',
		)
	);
}

// ---- Shipping zones ----------------------------------------------------------
function wallee_ec_seed_zone( $name, $country, $order ) {
	foreach ( WC_Shipping_Zones::get_zones() as $data ) {
		if ( $data['zone_name'] === $name ) {
			return new WC_Shipping_Zone( $data['id'] );
		}
	}
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( $name );
	$zone->set_zone_order( $order );
	$zone->add_location( $country, 'country' );
	$zone->save();
	return $zone;
}

function wallee_ec_seed_method( $zone, $method_id, $settings ) {
	foreach ( $zone->get_shipping_methods() as $method ) {
		if ( $method->id === $method_id ) {
			return;
		}
	}
	$instance_id = $zone->add_shipping_method( $method_id );
	update_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', $settings );
}

$ch = wallee_ec_seed_zone( 'Switzerland', 'CH', 1 );
wallee_ec_seed_method( $ch, 'flat_rate', array( 'title' => 'Flat rate', 'tax_status' => 'taxable', 'cost' => '7.50' ) );
wallee_ec_seed_method( $ch, 'free_shipping', array( 'title' => 'Free shipping', 'requires' => 'min_amount', 'min_amount' => '100.00', 'ignore_discounts' => 'no' ) );

$de = wallee_ec_seed_zone( 'Germany', 'DE', 2 );
wallee_ec_seed_method( $de, 'flat_rate', array( 'title' => 'Flat rate', 'tax_status' => 'taxable', 'cost' => '15.00' ) );

// ---- Products ----------------------------------------------------------------
if ( ! wc_get_product_id_by_sku( 'EC-NOTEBOOK' ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Notebook' );
	$product->set_sku( 'EC-NOTEBOOK' );
	$product->set_regular_price( '25.00' );
	$product->set_tax_status( 'taxable' );
	$product->set_status( 'publish' );
	$product->save();
}

if ( ! wc_get_product_id_by_sku( 'EC-GIFTCARD' ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Gift card' );
	$product->set_sku( 'EC-GIFTCARD' );
	$product->set_regular_price( '50.00' );
	$product->set_virtual( true );
	$product->set_tax_status( 'taxable' );
	$product->set_status( 'publish' );
	$product->save();
}

if ( ! wc_get_product_id_by_sku( 'EC-TSHIRT' ) ) {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array( 'S', 'M', 'L' ) );
	$attribute->set_position( 0 );
	$attribute->set_visible( true );
	$attribute->set_variation( true );

	$product = new WC_Product_Variable();
	$product->set_name( 'T-Shirt' );
	$product->set_sku( 'EC-TSHIRT' );
	$product->set_tax_status( 'taxable' );
	$product->set_attributes( array( $attribute ) );
	$product->set_status( 'publish' );
	$parent_id = $product->save();

	foreach ( array( 'S', 'M', 'L' ) as $size ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( 'size' => $size ) );
		$variation->set_regular_price( '30.00' );
		$variation->set_status( 'publish' );
		$variation->save();
	}
	WC_Product_Variable::sync( $parent_id );
}

// ---- Coupon TEN (10 %) -------------------------------------------------------
if ( ! wc_get_coupon_id_by_code( 'TEN' ) ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'TEN' );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( '10' );
	$coupon->save();
}

// ---- Pages -------------------------------------------------------------------
if ( ! get_page_by_path( 'classic-cart' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Classic cart',
			'post_name'    => 'classic-cart',
			'post_content' => '[woocommerce_cart]',
		)
	);
}

$terms = get_page_by_path( 'terms-and-conditions' );
if ( ! $terms ) {
	$terms_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Terms and conditions',
			'post_name'    => 'terms-and-conditions',
			'post_content' => 'Test shop terms. No real goods are sold.',
		)
	);
} else {
	$terms_id = $terms->ID;
}
update_option( 'woocommerce_terms_page_id', $terms_id );

echo "seed ok\n";
