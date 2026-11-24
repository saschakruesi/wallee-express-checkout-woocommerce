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

		foreach ( self::statusNotices( self::adapter(), Compat\SdkShim::status() ) as $notice ) {
			self::notice( $notice[0], $notice[1], $notice[2] );
		}

		self::register_components();
	}

	/**
	 * Admin notices for a usable main plugin: untested version, missing
	 * credentials, unavailable SDK shim.
	 *
	 * @param MainPluginAdapter $adapter     Adapter.
	 * @param string            $shim_status Result of Compat\SdkShim::status().
	 * @return array<int, array{0: string, 1: string, 2: bool}> CSS class, message, dismissible.
	 */
	public static function statusNotices( MainPluginAdapter $adapter, string $shim_status ): array {
		$notices = array();
		if ( $adapter->isNewerThanTested() ) {
			$notices[] = array(
				'notice-warning',
				sprintf(
					/* translators: 1: installed wallee plugin version, 2: last tested version. */
					__( 'wallee Express Checkout was tested up to wallee plugin version %2$s, but version %1$s is installed. Please test express checkout before relying on it.', 'wallee-express-checkout' ),
					$adapter->version(),
					MainPluginAdapter::TESTED_UP_TO
				),
				true,
			);
		}
		if ( ! $adapter->isConfigured() ) {
			$notices[] = array(
				'notice-warning',
				__( 'wallee Express Checkout buttons stay hidden until the wallee plugin is configured with a space ID, an application user ID and its key.', 'wallee-express-checkout' ),
				false,
			);
		}
		if ( 'unavailable' === $shim_status ) {
			$notices[] = array(
				'notice-error',
				__( "wallee Express Checkout is disabled: the wallee plugin's SDK cannot read Express Checkout transactions and the compatibility shim is not active, so the buttons are not shown.", 'wallee-express-checkout' ),
				false,
			);
		}

		return $notices;
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
	 * Whether express checkout may run: the main plugin is usable and
	 * configured, and its SDK can read Express Checkout transactions (without
	 * that, every webhook for these transactions would fail and could get the
	 * space's webhook listener deactivated, which also hits normal orders).
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		return array() === self::adapter()->missingRequirements()
			&& self::adapter()->isConfigured()
			&& 'unavailable' !== Compat\SdkShim::status();
	}

	/**
	 * Wires the components. Later tasks add one line each here.
	 */
	private static function register_components(): void {
		Session\Installer::register();
		Http\SessionController::register();
		Http\ShippingController::register();
		Http\DomainAssociation::register();
		Http\ReturnHandler::register();
		Http\ResultHandler::register();
		Frontend\CartButtons::register();
		Frontend\ProductButtons::register();
		Order\LinkScheduler::register();
		Order\FailedOrderSettlement::register();
		Privacy\PersonalData::register();
		add_filter( 'woocommerce_cancel_unpaid_order', array( Order\OrderFactory::class, 'cancelUnpaid' ), 10, 2 );
		// Admin-only hooks; registered on every request so they are also there for WP-CLI and admin-post.
		Admin\Settings::register();
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
				if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
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
