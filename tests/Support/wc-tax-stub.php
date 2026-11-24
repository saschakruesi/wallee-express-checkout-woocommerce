<?php
/**
 * Minimal stand-in for WooCommerce's WC_Tax: a rate table and find_rates().
 *
 * @package Wallee\ExpressCheckout
 */

if ( ! class_exists( 'WC_Tax', false ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting.ClassComment,Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test stub for a WooCommerce class.
	class WC_Tax {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing

		/**
		 * Rate rows: id, country, state, postcode, city, class, rate, shipping, compound.
		 * An empty state, postcode or city matches any value; a postcode ending
		 * in `*` matches by prefix.
		 */
		public static $rows = array();

		public static $calls = array();

		public static function reset(): void {
			self::$rows  = array(
				self::row( 1, 'CH', '', 8.1 ),
				self::row( 2, 'CH', 'reduced-rate', 2.6 ),
				self::row( 3, 'DE', '', 19.0 ),
				self::row( 4, 'DE', 'reduced-rate', 7.0 ),
				self::row( 5, 'AT', '', 20.0 ),
			);
			self::$calls = array();
		}

		public static function row( int $id, string $country, string $tax_class, float $rate, array $over = array() ): array {
			return array_merge(
				array(
					'id'       => $id,
					'country'  => $country,
					'state'    => '',
					'postcode' => '',
					'city'     => '',
					'class'    => $tax_class,
					'rate'     => $rate,
					'shipping' => 'yes',
					'compound' => 'no',
				),
				$over
			);
		}

		public static function find_rates( $args = array() ) {
			self::$calls[] = $args;
			$country       = strtoupper( (string) ( $args['country'] ?? '' ) );
			if ( '' === $country ) {
				return array();
			}
			$state    = strtoupper( (string) ( $args['state'] ?? '' ) );
			$postcode = strtoupper( str_replace( ' ', '', (string) ( $args['postcode'] ?? '' ) ) );
			$city     = strtoupper( (string) ( $args['city'] ?? '' ) );
			$class    = (string) ( $args['tax_class'] ?? '' );

			$found = array();
			foreach ( self::$rows as $row ) {
				if ( $row['country'] !== $country || $row['class'] !== $class ) {
					continue;
				}
				if ( '' !== $row['state'] && $row['state'] !== $state ) {
					continue;
				}
				if ( '' !== $row['city'] && strtoupper( $row['city'] ) !== $city ) {
					continue;
				}
				if ( '' !== $row['postcode'] && ! self::postcodeMatches( $row['postcode'], $postcode ) ) {
					continue;
				}
				$found[ $row['id'] ] = array(
					'rate'     => $row['rate'],
					'label'    => 'VAT',
					'shipping' => $row['shipping'],
					'compound' => $row['compound'],
				);
			}

			return $found;
		}

		private static function postcodeMatches( string $pattern, string $postcode ): bool {
			if ( '*' === substr( $pattern, -1 ) ) {
				return 0 === strpos( $postcode, substr( $pattern, 0, -1 ) );
			}

			return $pattern === $postcode;
		}
	}
}
