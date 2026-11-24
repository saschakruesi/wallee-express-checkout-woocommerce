<?php
/**
 * The only bridge to the wallee main plugin.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps every class, method and option of the main plugin that the add-on uses.
 *
 * No other class of the add-on may reference the main plugin.
 */
class MainPluginAdapter {

	/**
	 * Last main plugin version this add-on was tested against.
	 */
	const TESTED_UP_TO = '3.4.11';

	/**
	 * Method configuration states for which the main plugin registers gateways.
	 *
	 * @var string[]
	 */
	const REGISTERED_STATES = array( 'active', 'inactive' );

	/**
	 * Log source.
	 */
	const LOG_SOURCE = 'wallee-express-checkout';

	/**
	 * Classes the add-on needs.
	 *
	 * @var string[]
	 */
	const REQUIRED_CLASSES = array(
		'WooCommerce_Wallee',
		'WC_Wallee_Helper',
		'WC_Wallee_Service_Transaction',
		'WC_Wallee_Service_Webhook',
		'WC_Wallee_Service_Method_Configuration',
		'WC_Wallee_Entity_Method_Configuration',
	);

	/**
	 * Methods the add-on needs, as class => methods.
	 *
	 * @var array<string, string[]>
	 */
	const REQUIRED_METHODS = array(
		'WC_Wallee_Helper'                       => array( 'get_api_client', 'get_base_gateway_url' ),
		'WC_Wallee_Service_Transaction'          => array( 'get_transaction', 'update_transaction_info' ),
		'WC_Wallee_Service_Webhook'              => array( 'install' ),
		'WC_Wallee_Service_Method_Configuration' => array( 'synchronize' ),
		'WC_Wallee_Entity_Method_Configuration'  => array( 'load_by_configuration' ),
	);

	/**
	 * Lists what is missing from the main plugin. Empty when usable.
	 *
	 * @return string[]
	 */
	public function missingRequirements(): array {
		$missing = array();

		foreach ( self::REQUIRED_CLASSES as $class_name ) {
			if ( ! $this->class_exists_safe( $class_name ) ) {
				$missing[] = $class_name;
			}
		}

		foreach ( self::REQUIRED_METHODS as $class_name => $methods ) {
			if ( ! $this->class_exists_safe( $class_name ) ) {
				continue;
			}
			foreach ( $methods as $method ) {
				if ( ! $this->method_exists_safe( $class_name, $method ) ) {
					$missing[] = $class_name . '::' . $method;
				}
			}
		}

		return $missing;
	}

	/**
	 * Whether space ID, application user ID and key are set.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		foreach ( array( 'wc_wallee_space_id', 'wc_wallee_application_user_id', 'wc_wallee_application_user_key' ) as $option ) {
			$value = get_option( $option );
			if ( empty( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Version of the installed main plugin.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->main_plugin_version();
	}

	/**
	 * Whether the main plugin is newer than the last tested version.
	 *
	 * @return bool
	 */
	public function isNewerThanTested(): bool {
		return version_compare( $this->version(), self::TESTED_UP_TO, '>' );
	}

	/**
	 * Configured wallee space ID.
	 *
	 * @return int
	 */
	public function spaceId(): int {
		return (int) get_option( 'wc_wallee_space_id' );
	}

	/**
	 * Base URL of the wallee portal, with trailing slash.
	 *
	 * @return string
	 */
	public function baseUrl(): string {
		return rtrim( (string) \WC_Wallee_Helper::instance()->get_base_gateway_url(), '/' ) . '/';
	}

	/**
	 * The main plugin's authenticated SDK client (\Wallee\Sdk\ApiClient).
	 *
	 * @throws \Exception When the client cannot be built (for example missing credentials).
	 * @return object
	 */
	public function sdkClient(): object {
		return \WC_Wallee_Helper::instance()->get_api_client();
	}

	/**
	 * Verifies that the main plugin's credentials can read the configured space.
	 *
	 * @throws \Throwable When the client cannot be built or the space cannot be read.
	 */
	public function verifyCredentials(): void {
		$service = new \Wallee\Sdk\Service\SpaceService( $this->sdkClient() );
		$service->read( $this->spaceId() );
	}

	/**
	 * Reads a transaction (v1 SDK model).
	 *
	 * @param int $transaction_id Transaction ID.
	 * @return object
	 */
	public function readTransaction( int $transaction_id ): object {
		return \WC_Wallee_Service_Transaction::instance()->get_transaction( $this->spaceId(), $transaction_id );
	}

