<?php
/**
 * ShippingRateMapper tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Shipping;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Shipping\ShippingRateMapper;

/**
 * @covers \Wallee\ExpressCheckout\Shipping\ShippingRateMapper
 */
class ShippingRateMapperTest extends TestCase {

	public function test_option_adds_tax_to_cost_and_lists_the_rates() {
		$option = ShippingRateMapper::toOption(
			array(
				'id'        => 'flat_rate:1',
				'label'     => 'Flat rate',
				'cost'      => 6.94,
				'tax'       => 0.56,
				'tax_rates' => array(
					array(
						'label' => 'VAT',
						'rate'  => 8.1,
					),
				),
			),
			'CHF'
		);

		$this->assertSame(
			array(
				'id'                 => 'flat_rate:1',
				'label'              => 'Flat rate',
				'description'        => '',
				'amountIncludingTax' => 7.5,
				'taxes'              => array(
					array(
						'title' => 'VAT',
						'rate'  => 8.1,
					),
				),
				'currency'           => 'CHF',
			),
			$option
		);
	}

	public function test_free_rate_has_zero_amount_and_no_taxes() {
		$option = ShippingRateMapper::toOption(
			array(
				'id'        => 'free_shipping:2',
				'label'     => 'Free shipping',
				'cost'      => 0.0,
				'tax'       => 0.0,
				'tax_rates' => array(),
			),
			'CHF'
		);

		$this->assertSame( 0.0, $option['amountIncludingTax'] );
		$this->assertSame( array(), $option['taxes'] );
	}

	public function test_amount_is_rounded_to_the_currency_precision() {
		$option = ShippingRateMapper::toOption(
			array(
				'id'        => 'flat_rate:1',
				'label'     => 'Flat',
				'cost'      => 1000.0,
				'tax'       => 81.0,
				'tax_rates' => array(),
			),
			'JPY'
		);
		$this->assertSame( 1081.0, $option['amountIncludingTax'] );
	}

	public function test_success_wraps_options() {
		$this->assertSame(
			array(
				'shippingOptions' => array( array( 'id' => 'a' ) ),
				'error'           => null,
			),
			ShippingRateMapper::success( array( array( 'id' => 'a' ) ) )
		);
	}

	public function test_error_has_empty_options_and_the_code() {
		$result = ShippingRateMapper::error( 'COUNTRY_UNAVAILABLE', 'x' );

		$this->assertSame( array(), $result['shippingOptions'] );
		$this->assertSame( 'COUNTRY_UNAVAILABLE', $result['error']['error'] );
		$this->assertSame( 'x', $result['error']['message'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $result['error']['timestamp'] );
	}
}
