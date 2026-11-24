<?php
/**
 * Minimal stand-ins for WC_Coupon, its data store and WC_Discounts, so the
 * coupon re-validation can be unit tested.
 *
 * The stand-in WC_Discounts applies the two rules that depend on the
 * customer the way WooCommerce does: e-mail restrictions against the order's
 * billing e-mail, and the usage limit per user for customers with an
 * account. Everything else is a scripted "valid" flag.
 *
 * @package Wallee\ExpressCheckout
 */

// phpcs:disable Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting.ClassComment,Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing -- Test stubs for WooCommerce classes.

if ( ! class_exists( 'WC_Coupon', false ) ) {
	class WC_Coupon {

		/**
		 * Coupons by code: id, valid, email_restrictions, usage_limit_per_user, usage_by_email, usage_by_user.
		 *
		 * @var array
		 */
		public static $registry = array();

		public $props;

		public $code;

		public function __construct( $code ) {
			$this->code  = $code;
			$this->props = array_merge(
				array(
					'id'                   => 0,
					'valid'                => true,
					'email_restrictions'   => array(),
					'usage_limit_per_user' => 0,
					'usage_by_email'       => array(),
					'usage_by_user'        => array(),
					'usage_limit'          => 0,
					'hold'                 => null,
					'hold_for_user'        => null,
				),
				isset( self::$registry[ $code ] ) ? self::$registry[ $code ] : array()
			);
		}

		public function get_id() {
			return $this->props['id'];
		}

		public function get_code() {
			return $this->code;
		}

		public function get_usage_limit() {
			return $this->props['usage_limit'];
		}

		public function get_email_restrictions() {
			return $this->props['email_restrictions'];
		}

		public function set_email_restrictions( $emails ) {
			$this->props['email_restrictions'] = $emails;
		}

		public function get_usage_limit_per_user() {
			return $this->props['usage_limit_per_user'];
		}

		public function get_used_by() {
			return array();
		}

		public function get_data_store() {
			return new WC_Coupon_Data_Store_Stub();
		}
	}

	class WC_Coupon_Data_Store_Stub {

		/**
		 * Hold calls: array( method, code, aliases, alias ).
		 *
		 * @var array[]
		 */
		public static $holds = array();

		/**
		 * Scripted result of a hold: `hold` / `hold_for_user` of the coupon, a
		 * Throwable is thrown, null (the default) gives a key.
		 */
		private static function result( $coupon, $prop ) {
			$result = $coupon->props[ $prop ];
			if ( $result instanceof \Throwable ) {
				throw $result;
			}

			return null === $result ? 'held_' . $coupon->code : $result;
		}

		public function check_and_hold_coupon( $coupon ) {
			self::$holds[] = array( 'check_and_hold_coupon', $coupon->code );

			return self::result( $coupon, 'hold' );
		}

		public function check_and_hold_coupon_for_user( $coupon, $aliases, $alias ) {
			self::$holds[] = array( 'check_and_hold_coupon_for_user', $coupon->code, $aliases, $alias );

			return self::result( $coupon, 'hold_for_user' );
		}

		public function get_usage_by_email( $coupon, $email ) {
			return isset( $coupon->props['usage_by_email'][ $email ] ) ? $coupon->props['usage_by_email'][ $email ] : 0;
		}

		public function get_usage_by_user_id( $coupon, $user_id ) {
			return isset( $coupon->props['usage_by_user'][ $user_id ] ) ? $coupon->props['usage_by_user'][ $user_id ] : 0;
		}
	}
}

if ( ! class_exists( 'WC_Discounts', false ) ) {
	class WC_Discounts {

		public $object;

		public function __construct( $order ) {
			$this->object = $order;
		}

		public function is_coupon_valid( $coupon ) {
			if ( ! $coupon->props['valid'] ) {
				return new \stdClass();
			}
			$restrictions = $coupon->get_email_restrictions();
			if ( array() !== $restrictions ) {
				$email = strtolower( (string) $this->object->get_billing_email() );
				$match = false;
				foreach ( $restrictions as $allowed ) {
					$regex = '/^' . str_replace( '\*', '.*', preg_quote( strtolower( $allowed ), '/' ) ) . '$/';
					$match = $match || ( '' !== $email && 1 === preg_match( $regex, $email ) );
				}
				if ( ! $match ) {
					return new \stdClass();
				}
			}
			$user = (int) $this->object->get_customer_id();
			if ( $user > 0 && $coupon->get_usage_limit_per_user() > 0 && $coupon->get_data_store()->get_usage_by_user_id( $coupon, $user ) >= $coupon->get_usage_limit_per_user() ) {
				return new \stdClass();
			}

			return true;
		}
	}
}
