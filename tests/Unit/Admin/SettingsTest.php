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

	public function test_own_tab_follows_the_wallee_tab() {
		Functions\when( '__' )->returnArg();
		$settings = new Settings();

		$this->assertSame(
			array( 'general', 'checkout', 'wallee', 'wallee_ec', 'advanced' ),
			array_keys(
				$settings->addTab(
					array(
						'general'  => 'General',
						'checkout' => 'Payments',
						'wallee'   => 'wallee',
						'advanced' => 'Advanced',
					)
				)
			)
		);
		$this->assertSame( 'wallee Express Checkout', $settings->addTab( array() )['wallee_ec'] );
		$this->assertSame( array( 'general', 'wallee_ec' ), array_keys( $settings->addTab( array( 'general' => 'General' ) ) ), 'appended without the main plugin tab' );
		$this->assertSame( array( 'wallee_ec' ), array_keys( $settings->addTab( 'garbage' ) ) );
	}

	public function test_tab_holds_every_setting_and_keeps_the_apple_content_out_of_autoload() {
		Functions\when( '__' )->returnArg();
		$fields = ( new Settings() )->fields();
		$by_id  = array_column( $fields, null, 'id' );

		foreach ( array( 'wallee_ec_status', 'wallee_ec_cart_enabled', 'wallee_ec_product_enabled', 'wallee_ec_environment', 'wallee_ec_max_columns', 'wallee_ec_max_rows', 'wallee_ec_apple_domain_association', 'wallee_ec_configure' ) as $id ) {
			$this->assertArrayHasKey( $id, $by_id, $id );
		}
		$this->assertFalse( $by_id['wallee_ec_apple_domain_association']['autoload'] );
		$this->assertSame( 'title', $fields[0]['type'] );
		$this->assertSame( 'sectionend', $fields[ count( $fields ) - 1 ]['type'] );
	}

	public function test_register_uses_the_tab_hooks_and_no_payments_section() {
		Functions\when( 'plugin_basename' )->justReturn( 'wallee-express-checkout/wallee-express-checkout.php' );
		if ( ! defined( 'WALLEE_EC_FILE' ) ) {
			define( 'WALLEE_EC_FILE', '/plugins/wallee-express-checkout/wallee-express-checkout.php' );
		}

		Settings::register();

		$this->assertSame( 50, has_filter( 'woocommerce_settings_tabs_array', 'Wallee\ExpressCheckout\Admin\Settings->addTab()' ) );
		$this->assertNotFalse( has_action( 'woocommerce_settings_wallee_ec', 'Wallee\ExpressCheckout\Admin\Settings->renderTab()' ) );
		$this->assertNotFalse( has_action( 'woocommerce_update_options_wallee_ec', 'Wallee\ExpressCheckout\Admin\Settings->saveTab()' ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_wallee-express-checkout/wallee-express-checkout.php', 'Wallee\ExpressCheckout\Admin\Settings->actionLinks()' ) );
		$this->assertNotFalse( has_action( 'admin_post_wallee_ec_configure', 'Wallee\ExpressCheckout\Admin\Settings->handleConfigure()' ) );
		$this->assertFalse( has_filter( 'woocommerce_get_sections_checkout' ) );
		$this->assertFalse( has_filter( 'woocommerce_get_settings_checkout' ) );
	}

	public function test_settings_link_points_to_the_tab() {
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$links = ( new Settings() )->actionLinks( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertSame( '<a href="https://shop.test/wp-admin/admin.php?page=wc-settings&tab=wallee_ec">Settings</a>', $links['settings'] );
		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=wc-settings&tab=wallee_ec', Settings::url() );
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
