<?php
/**
 * The plugin deleted from the Plugins screen (or WP-CLI): Ordryx's key in
 * WooCommerce goes, then everything the plugin kept, and Ordryx's server is
 * told (never from a copy of the site: Ordryx_Site).
 *
 * On a multisite network WordPress runs this once, as the main site, though
 * the plugin may have been connected on any site of it: so each site's own
 * key, record and notice, as that site.
 *
 * Erasures still waiting are sent once more first (as much as the time
 * allows: the sites of a network share 30 seconds for it), then lost.
 *
 * @package Ordryx
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-ordryx-clock.php';
require_once __DIR__ . '/includes/class-ordryx-state.php';
require_once __DIR__ . '/includes/class-ordryx-site.php';
require_once __DIR__ . '/includes/class-ordryx-account.php';
require_once __DIR__ . '/includes/class-ordryx-keys.php';
require_once __DIR__ . '/includes/class-ordryx-lock.php';
require_once __DIR__ . '/includes/class-ordryx-erasure.php';
require_once __DIR__ . '/includes/class-ordryx-connection.php';

$ordryx_until = Ordryx_Clock::now() + 30;
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $ordryx_site_id ) {
		switch_to_blog( (int) $ordryx_site_id );
		Ordryx_Connection::uninstalled( $ordryx_until );
		restore_current_blog();
	}
} else {
	Ordryx_Connection::uninstalled( $ordryx_until );
}
