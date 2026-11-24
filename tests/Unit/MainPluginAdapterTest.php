<?php
/**
 * MainPluginAdapter tests.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Wallee\ExpressCheckout\MainPluginAdapter;

/**
 * Adapter with injectable environment.
 */
class FakeAdapter extends MainPluginAdapter {

	/** @var string[] Classes that "exist". */
	public $classes = array();
	/** @var string[] Methods that "exist" as Class::method. */
	public $methods = array();
	/** @var string */
	public $version = '3.4.11';
	/** @var array<array|null> Local row lookup results, consumed in order. */
	public $lookups = array();
	/** @var int */
	public $syncs = 0;

	protected function class_exists_safe( string $class_name ): bool {
		return in_array( $class_name, $this->classes, true );
	}

	protected function method_exists_safe( string $class_name, string $method ): bool {
		return in_array( $class_name . '::' . $method, $this->methods, true );
	}

	protected function main_plugin_version(): string {
		return $this->version;
	}

	protected function local_method( int $space_id, int $configuration_id ): ?array {
		return array_shift( $this->lookups );
	}

	public function syncPaymentMethods(): void {
		++$this->syncs;
	}
}

/**
 * @covers \Wallee\ExpressCheckout\MainPluginAdapter
 */
class MainPluginAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function transaction() {
		return new class() {
			public function getLinkedSpaceId() {
				return 7;
			}
			public function getPaymentConnectorConfiguration() {
				return new class() {
					public function getPaymentMethodConfiguration() {
						return new class() {
							public function getId() {
								return 99;
							}
						};
					}
				};
			}
		};
	}

	private function wc_with_gateways( array $ids ) {
		$gateways = array_fill_keys( $ids, true );
		$registry = new class( $gateways ) {
			private $g;
			public function __construct( $g ) {
				$this->g = $g;
			}
			public function payment_gateways() {
				return $this->g;
			}
		};
		$wc       = new class( $registry ) {
			private $r;
			public function __construct( $r ) {
				$this->r = $r;
			}
			public function payment_gateways() {
				return $this->r;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );
	}

	public function test_all_six_classes_missing() {
		$missing = ( new FakeAdapter() )->missingRequirements();
		$this->assertSame(
			array(
				'WooCommerce_Wallee',
				'WC_Wallee_Helper',
				'WC_Wallee_Service_Transaction',
				'WC_Wallee_Service_Webhook',
				'WC_Wallee_Service_Method_Configuration',
				'WC_Wallee_Entity_Method_Configuration',
			),
			$missing
		);
	}

	public function test_missing_methods_are_named() {
		$a          = new FakeAdapter();
		$a->classes = array_keys( MainPluginAdapter::REQUIRED_METHODS );
		$a->classes[] = 'WooCommerce_Wallee';
		$a->methods = array( 'WC_Wallee_Helper::get_api_client' );
		$missing    = $a->missingRequirements();
		$this->assertContains( 'WC_Wallee_Helper::get_base_gateway_url', $missing );
		$this->assertNotContains( 'WC_Wallee_Helper::get_api_client', $missing );
		$this->assertCount( 6, $missing );
	}

	public function test_nothing_missing_when_all_present() {
		$a          = new FakeAdapter();
		$a->classes = MainPluginAdapter::REQUIRED_CLASSES;
		foreach ( MainPluginAdapter::REQUIRED_METHODS as $c => $ms ) {
			foreach ( $ms as $m ) {
				$a->methods[] = $c . '::' . $m;
			}
		}
		$this->assertSame( array(), $a->missingRequirements() );
	}

	public function test_is_newer_than_tested() {
		$a = new FakeAdapter();
		foreach ( array(
			'3.5.0'  => true,
			'3.4.12' => true,
			'3.4.11' => false,
			'3.4.2'  => false,
		) as $version => $expected ) {
			$a->version = $version;
			$this->assertSame( $expected, $a->isNewerThanTested(), $version );
		}
	}

	public function test_is_configured_needs_all_three_options() {
		$opts = array(
			'wc_wallee_space_id'             => '7',
			'wc_wallee_application_user_id'  => '1',
			'wc_wallee_application_user_key' => '',
		);
		Functions\when( 'get_option' )->alias(
			function ( $name ) use ( &$opts ) {
				return $opts[ $name ] ?? false;
			}
		);
		$a = new FakeAdapter();
		$this->assertFalse( $a->isConfigured() );
		$opts['wc_wallee_application_user_key'] = 'k';
		$this->assertTrue( $a->isConfigured() );
		$this->assertSame( 7, $a->spaceId() );
	}

	private function row( int $id, string $state = 'active' ): array {
		return array(
			'id'    => $id,
			'state' => $state,
		);
	}

	public function test_resolve_gateway_id_found_without_sync() {
		$this->wc_with_gateways( array( 'wallee_5' ) );
		$a          = new FakeAdapter();
		$a->lookups = array( $this->row( 5 ) );
		$this->assertSame( 'wallee_5', $a->resolveGatewayId( $this->transaction() ) );
		$this->assertSame( 0, $a->syncs );
	}

	public function test_resolve_gateway_id_syncs_once_and_accepts_unregistered_gateway() {
		$this->wc_with_gateways( array() );
		foreach ( array( 'active', 'inactive' ) as $state ) {
			$a          = new FakeAdapter();
			$a->lookups = array( null, $this->row( 5, $state ) );
			$this->assertSame( 'wallee_5', $a->resolveGatewayId( $this->transaction() ), $state );
			$this->assertSame( 1, $a->syncs );
		}
	}

	public function test_resolve_gateway_id_null_when_post_sync_state_not_registrable() {
		$this->wc_with_gateways( array() );
		$a          = new FakeAdapter();
		$a->lookups = array( null, $this->row( 5, 'hidden' ) );
		$this->assertNull( $a->resolveGatewayId( $this->transaction() ) );
		$this->assertSame( 1, $a->syncs );
	}

	public function test_resolve_gateway_id_null_when_still_unknown() {
		$a          = new FakeAdapter();
		$a->lookups = array( null, null );
		$this->assertNull( $a->resolveGatewayId( $this->transaction() ) );
		$this->assertSame( 1, $a->syncs );
	}

	public function test_resolve_gateway_id_null_when_known_gateway_not_registered() {
		$this->wc_with_gateways( array( 'wallee_6' ) );
		$a          = new FakeAdapter();
		$a->lookups = array( $this->row( 5 ) );
		$this->assertNull( $a->resolveGatewayId( $this->transaction() ) );
		$this->assertSame( 0, $a->syncs );
	}
}
