<?php
/**
 * Immutable copy of the cart a session was created for.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data object. It makes no WordPress or WooCommerce calls, so it can be
 * built from the database as well as from a live cart.
 */
final class CartSnapshot {

	const CONTEXT_CART    = 'cart';
	const CONTEXT_PRODUCT = 'product';

	/**
	 * Context, cart or product.
	 *
	 * @var string
	 */
	private $context;

	/**
	 * ISO 4217 currency code.
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * WordPress user ID, 0 for guests.
	 *
	 * @var int
	 */
	private $customer_id;

	/**
	 * Whether item prices include tax.
	 *
	 * @var bool
	 */
	private $prices_include_tax;

	/**
	 * Country, state, postcode and city used for taxes.
	 *
	 * @var array
	 */
	private $tax_location;

	/**
	 * Line items.
	 *
	 * @var array
	 */
	private $items;

	/**
	 * Fees.
	 *
	 * @var array
	 */
	private $fees;

	/**
	 * Applied coupon codes.
	 *
	 * @var string[]
	 */
	private $coupons;

	/**
	 * Number of shipping packages.
	 *
	 * @var int
	 */
	private $shipping_package_count;

	/**
	 * Use fromArray().
	 *
	 * @param array $data Validated, normalised data.
	 */
	private function __construct( array $data ) {
		$this->context                = $data['context'];
		$this->currency               = $data['currency'];
		$this->customer_id            = $data['customer_id'];
		$this->prices_include_tax     = $data['prices_include_tax'];
		$this->tax_location           = $data['tax_location'];
		$this->items                  = $data['items'];
		$this->fees                   = $data['fees'];
		$this->coupons                = $data['coupons'];
		$this->shipping_package_count = $data['shipping_package_count'];
	}

	/**
	 * Builds a snapshot from an array, as produced by toArray() or read from JSON.
	 *
	 * @param array $data Snapshot data.
	 * @throws \InvalidArgumentException On a missing key, a wrong type or an invalid value.
	 */
	public static function fromArray( array $data ): self {
		$context = self::string( $data, 'context' );
		if ( self::CONTEXT_CART !== $context && self::CONTEXT_PRODUCT !== $context ) {
			throw new \InvalidArgumentException( 'Unknown snapshot context.' );
		}

		$tax_location = self::list_of( $data, 'tax_location' );
		$location     = array();
		foreach ( array( 'country', 'state', 'postcode', 'city' ) as $key ) {
			$location[ $key ] = self::string( $tax_location, $key, 'tax_location.' );
		}

		$items = array();
		foreach ( self::list_of( $data, 'items' ) as $item ) {
			$items[] = self::normalise_item( $item );
		}

		$fees = array();
		foreach ( self::list_of( $data, 'fees' ) as $fee ) {
			$fees[] = self::normalise_fee( $fee );
		}

		$coupons = array();
		foreach ( self::list_of( $data, 'coupons' ) as $coupon ) {
			if ( ! is_string( $coupon ) ) {
				throw new \InvalidArgumentException( 'Coupon codes must be strings.' );
			}
			$coupons[] = $coupon;
		}

		return new self(
			array(
				'context'                => $context,
				'currency'               => self::string( $data, 'currency' ),
				'customer_id'            => self::non_negative_int( $data, 'customer_id' ),
				'prices_include_tax'     => self::boolean( $data, 'prices_include_tax' ),
				'tax_location'           => $location,
				'items'                  => $items,
				'fees'                   => $fees,
				'coupons'                => $coupons,
				'shipping_package_count' => self::non_negative_int( $data, 'shipping_package_count' ),
			)
		);
	}

	/**
	 * Context, `cart` or `product`.
	 */
	public function context(): string {
		return $this->context;
	}

	/**
	 * ISO 4217 currency code.
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * WordPress user ID, 0 for guests.
	 */
	public function customerId(): int {
		return $this->customer_id;
	}

