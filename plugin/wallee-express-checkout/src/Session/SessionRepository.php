<?php
/**
 * Persistence for express checkout sessions.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Session;

use Wallee\ExpressCheckout\Cart\CartSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the session table. `claim()` is the guard against creating
 * two orders for one wallet approval.
 */
final class SessionRepository {

	const TABLE = 'wallee_ec_session';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database object.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Full table name for a database object.
	 *
	 * @param \wpdb $db Database.
	 */
	public static function tableName( \wpdb $db ): string {
		return $db->prefix . self::TABLE;
	}

	/**
	 * Inserts a new session in state `created`.
	 *
	 * @param string       $uuid              64 hex chars.
	 * @param int          $wallee_session_id wallee session ID.
	 * @param int          $space_id          Space ID.
	 * @param CartSnapshot $snapshot          Cart snapshot.
	 * @return int Row ID.
	 * @throws \RuntimeException When the row could not be stored.
	 */
	public function insert( string $uuid, int $wallee_session_id, int $space_id, CartSnapshot $snapshot ): int {
		$json = wp_json_encode( $snapshot->toArray() );
		if ( false === $json ) {
			throw new \RuntimeException( 'Snapshot could not be encoded to JSON.' );
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = $this->db->insert(
			$this->table(),
			array(
				'session_uuid'      => $uuid,
				'wallee_session_id' => $wallee_session_id,
				'space_id'          => $space_id,
				'cart_hash'         => $snapshot->hash(),
				'snapshot'          => $json,
				'state'             => SessionRecord::CREATED,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Session could not be stored.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Finds a session by its UUID.
	 *
	 * @param string $uuid Session UUID.
	 * @return SessionRecord|null Null when the UUID is malformed, unknown or the stored snapshot is unreadable.
	 */
	public function findByUuid( string $uuid ): ?SessionRecord {
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $uuid ) ) {
			return null;
		}

		$table = $this->table();
		$row   = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE session_uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				$uuid
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$data = json_decode( (string) $row['snapshot'], true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		try {
			$snapshot = CartSnapshot::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}

		return new SessionRecord(
			(int) $row['id'],
			(string) $row['session_uuid'],
			(int) $row['wallee_session_id'],
			(int) $row['space_id'],
			$snapshot,
			(string) $row['state'],
			null === $row['order_id'] ? null : (int) $row['order_id'],
			null === $row['transaction_id'] ? null : (int) $row['transaction_id'],
			(string) $row['created_at'],
			self::decode_quote( $row )
		);
	}

	/**
	 * Atomically moves a session from one state to another.
	 *
	 * One conditional UPDATE, so of any number of concurrent callers exactly one
	 * sees true. Only that caller may continue.
	 *
	 * @param int    $id   Row ID.
	 * @param string $from Expected current state.
	 * @param string $to   New state.
	 * @return bool Whether this call changed the row.
	 * @throws \InvalidArgumentException On an unknown state.
	 */
	public function claim( int $id, string $from, string $to ): bool {
		self::assert_state( $from );
		self::assert_state( $to );

		$table = $this->table();
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET state = %s, updated_at = %s WHERE id = %d AND state = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				$to,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				$from
			)
		);

		return 1 === (int) $this->db->rows_affected;
	}

	/**
	 * Stores the shipping quote, only while the row is still in state `created`.
	 *
	 * One conditional UPDATE, so a quote cannot be written after the state changed.
	 *
	 * @param int   $id    Row ID.
	 * @param array $quote Destination and rates.
	 * @return bool Whether this call changed the row.
	 * @throws \RuntimeException If the quote cannot be encoded as JSON.
	 */
	public function storeQuote( int $id, array $quote ): bool {
		$json = wp_json_encode( $quote );
		if ( false === $json ) {
			throw new \RuntimeException( 'Shipping quote could not be encoded to JSON.' );
		}

		$table  = $this->table();
		$result = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table} SET shipping_quote = %s, updated_at = %s WHERE id = %d AND state = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				$json,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				SessionRecord::CREATED
			)
		);

		return 1 === $result;
	}

	/**
	 * Updates state, order ID, transaction ID and/or shipping quote.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Column => value, keys limited to state, order_id, transaction_id, shipping_quote
	 *                      (an array, JSON-encoded here; null clears it).
	 * @throws \InvalidArgumentException On a key outside the allow list, an unknown state or a quote that is neither array nor null.
	 * @throws \RuntimeException When the database reports a failure.
	 */
	public function update( int $id, array $fields ): void {
		$allowed = array(
			'state'          => '%s',
			'order_id'       => '%d',
			'transaction_id' => '%d',
			'shipping_quote' => '%s',
		);

		$data    = array();
		$formats = array();
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Field cannot be updated.' );
			}
			if ( 'state' === $key ) {
				self::assert_state( (string) $value );
			}
			if ( 'shipping_quote' === $key ) {
				$data[ $key ] = self::encode_quote( $value );
			} else {
				$data[ $key ] = 'state' === $key ? (string) $value : (int) $value;
			}
			$formats[] = $allowed[ $key ];
		}
		if ( array() === $data ) {
			return;
		}

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]          = '%s';

		if ( false === $this->db->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) ) ) {
			throw new \RuntimeException( 'Session could not be updated.' );
		}
	}

	/**
	 * Deletes sessions created more than the given number of days ago.
	 *
	 * @param int $days Age in days.
	 * @return int Number of deleted rows.
	 */
	public function purgeOlderThan( int $days ): int {
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * 86400 );

		$this->db->query(
			$this->db->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the prefix plus a constant.
				$cutoff
			)
		);

		return (int) $this->db->rows_affected;
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return self::tableName( $this->db );
	}

	/**
	 * JSON for the quote column.
	 *
	 * @param mixed $value Array or null.
	 * @return string|null
	 * @throws \InvalidArgumentException If not an array or null.
	 * @throws \RuntimeException If the array cannot be encoded.
	 */
	private static function encode_quote( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'Shipping quote must be an array or null.' );
		}
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			throw new \RuntimeException( 'Shipping quote could not be encoded to JSON.' );
		}

		return $json;
	}

	/**
	 * Reads the quote column. Absent, empty or unreadable means no quote.
	 *
	 * @param array $row Table row.
	 */
	private static function decode_quote( array $row ): ?array {
		if ( ! isset( $row['shipping_quote'] ) || ! is_string( $row['shipping_quote'] ) || '' === $row['shipping_quote'] ) {
			return null;
		}
		$quote = json_decode( $row['shipping_quote'], true );

		return is_array( $quote ) ? $quote : null;
	}

	/**
	 * Rejects unknown states.
	 *
	 * @param string $state State.
	 * @throws \InvalidArgumentException On an unknown state.
	 */
	private static function assert_state( string $state ): void {
		if ( ! in_array( $state, SessionRecord::states(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown session state.' );
		}
	}
}
