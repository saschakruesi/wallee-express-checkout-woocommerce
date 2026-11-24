<?php
/**
 * SnapshotFactory tests: the total check and the product-context refusals.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Cart;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\SnapshotFactory;

require_once dirname( __DIR__, 2 ) . '/Support/wc-product-stub.php';

/**
 * @covers \Wallee\ExpressCheckout\Cart\SnapshotFactory
 */
class SnapshotFactoryTest extends TestCase {

	const SIMPLE    = 11;
	const VARIABLE  = 20;
	const VARIATION = 21;

	/** @var \WC_Product[] */
	private $products = array();

	/** @var array Notices as WooCommerce would hold them. */
	private $notices = array();

	/** @var array Add-to-cart validation calls. */
	private $validations = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->notices     = array();
		$this->validations = array();
		$this->products    = array(
			self::SIMPLE    => new \WC_Product( array( 'id' => self::SIMPLE ) ),
			self::VARIABLE  => new \WC_Product(
				array(
					'id'         => self::VARIABLE,
					'type'       => 'variable',
					'attributes' => array(
						new \WC_Product_Attribute( 'Size', array( 'S', 'M', 'L' ) ),
						new \WC_Product_Attribute( 'pa_color', array( 'red', 'blue' ), true, true ),
						new \WC_Product_Attribute( 'Material', array( 'Cotton' ), false ),
					),
				)
			),
			self::VARIATION => new \WC_Product(
				array(
					'id'        => self::VARIATION,
					'parent_id' => self::VARIABLE,
					'type'      => 'variation',
				)
			),
		);

