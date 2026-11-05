<?php
/**
 * uninstall.php run as WordPress runs it: the plugin inactive, so nothing of
 * it loaded but what uninstall.php loads itself (UninstallTest runs this in a
 * PHP of its own). WordPress's few functions it reaches are stood in for.
 *
 * With "copy" as its argument, the site is a copy of a connected store (a
 * staging site): a store, its secret and the address it was connected at,
 * and a Site Address that isn't it. Nothing may be posted.
 *
 * With "network", it's a multisite network of sites 1 and 3, run as the main
 * site (1) as WordPress runs it. Site 3 is the store connected to Ordryx, its
 * key 7 and its Action Scheduler table in its own wp_3_ tables; site 1 has
 * nothing of the plugin's, and no WooCommerce: so Action Scheduler isn't
 * loaded. What's said is prefixed with the site it's said on.
 *
 * With "waiting", a connected store with erasures still queued (orders 5 and
 * 6); with "offline" too, and nothing answers (Ordryx's server unreachable).
 * A copy has erasures queued too.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- a test's own PHP process.

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'ordryx-for-woocommerce/ordryx-for-woocommerce.php' );
$GLOBALS['said']    = array();
$GLOBALS['copy']    = isset( $argv[1] ) && 'copy' === $argv[1];
$GLOBALS['network'] = isset( $argv[1] ) && 'network' === $argv[1];
$GLOBALS['waiting'] = isset( $argv[1] ) && in_array( $argv[1], array( 'waiting', 'offline' ), true );
$GLOBALS['offline'] = isset( $argv[1] ) && 'offline' === $argv[1];
$GLOBALS['blog']    = 1;
function said( $what ) {
	$GLOBALS['said'][] = ( $GLOBALS['network'] ? 'site ' . $GLOBALS['blog'] . ': ' : '' ) . $what;
}
class Uninstall_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public function prepare( $query, ...$args ) {
		return vsprintf( $query, $args );
	}
	public function get_row( $query ) {
		if ( false === strpos( $query, "{$this->prefix}woocommerce_api_keys WHERE key_id = 7" ) ) {
			return null;
		}
		return (object) array( 'key_id' => 7, 'user_id' => 1, 'description' => 'Ordryx - API (October 27, 2026 at 9:00 am)', 'permissions' => 'read', 'consumer_key' => 'x' );
	}
	public function delete( $table, $where, $format = null ) {
		said( 'deleted key ' . $where['key_id'] . ' from ' . $table );
		return 1;
	}
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function get_var( $query ) {
		if ( preg_match( '/^SHOW TABLES LIKE (\S+)$/', $query, $like ) ) {
			$table = stripslashes( $like[1] );
			return 'wp_3_actionscheduler_actions' === $table ? $table : null; // (WooCommerce runs on site 3 only)
		}
		return null;
	}
	public function query( $query ) {
		said( 'query ' . $query );
		return 1;
	}
}
$GLOBALS['wpdb'] = new Uninstall_Wpdb();
function is_multisite() {
	return $GLOBALS['network'];
}
function get_sites( $args = array() ) {
	said( 'sites ' . json_encode( $args ) );
	return array( 1, 3 );
}
function switch_to_blog( $id ) {
	$GLOBALS['blog']         = $id;
	$GLOBALS['wpdb']->prefix  = 1 === $id ? 'wp_' : "wp_{$id}_";
	$GLOBALS['wpdb']->options = $GLOBALS['wpdb']->prefix . 'options'; // (as WordPress's own switch)
	return true;
}
function restore_current_blog() {
	switch_to_blog( 1 );
	return true;
}
function get_option( $name, $default = false ) {
	if ( $GLOBALS['network'] ) {
		$kept = 3 !== $GLOBALS['blog'] ? array() : array(
			'ordryx_state'  => array(
				'phase'  => 'connected',
				'store'  => 'st_3',
				'key_id' => 7,
				'site'   => array( 'home' => base64_encode( 'https://three.example.com' ), 'env' => 'production' ),
			),
			'ordryx_secret' => base64_encode( str_repeat( "\x33", 32 ) ),
			'ordryx_keys'   => array( 7 ),
			'home'          => 'https://three.example.com',
		);
		return isset( $kept[ $name ] ) ? $kept[ $name ] : $default;
	}
	if ( $GLOBALS['copy'] || $GLOBALS['waiting'] ) {
		$kept = array(
			'ordryx_state'  => array(
				'phase' => 'connected',
				'store' => 'st_4f9a',
				'site'  => array( 'home' => base64_encode( 'https://shop.example.com' ), 'env' => 'production' ),
			),
			'ordryx_secret' => base64_encode( str_repeat( "\x5a", 32 ) ),
			'ordryx_forget' => array( 5, 6 ),
			'home'          => $GLOBALS['copy'] ? 'https://staging.shop.example.com' : 'https://shop.example.com',
		);
		return isset( $kept[ $name ] ) ? $kept[ $name ] : $default;
	}
	return 'ordryx_state' === $name ? array( 'phase' => 'connected', 'store' => '' ) : $default;
}
function update_option( $name, $value, $autoload = null ) {
	return true;
}
function delete_option( $name ) {
	said( 'option ' . $name );
	return true;
}
function delete_transient( $name ) {
	said( 'transient ' . $name );
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	return true;
}
if ( ! $GLOBALS['network'] ) { // (on the network, WooCommerce isn't active on the main site)
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		said( 'unscheduled group ' . $group );
	}
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function wp_get_current_user() {
	return (object) array( 'display_name' => 'Avery Admin' );
}
function wp_get_environment_type() {
	return 'production';
}
function wp_remote_post( $url, $args ) {
	$body = json_decode( $args['body'], true );
	said( 'posted ' . $url . ' for ' . $args['headers']['X-Ordryx-Store'] . ' from ' . $body['site'] . ( isset( $body['orders'] ) ? ' orders ' . json_encode( $body['orders'] ) . ' within ' . $args['timeout'] : '' ) );
	return $GLOBALS['offline'] ? 'down' : array();
}
function is_wp_error( $thing ) {
	return 'down' === $thing;
}
function wp_remote_retrieve_body( $response ) {
	return '{"ok":true}';
}
function wp_remote_retrieve_response_code( $response ) {
	return 200;
}
require dirname( __DIR__ ) . '/uninstall.php';
echo implode( "\n", $GLOBALS['said'] ), "\ndone\n";
