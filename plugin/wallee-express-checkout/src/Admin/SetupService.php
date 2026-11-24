<?php
/**
 * One-click setup through the main plugin.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Admin;

use Wallee\ExpressCheckout\MainPluginAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the main plugin's credentials and sets up webhooks and payment methods.
 */
class SetupService {

	/**
	 * Adapter.
	 *
	 * @var MainPluginAdapter
	 */
	private $adapter;

	/**
	 * Constructor.
	 *
	 * @param MainPluginAdapter $adapter Adapter.
	 */
	public function __construct( MainPluginAdapter $adapter ) {
		$this->adapter = $adapter;
	}

	/**
	 * Runs all steps. A failing step does not stop the later ones.
	 *
	 * @return array<int, array{step: string, ok: bool, message: string}>
	 */
	public function run(): array {
		$results   = array();
		$results[] = $this->step(
			'credentials',
			function () {
				$this->adapter->verifyCredentials();
			},
			__( 'The credentials of the wallee plugin work against the configured space.', 'wallee-express-checkout' ),
			__( 'The wallee plugin credentials could not be verified. Check space ID, application user ID and key in the wallee plugin settings.', 'wallee-express-checkout' )
		);
		$results[] = $this->step(
			'webhooks',
			function () {
				$this->adapter->installWebhooks();
			},
			__( 'The webhook listeners are set up.', 'wallee-express-checkout' ),
			__( 'The webhook listeners could not be set up. See the WooCommerce log for details.', 'wallee-express-checkout' )
		);
		$results[] = $this->step(
			'payment_methods',
			function () {
				$this->adapter->syncPaymentMethods();
			},
			__( 'The payment methods are synchronised.', 'wallee-express-checkout' ),
			__( 'The payment methods could not be synchronised. See the WooCommerce log for details.', 'wallee-express-checkout' )
		);
		return $results;
	}

	/**
	 * Runs one step, turning any failure into a safe result.
	 *
	 * @param string   $step      Step name.
	 * @param callable $action    The work.
	 * @param string   $ok_text   Message on success.
	 * @param string   $fail_text Message on failure.
	 * @return array{step: string, ok: bool, message: string}
	 */
	private function step( string $step, callable $action, string $ok_text, string $fail_text ): array {
		try {
			$action();
			return array(
				'step'    => $step,
				'ok'      => true,
				'message' => $ok_text,
			);
		} catch ( \Throwable $e ) {
			// Class name only: exception messages may contain credentials or paths.
			$this->adapter->log( sprintf( 'Setup step %s failed (%s).', $step, get_class( $e ) ), 'error' );
			return array(
				'step'    => $step,
				'ok'      => false,
				'message' => $fail_text,
			);
		}
	}
}
