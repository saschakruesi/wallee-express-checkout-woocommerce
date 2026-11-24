<?php
/**
 * Plugin gate and notice tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Compat\SdkShim;
use Wallee\ExpressCheckout\Frontend\CartButtons;
use Wallee\ExpressCheckout\MainPluginAdapter;
use Wallee\ExpressCheckout\Plugin;
use Wallee\ExpressCheckout\Session\Installer;

/**
 * Adapter double: requirements met, configuration and version scripted.
 */
class GateAdapter extends MainPluginAdapter {

	/** @var bool */
	public $configured = true;

	/** @var string */
	public $installed = '3.4.11';

	public function missingRequirements(): array {
		return array();
	}

	public function isConfigured(): bool {
		return $this->configured;
	}

	protected function main_plugin_version(): string {
		return $this->installed;
	}
}

/**
 * @covers \Wallee\ExpressCheckout\Plugin
 * @covers \Wallee\ExpressCheckout\Compat\SdkShim::status
 * @covers \Wallee\ExpressCheckout\Session\Installer::register
 */
class PluginTest extends TestCase {

	/** @var GateAdapter */
	private $adapter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		// The add-on's copy of the SDK enum, as the shim loads it on a real shop.
		require_once SdkShim::compatFile();

		$this->adapter = new GateAdapter();
		$this->setAdapter( $this->adapter );
	}

	protected function tearDown(): void {
		$this->setAdapter( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function setAdapter( ?MainPluginAdapter $adapter ): void {
		$property = new \ReflectionProperty( Plugin::class, 'adapter' );
		$property->setAccessible( true );
		$property->setValue( null, $adapter );
	}

	public function test_active_with_configured_main_plugin_and_active_shim() {
		$this->assertSame( 'active', SdkShim::status() );
		$this->assertTrue( Plugin::isActive() );
	}

	public function test_shim_switched_off_by_filter_disables_express_checkout() {
		Filters\expectApplied( 'wallee_ec_enable_sdk_shim' )->andReturn( false );

		$this->assertSame( 'unavailable', SdkShim::status() );
		$this->assertFalse( Plugin::isActive() );
	}

	public function test_buttons_stay_off_through_the_is_active_callable_when_the_shim_is_off() {
		Functions\when( 'get_option' )->justReturn( 'yes' );
		$cart = new CartButtons( Plugin::settings(), array( Plugin::class, 'isActive' ) );
		$this->assertTrue( $cart->enabled() );

		Filters\expectApplied( 'wallee_ec_enable_sdk_shim' )->andReturn( false );
		$this->assertFalse( $cart->enabled() );
	}

	public function test_not_configured_main_plugin_is_inactive() {
		$this->adapter->configured = false;

		$this->assertFalse( Plugin::isActive() );
	}

	public function test_status_notices() {
		$this->assertSame( array(), Plugin::statusNotices( $this->adapter, 'active' ) );

		$this->adapter->configured = false;
		$this->adapter->installed  = '3.5.0';
		$notices                   = Plugin::statusNotices( $this->adapter, 'unavailable' );

		$this->assertSame( array( 'notice-warning', 'notice-warning', 'notice-error' ), array_column( $notices, 0 ) );
		$this->assertSame( array( true, false, false ), array_column( $notices, 2 ) );
		$this->assertStringContainsString( 'stay hidden until the wallee plugin is configured', $notices[1][1] );
		$this->assertStringContainsString( 'buttons are not shown', $notices[2][1] );
	}

	public function test_schema_upgrade_check_runs_on_every_request() {
		Installer::register();

		$this->assertNotFalse( has_action( 'init', array( Installer::class, 'maybeUpgrade' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( Installer::class, 'maybeUpgrade' ) ) );
	}
}
