<?php
/**
 * Recording wpdb test double.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Support;

require_once __DIR__ . '/wpdb-stub.php';

/**
 * Records every statement and replays canned results. `prepare()` substitutes
 * `%d` and `%s` placeholders like the real one, so tests can assert on SQL.
 */
class FakeWpdb extends \wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Rows affected by the last write.
	 *
	 * @var int
	 */
	public $rows_affected = 0;

	/**
	 * ID of the last insert.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Rows affected by the next query() call.
	 *
	 * @var int
	 */
	public $next_rows_affected = 0;

	/**
	 * Row returned by the next get_row() call.
	 *
	 * @var array|null
	 */
	public $next_row = null;

	/**
	 * Result of the next insert() call.
	 *
	 * @var int|false
	 */
	public $next_insert_result = 1;

	/**
	 * Result of the next update() call.
	 *
	 * @var int|false
	 */
	public $next_update_result = 1;

	/**
	 * Every statement, in order: prepared SQL strings and "INSERT <table>" / "UPDATE <table>" markers.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Arguments of the last insert() call.
	 *
	 * @var array|null
	 */
	public $last_insert = null;

	/**
	 * Arguments of the last update() call.
	 *
	 * @var array|null
	 */
	public $last_update = null;

	/**
	 * Substitutes placeholders.
	 *
	 * @param string $query Query with %d / %s placeholders.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( "'%s'", '%s', $query );
		$i     = 0;
		return preg_replace_callback(
			'/%([ds])/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return 'd' === $m[1] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * Records a statement.
	 *
	 * @param string $query SQL.
	 */
	public function query( $query ) {
		$this->queries[]     = $query;
		$this->rows_affected = $this->next_rows_affected;
		return $this->rows_affected;
	}

	/**
	 * Records a SELECT.
	 *
	 * @param string $query  SQL.
	 * @param string $output Output type.
	 */
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		$this->queries[] = $query;
		return $this->next_row;
	}

	/**
	 * Records an insert.
	 *
	 * @param string $table  Table.
	 * @param array  $data   Column => value.
	 * @param array  $format Formats.
	 */
	public function insert( $table, $data, $format = null ) {
		$this->queries[]   = 'INSERT ' . $table;
		$this->last_insert = array( $table, $data, $format );
		if ( false !== $this->next_insert_result ) {
			$this->insert_id = 42;
		}
		return $this->next_insert_result;
	}

	/**
	 * Records an update.
	 *
	 * @param string $table        Table.
	 * @param array  $data         Column => value.
	 * @param array  $where        Column => value.
	 * @param array  $format       Formats.
	 * @param array  $where_format Where formats.
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->queries[]   = 'UPDATE ' . $table;
		$this->last_update = array( $table, $data, $where );
		return $this->next_update_result;
	}
}
