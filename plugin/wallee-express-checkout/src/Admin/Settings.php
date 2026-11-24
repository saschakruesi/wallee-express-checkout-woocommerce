<?php
/**
 * Settings: option getters and the WooCommerce settings section.
 *
 * @package Wallee\ExpressCheckout
 */

namespace Wallee\ExpressCheckout\Admin;

use Wallee\ExpressCheckout\Compat\SdkShim;
use Wallee\ExpressCheckout\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the add-on options and renders the add-on's own tab under
 * WooCommerce > Settings (owner decision: not under Payments).
 */
class Settings {

	const TAB         = 'wallee_ec';
	const ACTION      = 'wallee_ec_configure';
	const NONCE       = 'wallee_ec_configure';
	const NONCE_FIELD = 'wallee_ec_nonce';

	const OPT_CART    = 'wallee_ec_cart_enabled';
	const OPT_PRODUCT = 'wallee_ec_product_enabled';
	const OPT_ENV     = 'wallee_ec_environment';
	const OPT_COLUMNS = 'wallee_ec_max_columns';
	const OPT_ROWS    = 'wallee_ec_max_rows';
	const OPT_APPLE   = 'wallee_ec_apple_domain_association';

	/**
	 * Whether buttons show on the cart page.
	 *
	 * @return bool
	 */
	public function cartEnabled(): bool {
		return 'yes' === get_option( self::OPT_CART, 'no' );
	}

	/**
	 * Whether buttons show on product pages.
	 *
	 * @return bool
	 */
	public function productEnabled(): bool {
		return 'yes' === get_option( self::OPT_PRODUCT, 'no' );
	}

	/**
	 * Authorization environment: TEST unless PRODUCTION is stored.
	 *
	 * @return string
	 */
	public function environment(): string {
		return 'PRODUCTION' === get_option( self::OPT_ENV, 'TEST' ) ? 'PRODUCTION' : 'TEST';
	}

	/**
	 * Buttons per row, 1 to 6.
	 *
	 * @return int
	 */
	public function maxColumns(): int {
		return max( 1, min( 6, (int) get_option( self::OPT_COLUMNS, 2 ) ) );
	}

	/**
	 * Maximum rows, 1 to 10, or null for no limit.
	 *
	 * @return int|null
	 */
	public function maxRows(): ?int {
		$rows = max( 0, min( 10, (int) get_option( self::OPT_ROWS, 0 ) ) );
		return 0 === $rows ? null : $rows;
	}

	/**
	 * Apple Pay domain verification file content, verbatim.
	 *
	 * @return string
	 */
	public function appleDomainAssociation(): string {
		return (string) get_option( self::OPT_APPLE, '' );
	}

	/**
	 * Sanitises the Apple verification content: unslash and trim only (it is not HTML).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public static function sanitizeAppleContent( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( (string) wp_unslash( (string) $value ) );
	}

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		$instance = new self();
		add_filter( 'woocommerce_settings_tabs_array', array( $instance, 'addTab' ), 50 );
		add_action( 'woocommerce_settings_' . self::TAB, array( $instance, 'renderTab' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( $instance, 'saveTab' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WALLEE_EC_FILE ), array( $instance, 'actionLinks' ) );
		add_action( 'woocommerce_admin_field_wallee_ec_status', array( $instance, 'renderStatus' ) );
		add_action( 'woocommerce_admin_field_wallee_ec_configure', array( $instance, 'renderConfigure' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPT_APPLE, array( $instance, 'filterAppleOption' ), 10, 2 );
		add_action( 'admin_notices', array( $instance, 'showResults' ) );
		add_action( 'admin_notices', array( $instance, 'showTestEnvironmentNotice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $instance, 'handleConfigure' ) );
	}

	/**
	 * Settings page URL of the add-on's tab.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB );
	}

	/**
	 * Adds the tab, right after the wallee main plugin's tab when present.
	 *
	 * @param mixed $tabs Tab ID => label.
	 * @return array
	 */
	public function addTab( $tabs ) {
		$tabs  = is_array( $tabs ) ? $tabs : array();
		$label = __( 'wallee Express Checkout', 'wallee-express-checkout' );
		unset( $tabs[ self::TAB ] );
		if ( ! array_key_exists( 'wallee', $tabs ) ) {
			$tabs[ self::TAB ] = $label;
			return $tabs;
		}

		$ordered = array();
		foreach ( $tabs as $id => $title ) {
			$ordered[ $id ] = $title;
			if ( 'wallee' === $id ) {
				$ordered[ self::TAB ] = $label;
			}
		}
		return $ordered;
	}

