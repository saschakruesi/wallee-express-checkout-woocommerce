<?php
/**
 * LineItemBuilder tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\LineItemBuilder;

/**
 * @covers \Wallee\ExpressCheckout\Cart\LineItemBuilder
 */
class LineItemBuilderTest extends TestCase {

	/**
	 * Builds a snapshot from items and fees.
	 *
	 * @param array $items Items.
	 * @param array $fees  Fees.
	 */
	private static function snapshot( array $items, array $fees = array() ): CartSnapshot {
		return CartSnapshot::fromArray(
			CartSnapshotTest::data(
				array(
					'items' => $items,
					'fees'  => $fees,
				)
			)
		);
	}

	/**
	 * Builds a fee.
	 *
	 * @param float $total Total.
	 * @param float $tax   Tax.
	 */
	private static function fee( float $total, float $tax = 0.0 ): array {
		return array(
			'id'        => 'fee_' . $total,
			'name'      => 'Fee',
			'total'     => $total,
			'tax'       => $tax,
			'tax_rates' => array(),
		);
	}

	private static function sum( array $lines ): float {
		return round( array_sum( array_column( $lines, 'amountIncludingTax' ) ), 2 );
	}

	public function test_sum_equals_snapshot_total_with_coupon_and_quantities() {
		// 3 x 19.99 excl. tax 8.1 %, 10 % coupon: line_total 53.973, line_tax 4.371813.
		// 1 x 25.00 incl. tax: line_total 23.126735, line_tax 1.873265.
		$snapshot = self::snapshot(
			array(
				CartSnapshotTest::item(
					array(
						'key'               => 'a',
						'quantity'          => 3,
						'line_subtotal'     => 59.97,
						'line_subtotal_tax' => 4.85757,
						'line_total'        => 53.973,
						'line_tax'          => 4.371813,
					)
				),
				CartSnapshotTest::item(
					array(
						'key'               => 'b',
						'quantity'          => 1,
						'line_subtotal'     => 23.126735,
						'line_subtotal_tax' => 1.873265,
						'line_total'        => 23.126735,
						'line_tax'          => 1.873265,
					)
				),
			)
		);

		$items = ( new LineItemBuilder() )->build( $snapshot );

		$this->assertSame( 83.34, self::sum( $items ) );
		$this->assertSame( 58.34, $items[0]['amountIncludingTax'] );
		$this->assertSame( 25.00, $items[1]['amountIncludingTax'] );
		$this->assertSame( 3, $items[0]['quantity'] );
		$this->assertSame( 'PRODUCT', $items[0]['type'] );
	}

	public function test_rounding_difference_is_refused_not_shifted() {
		$snapshot = self::snapshot(
			array(
				CartSnapshotTest::item(
					array(
						'key'        => 'a',
						'line_total' => 3.004,
						'line_tax'   => 0.0,
					)
				),
				CartSnapshotTest::item(
					array(
						'key'        => 'b',
						'line_total' => 5.004,
						'line_tax'   => 0.0,
					)
				),
				CartSnapshotTest::item(
					array(
						'key'        => 'c',
						'line_total' => 1.004,
						'line_tax'   => 0.0,
					)
				),
			)
		);

		// Lines 3.00 + 5.00 + 1.00 = 9.00, total 9.012 -> 9.01: one cent would have to move.
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( LineItemBuilder::ROUNDING_MISMATCH );
		( new LineItemBuilder() )->build( $snapshot );
	}

	public function test_negative_rounding_difference_is_refused_too() {
		$snapshot = self::snapshot(
			array(
				CartSnapshotTest::item(
					array(
						'key'        => 'a',
						'line_total' => 2.006,
						'line_tax'   => 0.0,
					)
				),
				CartSnapshotTest::item(
					array(
						'key'        => 'b',
						'line_total' => 1.006,
						'line_tax'   => 0.0,
					)
				),
				CartSnapshotTest::item(
					array(
						'key'        => 'c',
						'line_total' => 4.006,
						'line_tax'   => 0.0,
					)
				),
			)
		);

		// Lines 2.01 + 1.01 + 4.01 = 7.03, total 7.018 -> 7.02.
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( LineItemBuilder::ROUNDING_MISMATCH );
		( new LineItemBuilder() )->build( $snapshot );
	}

