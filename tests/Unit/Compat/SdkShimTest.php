<?php
/**
 * SdkShim tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Compat\SdkShim;

/**
 * @covers \Wallee\ExpressCheckout\Compat\SdkShim
 */
class SdkShimTest extends TestCase {

	/** @var string */
	private $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/wallee-ec-shim-' . uniqid();
		mkdir( $this->base, 0777, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->base );
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			is_dir( $entry ) ? $this->rrmdir( $entry ) : unlink( $entry );
		}
		rmdir( $dir );
	}

	private function make_sdk( string $plugin, string $contents ): string {
		$dir = $this->base . '/' . $plugin . '/wallee-sdk/lib/Model';
		mkdir( $dir, 0777, true );
		$file = $dir . '/TransactionUserInterfaceType.php';
		file_put_contents( $file, $contents );
		return $file;
	}

	private function reader(): callable {
		return static function ( string $path ) {
			return is_readable( $path ) ? file_get_contents( $path ) : false;
		};
	}

	public function test_returns_path_when_sdk_lacks_value(): void {
		$file = $this->make_sdk( 'woo-wallee', "const TERMINAL = 'TERMINAL';" );
		$this->assertSame(
			$file,
			SdkShim::sdkFileToShim( array( 'woo-wallee/wallee.php' ), $this->base, $this->reader() )
		);
	}

	public function test_returns_null_when_sdk_has_value(): void {
		$this->make_sdk( 'woo-wallee', "const EXPRESS_CHECKOUT = 'EXPRESS_CHECKOUT';" );
		$this->assertNull( SdkShim::sdkFileToShim( array( 'woo-wallee/wallee.php' ), $this->base, $this->reader() ) );
	}

	public function test_returns_null_when_main_plugin_not_active(): void {
		$this->make_sdk( 'woo-wallee', "const TERMINAL = 'TERMINAL';" );
		$this->assertNull( SdkShim::sdkFileToShim( array( 'akismet/akismet.php' ), $this->base, $this->reader() ) );
	}

	public function test_ignores_wallee_php_without_sdk_directory(): void {
		mkdir( $this->base . '/foo', 0777, true );
		$this->assertNull( SdkShim::sdkFileToShim( array( 'foo/wallee.php' ), $this->base, $this->reader() ) );
	}

	public function test_skips_non_matching_entry_and_finds_real_one(): void {
		mkdir( $this->base . '/foo', 0777, true );
		$file = $this->make_sdk( 'woo-wallee', "const TERMINAL = 'TERMINAL';" );
		$this->assertSame(
			$file,
			SdkShim::sdkFileToShim( array( 'foo/wallee.php', 'woo-wallee/wallee.php' ), $this->base, $this->reader() )
		);
	}

	public function test_compat_file_declares_the_extra_value(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/plugin/wallee-express-checkout/compat/TransactionUserInterfaceType.php' );
		$this->assertStringContainsString( "const EXPRESS_CHECKOUT = 'EXPRESS_CHECKOUT';", $source );
		$this->assertStringContainsString( 'self::EXPRESS_CHECKOUT,', $source );
	}
}
