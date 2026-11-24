<?php
/**
 * UrlSigner tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Security\UrlSigner;

/**
 * @covers \Wallee\ExpressCheckout\Security\UrlSigner
 */
class UrlSignerTest extends TestCase {

	public function test_signature_is_bound_to_uuid_and_purpose() {
		$s   = new UrlSigner( 'k' );
		$sig = $s->sign( 'abc', 'return' );
		$this->assertSame( 64, strlen( $sig ) );
		$this->assertSame( hash_hmac( 'sha256', 'return|abc', 'k' ), $sig );
		$this->assertTrue( $s->verify( 'abc', 'return', $sig ) );
		$this->assertFalse( $s->verify( 'abc', 'shipping', $sig ) );
		$this->assertFalse( $s->verify( 'abd', 'return', $sig ) );
		$this->assertFalse( $s->verify( 'abc', 'return', '' ) );
		$this->assertFalse( ( new UrlSigner( 'other' ) )->verify( 'abc', 'return', $sig ) );
	}

	public function test_new_uuid_is_64_hex_and_unique() {
		$a = UrlSigner::newUuid();
		$b = UrlSigner::newUuid();
		$this->assertNotSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );
		$this->assertTrue( ctype_xdigit( $a ) );
	}
}