	public function test_three_decimal_currency_without_remainder_builds() {
		$snapshot = CartSnapshot::fromArray(
			CartSnapshotTest::data(
				array(
					'currency' => 'KWD',
					'items'    => array(
						CartSnapshotTest::item(
							array(
								'line_total' => 1.234,
								'line_tax'   => 0.1,
							)
						),
					),
				)
			)
		);

		$items = ( new LineItemBuilder() )->build( $snapshot );
		$this->assertSame( 1.334, $items[0]['amountIncludingTax'] );
	}

	public function test_unique_ids_are_stable_and_distinct() {
		$snapshot = self::snapshot(
			array(
				CartSnapshotTest::item( array( 'key' => 'a' ) ),
				CartSnapshotTest::item( array( 'key' => 'b' ) ),
			)
		);
		$builder  = new LineItemBuilder();

		$first  = $builder->build( $snapshot );
		$second = $builder->build( $snapshot );

		$this->assertSame( $first[0]['uniqueId'], $second[0]['uniqueId'] );
		$this->assertNotSame( $first[0]['uniqueId'], $first[1]['uniqueId'] );
		$this->assertSame( LineItemBuilder::uniqueIdFor( 'item', 'a' ), $first[0]['uniqueId'] );
		$this->assertSame( 'ec_item_' . substr( sha1( 'a' ), 0, 32 ), $first[0]['uniqueId'] );
		$this->assertSame( 'ec_shipping_' . substr( sha1( 'x' ), 0, 32 ), LineItemBuilder::uniqueIdFor( 'shipping', 'x' ) );
	}

	public function test_virtual_item_is_not_shipping_required() {
		$snapshot = self::snapshot(
			array(
				CartSnapshotTest::item( array( 'needs_shipping' => false ) ),
				CartSnapshotTest::item(
					array(
						'key'            => 'b',
						'needs_shipping' => true,
					)
				),
			)
		);

		$items = ( new LineItemBuilder() )->build( $snapshot );

		$this->assertFalse( $items[0]['shippingRequired'] );
		$this->assertTrue( $items[1]['shippingRequired'] );
	}

	public function test_negative_fee_becomes_discount_positive_fee_becomes_fee() {
		$snapshot = self::snapshot(
			array( CartSnapshotTest::item() ),
			array( self::fee( 5.0, 0.4 ), self::fee( -2.0, -0.16 ) )
		);

		$items = ( new LineItemBuilder() )->build( $snapshot );

		$this->assertCount( 3, $items );
		$this->assertSame( 'FEE', $items[1]['type'] );
		$this->assertSame( 5.4, $items[1]['amountIncludingTax'] );
		$this->assertSame( 'DISCOUNT', $items[2]['type'] );
		$this->assertSame( -2.16, $items[2]['amountIncludingTax'] );
		$this->assertFalse( $items[1]['shippingRequired'] );
		$this->assertSame( 1, $items[1]['quantity'] );
		$this->assertSame( self::sum( $items ), round( $snapshot->total(), 2 ) );
	}

	public function test_taxes_carry_title_and_rate() {
		$items = ( new LineItemBuilder() )->build( self::snapshot( array( CartSnapshotTest::item() ) ) );

		$this->assertSame(
			array(
				array(
					'title' => 'VAT',
					'rate'  => 8.1,
				),
			),
			$items[0]['taxes']
		);
	}

	public function test_sku_falls_back_to_product_id_and_text_is_truncated() {
		$items = ( new LineItemBuilder() )->build(
			self::snapshot(
				array(
					CartSnapshotTest::item(
						array(
							'sku'  => '',
							'name' => str_repeat( 'n', 200 ),
						)
					),
					CartSnapshotTest::item(
						array(
							'key' => 'b',
							'sku' => str_repeat( 's', 250 ),
						)
					),
				)
			)
		);

		$this->assertSame( '11', $items[0]['sku'] );
		$this->assertSame( 150, strlen( $items[0]['name'] ) );
		$this->assertSame( 200, strlen( $items[1]['sku'] ) );
	}
}
