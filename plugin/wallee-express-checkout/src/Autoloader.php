<?php
/**
 * PSR-4 autoloader for the plugin namespace.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `Wallee\ExpressCheckout\Foo\Bar` to `<base_dir>/Foo/Bar.php`.
 */
final class Autoloader {

	const PREFIX = 'Wallee\\ExpressCheckout\\';

	/**
	 * Registers the autoloader for the given source directory.
	 *
	 * @param string $base_dir Absolute path of the `src` directory.
	 */
	public static function register( string $base_dir ): void {
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
