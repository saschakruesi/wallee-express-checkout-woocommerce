<?php
/**
 * SetupService tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Admin;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Admin\SetupService;
use Wallee\ExpressCheckout\MainPluginAdapter;

/**
 * Adapter whose main plugin calls are scripted.
 */
class ScriptedAdapter extends MainPluginAdapter {

	/** @var string[] Steps that throw. */
	public $failing = array();
	/** @var string[] Calls made. */
	public $calls = array();
	/** @var string[] Log lines. */
	public $logs = array();

	public function verifyCredentials(): void {
		$this->calls[] = 'credentials';
		if ( in_array( 'credentials', $this->failing, true ) ) {
			throw new \RuntimeException( 'secret-key-123 rejected at /var/www/x.php' );
		}
	}

	public function installWebhooks(): void {
		$this->calls[] = 'webhooks';
		if ( in_array( 'webhooks', $this->failing, true ) ) {
			throw new \RuntimeException( 'boom' );
		}
	}

	public function syncPaymentMethods(): void {
		$this->calls[] = 'payment_methods';
		if ( in_array( 'payment_methods', $this->failing, true ) ) {
			throw new \RuntimeException( 'boom' );
		}
	}

	public function log( string $message, string $level = 'info' ): void {
		$this->logs[] = $level . ': ' . $message;
	}
}

/**
 * @covers \Wallee\ExpressCheckout\Admin\SetupService
 */
class SetupServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_steps_succeed_in_order() {
		$adapter = new ScriptedAdapter();
		$results = ( new SetupService( $adapter ) )->run();

		$this->assertSame( array( 'credentials', 'webhooks', 'payment_methods' ), array_column( $results, 'step' ) );
		$this->assertSame( array( true, true, true ), array_column( $results, 'ok' ) );
		$this->assertSame( array( 'credentials', 'webhooks', 'payment_methods' ), $adapter->calls );
		foreach ( $results as $result ) {
			$this->assertNotSame( '', $result['message'] );
		}
	}

	public function test_failing_credentials_do_not_stop_later_steps_and_leak_nothing() {
		$adapter          = new ScriptedAdapter();
		$adapter->failing = array( 'credentials' );
		$results          = ( new SetupService( $adapter ) )->run();

		$this->assertSame( array( false, true, true ), array_column( $results, 'ok' ) );
		$this->assertSame( array( 'credentials', 'webhooks', 'payment_methods' ), $adapter->calls );
		$this->assertStringNotContainsString( 'secret-key-123', $results[0]['message'] );
		$this->assertStringNotContainsString( '/var/www', $results[0]['message'] );
		$this->assertCount( 1, $adapter->logs );
		$this->assertStringContainsString( 'credentials', $adapter->logs[0] );
		$this->assertStringContainsString( 'RuntimeException', $adapter->logs[0] );
		$this->assertStringNotContainsString( 'secret-key-123', $adapter->logs[0] );
	}

	public function test_webhook_install_throwing_is_reported_and_payment_methods_still_run() {
		$adapter          = new ScriptedAdapter();
		$adapter->failing = array( 'webhooks' );
		$results          = ( new SetupService( $adapter ) )->run();

		$this->assertSame( array( true, false, true ), array_column( $results, 'ok' ) );
		$this->assertSame( 'webhooks', $results[1]['step'] );
		$this->assertSame( 'payment_methods', $adapter->calls[2] );
	}
}
