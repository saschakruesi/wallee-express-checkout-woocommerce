<?php
/**
 * Creates and upgrades the session table, schedules the cleanup.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Install and upgrade routines.
 */
final class Installer {

	const DB_VERSION     = '2';
	const DB_VERSION_KEY = 'wallee_ec_db_version';
	const CRON_HOOK      = 'wallee_ec_cleanup';
	const RETENTION_DAYS = 7;

	/**
	 * Hooks upgrade check and cleanup handler.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybeUpgrade' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Creates the table and schedules the daily cleanup. Activation hook.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = SessionRepository::tableName( $wpdb );
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	session_uuid char(64) NOT NULL,
	wallee_session_id bigint(20) unsigned NOT NULL,
	space_id bigint(20) unsigned NOT NULL,
	cart_hash char(64) NOT NULL,
	snapshot longtext NOT NULL,
	state varchar(20) NOT NULL,
	order_id bigint(20) unsigned NULL DEFAULT NULL,
	transaction_id bigint(20) unsigned NULL DEFAULT NULL,
	shipping_quote longtext NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY session_uuid (session_uuid),
	KEY wallee_session_id (wallee_session_id),
	KEY state (state),
	KEY created_at (created_at)
) {$charset};"
		);

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Runs install() when the stored schema version is behind. Hooked to `admin_init`.
	 */
	public static function maybeUpgrade(): void {
		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Cron handler: purges sessions older than the retention period.
	 */
	public static function cleanup(): void {
		global $wpdb;
		( new SessionRepository( $wpdb ) )->purgeOlderThan( self::RETENTION_DAYS );
	}
}
