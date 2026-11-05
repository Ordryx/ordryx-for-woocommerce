<?php
/**
 * Deleting the plugin: uninstall.php needs only the classes it loads itself
 * (the dashboard's look once named a class it doesn't, and every delete
 * ended in a fatal error).
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class UninstallTest extends \PHPUnit\Framework\TestCase {

	private function uninstall( $as = '' ) {
		$out  = array();
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/uninstall-alone.php' ) . ( '' !== $as ? ' ' . escapeshellarg( $as ) : '' ) . ' 2>&1', $out, $code );
		$said = implode( "\n", $out );
		$this->assertSame( 0, $code, $said );
		return $said;
	}

	public function test_uninstall_runs_with_only_what_it_loads() {
		$said = $this->uninstall();
		$this->assertStringContainsString( 'option ordryx_state', $said );
		$this->assertStringContainsString( 'option ordryx_look_gen', $said );
		$this->assertStringContainsString( 'transient ordryx_look', $said );
		$this->assertStringContainsString( 'unscheduled group ordryx', $said );
		$this->assertStringNotContainsString( 'query UPDATE', $said ); // (Action Scheduler asked, never its table)
		$this->assertStringContainsString( 'query DELETE FROM wp_options WHERE option_name LIKE ordryx\_lock\_% OR option_name LIKE ordryx\_shipping\_%', $said ); // (every lock left)
		$this->assertStringEndsWith( 'done', $said );
	}

	public function test_deleting_the_plugin_sends_whats_waiting_first() {
		// (deleting the plugin dropped
		// erasures still queued; now they're sent once more, then lost)
		$said = $this->uninstall( 'waiting' );
		$lines = explode( "\n", $said );
		$forget = array_search( 'posted https://account.ordryx.com/api/woocommerce/v1/plugin/forget for st_4f9a from https://shop.example.com orders [5,6] within 12', $lines, true );
		$gone   = array_search( 'posted https://account.ordryx.com/api/woocommerce/v1/plugin/gone for st_4f9a from https://shop.example.com', $lines, true );
		$this->assertNotFalse( $forget, $said );
		$this->assertNotFalse( $gone, $said );
		$this->assertLessThan( $gone, $forget );
		$this->assertLessThan( array_search( 'option ordryx_forget', $lines, true ), $forget ); // (sent, then deleted)
		$this->assertStringEndsWith( 'done', $said );
	}

	public function test_deleting_while_ordryx_is_offline_still_says_gone() {
		$said = $this->uninstall( 'offline' );
		$this->assertSame( 1, substr_count( $said, '/plugin/forget' ) ); // (tried once, not again)
		$this->assertStringContainsString( '/plugin/gone', $said );
		$this->assertStringContainsString( 'option ordryx_forget', $said );
		$this->assertStringEndsWith( 'done', $said );
	}

	public function test_uninstall_on_a_copy_of_the_site_tells_nobody() {
		// (deleting the plugin on a staging
		// copy disconnected the live store)
		$said = $this->uninstall( 'copy' );
		$this->assertStringNotContainsString( 'posted', $said ); // (its erasures waiting too: never from a copy)
		$this->assertStringContainsString( 'option ordryx_state', $said );
		$this->assertStringContainsString( 'option ordryx_secret', $said );
		$this->assertStringEndsWith( 'done', $said );
	}

	public function test_uninstall_on_a_network_clears_every_site() {
		// (WordPress runs uninstall.php once, as the main
		// site, and a store connected on another site kept its record, its
		// secret, Ordryx's key in its own tables, and its Ordryx reading on)
		$said = $this->uninstall( 'network' );
		$this->assertStringContainsString( 'site 1: sites {"fields":"ids","number":0}', $said );
		$this->assertStringContainsString( 'site 3: deleted key 7 from wp_3_woocommerce_api_keys', $said );
		$this->assertStringContainsString( 'site 3: posted https://account.ordryx.com/api/woocommerce/v1/plugin/gone for st_3 from https://three.example.com', $said );
		foreach ( array( 1, 3 ) as $site ) {
			foreach ( array( 'ordryx_state', 'ordryx_secret', 'ordryx_keys', 'ordryx_shipments', 'ordryx_forget' ) as $option ) {
				$this->assertStringContainsString( "site {$site}: option {$option}", $said );
			}
			$this->assertStringContainsString( "site {$site}: transient ordryx_look", $said );
		}
		$this->assertStringNotContainsString( 'site 1: posted', $said );
		$this->assertStringNotContainsString( 'site 1: deleted', $said );
		// (without WooCommerce on the main site, Action
		// Scheduler isn't loaded, and site 3's daily check stayed pending, to
		// fail there with no plugin to run it)
		$this->assertStringContainsString( "site 3: query UPDATE wp_3_actionscheduler_actions SET status = 'canceled' WHERE status = 'pending' AND hook IN (ordryx_daily_check, ordryx_forget, ordryx_nudge)", $said );
		$this->assertStringNotContainsString( 'site 1: query UPDATE', $said ); // (no Action Scheduler table there)
		$this->assertStringContainsString( 'site 3: query DELETE FROM wp_3_options', $said );
		$this->assertStringNotContainsString( 'unscheduled', $said );
		$this->assertSame( 1, substr_count( $said, 'posted' ) );
		$this->assertStringEndsWith( 'done', $said );
	}
}
