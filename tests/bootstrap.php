<?php
/**
 * Unit tests without WordPress: Brain Monkey stands in for its functions,
 * tests/class-fake-account.php for Ordryx's server, and small classes below
 * for WordPress's and WooCommerce's objects the plugin touches.
 *
 * @package Ordryx
 */

require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ORDRYX_FILE', dirname( __DIR__ ) . '/ordryx-for-woocommerce.php' );
// The plugin's version as its own file defines it, never a copy kept here (a
// copy was left at 0.5.1 when the plugin went to 0.5.2).
if ( ! preg_match( "/^define\\( 'ORDRYX_VERSION', '(\\d+\\.\\d+\\.\\d+)' \\);$/m", (string) file_get_contents( ORDRYX_FILE ), $ordryx_version ) ) {
	throw new RuntimeException( 'ordryx-for-woocommerce.php defines no ORDRYX_VERSION.' );
}
define( 'ORDRYX_VERSION', $ordryx_version[1] );
define( 'ORDRYX_WC_AT_LEAST', '11.1' );
define( 'WC_VERSION', '11.2.1' );

require_once __DIR__ . '/doubles.php';
require_once __DIR__ . '/fulfillments-double.php';
require_once __DIR__ . '/class-fake-account.php';
require_once __DIR__ . '/class-ordryx-test-case.php';

foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
	require_once $file;
}
