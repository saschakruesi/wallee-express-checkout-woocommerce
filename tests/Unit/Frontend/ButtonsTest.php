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
		$cart = new CartButtons( new Settings(), static function () {
			return true;
		} );

		ob_start();
		$cart->render();
		$this->assertStringContainsString( 'id="wallee-ec-container-cart"', ob_get_clean() );

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
}
