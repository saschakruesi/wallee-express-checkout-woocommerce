<?php
/**
 * SnapshotFactory tests (the pure total check).
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\SnapshotFactory;

/**
 * @covers \Wallee\ExpressCheckout\Cart\SnapshotFactory::matchesCartTotal
 */
class SnapshotFactoryTest extends TestCase {

	public function test_cart_total_without_shipping_must_equal_the_snapshot_total() {
		$snapshot = CartSnapshot::fromArray( CartSnapshotTest::data() );
		$total    = $snapshot->total();

		$this->assertTrue( SnapshotFactory::matchesCartTotal( $snapshot, $total, 0, 0 ) );
		$this->assertTrue( SnapshotFactory::matchesCartTotal( $snapshot, (string) ( $total + 8.11 ), '7.50', '0.61' ), 'cart shipping is not part of the snapshot' );
		$this->assertFalse( SnapshotFactory::matchesCartTotal( $snapshot, $total + 0.01, 0, 0 ), 'one cent off (round tax at subtotal)' );
		$this->assertFalse( SnapshotFactory::matchesCartTotal( $snapshot, $total + 8.11, 7.5, 0 ) );
	}
}
