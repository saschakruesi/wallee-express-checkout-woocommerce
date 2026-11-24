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
	 * @return object
	 */
	public function sdkClient(): object {
		return \WC_Wallee_Helper::instance()->get_api_client();
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
	 * Synchronises the payment method configurations once on a miss.
	 *
	 * @param object $transaction v1 SDK Transaction.
	 * @return string|null Null when unknown or the gateway is not registered.
	 */
	public function resolveGatewayId( object $transaction ): ?string {
		$connector = $transaction->getPaymentConnectorConfiguration();
		$method    = is_object( $connector ) ? $connector->getPaymentMethodConfiguration() : null;
		if ( ! is_object( $method ) ) {
			return null;
		}

		$space_id         = (int) $transaction->getLinkedSpaceId();
		$configuration_id = (int) $method->getId();

		$local_id = $this->local_method_id( $space_id, $configuration_id );
		if ( null === $local_id ) {
			$this->syncPaymentMethods();
			$local_id = $this->local_method_id( $space_id, $configuration_id );
		}
		if ( null === $local_id ) {
			return null;
		}

		$gateway_id = 'wallee_' . $local_id;
		$gateways   = WC()->payment_gateways()->payment_gateways();

		return isset( $gateways[ $gateway_id ] ) ? $gateway_id : null;
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
	 * Local method configuration ID for a wallee configuration, or null.
	 *
	 * @param int $space_id          Space ID.
	 * @param int $configuration_id  Payment method configuration ID.
	 * @return int|null
	 */
	protected function local_method_id( int $space_id, int $configuration_id ): ?int {
		$entity = \WC_Wallee_Entity_Method_Configuration::load_by_configuration( $space_id, $configuration_id );
		$id     = $entity->get_id();
		return empty( $id ) ? null : (int) $id;
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
