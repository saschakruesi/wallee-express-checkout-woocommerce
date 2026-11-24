<?php
/**
 * In-memory session repository.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

use Wallee\ExpressCheckout\Cart\CartSnapshot;
use Wallee\ExpressCheckout\Session\SessionRecord;
use Wallee\ExpressCheckout\Session\SessionRepository;

/**
 * Holds one row in memory. `claim` is a real compare-and-set on that row.
 */
class FakeSessionRepository extends SessionRepository {

	/**
	 * The row, null when none exists.
	 *
	 * @var array|null
	 */
	public $row;

	/**
	 * Every claim and update, in order.
	 *
	 * @var array[]
	 */
	public $calls = array();

	/**
	 * When set, a claim loses: the row is first changed to these fields (as if
	 * a concurrent request had won), then the compare-and-set runs.
	 *
	 * @var array|null
	 */
	public $concurrent = null;

	/**
	 * Updates touching these keys throw.
	 *
	 * @var string[]
	 */
	public $failing_keys = array();

	/**
	 * Constructor.
	 *
	 * @param array $snapshot Snapshot data.
	 * @param array $over     Row fields to override.
	 */
	public function __construct( array $snapshot, array $over = array() ) {
		parent::__construct( new FakeWpdb() );
		$this->row = array_merge(
			array(
				'id'                => 7,
				'uuid'              => str_repeat( 'a', 64 ),
				'wallee_session_id' => 335,
				'space_id'          => 4711,
				'snapshot'          => $snapshot,
				'state'             => SessionRecord::CREATED,
				'order_id'          => null,
				'transaction_id'    => null,
				'created_at'        => '2026-11-24 10:00:00',
				'shipping_quote'    => null,
			),
			$over
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $uuid UUID.
	 */
	public function findByUuid( string $uuid ): ?SessionRecord {
		if ( null === $this->row || $uuid !== $this->row['uuid'] ) {
			return null;
		}
		$r = $this->row;

		return new SessionRecord( $r['id'], $r['uuid'], $r['wallee_session_id'], $r['space_id'], CartSnapshot::fromArray( $r['snapshot'] ), $r['state'], $r['order_id'], $r['transaction_id'], $r['created_at'], $r['shipping_quote'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id   Row ID.
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public function claim( int $id, string $from, string $to ): bool {
		$this->calls[] = array( 'claim', $from, $to );
		if ( null !== $this->concurrent ) {
			$this->row        = array_merge( $this->row, $this->concurrent );
			$this->concurrent = null;
		}
		if ( $id !== $this->row['id'] || $from !== $this->row['state'] ) {
			return false;
		}
		$this->row['state'] = $to;

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Fields.
	 * @throws \RuntimeException For a key in $failing_keys.
	 */
	public function update( int $id, array $fields ): void {
		$this->calls[] = array( 'update', $fields );
		if ( array() !== array_intersect( array_keys( $fields ), $this->failing_keys ) ) {
			throw new \RuntimeException( 'Session could not be updated.' );
		}
		$this->row = array_merge( $this->row, $fields );
	}

	/**
	 * States the row went through, from the recorded claims and updates.
	 *
	 * @return string[]
	 */
	public function stateChanges(): array {
		$states = array();
		foreach ( $this->calls as $call ) {
			if ( 'claim' === $call[0] ) {
				$states[] = $call[2];
			} elseif ( isset( $call[1]['state'] ) ) {
				$states[] = $call[1]['state'];
			}
		}

		return $states;
	}
}
