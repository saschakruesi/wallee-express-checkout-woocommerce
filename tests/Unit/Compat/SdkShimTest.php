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
		foreach ( (array) glob( $dir . '/{,.}[!.]*', GLOB_BRACE ) as $entry ) {
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

	public function test_exactly_the_five_known_constants_returns_path(): void {
		$file = $this->make_sdk( 'woo-wallee', "const IFRAME = 'IFRAME';\n    const LIGHTBOX = 'LIGHTBOX';\n    const PAYMENT_PAGE = 'PAYMENT_PAGE';\n    const MOBILE_SDK = 'MOBILE_SDK';\n    const TERMINAL = 'TERMINAL';\n" );
		$this->assertSame( $file, SdkShim::sdkFileToShim( array( 'woo-wallee/wallee.php' ), $this->base, $this->reader() ) );
	}

	public function test_unknown_extra_constant_without_value_returns_null(): void {
		$this->make_sdk( 'woo-wallee', "const IFRAME = 'IFRAME';\n    const NEW_THING = 'NEW_THING';\n" );
		$this->assertNull( SdkShim::sdkFileToShim( array( 'woo-wallee/wallee.php' ), $this->base, $this->reader() ) );
	}

	public function test_rejects_traversal_absolute_and_non_string_entries(): void {
		$this->make_sdk( 'evil', "const IFRAME = 'IFRAME';" );
		$entries = array( '../evil/wallee.php', 'a/../../evil/wallee.php', $this->base . '/evil/wallee.php', '/evil/wallee.php', 'wallee.php', 'a\\b/wallee.php', 42, null );
		$this->assertNull( SdkShim::sdkFileToShim( $entries, $this->base . '/plugins', $this->reader() ) );
		$this->assertNull( SdkShim::sdkFileToShim( $entries, $this->base, $this->reader() ) );
	}

	public function test_dot_prefixed_directory_is_supported(): void {
		$file = $this->make_sdk( '.hidden', "const IFRAME = 'IFRAME';" );
		$this->assertSame( $file, SdkShim::sdkFileToShim( array( '.hidden/wallee.php' ), $this->base, $this->reader() ) );
	}

	public function test_compat_class_declares_sdk_values_plus_express_checkout(): void {
		$file = dirname( __DIR__, 3 ) . '/plugin/wallee-express-checkout/compat/TransactionUserInterfaceType.php';
		$script = 'require ' . var_export( $file, true ) . '; echo json_encode( \\Wallee\\Sdk\\Model\\TransactionUserInterfaceType::getAllowableEnumValues() );';
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
		$this->assertSame(
			array( 'IFRAME', 'LIGHTBOX', 'PAYMENT_PAGE', 'MOBILE_SDK', 'TERMINAL', 'EXPRESS_CHECKOUT' ),
			json_decode( (string) $output, true )
		);
	}
}
