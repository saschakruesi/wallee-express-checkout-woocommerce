<?php
/**
 * Compatibility shim for the main plugin's bundled SDK.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the main plugin's SDK accept the transaction user interface type EXPRESS_CHECKOUT.
 *
 * The SDK bundled with wallee plugin 3.4.11 rejects that enum value. This class declares the
 * enum class from a patched copy before the main plugin's SDK autoloader can load the original.
 * Second explicit exception to "only MainPluginAdapter references the main plugin".
 */
final class SdkShim {

	const ENUM_CLASS = '\Wallee\Sdk\Model\TransactionUserInterfaceType';
	const SDK_FILE   = 'wallee-sdk/lib/Model/TransactionUserInterfaceType.php';
	const VALUE      = 'EXPRESS_CHECKOUT';

	/**
	 * Path of our compat copy.
	 *
	 * @return string
	 */
	public static function compatFile(): string {
		return dirname( __DIR__, 2 ) . '/compat/TransactionUserInterfaceType.php';
	}

	/**
	 * Loads the compat enum when the main plugin's SDK needs it. Call at plugin load time.
	 */
	public static function install(): void {
		if ( class_exists( self::ENUM_CLASS, false ) ) {
			return;
		}
		if ( ! apply_filters( 'wallee_ec_enable_sdk_shim', true ) ) {
			return;
		}

		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$reader = static function ( string $path ) {
			return is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		};

		if ( null !== self::sdkFileToShim( $plugins, WP_PLUGIN_DIR, $reader ) ) {
			require_once self::compatFile();
		}
	}

	/**
	 * Decides whether the main plugin's SDK enum file needs the shim.
	 *
	 * @param string[] $active_plugins Active plugin files relative to the plugins directory.
	 * @param string   $plugins_dir    Plugins base directory.
	 * @param callable $read_file      Takes a path, returns the contents or false.
	 * @return string|null Path of the SDK enum file to shim against, or null when no shim is needed.
	 */
	public static function sdkFileToShim( array $active_plugins, string $plugins_dir, callable $read_file ): ?string {
		foreach ( $active_plugins as $plugin ) {
			$plugin = (string) $plugin;
			if ( 'wallee.php' !== basename( $plugin ) ) {
				continue;
			}
			$path     = rtrim( $plugins_dir, '/' ) . '/' . trim( dirname( $plugin ), './' ) . '/' . self::SDK_FILE;
			$contents = $read_file( $path );
			if ( false === $contents || null === $contents ) {
				continue;
			}
			return false === strpos( (string) $contents, self::VALUE ) ? $path : null;
		}
		return null;
	}

	/**
	 * Whether our compat copy is the enum class that got loaded.
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		if ( ! class_exists( self::ENUM_CLASS, false ) ) {
			return false;
		}
		$file = ( new \ReflectionClass( self::ENUM_CLASS ) )->getFileName();
		return false !== $file && realpath( $file ) === realpath( self::compatFile() );
	}

	/**
	 * Status for display and the admin warning: 'active' (our copy is loaded), 'not_needed'
	 * (the SDK already knows the value) or 'unavailable' (the SDK cannot read Express Checkout transactions).
	 *
	 * Only call once the main plugin's SDK autoloader is registered (plugins_loaded or later).
	 *
	 * @return string
	 */
	public static function status(): string {
		if ( self::isActive() ) {
			return 'active';
		}
		if ( class_exists( self::ENUM_CLASS ) && in_array( self::VALUE, call_user_func( array( self::ENUM_CLASS, 'getAllowableEnumValues' ) ), true ) ) {
			return 'not_needed';
		}
		return 'unavailable';
	}
}