	/**
	 * Whether item prices include tax.
	 */
	public function pricesIncludeTax(): bool {
		return $this->prices_include_tax;
	}

	/**
	 * Tax location: country, state, postcode, city.
	 */
	public function taxLocation(): array {
		return $this->tax_location;
	}

	/**
	 * Line items.
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Fees.
	 */
	public function fees(): array {
		return $this->fees;
	}

	/**
	 * Applied coupon codes.
	 *
	 * @return string[]
	 */
	public function coupons(): array {
		return $this->coupons;
	}

	/**
	 * Number of shipping packages the cart would be split into.
	 */
	public function shippingPackageCount(): int {
		return $this->shipping_package_count;
	}

	/**
	 * Items and fees including tax, after discounts, without shipping.
	 */
	public function total(): float {
		$total = 0.0;
		foreach ( $this->items as $item ) {
			$total += $item['line_total'] + $item['line_tax'];
		}
		foreach ( $this->fees as $fee ) {
			$total += $fee['total'] + $fee['tax'];
		}

		return $total;
	}

	/**
	 * Whether any item needs shipping.
	 */
	public function needsShipping(): bool {
		foreach ( $this->items as $item ) {
			if ( $item['needs_shipping'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * SHA-256 of the canonical JSON, independent of array key order.
	 */
	public function hash(): string {
		return hash( 'sha256', (string) json_encode( self::canonical( $this->toArray() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class, no WordPress available.
	}

	/**
	 * Plain array form, accepted by fromArray().
	 */
	public function toArray(): array {
		return array(
			'context'                => $this->context,
			'currency'               => $this->currency,
			'customer_id'            => $this->customer_id,
			'prices_include_tax'     => $this->prices_include_tax,
			'tax_location'           => $this->tax_location,
			'items'                  => $this->items,
			'fees'                   => $this->fees,
			'coupons'                => $this->coupons,
			'shipping_package_count' => $this->shipping_package_count,
		);
	}

	/**
	 * Sorts associative arrays by key, recursively. Lists keep their order.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::canonical( $v );
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * Validates and normalises an item.
	 *
	 * @param mixed $item Raw item.
	 * @throws \InvalidArgumentException On invalid input.
	 */
	private static function normalise_item( $item ): array {
		if ( ! is_array( $item ) ) {
			throw new \InvalidArgumentException( 'Item must be an array.' );
		}

		$variation = array();
		foreach ( self::list_of( $item, 'variation', 'item.' ) as $attribute => $value ) {
			if ( ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( 'Variation values must be scalars.' );
			}
			$variation[ (string) $attribute ] = (string) $value;
		}

		return array(
			'key'               => self::string( $item, 'key', 'item.' ),
			'product_id'        => self::non_negative_int( $item, 'product_id', 'item.' ),
			'variation_id'      => self::non_negative_int( $item, 'variation_id', 'item.' ),
			'variation'         => $variation,
			'name'              => self::string( $item, 'name', 'item.' ),
			'sku'               => self::string( $item, 'sku', 'item.' ),
			'quantity'          => self::non_negative_int( $item, 'quantity', 'item.' ),
			'line_subtotal'     => self::number( $item, 'line_subtotal', 'item.' ),
			'line_subtotal_tax' => self::number( $item, 'line_subtotal_tax', 'item.' ),
			'line_total'        => self::number( $item, 'line_total', 'item.' ),
			'line_tax'          => self::number( $item, 'line_tax', 'item.' ),
			'tax_class'         => self::string( $item, 'tax_class', 'item.' ),
			'tax_rates'         => self::tax_rates( $item, 'item.' ),
			'needs_shipping'    => self::boolean( $item, 'needs_shipping', 'item.' ),
			'is_subscription'   => self::boolean( $item, 'is_subscription', 'item.' ),
		);
	}

	/**
	 * Validates and normalises a fee.
	 *
	 * @param mixed $fee Raw fee.
	 * @throws \InvalidArgumentException On invalid input.
	 */
	private static function normalise_fee( $fee ): array {
		if ( ! is_array( $fee ) ) {
			throw new \InvalidArgumentException( 'Fee must be an array.' );
		}

		return array(
			'id'        => self::string( $fee, 'id', 'fee.' ),
			'name'      => self::string( $fee, 'name', 'fee.' ),
			'total'     => self::number( $fee, 'total', 'fee.' ),
			'tax'       => self::number( $fee, 'tax', 'fee.' ),
			'tax_rates' => self::tax_rates( $fee, 'fee.' ),
		);
	}

	/**
	 * Validates a list of tax rates.
	 *
	 * @param array  $data   Parent array.
	 * @param string $prefix Key prefix for messages.
	 * @throws \InvalidArgumentException On invalid input.
	 */
	private static function tax_rates( array $data, string $prefix ): array {
		$rates = array();
		foreach ( self::list_of( $data, 'tax_rates', $prefix ) as $rate ) {
			if ( ! is_array( $rate ) ) {
				throw new \InvalidArgumentException( 'Tax rate must be an array.' );
			}
			$rates[] = array(
				'label' => self::string( $rate, 'label', $prefix . 'tax_rate.' ),
				'rate'  => self::number( $rate, 'rate', $prefix . 'tax_rate.' ),
			);
		}

		return $rates;
	}

	/**
	 * Reads a required array.
	 *
	 * @param array  $data   Parent array.
	 * @param string $key    Key.
	 * @param string $prefix Key prefix for messages.
	 * @throws \InvalidArgumentException If missing or not an array.
	 */
	private static function list_of( array $data, string $key, string $prefix = '' ): array {
		if ( ! array_key_exists( $key, $data ) || ! is_array( $data[ $key ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Snapshot key %s%s must be an array.', $prefix, $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return $data[ $key ];
	}

	/**
	 * Reads a required string.
	 *
	 * @param array  $data   Parent array.
	 * @param string $key    Key.
	 * @param string $prefix Key prefix for messages.
	 * @throws \InvalidArgumentException If missing or not a string.
	 */
	private static function string( array $data, string $key, string $prefix = '' ): string {
		if ( ! array_key_exists( $key, $data ) || ! is_string( $data[ $key ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Snapshot key %s%s must be a string.', $prefix, $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return $data[ $key ];
	}

	/**
	 * Reads a required boolean.
	 *
	 * @param array  $data   Parent array.
	 * @param string $key    Key.
	 * @param string $prefix Key prefix for messages.
	 * @throws \InvalidArgumentException If missing or not a boolean.
	 */
	private static function boolean( array $data, string $key, string $prefix = '' ): bool {
		if ( ! array_key_exists( $key, $data ) || ! is_bool( $data[ $key ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Snapshot key %s%s must be a boolean.', $prefix, $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return $data[ $key ];
	}

	/**
	 * Reads a required integer that is not negative.
	 *
	 * @param array  $data   Parent array.
	 * @param string $key    Key.
	 * @param string $prefix Key prefix for messages.
	 * @throws \InvalidArgumentException If missing, not an integer or negative.
	 */
	private static function non_negative_int( array $data, string $key, string $prefix = '' ): int {
		if ( ! array_key_exists( $key, $data ) || ! is_int( $data[ $key ] ) || $data[ $key ] < 0 ) {
			throw new \InvalidArgumentException( sprintf( 'Snapshot key %s%s must be a non-negative integer.', $prefix, $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return $data[ $key ];
	}

	/**
	 * Reads a required number.
	 *
	 * @param array  $data   Parent array.
	 * @param string $key    Key.
	 * @param string $prefix Key prefix for messages.
	 * @throws \InvalidArgumentException If missing or not numeric.
	 */
	private static function number( array $data, string $key, string $prefix = '' ): float {
		if ( ! array_key_exists( $key, $data ) || ! ( is_int( $data[ $key ] ) || is_float( $data[ $key ] ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'Snapshot key %s%s must be a number.', $prefix, $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return (float) $data[ $key ];
	}
}
