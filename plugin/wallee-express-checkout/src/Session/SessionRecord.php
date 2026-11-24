<?php
/**
 * One express checkout session row.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Session;

use Wallee\ExpressCheckout\Cart\CartSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object for a row of the session table.
 */
final class SessionRecord {

	const CREATED   = 'created';
	const ORDERING  = 'ordering';
	const PROCESSED = 'processed';
	const COMPLETED = 'completed';
	const FAILED    = 'failed';

	/**
	 * Row ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Session UUID (64 hex chars).
	 *
	 * @var string
	 */
	private $uuid;

	/**
	 * Session ID at wallee.
	 *
	 * @var int
	 */
	private $wallee_session_id;

	/**
	 * Space ID.
	 *
	 * @var int
	 */
	private $space_id;

	/**
	 * Cart snapshot.
	 *
	 * @var CartSnapshot
	 */
	private $snapshot;

	/**
	 * State.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * WooCommerce order ID.
	 *
	 * @var int|null
	 */
	private $order_id;

	/**
	 * Transaction ID at wallee.
	 *
	 * @var int|null
	 */
	private $transaction_id;

	/**
	 * Creation time, UTC.
	 *
	 * @var string
	 */
	private $created_at;

	/**
	 * Constructor.
	 *
	 * @param int          $id                Row ID.
	 * @param string       $uuid              Session UUID.
	 * @param int          $wallee_session_id wallee session ID.
	 * @param int          $space_id          Space ID.
	 * @param CartSnapshot $snapshot          Cart snapshot.
	 * @param string       $state             State.
	 * @param int|null     $order_id          Order ID.
	 * @param int|null     $transaction_id    Transaction ID.
	 * @param string       $created_at        Creation time, UTC.
	 */
	public function __construct( int $id, string $uuid, int $wallee_session_id, int $space_id, CartSnapshot $snapshot, string $state, ?int $order_id, ?int $transaction_id, string $created_at ) {
		$this->id                = $id;
		$this->uuid              = $uuid;
		$this->wallee_session_id = $wallee_session_id;
		$this->space_id          = $space_id;
		$this->snapshot          = $snapshot;
		$this->state             = $state;
		$this->order_id          = $order_id;
		$this->transaction_id    = $transaction_id;
		$this->created_at        = $created_at;
	}

	/**
	 * All valid states.
	 *
	 * @return string[]
	 */
	public static function states(): array {
		return array( self::CREATED, self::ORDERING, self::PROCESSED, self::COMPLETED, self::FAILED );
	}

	/**
	 * Row ID.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Session UUID.
	 */
	public function uuid(): string {
		return $this->uuid;
	}

	/**
	 * The wallee session ID.
	 */
	public function walleeSessionId(): int {
		return $this->wallee_session_id;
	}

	/**
	 * Space ID.
	 */
	public function spaceId(): int {
		return $this->space_id;
	}

	/**
	 * Cart snapshot.
	 */
	public function snapshot(): CartSnapshot {
		return $this->snapshot;
	}

	/**
	 * State.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Order ID, once an order exists.
	 */
	public function orderId(): ?int {
		return $this->order_id;
	}

	/**
	 * Transaction ID, once known.
	 */
	public function transactionId(): ?int {
		return $this->transaction_id;
	}

	/**
	 * Creation time, UTC.
	 */
	public function createdAt(): string {
		return $this->created_at;
	}
}
