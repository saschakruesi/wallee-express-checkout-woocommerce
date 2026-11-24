<?php
/**
 * Plugin boot.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the environment and wires the components.
 */
final class Plugin {

	/**
	 * Adapter instance.
	 *
	 * @var MainPluginAdapter|null
	 */
	private static $adapter = null;

	/**
	 * Settings instance.
	 *
	 * @var Admin\Settings|null
	 */
	private static $settings = null;

	/**
	 * Boots the plugin on `plugins_loaded`.
	 */
	public static function boot(): void {
		$missing = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}
		$missing = array_merge( $missing, self::adapter()->missingRequirements() );

		if ( ! empty( $missing ) ) {
			self::notice(
				'notice-error',
				sprintf(
					/* translators: %s: comma separated list of missing classes and methods. */
					__( 'wallee Express Checkout is inactive. Missing: %s. Please activate WooCommerce and a compatible wallee plugin.', 'wallee-express-checkout' ),
					implode( ', ', $missing )
				),
				false
			);
			return;
		}

		if ( self::adapter()->isNewerThanTested() ) {
			self::notice(
				'notice-warning',
				sprintf(
					/* translators: 1: installed wallee plugin version, 2: last tested version. */
					__( 'wallee Express Checkout was tested up to wallee plugin version %2$s, but version %1$s is installed. Please test express checkout before relying on it.', 'wallee-express-checkout' ),
					self::adapter()->version(),
					MainPluginAdapter::TESTED_UP_TO
				),
				true
			);
		}

		if ( 'unavailable' === Compat\SdkShim::status() ) {
			self::notice(
				'notice-warning',
				__( "The wallee plugin's SDK cannot read Express Checkout transactions and the compatibility shim is not active.", 'wallee-express-checkout' ),
				false
			);
		}

		self::register_components();
	}

	/**
	 * The shared adapter.
	 *
	 * @return MainPluginAdapter
	 */
	public static function adapter(): MainPluginAdapter {
		if ( null === self::$adapter ) {
			self::$adapter = new MainPluginAdapter();
		}
		return self::$adapter;
	}

	/**
	 * The shared settings reader.
	 *
	 * @return Admin\Settings
	 */
	public static function settings(): Admin\Settings {
		if ( null === self::$settings ) {
			self::$settings = new Admin\Settings();
		}
		return self::$settings;
	}

	/**
	 * Whether the main plugin is usable and configured.
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		return array() === self::adapter()->missingRequirements() && self::adapter()->isConfigured();
	}

	/**
	 * Wires the components. Later tasks add one line each here.
	 */
	private static function register_components(): void {
		Session\Installer::register();
		Http\SessionController::register();
		Http\ShippingController::register();
		Http\DomainAssociation::register();
		Frontend\CartButtons::register();
		Frontend\ProductButtons::register();
		if ( is_admin() ) {
			Admin\Settings::register();
		}
	}

	/**
	 * Registers an admin notice.
	 *
	 * @param string $class_name   Notice CSS class.
	 * @param string $message      Plain text message.
	 * @param bool   $dismissible  Whether it can be dismissed.
	 */
	private static function notice( string $class_name, string $message, bool $dismissible ): void {
		add_action(
			'admin_notices',
			static function () use ( $class_name, $message, $dismissible ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				printf(
					'<div class="notice %1$s%2$s"><p>%3$s</p></div>',
					esc_attr( $class_name ),
					$dismissible ? ' is-dismissible' : '',
					esc_html( $message )
				);
			}
		);
	}
}
