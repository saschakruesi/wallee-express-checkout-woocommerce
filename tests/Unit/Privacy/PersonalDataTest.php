<?php
/**
 * PersonalData tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Privacy\PersonalData;
use Wallee\ExpressCheckout\Session\SessionRepository;
use Wallee\ExpressCheckout\Tests\Support\FakeWpdb;

require_once dirname( __DIR__, 2 ) . '/Support/wc-order-stub.php';

/**
 * Repository double: rows per order, records deletions.
 */
class PrivacyRepository extends SessionRepository {

	/**
	 * Rows by order ID.
	 *
	 * @var array<int, array[]>
	 */
	public $rows = array();

	/**
	 * Deletions: order ID and states.
	 *
	 * @var array[]
	 */
	public $deleted = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( new FakeWpdb() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id Order ID.
	 */
	public function rowsForOrder( int $order_id ): array {
		return $this->rows[ $order_id ] ?? array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int      $order_id Order ID.
	 * @param string[] $states   States.
	 */
	public function deleteForOrder( int $order_id, array $states ): int {
		$this->deleted[] = array( $order_id, $states );
		$before          = count( $this->rows[ $order_id ] ?? array() );

		$this->rows[ $order_id ] = array_values(
			array_filter(
				$this->rows[ $order_id ] ?? array(),
				static function ( $row ) use ( $states ) {
					return ! in_array( $row['state'], $states, true );
				}
			)
		);

		return $before - count( $this->rows[ $order_id ] );
	}
}

/**
 * @covers \Wallee\ExpressCheckout\Privacy\PersonalData
 */
class PersonalDataTest extends TestCase {

	/** @var PrivacyRepository */
	private $repository;

	/** @var \WC_Order[] */
	private $orders = array();

	/** @var array[] Calls of the order finder: e-mail, page. */
	private $lookups = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->repository = new PrivacyRepository();
		$this->lookups    = array();

		$express = new \WC_Order( 501, 60.11 );
		$express->meta['_wallee_ec_wallet']         = 'Apple Pay';
		$express->meta['_wallee_ec_environment']    = 'TEST';
		$express->meta['_wallee_ec_transaction_id'] = 629017977;
		$express->meta['_wallee_ec_session_id']     = 335;
		$express->meta['_wallee_ec_failed_reason']  = 'no_email';

		$regular              = new \WC_Order( 502, 10.0 );
		$regular->created_via = 'checkout';

		$this->orders = array( $express, $regular );

		$this->repository->rows[501] = array(
			self::row( 7, 'completed' ),
			self::row( 8, 'processed' ),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function row( int $id, string $state ): array {
		return array(
			'id'                => $id,
			'state'             => $state,
			'wallee_session_id' => 335,
			'transaction_id'    => 629017977,
			'created_at'        => '2026-11-24 10:00:00',
			'tax_location'      => array(
				'country'  => 'CH',
				'state'    => '',
				'postcode' => '8000',
				'city'     => 'Zurich',
			),
			'destination'       => array(
				'country'  => 'CH',
				'state'    => 'ZH',
				'postcode' => '8001',
				'city'     => 'Zurich',
			),
		);
	}

	private function data(): PersonalData {
		return new PersonalData(
			$this->repository,
			function ( string $email, int $page ) {
				$this->lookups[] = array( $email, $page );
				return 1 === $page ? $this->orders : array();
			}
		);
	}

	private static function values( array $item ): array {
		return array_column( $item['data'], 'value', 'name' );
	}

	public function test_export_lists_session_rows_and_order_meta_of_express_orders_only() {
		$result = $this->data()->export( 'buyer@example.test', 1 );

		$this->assertTrue( $result['done'], 'fewer orders than a page' );
		$this->assertSame( array( array( 'buyer@example.test', 1 ) ), $this->lookups );
		$this->assertCount( 3, $result['data'], 'two rows and the meta of one express order' );

		$row = $result['data'][0];
		$this->assertSame( 'wallee-express-checkout-sessions', $row['group_id'] );
		$this->assertSame( 'wallee-express-checkout-session-7', $row['item_id'] );
		$values = self::values( $row );
		$this->assertSame( '501', $values['Order'] );
		$this->assertSame( 'completed', $values['State'] );
		$this->assertSame( '2026-11-24 10:00:00 UTC', $values['Created'] );
		$this->assertSame( '335', $values['wallee session ID'] );
		$this->assertSame( '629017977', $values['wallee transaction ID'] );
		$this->assertSame( 'CH, 8000 Zurich', $values['Tax location used for the prices'] );
		$this->assertSame( 'CH, ZH, 8001 Zurich', $values['Delivery destination chosen in the wallet'] );

		$meta = $result['data'][2];
		$this->assertSame( 'wallee-express-checkout-orders', $meta['group_id'] );
		$this->assertSame( 'wallee-express-checkout-order-501', $meta['item_id'] );
		$values = self::values( $meta );
		$this->assertSame( 'Apple Pay', $values['Wallet'] );
		$this->assertSame( 'TEST', $values['Environment'] );
		$this->assertSame( '629017977', $values['wallee transaction ID'] );
		$this->assertSame( 'no_email', $values['Reason the order failed'] );
		$this->assertArrayNotHasKey( 'Void requested', $values, 'absent meta is not exported' );
	}

	public function test_export_pages_through_orders() {
		$this->orders = array_fill( 0, PersonalData::PAGE_SIZE, $this->orders[1] );

		$this->assertFalse( $this->data()->export( 'buyer@example.test', 1 )['done'] );
		$this->assertTrue( $this->data()->export( 'buyer@example.test', 2 )['done'] );
	}

	public function test_export_without_orders_is_empty_and_done() {
		$this->orders = array();

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->data()->export( 'nobody@example.test', 1 )
		);
	}

	public function test_erase_deletes_finished_rows_and_keeps_rows_of_a_payment_in_progress() {
		$result = $this->data()->erase( 'buyer@example.test', 1 );

		$this->assertSame( array( array( 501, array( 'completed', 'failed' ) ) ), $this->repository->deleted, 'only the express order, only finished rows' );
		$this->assertSame( 1, $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( '501', $result['messages'][0] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( self::row( 8, 'processed' ) ), $this->repository->rows[501] );
		$this->assertSame( 'pending', $this->orders[0]->status, 'the order is not touched' );
		$this->assertSame( 'TEST', $this->orders[0]->meta['_wallee_ec_environment'], 'order meta stays with the order' );
	}

	public function test_erase_with_nothing_left_retains_nothing() {
		$this->repository->rows[501] = array( self::row( 7, 'failed' ) );

		$result = $this->data()->erase( 'buyer@example.test', 1 );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
	}

	public function test_exporter_and_eraser_are_registered_with_wordpress() {
		$exporters = PersonalData::addExporter( array( 'other' => array() ) );
		$erasers   = PersonalData::addEraser( array() );

		$this->assertArrayHasKey( 'other', $exporters );
		$this->assertSame( 'wallee Express Checkout', $exporters[ PersonalData::ID ]['exporter_friendly_name'] );
		$this->assertTrue( is_callable( $exporters[ PersonalData::ID ]['callback'] ) );
		$this->assertSame( 'wallee Express Checkout', $erasers[ PersonalData::ID ]['eraser_friendly_name'] );
		$this->assertTrue( is_callable( $erasers[ PersonalData::ID ]['callback'] ) );
	}

	public function test_policy_text_is_added_once_wordpress_offers_it() {
		$added = array();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			function ( $name, $text ) use ( &$added ) {
				$added[] = array( $name, $text );
			}
		);

		PersonalData::addPolicyContent();

		$this->assertCount( 1, $added );
		$this->assertSame( 'wallee Express Checkout', $added[0][0] );
		$this->assertStringContainsString( '7 days', $added[0][1] );
		$this->assertStringContainsString( 'wallee', $added[0][1] );
	}
}
