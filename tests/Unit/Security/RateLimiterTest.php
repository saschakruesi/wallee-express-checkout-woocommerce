<?php
/**
 * RateLimiter tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Security\RateLimiter;

/**
 * @covers \Wallee\ExpressCheckout\Security\RateLimiter
 */
class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allows_up_to_max_then_denies_with_independent_buckets() {
		$store = array();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$limiter = new RateLimiter();
		$this->assertTrue( $limiter->allow( 'a', 2, 60 ) );
		$this->assertTrue( $limiter->allow( 'a', 2, 60 ) );
		$this->assertFalse( $limiter->allow( 'a', 2, 60 ) );
		$this->assertTrue( $limiter->allow( 'b', 2, 60 ) );
		$this->assertArrayHasKey( 'wallee_ec_rl_' . md5( 'a' ), $store );
	}
}