	/**
	 * State of a transaction read by readTransaction(), empty if unknown.
	 *
	 * @param object $transaction v1 SDK Transaction.
	 * @return string
	 */
	public function transactionState( object $transaction ): string {
		return method_exists( $transaction, 'getState' ) ? (string) $transaction->getState() : '';
	}

	/**
	 * Writes the main plugin's transaction info row for the order (idempotent).
	 *
	 * @param object    $transaction v1 SDK Transaction.
	 * @param \WC_Order $order       Order.
	 */
	public function linkOrder( object $transaction, \WC_Order $order ): void {
		\WC_Wallee_Service_Transaction::instance()->update_transaction_info( $transaction, $order );
	}

	/**
	 * Resolves the main plugin gateway ID (`wallee_<local id>`) of a transaction.
	 *
	 * If the configuration is known locally, the gateway must also be registered
	 * in WooCommerce, otherwise null is returned. If it is only known after the
	 * one-off synchronisation, the gateway cannot be in this request's registry
	 * (the main plugin builds it from the database when WooCommerce first loads
	 * the gateways). It is then accepted when the row's state is one the main
	 * plugin registers gateways for; it is registered from the next request on.
	 *
	 * @param object $transaction v1 SDK Transaction.
	 * @return string|null Null when unknown or the gateway is not (going to be) registered.
	 */
	public function resolveGatewayId( object $transaction ): ?string {
		$connector = $transaction->getPaymentConnectorConfiguration();
		$method    = is_object( $connector ) ? $connector->getPaymentMethodConfiguration() : null;
		if ( ! is_object( $method ) ) {
			return null;
		}

		$space_id         = (int) $transaction->getLinkedSpaceId();
		$configuration_id = (int) $method->getId();

		$row = $this->local_method( $space_id, $configuration_id );
		if ( null !== $row ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			return isset( $gateways[ 'wallee_' . $row['id'] ] ) ? 'wallee_' . $row['id'] : null;
		}

		$this->syncPaymentMethods();
		$row = $this->local_method( $space_id, $configuration_id );
		if ( null === $row || ! in_array( $row['state'], self::REGISTERED_STATES, true ) ) {
			return null;
		}

		return 'wallee_' . $row['id'];
	}

	/**
	 * Registers the main plugin's webhook listeners at wallee.
	 */
	public function installWebhooks(): void {
		\WC_Wallee_Service_Webhook::instance()->install();
	}

	/**
	 * Synchronises payment method configurations from wallee.
	 */
	public function syncPaymentMethods(): void {
		\WC_Wallee_Service_Method_Configuration::instance()->synchronize();
	}

	/**
	 * Writes to the WooCommerce log. Messages must carry IDs and states only.
	 *
	 * @param string $message Message.
	 * @param string $level   PSR-3 level.
	 */
	public function log( string $message, string $level = 'info' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}

	/**
	 * Local method configuration row for a wallee configuration, or null.
	 *
	 * @param int $space_id          Space ID.
	 * @param int $configuration_id  Payment method configuration ID.
	 * @return array{id: int, state: string}|null
	 */
	protected function local_method( int $space_id, int $configuration_id ): ?array {
		$entity = \WC_Wallee_Entity_Method_Configuration::load_by_configuration( $space_id, $configuration_id );
		$id     = $entity->get_id();
		if ( empty( $id ) ) {
			return null;
		}
		return array(
			'id'    => (int) $id,
			'state' => (string) $entity->get_state(),
		);
	}

	/**
	 * Class existence check, overridable in tests.
	 *
	 * @param string $class_name Class.
	 * @return bool
	 */
	protected function class_exists_safe( string $class_name ): bool {
		return class_exists( $class_name );
	}

	/**
	 * Method existence check, overridable in tests.
	 *
	 * @param string $class_name Class.
	 * @param string $method     Method.
	 * @return bool
	 */
	protected function method_exists_safe( string $class_name, string $method ): bool {
		return method_exists( $class_name, $method );
	}

	/**
	 * Main plugin version, overridable in tests.
	 *
	 * @return string
	 */
	protected function main_plugin_version(): string {
		if ( ! $this->class_exists_safe( 'WooCommerce_Wallee' ) ) {
			return '0';
		}
		return (string) \WooCommerce_Wallee::instance()->get_version();
	}
}
