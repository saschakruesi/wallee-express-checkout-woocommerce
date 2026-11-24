<?php
/**
 * PHPUnit bootstrap.
 *
 * @package Wallee\ExpressCheckout
 */

// Plugin files guard against direct access with `defined( 'ABSPATH' ) || exit;`.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require_once dirname( __DIR__ ) . '/plugin/wallee-express-checkout/src/Autoloader.php';
\Wallee\ExpressCheckout\Autoloader::register( dirname( __DIR__ ) . '/plugin/wallee-express-checkout/src' );
