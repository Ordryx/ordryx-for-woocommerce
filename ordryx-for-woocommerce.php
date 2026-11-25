<?php
/**
 * Plugin Name:          Ordryx for WooCommerce
 * Plugin URI:           https://ordryx.com/woocommerce
 * Update URI:           https://ordryx.com/woocommerce
 * Description:          Connects this store to its Ordryx: orders flow in, with a read-only key, to plan, make and ship them, and orders shipped in Ordryx are marked shipped here.
 * Version:              0.5.12
 * Author:               Ordryx
 * Author URI:           https://ordryx.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          ordryx-for-woocommerce
 * Requires at least:    7.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.1
 * WC tested up to:      11.2
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

define( 'ORDRYX_VERSION', '0.5.12' );
define( 'ORDRYX_FILE', __FILE__ );
define( 'ORDRYX_WC_AT_LEAST', '11.1' );

require_once __DIR__ . '/includes/class-ordryx-clock.php';
require_once __DIR__ . '/includes/class-ordryx-state.php';
require_once __DIR__ . '/includes/class-ordryx-lock.php';
require_once __DIR__ . '/includes/class-ordryx-site.php';
require_once __DIR__ . '/includes/class-ordryx-account.php';
require_once __DIR__ . '/includes/class-ordryx-keys.php';
require_once __DIR__ . '/includes/class-ordryx-connection.php';
require_once __DIR__ . '/includes/class-ordryx-nudge.php';
require_once __DIR__ . '/includes/class-ordryx-fees.php';
require_once __DIR__ . '/includes/class-ordryx-proof.php';
require_once __DIR__ . '/includes/class-ordryx-shipped.php';
require_once __DIR__ . '/includes/class-ordryx-erasure.php';
require_once __DIR__ . '/includes/class-ordryx-dashboard.php';
require_once __DIR__ . '/includes/class-ordryx-admin.php';

// High-performance order storage and the cart and checkout blocks: the plugin
// reads orders only through WooCommerce's own order objects.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORDRYX_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ORDRYX_FILE, true );
		}
	}
);

register_deactivation_hook( __FILE__, array( 'Ordryx_Connection', 'deactivated' ) );

add_action(
	'plugins_loaded',
	static function () {
		Ordryx_Connection::hook();
		Ordryx_Nudge::hook();
		Ordryx_Fees::hook();
		Ordryx_Proof::hook();
		Ordryx_Shipped::hook();
		Ordryx_Erasure::hook();
		if ( is_admin() ) {
			Ordryx_Admin::hook();
		}
	}
);
