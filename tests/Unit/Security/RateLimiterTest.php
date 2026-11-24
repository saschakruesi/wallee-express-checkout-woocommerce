<?php
/**
 * RateLimiter tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\Security\RateLimiter;

require_once dirname( __DIR__, 2 ) . '/Support/wpdb-stub.php';

/**
 * Database double that executes the limiter's one upsert statement on an
 * in-memory table, the way MySQL does: LAST_INSERT_ID(expr) of the statement
 * is what `SELECT LAST_INSERT_ID()` returns afterwards on the same
 * connection (checked against MySQL 8.0; mysqli's insert ID stays 0).
 */
class RateTableWpdb extends \wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Value of LAST_INSERT_ID() after the last statement.
	 *
	 * @var int
	 */
	public $last_insert_id = 0;

	/**
	 * Rows: bucket => [hits, expires_at].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	public $rows = array();

	/**
	 * Every statement as passed to query().
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Arguments of the last prepare() call.
	 *
	 * @var array
	 */
	public $last_args = array();

	/**
	 * Whether query() reports a database error.
	 *
	 * @var bool
	 */
	public $fail = false;

	/**
	 * Records the arguments, returns the template marked as prepared.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( $query, ...$args ) {
		$this->last_args = $args;
		return 'PREPARED ' . $query;
	}

	/**
	 * Runs the upsert on the in-memory table.
	 *
	 * @param string $query SQL.
	 */
	public function query( $query ) {
		$this->queries[] = $query;
		if ( $this->fail ) {
			return false;
		}
		list( $bucket, $expires, $now ) = $this->last_args;
		if ( ! isset( $this->rows[ $bucket ] ) ) {
			$this->rows[ $bucket ] = array( 1, $expires );
			$this->last_insert_id  = 1;

			return 1;
		}
		list( $hits, $old_expires ) = $this->rows[ $bucket ];
		$hits                       = $old_expires <= $now ? 1 : $hits + 1;
		$this->rows[ $bucket ]      = array( $hits, $old_expires <= $now ? $expires : $old_expires );
		$this->last_insert_id       = $hits;

		return 2;
	}

	/**
	 * Answers `SELECT LAST_INSERT_ID()`.
	 *
	 * @param string $query SQL.
	 */
	public function get_var( $query = null ) {
		$this->queries[] = $query;
		return 'SELECT LAST_INSERT_ID()' === $query ? (string) $this->last_insert_id : null;
	}
}

/**
 * @covers \Wallee\ExpressCheckout\Security\RateLimiter
 */
class RateLimiterTest extends TestCase {

	/**
	 * Transient store of the fallback.
	 *
	 * @var array
	 */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allows_up_to_max_then_denies_with_independent_buckets() {
		$db      = new RateTableWpdb();
		$limiter = new RateLimiter( $db );

		$this->assertTrue( $limiter->allow( 'a', 2, 60 ) );
		$this->assertTrue( $limiter->allow( 'a', 2, 60 ) );
		$this->assertFalse( $limiter->allow( 'a', 2, 60 ) );
		$this->assertTrue( $limiter->allow( 'b', 2, 60 ) );
		$this->assertArrayHasKey( md5( 'a' ), $db->rows );
		$this->assertSame( array(), $this->store, 'no transients with the table' );
	}

	public function test_each_hit_is_one_upsert_that_increments_in_the_database() {
		$db = new RateTableWpdb();

		( new RateLimiter( $db ) )->allow( 'session|203.0.113.9', 20, 60 );

		$this->assertCount( 2, $db->queries );
		$this->assertSame( 'SELECT LAST_INSERT_ID()', $db->queries[1], 'the count comes from the write, not from a read of the table' );
		$sql = $db->queries[0];
		$this->assertStringStartsWith( 'PREPARED INSERT INTO wp_wallee_ec_rate ', $sql );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
		$this->assertStringContainsString( 'hits = LAST_INSERT_ID(IF(expires_at <= %d, 1, hits + 1))', $sql );
		$this->assertSame( md5( 'session|203.0.113.9' ), $db->last_args[0] );
		$this->assertEqualsWithDelta( time() + 60, $db->last_args[1], 2 );
		$this->assertEqualsWithDelta( time(), $db->last_args[2], 2 );
	}

	public function test_a_new_window_starts_after_expiry() {
		$db                      = new RateTableWpdb();
		$db->rows[ md5( 'a' ) ] = array( 5, time() - 1 );

		$this->assertTrue( ( new RateLimiter( $db ) )->allow( 'a', 2, 60 ), 'the old window is over' );
		$this->assertSame( 1, $db->rows[ md5( 'a' ) ][0] );
		$this->assertEqualsWithDelta( time() + 60, $db->rows[ md5( 'a' ) ][1], 2 );
	}

