<?php
/**
 * SessionRepository tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Session;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;

/**
 * @covers \Wallee\ExpressCheckout\Session\SessionRepository
 * @covers \Wallee\ExpressCheckout\Session\SessionRecord
 */
class SessionRepositoryTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var FakeWpdb
	 */
	private $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		$this->db = new FakeWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function snapshot(): CartSnapshot {
		return CartSnapshot::fromArray(
			array(
				'context'                => 'cart',
				'currency'               => 'CHF',
				'customer_id'            => 0,
				'prices_include_tax'     => true,
				'tax_location'           => array(
					'country'  => 'CH',
					'state'    => '',
					'postcode' => '8000',
					'city'     => 'Zurich',
				),
				'items'                  => array(),
				'fees'                   => array(),
				'coupons'                => array(),
				'shipping_package_count' => 1,
			)
		);
	}

	private function repo(): SessionRepository {
		return new SessionRepository( $this->db );
	}

	public function test_claim_issues_one_conditional_update_and_true_on_one_row() {
		$this->db->next_rows_affected = 1;

		$this->assertTrue( $this->repo()->claim( 7, 'created', 'ordering' ) );

		$this->assertCount( 1, $this->db->queries );
		$sql = $this->db->queries[0];
		$this->assertStringStartsWith( 'UPDATE wp_wallee_ec_session SET state = \'ordering\'', $sql );
		$this->assertMatchesRegularExpression( '/WHERE id = 7 AND state = \'created\'$/', $sql );
	}

	public function test_claim_is_false_when_no_row_changed() {
		$this->db->next_rows_affected = 0;

		$this->assertFalse( $this->repo()->claim( 7, 'created', 'ordering' ) );
		$this->assertCount( 1, $this->db->queries );
	}

	public function test_claim_rejects_unknown_states_without_querying() {
		$this->expectException( \InvalidArgumentException::class );
		try {
			$this->repo()->claim( 7, 'created', 'bogus' );
		} finally {
			$this->assertSame( array(), $this->db->queries );
		}
	}

	public function test_find_by_uuid_returns_null_for_non_hex_without_querying() {
		$this->assertNull( $this->repo()->findByUuid( 'not-hex' ) );
		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'a', 63 ) ) );
		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'g', 64 ) ) );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_find_by_uuid_returns_null_when_not_found() {
		$this->db->next_row = null;

		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'a', 64 ) ) );
		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( "session_uuid = '" . str_repeat( 'a', 64 ) . "'", $this->db->queries[0] );
	}

	public function test_find_by_uuid_rebuilds_the_record() {
		$this->db->next_row = array(
			'id'                => '5',
			'session_uuid'      => str_repeat( 'b', 64 ),
			'wallee_session_id' => '99',
			'space_id'          => '12',
			'snapshot'          => json_encode( $this->snapshot()->toArray() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'state'             => 'created',
			'order_id'          => null,
			'transaction_id'    => '31',
			'created_at'        => '2026-11-24 10:00:00',
		);

		$record = $this->repo()->findByUuid( str_repeat( 'b', 64 ) );

		$this->assertInstanceOf( SessionRecord::class, $record );
		$this->assertSame( 5, $record->id() );
		$this->assertSame( 99, $record->walleeSessionId() );
		$this->assertSame( 12, $record->spaceId() );
		$this->assertSame( SessionRecord::CREATED, $record->state() );
		$this->assertNull( $record->orderId() );
		$this->assertSame( 31, $record->transactionId() );
		$this->assertSame( '2026-11-24 10:00:00', $record->createdAt() );
		$this->assertSame( $this->snapshot()->hash(), $record->snapshot()->hash() );
	}

	public function test_find_by_uuid_returns_null_for_corrupt_snapshot() {
		$this->db->next_row = array(
			'id'                => '5',
			'session_uuid'      => str_repeat( 'b', 64 ),
			'wallee_session_id' => '99',
			'space_id'          => '12',
			'snapshot'          => '{broken',
			'state'             => 'created',
			'order_id'          => null,
			'transaction_id'    => null,
			'created_at'        => '2026-11-24 10:00:00',
		);

		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'b', 64 ) ) );
	}

	public function test_insert_stores_hash_snapshot_state_and_utc_timestamps() {
		$id = $this->repo()->insert( str_repeat( 'c', 64 ), 99, 12, $this->snapshot() );

		$this->assertSame( 42, $id );
		list( $table, $data ) = $this->db->last_insert;
		$this->assertSame( 'wp_wallee_ec_session', $table );
		$this->assertSame( str_repeat( 'c', 64 ), $data['session_uuid'] );
		$this->assertSame( $this->snapshot()->hash(), $data['cart_hash'] );
		$this->assertSame( json_encode( $this->snapshot()->toArray() ), $data['snapshot'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertSame( 'created', $data['state'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['created_at'] );
		$this->assertSame( $data['created_at'], $data['updated_at'] );
	}

	public function test_insert_throws_when_the_database_fails() {
		$this->db->next_insert_result = false;
		$this->expectException( \RuntimeException::class );
		$this->repo()->insert( str_repeat( 'c', 64 ), 99, 12, $this->snapshot() );
	}

	public function test_update_rejects_fields_outside_the_allow_list() {
		$this->expectException( \InvalidArgumentException::class );
		try {
			$this->repo()->update( 1, array( 'snapshot' => 'x' ) );
		} finally {
			$this->assertSame( array(), $this->db->queries );
		}
	}

	public function test_update_rejects_an_invalid_state() {
		$this->expectException( \InvalidArgumentException::class );
		$this->repo()->update( 1, array( 'state' => 'nope' ) );
	}

	public function test_update_writes_allowed_fields_and_updated_at() {
		$this->repo()->update(
			1,
			array(
				'state'    => 'processed',
				'order_id' => 55,
			)
		);

		list( $table, $data, $where ) = $this->db->last_update;
		$this->assertSame( 'wp_wallee_ec_session', $table );
		$this->assertSame( 'processed', $data['state'] );
		$this->assertSame( 55, $data['order_id'] );
		$this->assertArrayHasKey( 'updated_at', $data );
		$this->assertSame( array( 'id' => 1 ), $where );
	}

	public function test_update_json_encodes_the_shipping_quote() {
		$quote = array(
			'destination' => array( 'country' => 'CH', 'state' => '', 'postcode' => '8400', 'city' => 'Winterthur' ),
			'rates'       => array( array( 'id' => 'flat_rate:1', 'cost' => 7.5, 'tax' => 0.61 ) ),
		);

		$this->repo()->update( 1, array( 'shipping_quote' => $quote ) );

		$this->assertSame( json_encode( $quote ), $this->db->last_update[1]['shipping_quote'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_update_with_null_clears_the_shipping_quote() {
		$this->repo()->update( 1, array( 'shipping_quote' => null ) );

		$this->assertArrayHasKey( 'shipping_quote', $this->db->last_update[1] );
		$this->assertNull( $this->db->last_update[1]['shipping_quote'] );
	}

	public function test_update_rejects_a_shipping_quote_that_is_not_an_array() {
		$this->expectException( \InvalidArgumentException::class );
		$this->repo()->update( 1, array( 'shipping_quote' => 'x' ) );
	}

	public function test_update_throws_when_the_database_fails() {
		$this->db->next_update_result = false;
		$this->expectException( \RuntimeException::class );
		$this->repo()->update( 1, array( 'state' => 'failed' ) );
	}

	public function test_find_by_uuid_decodes_the_shipping_quote() {
		$quote              = array(
			'destination' => array( 'country' => 'CH' ),
			'rates'       => array( array( 'id' => 'flat_rate:1' ) ),
		);
		$this->db->next_row = array(
			'id'                => '5',
			'session_uuid'      => str_repeat( 'b', 64 ),
			'wallee_session_id' => '99',
			'space_id'          => '12',
			'snapshot'          => json_encode( $this->snapshot()->toArray() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'state'             => 'created',
			'order_id'          => null,
			'transaction_id'    => null,
			'created_at'        => '2026-11-24 10:00:00',
			'shipping_quote'    => json_encode( $quote ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);

		$this->assertSame( $quote, $this->repo()->findByUuid( str_repeat( 'b', 64 ) )->shippingQuote() );

		$this->db->next_row['shipping_quote'] = null;
		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'b', 64 ) )->shippingQuote() );

		unset( $this->db->next_row['shipping_quote'] );
		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'b', 64 ) )->shippingQuote() );

		$this->db->next_row['shipping_quote'] = '{broken';
		$this->assertNull( $this->repo()->findByUuid( str_repeat( 'b', 64 ) )->shippingQuote() );
	}

	public function test_purge_older_than_deletes_by_cutoff_and_returns_count() {
		$this->db->next_rows_affected = 3;

		$this->assertSame( 3, $this->repo()->purgeOlderThan( 7 ) );
		$this->assertCount( 1, $this->db->queries );
		$this->assertMatchesRegularExpression( "/^DELETE FROM wp_wallee_ec_session WHERE created_at < '\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}'$/", $this->db->queries[0] );
	}
}
