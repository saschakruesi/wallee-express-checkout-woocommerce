<?php
/**
 * Turns a cart snapshot into wallee line items.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Cart;

use Wallee\ExpressCheckout\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons are not separate lines: each product line carries its amount after
 * discount, so the line items sum to the snapshot total.
 */
final class LineItemBuilder {

	const NAME_LENGTH = 150;
	const SKU_LENGTH  = 200;

	/**
	 * Exception message when the rounded lines do not add up to the total.
	 */
	const ROUNDING_MISMATCH = 'rounding_mismatch';

	/**
	 * Builds the line items. Their rounded sum equals the rounded snapshot total.
	 *
	 * @param CartSnapshot $snapshot Snapshot.
	 * @return array[] Line items.
	 * @throws \InvalidArgumentException With message `rounding_mismatch` when the rounded lines do not add up
	 *                                   to the snapshot total. Shifting the difference onto a line would make
	 *                                   that line differ from its order item and break the refund mapping.
	 */
	public function build( CartSnapshot $snapshot ): array {
		$decimals = Money::decimals( $snapshot->currency() );
		$lines    = array();

		foreach ( $snapshot->items() as $item ) {
			$sku     = '' !== $item['sku'] ? $item['sku'] : (string) $item['product_id'];
			$lines[] = array(
				'uniqueId'           => self::uniqueIdFor( 'item', $item['key'] ),
				'sku'                => self::cut( $sku, self::SKU_LENGTH ),
				'name'               => self::cut( $item['name'], self::NAME_LENGTH ),
				'type'               => 'PRODUCT',
				'quantity'           => $item['quantity'],
				'amountIncludingTax' => Money::round( $item['line_total'] + $item['line_tax'], $decimals ),
				'shippingRequired'   => $item['needs_shipping'],
				'taxes'              => self::taxes( $item['tax_rates'] ),
			);
		}

		foreach ( $snapshot->fees() as $fee ) {
			$amount  = Money::round( $fee['total'] + $fee['tax'], $decimals );
			$lines[] = array(
				'uniqueId'           => self::uniqueIdFor( 'fee', $fee['id'] ),
				'sku'                => self::cut( $fee['id'], self::SKU_LENGTH ),
				'name'               => self::cut( $fee['name'], self::NAME_LENGTH ),
				'type'               => $amount < 0 ? 'DISCOUNT' : 'FEE',
				'quantity'           => 1,
				'amountIncludingTax' => $amount,
				'shippingRequired'   => false,
				'taxes'              => self::taxes( $fee['tax_rates'] ),
			);
		}

		$this->assert_sum( $lines, Money::round( $snapshot->total(), $decimals ), $decimals );

		return $lines;
	}

	/**
	 * Stable line item ID: `ec_` + kind + `_` + first 32 characters of the SHA-1 of the key.
	 *
	 * @param string $kind `item`, `fee` or `shipping`.
	 * @param string $key  Source key.
	 */
	public static function uniqueIdFor( string $kind, string $key ): string {
		return 'ec_' . $kind . '_' . substr( sha1( $key ), 0, 32 );
	}

	/**
	 * Fails closed when the rounded lines do not add up to the rounded total.
	 *
	 * @param array[] $lines    Line items.
	 * @param float   $total    Rounded snapshot total.
	 * @param int     $decimals Decimal places.
	 * @throws \InvalidArgumentException With message `rounding_mismatch`.
	 */
	private function assert_sum( array $lines, float $total, int $decimals ): void {
		$sum = 0.0;
		foreach ( $lines as $line ) {
			$sum += $line['amountIncludingTax'];
		}

		if ( ! Money::equals( $sum, $total, $decimals ) ) {
			throw new \InvalidArgumentException( self::ROUNDING_MISMATCH ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}

	/**
	 * Maps snapshot tax rates to wallee taxes.
	 *
	 * @param array $rates Snapshot tax rates.
	 */
	private static function taxes( array $rates ): array {
		$taxes = array();
		foreach ( $rates as $rate ) {
			$taxes[] = array(
				'title' => $rate['label'],
				'rate'  => $rate['rate'],
			);
		}

		return $taxes;
	}

	/**
	 * Cuts a string to a number of characters.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length.
	 */
	private static function cut( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
