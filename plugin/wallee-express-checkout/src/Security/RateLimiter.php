<?php
/**
 * Transient based fixed-window rate limiter.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per bucket within a time window.
 */
final class RateLimiter {

	/**
	 * Registers a hit and tells whether it is within the limit.
	 *
	 * @param string $bucket         Bucket name (e.g. action plus client).
	 * @param int    $max            Maximum hits per window.
	 * @param int    $window_seconds Window length in seconds.
	 */
	public function allow( string $bucket, int $max, int $window_seconds ): bool {
		$key   = 'wallee_ec_rl_' . md5( $bucket );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window_seconds );

		return true;
	}
}
