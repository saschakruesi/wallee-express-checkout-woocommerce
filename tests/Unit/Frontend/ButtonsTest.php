<?php
/**
 * Cart and product buttons tests (setting and activity gates).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Admin\Settings;
use Wallee\ExpressCheckout\Frontend\Assets;
use Wallee\ExpressCheckout\Frontend\CartButtons;
use Wallee\ExpressCheckout\Frontend\ProductButtons;

/**
 * @covers \Wallee\ExpressCheckout\Frontend\CartButtons
 * @covers \Wallee\ExpressCheckout\Frontend\ProductButtons
 */
class ButtonsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Assets::resetEmitted();
		Functions\stubs(
			array(
				'esc_attr',
				'esc_url',
				'esc_html__' => static function ( $text ) {
					return $text;
				},
				'__'         => static function ( $text ) {
					return $text;
				},
				'wp_kses_post',
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	public function test_cart_output_is_off_when_setting_is_off() {
		$this->stub_options( array( Settings::OPT_CART => 'no' ) );
		$cart = new CartButtons( new Settings(), static function () {
			return true;
		} );
		ob_start();
		$cart->render();
		$this->assertSame( '', ob_get_clean() );

		$block = array( 'blockName' => CartButtons::BLOCK );
		$this->assertSame( '<p>x</p>', $cart->filterBlock( '<p>x</p>', $block ) );
	}

	public function test_cart_output_is_off_when_add_on_is_inactive() {
		$this->stub_options( array( Settings::OPT_CART => 'yes' ) );
		$cart = new CartButtons( new Settings(), static function () {
			return false;
		} );
		ob_start();
		$cart->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_cart_renders_container_and_extends_the_block_once() {
		$this->stub_options( array( Settings::OPT_CART => 'yes' ) );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'is_cart' )->justReturn( true );
		$cart = new CartButtons( new Settings(), static function () {
			return true;
		} );

		ob_start();
		$cart->render();
		$this->assertStringContainsString( 'id="wallee-ec-container-cart"', ob_get_clean() );

		Assets::resetEmitted();
		$block = array( 'blockName' => CartButtons::BLOCK );
		$once  = $cart->filterBlock( '<p>x</p>', $block );
		$this->assertStringContainsString( 'id="wallee-ec-container-cart"', $once );
		$this->assertSame( $once, $cart->filterBlock( $once, $block ) );
		$this->assertSame( '<p>y</p>', $cart->filterBlock( '<p>y</p>', array( 'blockName' => 'core/paragraph' ) ) );
	}

	public function test_product_output_is_off_when_setting_is_off() {
		$this->stub_options( array( Settings::OPT_PRODUCT => 'no' ) );
		$product = new ProductButtons( new Settings(), static function () {
			return true;
		} );
		ob_start();
		$product->render();
		$this->assertSame( '', ob_get_clean() );
	}

	private function active(): callable {
		return static function () {
			return true;
		};
	}

	public function test_cart_render_needs_the_cart_page() {
		$this->stub_options( array( Settings::OPT_CART => 'yes' ) );
		Functions\when( 'is_cart' )->justReturn( false );
		$cart = new CartButtons( new Settings(), $this->active() );
		ob_start();
		$cart->render();
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( '<p>x</p>', $cart->filterBlock( '<p>x</p>', array( 'blockName' => CartButtons::BLOCK ) ) );
	}

	public function test_cart_container_is_emitted_once_per_request() {
		$this->stub_options( array( Settings::OPT_CART => 'yes' ) );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		$cart = new CartButtons( new Settings(), $this->active() );

		$first = $cart->filterBlock( '<p>x</p>', array( 'blockName' => CartButtons::BLOCK ) );
		$this->assertStringContainsString( 'wallee-ec-container-cart', $first );
		// A second, separate rendering of the block gets nothing.
		$this->assertSame( '<p>y</p>', $cart->filterBlock( '<p>y</p>', array( 'blockName' => CartButtons::BLOCK ) ) );
		ob_start();
		$cart->render();
		$this->assertSame( '', ob_get_clean() );

		Assets::resetEmitted();
		ob_start();
		$cart->render();
		$this->assertStringContainsString( 'wallee-ec-container-cart', ob_get_clean() );
	}

	/**
	 * @param string $type Product type.
	 */
	private function render_product( string $type ): string {
		global $product;
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_type' )->andReturn( $type );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$buttons = new ProductButtons( new Settings(), $this->active() );
		ob_start();
		$buttons->render();
		Assets::resetEmitted();
		return ob_get_clean();
	}

	public function test_product_container_for_simple_and_variable_only() {
		$this->stub_options( array( Settings::OPT_PRODUCT => 'yes' ) );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );

		foreach ( array( 'simple', 'variable' ) as $type ) {
			$html = $this->render_product( $type );
			$this->assertStringContainsString( 'data-context="product" data-product-id="42" data-product-type="' . $type . '"', $html );
		}
		foreach ( array( 'grouped', 'external' ) as $type ) {
			$this->assertSame( '', $this->render_product( $type ) );
		}
	}

	public function test_product_render_needs_the_product_page() {
		$this->stub_options( array( Settings::OPT_PRODUCT => 'yes' ) );
		Functions\when( 'is_product' )->justReturn( false );
		$this->assertSame( '', $this->render_product( 'simple' ) );
	}
}