	public function test_hits_in_an_open_window_keep_its_expiry() {
		$db                      = new RateTableWpdb();
		$expires                 = time() + 30;
		$db->rows[ md5( 'a' ) ] = array( 1, $expires );

		( new RateLimiter( $db ) )->allow( 'a', 2, 60 );

		$this->assertSame( array( 2, $expires ), $db->rows[ md5( 'a' ) ] );
	}

	public function test_a_database_error_falls_back_to_transients() {
		$db       = new RateTableWpdb();
		$db->fail = true;
		$limiter  = new RateLimiter( $db );

		$this->assertTrue( $limiter->allow( 'a', 1, 60 ) );
		$this->assertFalse( $limiter->allow( 'a', 1, 60 ) );
		$this->assertArrayHasKey( 'wallee_ec_rl_' . md5( 'a' ), $this->store );
	}

	public function test_an_unusable_count_falls_back_to_transients() {
		$db = new class() extends RateTableWpdb {
			public function get_var( $query = null ) {
				return '0';
			}
		};

		$this->assertTrue( ( new RateLimiter( $db ) )->allow( 'a', 1, 60 ) );
		$this->assertArrayHasKey( 'wallee_ec_rl_' . md5( 'a' ), $this->store );
	}

	public function test_without_a_database_transients_are_used() {
		$limiter = new RateLimiter();

		$this->assertTrue( $limiter->allow( 'a', 2, 60 ) );
		$this->assertTrue( $limiter->allow( 'a', 2, 60 ) );
		$this->assertFalse( $limiter->allow( 'a', 2, 60 ) );
		$this->assertArrayHasKey( 'wallee_ec_rl_' . md5( 'a' ), $this->store );
	}

	public function test_expired_counters_are_purged_by_one_statement() {
		$db = new class() extends \wpdb {
			public $prefix  = 'wp_';
			public $queries = array();
			public function prepare( $query, ...$args ) {
				return str_replace( '%d', (string) $args[0], $query );
			}
			public function query( $query ) {
				$this->queries[] = $query;
				return 3;
			}
		};

		$this->assertSame( 3, RateLimiter::purgeExpired( $db ) );
		$this->assertCount( 1, $db->queries );
		$this->assertMatchesRegularExpression( '/^DELETE FROM wp_wallee_ec_rate WHERE expires_at < \d+$/', $db->queries[0] );
	}
	/**
	 * Database double for claimOnce(): INSERT IGNORE on a primary key.
	 *
	 * @param bool $fail Whether the statement fails.
	 */
	private static function claim_db( bool $fail = false ): \wpdb {
		return new class( $fail ) extends \wpdb {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Buckets present.
			 *
			 * @var array<string, int>
			 */
			public $rows = array();

			/**
			 * Statements.
			 *
			 * @var string[]
			 */
			public $queries = array();

			/**
			 * Arguments of the last prepare().
			 *
			 * @var array
			 */
			private $args = array();

			/**
			 * Whether query() fails.
			 *
			 * @var bool
			 */
			private $fail;

			/**
			 * Constructor.
			 *
			 * @param bool $fail Whether query() fails.
			 */
			public function __construct( bool $fail ) {
				$this->fail = $fail;
			}

			/**
			 * Records the arguments.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Values.
			 */
			public function prepare( $query, ...$args ) {
				$this->args = $args;
				return 'PREPARED ' . $query;
			}

			/**
			 * INSERT IGNORE: 1 row for a new bucket, 0 for an existing one.
			 *
			 * @param string $query SQL.
			 */
			public function query( $query ) {
				$this->queries[] = $query;
				if ( $this->fail ) {
					return false;
				}
				if ( isset( $this->rows[ $this->args[0] ] ) ) {
					return 0;
				}
				$this->rows[ $this->args[0] ] = $this->args[1];

				return 1;
			}
		};
	}

	public function test_a_claim_succeeds_once_per_name() {
		$db = self::claim_db();

		$this->assertTrue( RateLimiter::claimOnce( $db, 'void|501', 3600 ) );
		$this->assertFalse( RateLimiter::claimOnce( $db, 'void|501', 3600 ) );
		$this->assertTrue( RateLimiter::claimOnce( $db, 'void|502', 3600 ) );
		$this->assertStringStartsWith( 'PREPARED INSERT IGNORE INTO wp_wallee_ec_rate (bucket, hits, expires_at) VALUES (%s, 1, %d)', $db->queries[0] );
		$this->assertArrayHasKey( md5( 'once|void|501' ), $db->rows );
	}

	public function test_a_claim_without_a_database_answer_is_unknown() {
		$this->assertNull( RateLimiter::claimOnce( self::claim_db( true ), 'void|501', 3600 ) );
	}
}
