<?php
/**
 * CartSnapshot tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;

/**
 * @covers \Wallee\ExpressCheckout\Cart\CartSnapshot
 */
class CartSnapshotTest extends TestCase {

	/**
	 * Builds a valid item, with overrides.
	 *
	 * @param array $over Overrides.
	 */
	public static function item( array $over = array() ): array {
		return array_merge(
			array(
				'key'               => 'abc123',
				'product_id'        => 11,
				'variation_id'      => 0,
				'variation'         => array(),
				'name'              => 'Notebook',
				'sku'               => 'NB-1',
				'quantity'          => 2,
				'line_subtotal'     => 46.25,
				'line_subtotal_tax' => 3.75,
				'line_total'        => 46.25,
				'line_tax'          => 3.75,
				'tax_class'         => '',
				'tax_rates'         => array(
					array(
						'label' => 'VAT',
						'rate'  => 8.1,
					),
				),
				'needs_shipping'    => true,
				'is_subscription'   => false,
			),
			$over
		);
	}

	/**
	 * Builds a valid snapshot array, with overrides.
	 *
	 * @param array $over Top-level overrides.
	 */
	public static function data( array $over = array() ): array {
		return array_merge(
			array(
				'context'                => 'cart',
				'currency'               => 'CHF',
				'customer_id'            => 0,
				'prices_include_tax'     => false,
				'tax_location'           => array(
					'country'  => 'CH',
					'state'    => '',
					'postcode' => '8000',
					'city'     => 'Zurich',
				),
				'items'                  => array( self::item() ),
				'fees'                   => array(
					array(
						'id'        => 'handling',
						'name'      => 'Handling',
						'total'     => 1.85,
						'tax'       => 0.15,
						'tax_rates' => array(),
					),
				),
				'coupons'                => array( 'save5' ),
				'shipping_package_count' => 1,
			),
			$over
		);
	}

	public function test_round_trip_keeps_data_and_hash() {
		$snapshot = CartSnapshot::fromArray( self::data() );
		$copy     = CartSnapshot::fromArray( $snapshot->toArray() );

		$this->assertEquals( $snapshot, $copy );
		$this->assertSame( $snapshot->toArray(), $copy->toArray() );
		$this->assertSame( $snapshot->hash(), $copy->hash() );
		$this->assertSame( 64, strlen( $snapshot->hash() ) );
	}

	public function test_round_trip_through_json() {
		$snapshot = CartSnapshot::fromArray( self::data() );
		$copy     = CartSnapshot::fromArray( json_decode( json_encode( $snapshot->toArray() ), true ) );

		$this->assertSame( $snapshot->hash(), $copy->hash() );
	}

	public function test_getters() {
		$s = CartSnapshot::fromArray( self::data( array( 'customer_id' => 7 ) ) );

		$this->assertSame( 'cart', $s->context() );
		$this->assertSame( 'CHF', $s->currency() );
		$this->assertSame( 7, $s->customerId() );
		$this->assertFalse( $s->pricesIncludeTax() );
		$this->assertSame( 'CH', $s->taxLocation()['country'] );
		$this->assertCount( 1, $s->items() );
		$this->assertCount( 1, $s->fees() );
		$this->assertSame( array( 'save5' ), $s->coupons() );
		$this->assertSame( 1, $s->shippingPackageCount() );
	}

	public function test_changing_quantity_changes_hash() {
		$a = CartSnapshot::fromArray( self::data() );
		$b = CartSnapshot::fromArray( self::data( array( 'items' => array( self::item( array( 'quantity' => 3 ) ) ) ) ) );

		$this->assertNotSame( $a->hash(), $b->hash() );
	}

	public function test_hash_is_independent_of_key_order() {
		$data     = self::data();
		$reversed = array_reverse( $data, true );
		$reversed['items'][0] = array_reverse( $reversed['items'][0], true );

		$this->assertSame(
			CartSnapshot::fromArray( $data )->hash(),
			CartSnapshot::fromArray( $reversed )->hash()
		);
	}

