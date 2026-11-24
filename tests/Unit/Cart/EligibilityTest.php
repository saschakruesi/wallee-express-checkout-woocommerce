<?php
/**
 * Eligibility tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Cart\Eligibility;

/**
 * @covers \Wallee\ExpressCheckout\Cart\Eligibility
 */
class EligibilityTest extends TestCase {

	/**
	 * Builds a snapshot.
	 *
	 * @param array $over Top-level overrides.
	 */
	private function snapshot( array $over = array() ): CartSnapshot {
		return CartSnapshot::fromArray( CartSnapshotTest::data( $over ) );
	}

	public function test_eligible_returns_null() {
		$e = new Eligibility( true, true, false );

		$this->assertNull( $e->reason( $this->snapshot() ) );
	}

	public function test_empty() {
		$e = new Eligibility( true, true, false );

		$this->assertSame( 'empty', $e->reason( $this->snapshot( array( 'items' => array() ) ) ) );
	}

	public function test_subscription() {
		$e    = new Eligibility( true, true, false );
		$item = CartSnapshotTest::item( array( 'is_subscription' => true ) );

		$this->assertSame( 'subscription', $e->reason( $this->snapshot( array( 'items' => array( $item ) ) ) ) );
	}

	public function test_multiple_packages() {
		$e = new Eligibility( true, true, false );

		$this->assertSame( 'multiple_packages', $e->reason( $this->snapshot( array( 'shipping_package_count' => 2 ) ) ) );
	}

	public function test_guest_checkout_disabled_for_guest() {
		$e = new Eligibility( true, false, false );

		$this->assertSame( 'guest_checkout_disabled', $e->reason( $this->snapshot() ) );
	}

	public function test_guest_checkout_disabled_but_logged_in_is_eligible() {
		$e = new Eligibility( true, false, true );

		$this->assertNull( $e->reason( $this->snapshot() ) );
	}

	public function test_not_configured() {
		$e = new Eligibility( false, true, false );

		$this->assertSame( 'not_configured', $e->reason( $this->snapshot() ) );
	}

	private function virtual_only(): CartSnapshot {
		return $this->snapshot( array( 'items' => array( CartSnapshotTest::item( array( 'needs_shipping' => false ) ) ) ) );
	}

	public function test_virtual_only_is_refused() {
		$e = new Eligibility( true, true, false );

		$this->assertSame( 'no_shipping_needed', $e->reason( $this->virtual_only() ) );
	}

	public function test_virtual_only_with_the_allow_flag_is_eligible() {
		$e = new Eligibility( true, true, false, true );

		$this->assertNull( $e->reason( $this->virtual_only() ) );
	}

	public function test_mixed_cart_with_one_shippable_item_is_eligible() {
		$e     = new Eligibility( true, true, false );
		$items = array(
			CartSnapshotTest::item( array( 'needs_shipping' => false ) ),
			CartSnapshotTest::item(
				array(
					'key'            => 'b',
					'needs_shipping' => true,
				)
			),
		);

		$this->assertNull( $e->reason( $this->snapshot( array( 'items' => $items ) ) ) );
	}

	public function test_no_shipping_needed_comes_after_empty_and_before_the_others() {
		$e    = new Eligibility( false, false, false );
		$item = CartSnapshotTest::item(
			array(
				'needs_shipping'  => false,
				'is_subscription' => true,
			)
		);

		$this->assertSame( 'empty', $e->reason( $this->snapshot( array( 'items' => array() ) ) ) );
		$this->assertSame( 'no_shipping_needed', $e->reason( $this->snapshot( array( 'items' => array( $item ) ) ) ) );
	}

	public function test_from_environment_applies_the_filter() {
		\Brain\Monkey\setUp();
		try {
			\Brain\Monkey\Functions\when( 'get_option' )->justReturn( 'yes' );
			\Brain\Monkey\Functions\when( 'is_user_logged_in' )->justReturn( false );
			$this->assertSame( 'no_shipping_needed', Eligibility::fromEnvironment( true )->reason( $this->virtual_only() ) );

			\Brain\Monkey\Filters\expectApplied( 'wallee_ec_allow_without_shipping' )->andReturn( true );
			$this->assertNull( Eligibility::fromEnvironment( true )->reason( $this->virtual_only() ) );
		} finally {
			\Brain\Monkey\tearDown();
		}
	}

	public function test_reason_priority_empty_first() {
		$e = new Eligibility( false, false, false );

		$this->assertSame( 'empty', $e->reason( $this->snapshot( array( 'items' => array() ) ) ) );
	}
}
