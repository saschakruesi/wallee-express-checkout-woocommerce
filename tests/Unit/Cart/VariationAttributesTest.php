<?php
/**
 * VariationAttributes tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\VariationAttributes;

/**
 * @covers \Wallee\ExpressCheckout\Cart\VariationAttributes
 */
class VariationAttributesTest extends TestCase {

	/**
	 * Parent with a custom attribute `size` and a taxonomy attribute `pa_color`.
	 */
	private function parent_options(): array {
		return array(
			'attribute_size'     => array( 'S', 'M', 'L' ),
			'attribute_pa_color' => array( 'red', 'blue' ),
		);
	}

	/**
	 * Variation: size M, any colour.
	 */
	private function variation_values(): array {
		return array(
			'attribute_size'     => 'M',
			'attribute_pa_color' => '',
		);
	}

	private function resolve( array $requested ): array {
		return VariationAttributes::resolve( $this->parent_options(), $this->variation_values(), $requested );
	}

	public function test_valid_values_resolve_to_the_full_attribute_set() {
		$this->assertSame(
			array(
				'attribute_size'     => 'M',
				'attribute_pa_color' => 'blue',
			),
			$this->resolve(
				array(
					'attribute_size'     => 'M',
					'attribute_pa_color' => 'blue',
				)
			)
		);
	}

	public function test_a_fixed_value_may_be_omitted_and_comes_from_the_variation() {
		$this->assertSame(
			array(
				'attribute_size'     => 'M',
				'attribute_pa_color' => 'red',
			),
			$this->resolve( array( 'attribute_pa_color' => 'red' ) )
		);
	}

	/**
	 * @dataProvider foreign_keys
	 *
	 * @param string $key Key that is not a variation attribute of the parent.
	 */
	public function test_keys_that_are_not_attributes_of_the_parent_are_refused( string $key ) {
		$this->expectException( \InvalidArgumentException::class );

		$this->resolve(
			array(
				'attribute_pa_color' => 'red',
				$key                 => '12',
			)
		);
	}

	public function foreign_keys(): array {
		return array(
			'product id'        => array( '_product_id' ),
			'variation id'      => array( '_variation_id' ),
			'reduced stock'     => array( '_reduced_stock' ),
			'tax class'         => array( '_tax_class' ),
			'prefixed internal' => array( 'attribute__product_id' ),
			'unknown attribute' => array( 'attribute_material' ),
			'no prefix'         => array( 'size' ),
		);
	}

	public function test_a_value_other_than_the_variations_own_is_refused() {
		$this->expectException( \InvalidArgumentException::class );

		$this->resolve(
			array(
				'attribute_size'     => 'L',
				'attribute_pa_color' => 'red',
			)
		);
	}

	public function test_an_any_attribute_with_a_value_outside_the_parents_options_is_refused() {
		$this->expectException( \InvalidArgumentException::class );

		$this->resolve( array( 'attribute_pa_color' => 'green' ) );
	}

	public function test_an_any_attribute_without_a_value_is_refused() {
		$this->expectException( \InvalidArgumentException::class );

		$this->resolve( array( 'attribute_size' => 'M' ) );
	}

	public function test_an_empty_value_counts_as_absent() {
		$this->expectException( \InvalidArgumentException::class );

		$this->resolve( array( 'attribute_pa_color' => '' ) );
	}

	public function test_attribute_keys_follow_woocommerce_slugs() {
		$this->assertTrue( VariationAttributes::isAttributeKey( 'attribute_pa_color' ) );
		$this->assertTrue( VariationAttributes::isAttributeKey( 'attribute_gr%c3%b6sse' ) );
		$this->assertTrue( VariationAttributes::isAttributeKey( 'attribute_shoe-size' ) );
		$this->assertTrue( VariationAttributes::isAttributeKey( 'attribute_' . str_repeat( 'a', 100 ) ) );

		$this->assertFalse( VariationAttributes::isAttributeKey( 'attribute_' ) );
		$this->assertFalse( VariationAttributes::isAttributeKey( 'attribute_' . str_repeat( 'a', 101 ) ) );
		$this->assertFalse( VariationAttributes::isAttributeKey( '_product_id' ) );
		$this->assertFalse( VariationAttributes::isAttributeKey( 'attribute_size name' ) );
		$this->assertFalse( VariationAttributes::isAttributeKey( 12 ) );
	}
}