	/**
	 * Renders the tab.
	 */
	public function renderTab(): void {
		woocommerce_admin_fields( $this->fields() );
	}

	/**
	 * Saves the tab. WooCommerce has checked the settings nonce and the capability.
	 */
	public function saveTab(): void {
		woocommerce_update_options( $this->fields() );
	}

	/**
	 * Adds a "Settings" link to the add-on's row on the Plugins screen.
	 *
	 * @param mixed $links Action links.
	 * @return array
	 */
	public function actionLinks( $links ) {
		$links = is_array( $links ) ? $links : array();
		return array_merge(
			array(
				'settings' => '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'wallee-express-checkout' ) . '</a>',
			),
			$links
		);
	}

	/**
	 * The fields of the tab.
	 *
	 * @return array
	 */
	public function fields(): array {
		return array(
			array(
				'title' => __( 'wallee Express Checkout', 'wallee-express-checkout' ),
				'type'  => 'title',
				'id'    => 'wallee_ec_section',
			),
			array(
				'type' => 'wallee_ec_status',
				'id'   => 'wallee_ec_status',
			),
			array(
				'title'   => __( 'Cart page', 'wallee-express-checkout' ),
				'desc'    => __( 'Show express checkout buttons on the cart page', 'wallee-express-checkout' ),
				'id'      => self::OPT_CART,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Product pages', 'wallee-express-checkout' ),
				'desc'    => __( 'Show express checkout buttons on product pages', 'wallee-express-checkout' ),
				'id'      => self::OPT_PRODUCT,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Environment', 'wallee-express-checkout' ),
				'id'      => self::OPT_ENV,
				'type'    => 'select',
				'default' => 'TEST',
				'options' => array(
					'TEST'       => __( 'Test', 'wallee-express-checkout' ),
					'PRODUCTION' => __( 'Production', 'wallee-express-checkout' ),
				),
			),
			array(
				'title'             => __( 'Buttons per row', 'wallee-express-checkout' ),
				'id'                => self::OPT_COLUMNS,
				'type'              => 'number',
				'default'           => '2',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '6',
					'step' => '1',
				),
			),
			array(
				'title'             => __( 'Maximum rows', 'wallee-express-checkout' ),
				'desc_tip'          => __( '0 means no limit.', 'wallee-express-checkout' ),
				'id'                => self::OPT_ROWS,
				'type'              => 'number',
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '10',
					'step' => '1',
				),
			),
			array(
				'title'    => __( 'Apple Pay domain verification file', 'wallee-express-checkout' ),
				'desc'     => __( 'Content of the domain association file, served as plain text.', 'wallee-express-checkout' ),
				'id'       => self::OPT_APPLE,
				'type'     => 'textarea',
				'css'      => 'width:100%;height:120px;font-family:monospace;',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'wallee_ec_configure',
				'id'   => 'wallee_ec_configure',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wallee_ec_section',
			),
		);
	}

	/**
	 * Keeps the Apple content verbatim: WooCommerce would run textareas through kses.
	 *
	 * @param mixed $value  Value WooCommerce sanitised.
	 * @param array $option Field definition.
	 * @return string
	 */
	public function filterAppleOption( $value, $option ) {
		unset( $value );
		$id = is_array( $option ) && isset( $option['id'] ) ? (string) $option['id'] : self::OPT_APPLE;
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- WooCommerce verified the settings nonce; sanitizeAppleContent unslashes and trims.
		return self::sanitizeAppleContent( isset( $_POST[ $id ] ) ? $_POST[ $id ] : '' );
		// phpcs:enable
	}

	/**
	 * Renders the read-only status block.
	 */
	public function renderStatus(): void {
		$adapter = Plugin::adapter();
		$missing = $adapter->missingRequirements();
		$url     = home_url( '/.well-known/apple-developer-merchantid-domain-association' );

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Status', 'wallee-express-checkout' ) . '</th><td class="forminp">';
		if ( ! empty( $missing ) ) {
			echo '<p>' . esc_html__( 'The wallee plugin was not detected.', 'wallee-express-checkout' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: wallee plugin version. */
					__( 'wallee plugin version: %s', 'wallee-express-checkout' ),
					$adapter->version()
				)
			) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: wallee space ID. */
					__( 'Space ID in use: %s', 'wallee-express-checkout' ),
					$adapter->isConfigured() ? (string) $adapter->spaceId() : __( 'not configured', 'wallee-express-checkout' )
				)
			) . '</p>';
			$shim_labels = array(
				'active'      => __( 'active', 'wallee-express-checkout' ),
				'not_needed'  => __( 'not needed', 'wallee-express-checkout' ),
				'unavailable' => __( 'unavailable', 'wallee-express-checkout' ),
			);
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: active, not needed or unavailable. */
					__( 'SDK compatibility shim: %s', 'wallee-express-checkout' ),
					$shim_labels[ SdkShim::status() ]
				)
			) . '</p>';
		}
		echo '<p>' . esc_html__( 'Apple Pay verification file is served at:', 'wallee-express-checkout' ) . ' <code>' . esc_html( $url ) . '</code></p>';
		echo '</td></tr>';
	}

	/**
	 * Renders the Configure button. It posts to admin-post.php with its own nonce.
	 */
	public function renderConfigure(): void {
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Setup', 'wallee-express-checkout' ) . '</th><td class="forminp">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
		printf(
			'<button type="submit" class="button button-secondary" formmethod="post" formnovalidate="formnovalidate" formaction="%s">%s</button>',
			esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ),
			esc_html__( 'Configure', 'wallee-express-checkout' )
		);
		echo '<p class="description">' . esc_html__( 'Verifies the wallee plugin credentials, sets up the webhook listeners and synchronises the payment methods. Unsaved changes on this page are not saved by this button.', 'wallee-express-checkout' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Handles the Configure button.
	 */
	public function handleConfigure(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You are not allowed to do this.', 'wallee-express-checkout' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, self::NONCE_FIELD );

		$results = ( new SetupService( Plugin::adapter() ) )->run();
		set_transient( $this->resultKey(), $results, 60 );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Shows the stored result of the last Configure run as notices.
	 */
	public function showResults(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( self::TAB !== $tab || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$results = get_transient( $this->resultKey() );
		if ( ! is_array( $results ) ) {
			return;
		}
		delete_transient( $this->resultKey() );

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || ! isset( $result['ok'], $result['message'] ) ) {
				continue;
			}
			printf(
				'<div class="notice %1$s"><p>%2$s</p></div>',
				esc_attr( $result['ok'] ? 'notice-success' : 'notice-error' ),
				esc_html( (string) $result['message'] )
			);
		}
	}

	/**
	 * Warning while the buttons are shown in the TEST environment, null
	 * otherwise.
	 *
	 * @return string|null
	 */
	public function testEnvironmentNotice(): ?string {
		if ( 'TEST' !== $this->environment() || ( ! $this->cartEnabled() && ! $this->productEnabled() ) ) {
			return null;
		}

		return __( 'wallee Express Checkout runs in the TEST environment. Payments are simulated; do not ship goods for these orders.', 'wallee-express-checkout' );
	}

	/**
	 * Shows the TEST environment warning on every admin page to users who
	 * manage WooCommerce. It cannot be dismissed.
	 */
	public function showTestEnvironmentNotice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$notice = $this->testEnvironmentNotice();
		if ( null === $notice ) {
			return;
		}
		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $notice ) );
	}

	/**
	 * Transient key of the current user's result.
	 *
	 * @return string
	 */
	private function resultKey(): string {
		return 'wallee_ec_setup_' . get_current_user_id();
	}
}
