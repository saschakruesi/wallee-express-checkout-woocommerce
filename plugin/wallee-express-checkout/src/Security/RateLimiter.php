<?php
/**
 * Fixed-window rate limiter with atomic counters.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per bucket within a time window.
 *
 * With a database, every hit is one `INSERT ... ON DUPLICATE KEY UPDATE` on
 * the add-on's own counter table: the database increments the counter (or
 * starts a new window when the old one is over) and hands the new count back
 * through `LAST_INSERT_ID(expr)`, which is per connection. There is no read
 * before the write, so parallel requests cannot both see the same count. No
 * persistent object cache is needed.
 *
 * If the statement fails (for example before the table exists), or no
 * database is given, the limiter falls back to transients, which are not
 * atomic.
 */
final class RateLimiter {

	const TABLE = 'wallee_ec_rate';

	/**
	 * Database, null for the transient fallback only.
	 *
	 * @var \wpdb|null
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db WordPress database object; null uses transients only.
	 */
	public function __construct( ?\wpdb $db = null ) {
		$this->db = $db;
	}

	/**
	 * Full table name for a database object.
	 *
	 * @param \wpdb $db Database.
	 */
	public static function tableName( \wpdb $db ): string {
		return $db->prefix . self::TABLE;
	}

	/**
	 * Registers a hit and tells whether it is within the limit.
	 *
	 * @param string $bucket         Bucket name (e.g. action plus client).
	 * @param int    $max            Maximum hits per window.
	 * @param int    $window_seconds Window length in seconds.
	 */
	public function allow( string $bucket, int $max, int $window_seconds ): bool {
		$key = md5( $bucket );
		if ( null !== $this->db ) {
			$count = $this->hit( $key, $window_seconds );
			if ( null !== $count ) {
				return $count <= $max;
			}
		}

		return $this->allowByTransient( $key, $max, $window_seconds );
	}

	/**
	 * Deletes counters whose window is over. Called by the daily cleanup.
	 *
	 * @param \wpdb $db Database.
	 * @return int Number of deleted rows.
	 */
	public static function purgeExpired( \wpdb $db ): int {
		$table = self::tableName( $db );

		return (int) $db->query(
			$db->prepare(
				"DELETE FROM {$table} WHERE expires_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				time()
			)
		);
	}

	/**
	 * Claims a name once, atomically: the counter table doubles as a store of
	 * one-time claims (`INSERT IGNORE` on its primary key). Of two requests
	 * claiming the same name at the same time, exactly one gets true. The
	 * claim expires with its row (purgeExpired()).
	 *
	 * @param \wpdb  $db          Database.
	 * @param string $name        Claim name (no personal data).
	 * @param int    $ttl_seconds How long the claim is kept.
	 * @return bool|null True if this call took the claim, false if it was taken before,
	 *                   null if the database did not answer.
	 */
	public static function claimOnce( \wpdb $db, string $name, int $ttl_seconds ): ?bool {
		$table  = self::tableName( $db );
		$result = $db->query(
			$db->prepare(
				"INSERT IGNORE INTO {$table} (bucket, hits, expires_at) VALUES (%s, 1, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				md5( 'once|' . $name ),
				time() + max( 1, $ttl_seconds )
			)
		);
		if ( false === $result ) {
			return null;
		}

		return (int) $result > 0;
	}

	/**
	 * Counts one hit in the database.
	 *
	 * @param string $key            Bucket key (md5).
	 * @param int    $window_seconds Window length in seconds.
	 * @return int|null The bucket's count including this hit, null if the database did not answer.
	 */
	private function hit( string $key, int $window_seconds ): ?int {
		$table   = self::tableName( $this->db );
		$now     = time();
		$expires = $now + max( 1, $window_seconds );

		// Assignments run left to right: `hits` is computed from the old expiry before it changes.
		$result = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$table} (bucket, hits, expires_at) VALUES (%s, LAST_INSERT_ID(1), %d) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(expires_at <= %d, 1, hits + 1)), expires_at = IF(expires_at <= %d, %d, expires_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				$key,
				$expires,
				$now,
				$now,
				$expires
			)
		);
		if ( false === $result ) {
			return null;
		}

		// Per connection, so it is this request's count. mysqli's insert ID stays 0 here (the table
		// has no AUTO_INCREMENT column), hence the explicit read.
		$count = (int) $this->db->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $count > 0 ? $count : null;
	}

	/**
	 * Fallback counter in a transient (read, then write: not atomic).
	 *
	 * @param string $key            Bucket key (md5).
	 * @param int    $max            Maximum hits per window.
	 * @param int    $window_seconds Window length in seconds.
	 */
	private function allowByTransient( string $key, int $max, int $window_seconds ): bool {
		$transient = 'wallee_ec_rl_' . $key;
		$count     = (int) get_transient( $transient );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $transient, $count + 1, $window_seconds );

		return true;
	}
}
