<?php
/**
 * Checks the attribute values requested for a variation against the product.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules, no WooCommerce calls: SnapshotFactory reads the product data
 * and passes it in. The result is the complete, validated attribute set of
 * the variation, in the form WooCommerce stores it on a cart item
 * (`attribute_<slug>` => value).
 */
final class VariationAttributes {

	/**
	 * Form of an attribute key on a cart item. Custom attribute slugs may be
	 * URL-encoded (sanitize_title of a name with non-ASCII characters).
	 */
	const KEY_PATTERN = '/^attribute_[a-z0-9_%\-]{1,100}$/i';

	/**
	 * Whether a key has the form of a WooCommerce variation attribute key.
	 *
	 * @param mixed $key Key.
	 */
	public static function isAttributeKey( $key ): bool {
		return is_string( $key ) && 1 === preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Resolves the requested attribute values for one variation.
	 *
	 * Rules (the same as WooCommerce's add to cart):
	 * - every requested key must be a variation attribute of the parent;
	 * - where the variation has a fixed value, the requested value must be
	 *   that value (or be absent);
	 * - where the variation has "any" (empty), a value is required and must
	 *   be one of the parent's options for that attribute.
	 *
	 * Together these rules mean the combination matches the requested
	 * variation and no other key reaches the order.
	 *
	 * @param array $parent_options   `attribute_<slug>` => list of allowed values, variation attributes of the parent only.
	 * @param array $variation_values `attribute_<slug>` => the variation's value, '' for "any".
	 * @param array $requested        Requested `attribute_<slug>` => value; empty values count as absent.
	 * @return array<string, string> `attribute_<slug>` => value for every variation attribute of the parent.
	 * @throws \InvalidArgumentException If a key or value is not allowed or a value is missing.
	 */
	public static function resolve( array $parent_options, array $variation_values, array $requested ): array {
		foreach ( $requested as $key => $value ) {
			if ( ! self::isAttributeKey( $key ) || ! array_key_exists( $key, $parent_options ) ) {
				throw new \InvalidArgumentException( 'Unknown variation attribute.' );
			}
			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( 'Variation attribute values must be strings.' );
			}
		}

		$resolved = array();
		foreach ( $parent_options as $key => $options ) {
			$fixed = isset( $variation_values[ $key ] ) ? (string) $variation_values[ $key ] : '';
			$given = isset( $requested[ $key ] ) && '' !== $requested[ $key ] ? $requested[ $key ] : null;

			if ( '' !== $fixed ) {
				if ( null !== $given && $given !== $fixed ) {
					throw new \InvalidArgumentException( 'Variation attribute value does not match the variation.' );
				}
				$resolved[ (string) $key ] = $fixed;
				continue;
			}

			if ( null === $given || ! is_array( $options ) || ! in_array( $given, array_map( 'strval', $options ), true ) ) {
				throw new \InvalidArgumentException( 'Variation attribute value is missing or not offered.' );
			}
			$resolved[ (string) $key ] = $given;
		}

		return $resolved;
	}
}