		Functions\when( 'WC' )->justReturn( (object) array( 'customer' => new \stdClass() ) );
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return isset( $this->products[ $id ] ) ? $this->products[ $id ] : false;
			}
		);
		Functions\when( 'wc_get_product_variation_attributes' )->justReturn(
			array(
				'attribute_size'     => 'M',
				'attribute_pa_color' => '',
			)
		);
		Functions\when( 'sanitize_title' )->alias(
			static function ( $value ) {
				return strtolower( str_replace( ' ', '-', trim( (string) $value ) ) );
			}
		);
		Functions\when( 'wc_clean' )->alias( 'trim' );
		Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
		Functions\when( 'wc_get_notices' )->alias(
			function () {
				return $this->notices;
			}
		);
		Functions\when( 'wc_set_notices' )->alias(
			function ( $notices ) {
				$this->notices = $notices;
			}
		);
		Functions\when( 'wc_notice_count' )->alias(
			function ( $type ) {
				return isset( $this->notices[ $type ] ) ? count( $this->notices[ $type ] ) : 0;
			}
		);
		// Pricing is outside these tests: reaching it means every check passed.
		Functions\when( 'get_woocommerce_currency' )->alias(
			static function () {
				throw new \LogicException( 'priced' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cart_total_without_shipping_must_equal_the_snapshot_total() {
		$snapshot = CartSnapshot::fromArray( CartSnapshotTest::data() );
		$total    = $snapshot->total();

		$this->assertTrue( SnapshotFactory::matchesCartTotal( $snapshot, $total, 0, 0 ) );
		$this->assertTrue( SnapshotFactory::matchesCartTotal( $snapshot, (string) ( $total + 8.11 ), '7.50', '0.61' ), 'cart shipping is not part of the snapshot' );
		$this->assertFalse( SnapshotFactory::matchesCartTotal( $snapshot, $total + 0.01, 0, 0 ), 'one cent off (round tax at subtotal)' );
		$this->assertFalse( SnapshotFactory::matchesCartTotal( $snapshot, $total + 8.11, 7.5, 0 ) );
	}

	/**
	 * Expects the product checks to pass: the call reaches the pricing step.
	 *
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @param array $variation    Attributes.
	 */
	private function assertAccepted( int $product_id, int $variation_id, array $variation ): void {
		try {
			( new SnapshotFactory() )->fromProduct( $product_id, $variation_id, $variation, 1 );
			$this->fail( 'pricing was expected' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'priced', $e->getMessage() );
		}
	}

	private function refuse( int $product_id, int $variation_id, array $variation ): void {
		$this->expectException( \InvalidArgumentException::class );

		( new SnapshotFactory() )->fromProduct( $product_id, $variation_id, $variation, 1 );
	}

	public function test_a_simple_product_without_variation_data_is_accepted() {
		$this->assertAccepted( self::SIMPLE, 0, array() );
	}

	/**
	 * @dataProvider any_variation_map
	 *
	 * @param array $variation Variation map sent for a simple product.
	 */
	public function test_a_simple_product_with_any_variation_map_is_refused( array $variation ) {
		$this->refuse( self::SIMPLE, 0, $variation );
	}

	public function any_variation_map(): array {
		return array(
			'product id'      => array( array( '_product_id' => '12' ) ),
			'variation id'    => array( array( '_variation_id' => '21' ) ),
			'reduced stock'   => array( array( '_reduced_stock' => '1' ) ),
			'tax class'       => array( array( '_tax_class' => 'zero-rate' ) ),
			'attribute shape' => array( array( 'attribute_size' => 'M' ) ),
		);
	}

	public function test_a_variation_with_valid_attributes_is_accepted() {
		$this->assertAccepted(
			self::VARIABLE,
			self::VARIATION,
			array(
				'attribute_size'     => 'M',
				'attribute_pa_color' => 'Blue',
			)
		);
	}

	/**
	 * @dataProvider internal_keys
	 *
	 * @param string $key Internal order item key.
	 */
	public function test_a_variation_with_an_internal_key_is_refused( string $key ) {
		$this->refuse(
			self::VARIABLE,
			self::VARIATION,
			array(
				'attribute_pa_color' => 'red',
				$key                 => '12',
			)
		);
	}

	public function internal_keys(): array {
		return array(
			'product id'    => array( '_product_id' ),
			'variation id'  => array( '_variation_id' ),
			'reduced stock' => array( '_reduced_stock' ),
			'tax class'     => array( '_tax_class' ),
		);
	}

	public function test_a_variation_with_a_non_variation_attribute_is_refused() {
		$this->refuse(
			self::VARIABLE,
			self::VARIATION,
			array(
				'attribute_pa_color' => 'red',
				'attribute_material' => 'Cotton',
			)
		);
	}

	public function test_a_wrong_value_for_a_fixed_attribute_is_refused() {
		$this->refuse(
			self::VARIABLE,
			self::VARIATION,
			array(
				'attribute_size'     => 'XL',
				'attribute_pa_color' => 'red',
			)
		);
	}

	public function test_an_any_attribute_with_a_value_outside_the_options_is_refused() {
		$this->refuse( self::VARIABLE, self::VARIATION, array( 'attribute_pa_color' => 'green' ) );
	}

	public function test_a_variation_of_another_parent_is_refused() {
		$this->products[ self::VARIATION ]->props['parent_id'] = 99;

		$this->refuse( self::VARIABLE, self::VARIATION, array( 'attribute_pa_color' => 'red' ) );
	}

	public function test_a_password_protected_product_is_refused() {
		$this->products[ self::SIMPLE ]->props['post_password'] = 'secret';

		$this->refuse( self::SIMPLE, 0, array() );
	}

	/**
	 * @dataProvider hidden_statuses
	 *
	 * @param string $status Post status.
	 */
	public function test_a_product_that_is_not_published_is_refused( string $status ) {
		$this->products[ self::SIMPLE ]->props['status'] = $status;

		$this->refuse( self::SIMPLE, 0, array() );
	}

	public function hidden_statuses(): array {
		return array(
			'private' => array( 'private' ),
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
		);
	}

	public function test_add_to_cart_validation_runs_with_woocommerces_arguments() {
		Filters\expectApplied( 'woocommerce_add_to_cart_validation' )->twice()->andReturnUsing(
			function ( ...$args ) {
				$this->validations[] = $args;
				return $args[0];
			}
		);

		$this->assertAccepted( self::SIMPLE, 0, array() );
		$this->assertAccepted( self::VARIABLE, self::VARIATION, array( 'attribute_pa_color' => 'red' ) );

		$this->assertSame( array( true, self::SIMPLE, 1 ), $this->validations[0] );
		$this->assertSame(
			array(
				true,
				self::VARIABLE,
				1,
				self::VARIATION,
				array(
					'attribute_size'     => 'M',
					'attribute_pa_color' => 'red',
				),
			),
			$this->validations[1]
		);
	}

	public function test_add_to_cart_validation_returning_false_refuses() {
		Filters\expectApplied( 'woocommerce_add_to_cart_validation' )->once()->andReturn( false );

		$this->refuse( self::SIMPLE, 0, array() );
	}

	public function test_an_error_notice_from_validation_refuses_and_is_removed_again() {
		$this->notices = array( 'notice' => array( array( 'notice' => 'earlier' ) ) );
		Filters\expectApplied( 'woocommerce_add_to_cart_validation' )->once()->andReturnUsing(
			function () {
				$this->notices['error'][] = array( 'notice' => 'Minimum quantity is 2.' );
				return true;
			}
		);

		try {
			( new SnapshotFactory() )->fromProduct( self::SIMPLE, 0, array(), 1 );
			$this->fail( 'refusal expected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( SnapshotFactory::VALIDATION_FAILED, $e->getMessage() );
		}
		$this->assertSame( array( 'notice' => array( array( 'notice' => 'earlier' ) ) ), $this->notices, 'earlier notices kept, the new error removed' );
	}

	public function test_validation_passes_without_new_errors_and_keeps_existing_errors() {
		$this->notices = array( 'error' => array( array( 'notice' => 'from before' ) ) );

		$this->assertTrue(
			SnapshotFactory::passesValidation(
				function () {
					$this->notices['success'][] = array( 'notice' => 'added' );
					return true;
				}
			)
		);
		$this->assertSame( array( 'error' => array( array( 'notice' => 'from before' ) ) ), $this->notices );
		$this->assertFalse(
			SnapshotFactory::passesValidation(
				static function () {
					return false;
				}
			)
		);
	}

	/**
	 * A cart with the given lines. Totals are a no-op; reaching the shipping
	 * question means every line check passed.
	 *
	 * @param array $lines Cart lines as WC_Cart::get_cart() returns them.
	 */
	private function cart( array $lines ): \WC_Cart {
		if ( ! class_exists( 'WC_Cart', false ) ) {
			eval( 'class WC_Cart { public function calculate_totals() { throw new \\LogicException( "totals" ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		return new class( $lines ) extends \WC_Cart {
			private $lines;

			public function __construct( array $lines ) {
				$this->lines = $lines;
			}

			public function calculate_totals() {
			}

			public function get_cart() {
				return $this->lines;
			}

			public function needs_shipping() {
				throw new \LogicException( 'lines checked' );
			}
		};
	}

	/**
	 * A cart line.
	 *
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @param array $variation    Variation data.
	 */
	private function line( int $product_id, int $variation_id, array $variation ): array {
		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => $variation,
			'quantity'     => 1,
			'data'         => $this->products[ $variation_id > 0 ? $variation_id : $product_id ],
		);
	}

	private function assertCartAccepted( array $lines ): void {
		try {
			( new SnapshotFactory() )->fromCart( $this->cart( $lines ) );
			$this->fail( 'line checks were expected to pass' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'lines checked', $e->getMessage() );
		}
	}

	public function test_a_cart_with_the_parents_variation_attributes_is_accepted() {
		$this->assertCartAccepted(
			array(
				'a' => $this->line( self::SIMPLE, 0, array() ),
				'b' => $this->line(
					self::VARIABLE,
					self::VARIATION,
					array(
						'attribute_size'     => 'M',
						'attribute_pa_color' => 'red',
					)
				),
			)
		);
	}

	/**
	 * @dataProvider cart_variation_data_not_of_the_parent
	 *
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @param array $variation    Variation data of the line.
	 */
	public function test_a_cart_line_with_variation_data_that_is_no_variation_attribute_is_refused( int $product_id, int $variation_id, array $variation ) {
		$this->expectException( \InvalidArgumentException::class );

		( new SnapshotFactory() )->fromCart( $this->cart( array( 'x' => $this->line( $product_id, $variation_id, $variation ) ) ) );
	}

	public function cart_variation_data_not_of_the_parent(): array {
		return array(
			'attribute not used for variations' => array( self::VARIABLE, self::VARIATION, array( 'attribute_material' => 'Cotton' ) ),
			'unknown attribute'                 => array( self::VARIABLE, self::VARIATION, array( 'attribute_weight' => '1' ) ),
			'internal meta name'                => array( self::VARIABLE, self::VARIATION, array( 'attribute__reduced_stock' => '1' ) ),
			'nested prefix'                     => array( self::VARIABLE, self::VARIATION, array( 'attribute_attribute__tax_class' => 'zero-rate' ) ),
			'simple product with data'          => array( self::SIMPLE, 0, array( 'attribute_size' => 'M' ) ),
		);
	}

	public function test_a_cart_line_whose_parent_is_gone_is_refused() {
		$line = $this->line( self::VARIABLE, self::VARIATION, array( 'attribute_size' => 'M' ) );
		unset( $this->products[ self::VARIABLE ] );

		$this->expectException( \InvalidArgumentException::class );
		( new SnapshotFactory() )->fromCart( $this->cart( array( 'x' => $line ) ) );
	}

	public function test_cart_validation_runs_woocommerces_cart_checks_and_refuses_on_errors() {
		if ( ! class_exists( 'WC_Cart', false ) ) {
			eval( 'class WC_Cart { public function calculate_totals() { throw new \\LogicException( "totals" ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		Actions\expectDone( 'woocommerce_check_cart_items' )->once()->whenHappen(
			function () {
				$this->notices['error'][] = array( 'notice' => 'Not enough stock.' );
			}
		);

		try {
			( new SnapshotFactory() )->fromCart( new \WC_Cart() );
			$this->fail( 'refusal expected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( SnapshotFactory::VALIDATION_FAILED, $e->getMessage() );
		}
		$this->assertSame( array(), $this->notices, 'the error is not left for the next page' );
	}
}
