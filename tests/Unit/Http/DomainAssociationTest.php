<?php
/**
 * DomainAssociation tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Http\DomainAssociation;

/**
 * @covers \Wallee\ExpressCheckout\Http\DomainAssociation
 */
class DomainAssociationTest extends TestCase {

	const PATH = '/.well-known/apple-developer-merchantid-domain-association';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component = -1 ) {
				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function home( string $url ): void {
		Functions\when( 'home_url' )->justReturn( $url );
	}

	public function test_exact_path_matches() {
		$this->home( 'https://shop.example' );
		$this->assertSame( '/.well-known/apple-developer-merchantid-domain-association', DomainAssociation::PATH );
		$this->assertTrue( DomainAssociation::matches( self::PATH ) );
	}

	public function test_query_string_is_ignored() {
		$this->home( 'https://shop.example' );
		$this->assertTrue( DomainAssociation::matches( self::PATH . '?x=1' ) );
	}

	public function test_trailing_slash_extension_and_other_paths_do_not_match() {
		$this->home( 'https://shop.example' );
		$this->assertFalse( DomainAssociation::matches( self::PATH . '/' ) );
		$this->assertFalse( DomainAssociation::matches( self::PATH . '.txt' ) );
		$this->assertFalse( DomainAssociation::matches( '/shop' . self::PATH ) );
		$this->assertFalse( DomainAssociation::matches( strtoupper( self::PATH ) ) );
		$this->assertFalse( DomainAssociation::matches( '/' ) );
		$this->assertFalse( DomainAssociation::matches( '' ) );
	}

	public function test_home_path_in_subdirectory_is_honoured() {
		$this->home( 'https://shop.example/shop' );
		$this->assertTrue( DomainAssociation::matches( '/shop' . self::PATH ) );
		$this->assertTrue( DomainAssociation::matches( '/shop' . self::PATH . '?a=b' ) );
		$this->assertFalse( DomainAssociation::matches( self::PATH ) );
	}

	public function test_home_path_with_trailing_slash_is_normalised() {
		$this->home( 'https://shop.example/shop/' );
		$this->assertTrue( DomainAssociation::matches( '/shop' . self::PATH ) );
	}

	public function test_respond_serves_content_verbatim_for_get_and_head() {
		$this->home( 'https://shop.example' );
		foreach ( array( 'GET', 'HEAD' ) as $method ) {
			$response = DomainAssociation::respond( $method, self::PATH, "abc\n123" );
			$this->assertSame( 200, $response[0] );
			$this->assertSame( 'text/plain; charset=utf-8', $response[1]['Content-Type'] );
			$this->assertSame( 'noindex', $response[1]['X-Robots-Tag'] );
			$this->assertSame( 'nosniff', $response[1]['X-Content-Type-Options'] );
			$this->assertSame( 'no-store, max-age=0', $response[1]['Cache-Control'] );
			$this->assertSame( "abc\n123", $response[2] );
		}
	}

	public function test_respond_returns_null_when_nothing_to_do() {
		$this->home( 'https://shop.example' );
		$this->assertNull( DomainAssociation::respond( 'GET', self::PATH, '' ) );
		$this->assertNull( DomainAssociation::respond( 'POST', self::PATH, 'abc' ) );
		$this->assertNull( DomainAssociation::respond( 'GET', self::PATH . '/', 'abc' ) );
		$this->assertNull( DomainAssociation::respond( 'GET', '/other', 'abc' ) );
	}
}
