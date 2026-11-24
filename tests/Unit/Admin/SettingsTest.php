<?php
/**
 * Settings tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Admin\Settings;

/**
 * @covers \Wallee\ExpressCheckout\Admin\Settings
 */
class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs get_option from a map; missing keys return the passed default.
	 *
	 * @param array $options Stored options.
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	public function test_defaults() {
		$this->stub_options( array() );
		$settings = new Settings();
		$this->assertFalse( $settings->cartEnabled() );
		$this->assertFalse( $settings->productEnabled() );
		$this->assertSame( 'TEST', $settings->environment() );
		$this->assertSame( 2, $settings->maxColumns() );
		$this->assertNull( $settings->maxRows() );
		$this->assertSame( '', $settings->appleDomainAssociation() );
	}

	public function test_environment_is_test_unless_production() {
		foreach ( array( 'garbage', '', 'production', 'TEST', 5 ) as $stored ) {
			$this->stub_options( array( 'wallee_ec_environment' => $stored ) );
			$this->assertSame( 'TEST', ( new Settings() )->environment() );
		}
		$this->stub_options( array( 'wallee_ec_environment' => 'PRODUCTION' ) );
		$this->assertSame( 'PRODUCTION', ( new Settings() )->environment() );
	}

	public function test_max_columns_is_clamped() {
		$this->stub_options( array( 'wallee_ec_max_columns' => '0' ) );
		$this->assertSame( 1, ( new Settings() )->maxColumns() );
		$this->stub_options( array( 'wallee_ec_max_columns' => '9' ) );
		$this->assertSame( 6, ( new Settings() )->maxColumns() );
		$this->stub_options( array( 'wallee_ec_max_columns' => '4' ) );
		$this->assertSame( 4, ( new Settings() )->maxColumns() );
	}

	public function test_max_rows_zero_means_no_limit_and_is_clamped() {
		$this->stub_options( array( 'wallee_ec_max_rows' => '0' ) );
		$this->assertNull( ( new Settings() )->maxRows() );
		$this->stub_options( array( 'wallee_ec_max_rows' => '3' ) );
		$this->assertSame( 3, ( new Settings() )->maxRows() );
		$this->stub_options( array( 'wallee_ec_max_rows' => '99' ) );
		$this->assertSame( 10, ( new Settings() )->maxRows() );
		$this->stub_options( array( 'wallee_ec_max_rows' => '-4' ) );
		$this->assertNull( ( new Settings() )->maxRows() );
	}

	public function test_cart_and_product_toggles_are_independent() {
		$this->stub_options(
			array(
				'wallee_ec_cart_enabled'    => 'yes',
				'wallee_ec_product_enabled' => 'no',
			)
		);
		$settings = new Settings();
		$this->assertTrue( $settings->cartEnabled() );
		$this->assertFalse( $settings->productEnabled() );

		$this->stub_options(
			array(
				'wallee_ec_cart_enabled'    => 'no',
				'wallee_ec_product_enabled' => 'yes',
			)
		);
		$settings = new Settings();
		$this->assertFalse( $settings->cartEnabled() );
		$this->assertTrue( $settings->productEnabled() );
	}

	public function test_apple_domain_association_is_returned_verbatim() {
		$this->stub_options( array( 'wallee_ec_apple_domain_association' => "abc\n<def>\n" ) );
		$this->assertSame( "abc\n<def>\n", ( new Settings() )->appleDomainAssociation() );
	}

	public function test_sanitize_apple_content_only_unslashes_and_trims() {
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return stripslashes( $value );
			}
		);
		$this->assertSame( "a<b>\\x\nc", Settings::sanitizeAppleContent( "  a<b>\\\\x\nc \n" ) );
		$this->assertSame( '', Settings::sanitizeAppleContent( null ) );
	}
}