	public function test_needs_shipping_if_any_item_needs_it() {
		$digital = self::item( array( 'needs_shipping' => false ) );
		$physical = self::item( array( 'key' => 'def', 'needs_shipping' => true ) );

		$none = CartSnapshot::fromArray( self::data( array( 'items' => array( $digital ) ) ) );
		$some = CartSnapshot::fromArray( self::data( array( 'items' => array( $digital, $physical ) ) ) );

		$this->assertFalse( $none->needsShipping() );
		$this->assertTrue( $some->needsShipping() );
	}

	public function test_total_is_items_plus_fees_including_tax() {
		$s = CartSnapshot::fromArray( self::data() );

		$this->assertEqualsWithDelta( 52.00, $s->total(), 0.0001 );
	}

	public function test_total_without_fees() {
		$s = CartSnapshot::fromArray( self::data( array( 'fees' => array() ) ) );

		$this->assertEqualsWithDelta( 50.00, $s->total(), 0.0001 );
	}

	public function test_missing_key_is_rejected() {
		$data = self::data();
		unset( $data['currency'] );

		$this->expectException( \InvalidArgumentException::class );
		CartSnapshot::fromArray( $data );
	}

	public function test_missing_item_key_is_rejected() {
		$item = self::item();
		unset( $item['line_tax'] );

		$this->expectException( \InvalidArgumentException::class );
		CartSnapshot::fromArray( self::data( array( 'items' => array( $item ) ) ) );
	}

	/**
	 * @dataProvider non_attribute_keys
	 *
	 * @param string|int $key Variation key that is not an attribute key.
	 */
	public function test_variation_keys_other_than_attribute_keys_are_rejected( $key ) {
		$this->expectException( \InvalidArgumentException::class );

		CartSnapshot::fromArray( self::data( array( 'items' => array( self::item( array( 'variation' => array( $key => '12' ) ) ) ) ) ) );
	}

	public function non_attribute_keys(): array {
		return array(
			'product id'    => array( '_product_id' ),
			'variation id'  => array( '_variation_id' ),
			'reduced stock' => array( '_reduced_stock' ),
			'tax class'     => array( '_tax_class' ),
			'bare slug'     => array( 'pa_color' ),
			'with a space'  => array( 'attribute_pa color' ),
			'numeric'       => array( 7 ),
		);
	}

	public function test_attribute_keys_are_kept() {
		$variation = array(
			'attribute_pa_color'    => 'red',
			'attribute_gr%c3%b6sse' => 'M',
		);
		$snapshot  = CartSnapshot::fromArray( self::data( array( 'items' => array( self::item( array( 'variation' => $variation ) ) ) ) ) );

		$this->assertSame( $variation, $snapshot->items()[0]['variation'] );
	}

	public function test_unknown_context_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		CartSnapshot::fromArray( self::data( array( 'context' => 'basket' ) ) );
	}

	public function test_negative_quantity_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		CartSnapshot::fromArray( self::data( array( 'items' => array( self::item( array( 'quantity' => -1 ) ) ) ) ) );
	}

	public function test_wrong_type_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		CartSnapshot::fromArray( self::data( array( 'items' => 'nope' ) ) );
	}

	public function test_negative_package_count_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		CartSnapshot::fromArray( self::data( array( 'shipping_package_count' => -1 ) ) );
	}

	public function test_total_is_rounded_to_currency_decimals() {
		$item = self::item( array( 'line_total' => 0.1, 'line_tax' => 0.2 ) );
		$s    = CartSnapshot::fromArray( self::data( array( 'items' => array( $item ), 'fees' => array() ) ) );

		$this->assertSame( 0.3, $s->total() );

		$yen = CartSnapshot::fromArray( self::data( array( 'currency' => 'JPY', 'items' => array( self::item( array( 'line_total' => 1000.4, 'line_tax' => 0.2 ) ) ), 'fees' => array() ) ) );
		$this->assertSame( 1001.0, $yen->total() );
	}

	public function test_hash_throws_when_json_encoding_fails() {
		$s = CartSnapshot::fromArray( self::data( array( 'items' => array( self::item( array( 'name' => "\xB1\x31" ) ) ) ) ) );

		$this->expectException( \RuntimeException::class );
		$s->hash();
	}
}
