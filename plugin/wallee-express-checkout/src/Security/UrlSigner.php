<?php
/**
 * Signed, purpose-bound URL tokens.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies opaque UUIDs for callback URLs (HMAC-SHA256).
 */
final class UrlSigner {

	/**
	 * Signing key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Creates a signer.
	 *
	 * @param string $key Secret signing key.
	 */
	public function __construct( string $key ) {
		$this->key = $key;
	}

	/**
	 * Builds a signer keyed from the site's auth salt.
	 */
	public static function fromWordPress(): self {
		return new self( hash( 'sha256', 'wallee_ec|' . wp_salt( 'auth' ) ) );
	}

	/**
	 * Generates a random 64 character hex identifier.
	 */
	public static function newUuid(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Signs a UUID for one purpose (`return`, `shipping` or `result`).
	 *
	 * @param string $uuid    Identifier.
	 * @param string $purpose Purpose the signature is bound to.
	 */
	public function sign( string $uuid, string $purpose ): string {
		return hash_hmac( 'sha256', $purpose . '|' . $uuid, $this->key );
	}

	/**
	 * Verifies a signature in constant time.
	 *
	 * @param string $uuid      Identifier.
	 * @param string $purpose   Purpose.
	 * @param string $signature Presented signature.
	 */
	public function verify( string $uuid, string $purpose, string $signature ): bool {
		return hash_equals( $this->sign( $uuid, $purpose ), $signature );
	}
}
