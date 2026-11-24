<?php
/**
 * Front-end shared pieces tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Frontend\Assets;

/**
 * @covers \Wallee\ExpressCheckout\Frontend\Assets
 */
class AssetsTest extends TestCase {

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

	public function test_handler_url_with_and_without_trailing_slash() {
		$expected = 'https://example.test/wp-content/plugins/wallee/assets/payment/express-checkout-handler.js';
		$this->assertSame( $expected, Assets::handlerScriptUrl( 'https://example.test/wp-content/plugins/wallee/' ) );
		$this->assertSame( $expected, Assets::handlerScriptUrl( 'https://example.test/wp-content/plugins/wallee' ) );
	}

	public function test_handler_url_is_filterable() {
		Filters\expectApplied( 'wallee_ec_handler_script_url' )->once()->andReturn( 'https://cdn.test/h.js' );
		$this->assertSame( 'https://cdn.test/h.js', Assets::handlerScriptUrl( 'https://example.test/' ) );
	}

	public function test_container_markup_without_terms() {
		$html = Assets::containerHtml( 'cart', 0 );
		$this->assertSame(
			'<div class="wallee-ec" data-context="cart"><div id="wallee-ec-container-cart"></div></div>',
			$html
		);
	}

	public function test_container_carries_data_attributes() {
		$html = Assets::containerHtml(
			'product',
			0,
			array(
				'product-id'   => 12,
				'product-type' => 'variable',
			)
		);
		$this->assertStringContainsString( 'data-context="product" data-product-id="12" data-product-type="variable"', $html );
		$this->assertStringContainsString( 'id="wallee-ec-container-product"', $html );
	}

	public function test_terms_line_only_with_terms_page() {
		Functions\expect( 'get_permalink' )->once()->with( 9 )->andReturn( 'https://example.test/terms/' );
		$this->assertSame( '', Assets::termsLine( 0 ) );

		$html = Assets::containerHtml( 'cart', 9 );
		$this->assertStringContainsString( '<p class="wallee-ec-terms">By paying you accept our <a href="https://example.test/terms/"', $html );
		$this->assertStringContainsString( 'terms and conditions</a>.</p>', $html );
	}

	public function test_append_to_block_only_for_named_block() {
		$block = array( 'blockName' => 'woocommerce/proceed-to-checkout-block' );
		$this->assertSame(
			'<div>x</div><i>c</i>',
			Assets::appendToBlock( '<div>x</div>', $block, 'woocommerce/proceed-to-checkout-block', '<i>c</i>', 'cart' )
		);
		$this->assertSame(
			'<div>x</div>',
			Assets::appendToBlock( '<div>x</div>', array( 'blockName' => 'core/paragraph' ), 'woocommerce/proceed-to-checkout-block', '<i>c</i>', 'cart' )
		);
		$this->assertSame(
			'<div>x</div>',
			Assets::appendToBlock( '<div>x</div>', array(), 'woocommerce/proceed-to-checkout-block', '<i>c</i>', 'cart' )
		);
	}

	public function test_append_to_block_only_once() {
		$block   = array( 'blockName' => 'woocommerce/proceed-to-checkout-block' );
		$content = '<div>x</div>' . Assets::containerHtml( 'cart', 0 );
		$this->assertSame(
			$content,
			Assets::appendToBlock( $content, $block, 'woocommerce/proceed-to-checkout-block', Assets::containerHtml( 'cart', 0 ), 'cart' )
		);
	}

	public function test_terms_line_is_skipped_without_a_permalink() {
		Functions\when( 'get_permalink' )->justReturn( false );
		$this->assertSame( '', Assets::termsLine( 9 ) );
		$this->assertStringNotContainsString( 'wallee-ec-terms', Assets::containerHtml( 'cart', 9 ) );
	}

	public function test_origin_of_the_wallee_base_url() {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->assertSame( 'https://app-wallee.com', Assets::origin( 'https://app-wallee.com/' ) );
		$this->assertSame( 'https://app-wallee.com', Assets::origin( 'HTTPS://App-Wallee.com:443/api' ) );
		$this->assertSame( 'https://staging.example.test:8443', Assets::origin( 'https://staging.example.test:8443/' ) );
		$this->assertSame( '', Assets::origin( '/relative' ) );
		$this->assertSame( '', Assets::origin( '' ) );
	}

	public function test_claim_is_true_once_until_reset() {
		Assets::resetEmitted();
		$this->assertTrue( Assets::claim( 'cart' ) );
		$this->assertFalse( Assets::claim( 'cart' ) );
		$this->assertTrue( Assets::claim( 'product' ) );
		Assets::resetEmitted();
		$this->assertTrue( Assets::claim( 'cart' ) );
	}
}
