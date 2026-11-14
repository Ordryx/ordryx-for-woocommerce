<?php
/**
 * The settings tab: who may do what, fixed sentences, and nothing from the far
 * end drawn unescaped.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

use Brain\Monkey\Functions;


class AdminTest extends Ordryx_Test_Case {

	/** @var array What the nonce check was asked. */
	private $nonce = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) {
				throw new Redirected( $url );
			}
		);
		Functions\when( 'wp_die' )->alias(
			function ( $message ) {
				throw new Redirected( 'died: ' . $message );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action, $name ) {
				$this->nonce = array( $action, $name );
				return 1;
			}
		);
	}

	private function act( array $post ) {
		$_POST = $post;
		try {
			Ordryx_Admin::act();
		} catch ( Redirected $e ) {
			return $e->getMessage();
		}
		$this->fail( 'act() should end in a redirect' );
	}

	public function test_every_result_has_its_own_sentence() {
		$fallback = Ordryx_Admin::sentence( 'no-such-result' );
		$seen     = array();
		foreach ( array_diff( Ordryx_Admin::RESULTS, array( 'failed' ) ) as $result ) { // (failed: the fallback itself)
			$text = Ordryx_Admin::sentence( $result );
			$this->assertNotSame( $fallback, $text, $result );
			$this->assertNotContains( $text, $seen, $result );
			$seen[] = $text;
		}
	}

	public function test_what_ordryx_reads_says_only_the_billing_phone_never_goes() {
		// (the ship-to's phone is kept for the label)
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'the ship-to name, address and phone', $html );
		$this->assertStringContainsString( 'Never the billing address or billing phone, and never customer accounts.', $html );
		$this->assertStringContainsString( 'Ordryx never asks for them.', $html );
		// (a disconnect keeps the orders, not their customers: Ordryx 0.0.210)
		$this->assertStringContainsString( 'at once for orders still waiting to ship, 29 days later for the rest', $html );
		$this->assertStringNotContainsString( 'phone numbers', $html );
		// (what the customer typed for a line is kept too, and goes with them)
		$this->assertStringContainsString( 'What the customer typed or chose for each line', $html );
		$this->assertStringContainsString( 'and what they typed for a line go', $html );
	}

	public function test_only_an_administrator_changes_the_connection() {
		$this->admin = false;
		$this->assertStringStartsWith( 'died:', $this->act( array( 'do' => 'pair', 'code' => 'R7WT4HQN' ) ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_a_form_is_checked_against_its_own_nonce() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		$to = $this->act( array( 'do' => 'pair', 'code' => 'r7wt-4hqn', 'terms' => '1' ) );
		$this->assertSame( array( 'ordryx-tab', 'ordryx_nonce' ), $this->nonce );
		$this->assertSame( 'https://shop.example.com/wp-admin/admin.php?page=wc-settings&tab=ordryx', $to );
		$this->assertSame( 'pairing', Ordryx_State::phase() );
	}

	public function test_a_bad_code_says_so_without_asking_anyone() {
		$to = $this->act( array( 'do' => 'pair', 'code' => 'R7WT' ) );
		$this->assertStringEndsWith( '&ordryx=format', $to );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_the_code_card_asks_for_the_terms_tick_with_ordryxs_own_links() {
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'value="pair"', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="terms" value="1" required>', $html );
		$this->assertStringContainsString( 'I agree to Ordryx&#039;s <a href="https://ordryx.com/terms"', $html );
		$this->assertStringContainsString( '>terms of service</a> and <a href="https://ordryx.com/privacy"', $html );
		$this->assertStringContainsString( '>privacy policy</a>', $html );
	}

	public function test_continue_without_the_terms_ticked_asks_no_one() {
		foreach ( array( array(), array( 'terms' => '' ), array( 'terms' => '0' ) ) as $unticked ) {
			$to = $this->act( array_merge( array( 'do' => 'pair', 'code' => 'R7WT-4HQN' ), $unticked ) );
			$this->assertStringEndsWith( '&ordryx=agree', $to );
		}
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_continue_with_the_terms_ticked_sends_them() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		$this->act( array( 'do' => 'pair', 'code' => 'R7WT-4HQN', 'terms' => '1' ) );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/pair' );
		$this->assertTrue( $call['body']['terms'] );
		$this->assertSame( 'pairing', Ordryx_State::phase() );
	}

	public function test_the_accounts_terms_refusal_gets_its_own_sentence() {
		$this->account->will( '/api/woocommerce/v1/pair', 422, array( 'error' => 'terms', 'detail' => 'whatever' ) );
		$to = $this->act( array( 'do' => 'pair', 'code' => 'R7WT-4HQN', 'terms' => '1' ) );
		$this->assertStringEndsWith( '&ordryx=terms', $to );
		$this->assertStringContainsString( 'agree to its terms first', Ordryx_Admin::sentence( 'terms' ) );
		$this->assertStringContainsString( 'Tick the box to agree', Ordryx_Admin::sentence( 'agree' ) );
	}

	public function test_move_sends_the_terms_ticked_with_its_code() {
		$this->account->will( '/api/woocommerce/v1/pair', 409, array( 'error' => 'elsewhere' ) );
		$this->act( array( 'do' => 'pair', 'code' => 'R7WT-4HQN', 'terms' => '1' ) );
		$this->assertSame( 1, Ordryx_State::get()['pending_terms'] ); // (who ticked them)
		// the same person: no second tick box on the Move form
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'value="move"', $html );
		$this->assertStringNotContainsString( 'name="terms"', $html );
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		$this->act( array( 'do' => 'move' ) );
		$calls = $this->account->to( '/api/woocommerce/v1/pair' );
		$this->assertTrue( $calls[1]['body']['move'] );
		$this->assertTrue( $calls[1]['body']['terms'] );
		$this->assertSame( 0, Ordryx_State::get()['pending_terms'] );
	}

	public function test_move_for_a_code_kept_by_0_5_3_asks_for_the_tick_on_the_move_form() {
		// (a code kept before the tick box: no pending_terms at all)
		Ordryx_State::update( array( 'other_ordryx' => 'Other', 'pending_code' => 'R7WT-4HQN', 'pending_until' => time() + 60 ) );
		$state = get_option( Ordryx_State::OPTION );
		unset( $state['pending_terms'] );
		update_option( Ordryx_State::OPTION, $state );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'value="move"', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="terms" value="1" required>', $html );
		$this->assertStringEndsWith( '&ordryx=move_agree', $this->act( array( 'do' => 'move' ) ) );
		$this->assertSame( array(), $this->account->calls );
		$this->assertStringContainsString( 'then press Move it here', Ordryx_Admin::sentence( 'move_agree' ) );
		$this->assertStringNotContainsString( 'Continue', Ordryx_Admin::sentence( 'move_agree' ) );
		// ticked there: it's sent
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		$this->act( array( 'do' => 'move', 'terms' => '1' ) );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/pair' );
		$this->assertTrue( $call['body']['move'] );
		$this->assertTrue( $call['body']['terms'] );
	}

	public function test_move_by_another_administrator_needs_their_own_tick() {
		// (pending_terms is the whole site's: who ticked it is kept with it)
		$this->account->will( '/api/woocommerce/v1/pair', 409, array( 'error' => 'elsewhere' ) );
		$this->act( array( 'do' => 'pair', 'code' => 'R7WT-4HQN', 'terms' => '1' ) );
		$this->user_id = 2;
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( '<input type="checkbox" name="terms" value="1" required>', $html );
		$this->assertStringEndsWith( '&ordryx=move_agree', $this->act( array( 'do' => 'move' ) ) );
		$this->assertCount( 1, $this->account->to( '/api/woocommerce/v1/pair' ) ); // (Move asked no one)
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		$this->act( array( 'do' => 'move', 'terms' => '1' ) );
		$calls = $this->account->to( '/api/woocommerce/v1/pair' );
		$this->assertCount( 2, $calls );
		$this->assertTrue( $calls[1]['body']['terms'] );
	}

	public function test_a_kept_true_from_an_unreleased_build_is_nobodys() {
		Ordryx_State::update( array( 'other_ordryx' => 'Other', 'pending_code' => 'R7WT-4HQN', 'pending_until' => time() + 60, 'pending_terms' => true ) );
		$this->assertStringEndsWith( '&ordryx=move_agree', $this->act( array( 'do' => 'move' ) ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_move_uses_only_the_code_kept_for_it_and_only_while_it_lasts() {
		Ordryx_State::update( array( 'other_ordryx' => 'Other', 'pending_code' => 'R7WT-4HQN', 'pending_until' => time() - 1 ) );
		$this->assertStringEndsWith( '&ordryx=code', $this->act( array( 'do' => 'move', 'code' => 'ZZZZ-ZZZZ' ) ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_elsewhere_offers_move_without_naming_the_other_ordryx() {
		// (the account names no other Ordryx: the site isn't proven until a key works)
		$this->account->will( '/api/woocommerce/v1/pair', 409, array( 'error' => 'elsewhere' ) );
		$this->assertSame( 'elsewhere', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'Already connected elsewhere', $html );
		$this->assertStringContainsString( 'value="move"', $html );
	}

	public function test_the_ordryxs_name_is_drawn_escaped() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$state                   = Ordryx_State::get();
		$state['ordryx']['name'] = '<img src=x onerror=alert(1)>'; // as if kept unchecked
		$this->options[ Ordryx_State::OPTION ] = $state;
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img', $html );
		$this->assertStringContainsString( 'wc-auth-route=authorize', $html );
	}

	public function test_a_result_in_the_address_shows_only_its_fixed_sentence() {
		$_GET['ordryx'] = '<script>';
		$html           = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringNotContainsString( 'script', $html );
		$_GET['ordryx'] = 'code';
		$html           = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'That code has run out', $html );
	}

	public function test_a_shop_manager_sees_where_it_stands_without_the_forms() {
		$this->connected();
		$this->admin = false;
		$html        = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
	}

	public function test_a_store_the_account_doesnt_know_offers_to_delete_its_key() {
		// (the tab said "nothing flows" while the key
		// was still there, and offered no way to delete it)
		$this->connected();
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 404, array( 'error' => 'store' ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( 'unknown', Ordryx_State::phase() );
		$this->assertStringContainsString( 'nothing this plugin sends reaches an Ordryx', $html );
		$this->assertStringNotContainsString( 'nothing flows', $html );
		$this->assertStringContainsString( 'Ordryx&#039;s key is still in this store', $html );
		$this->assertStringContainsString( 'value="old_keys"', $html );
		$this->admin = false; // (a Shop Manager sees where it stands, never the button)
		$html        = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringNotContainsString( 'value="old_keys"', $html );
		$this->admin = true;
		$this->assertStringEndsWith( '&ordryx=old_keys', $this->act( array( 'do' => 'old_keys' ) ) );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringNotContainsString( 'value="old_keys"', $html );
		$this->assertStringContainsString( 'Enter the code from your Ordryx', $html );
	}

	public function test_a_deleted_key_is_said_on_the_tab() {
		$this->connected();
		unset( $this->wpdb->keys[7] );
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( 'key was deleted', $html );
		// (pair() refuses a connected store: Disconnect first, as the line says)
		$this->assertStringContainsString( 'until you Disconnect below, then connect again with a new code from Ordryx', $html );
		$this->assertStringContainsString( 'orders shipped from Ordryx meanwhile are marked shipped here then', $html );
	}

	public function test_order_notices_are_said_held_while_the_key_cant_read() {
		// (Ordryx_Nudge sends none then, yet the card said
		// notices were on, or "still syncs … nothing is lost")
		$this->connected();
		Ordryx_State::update( array( 'failing_since' => 1792700000 ) );
		unset( $this->wpdb->keys[7] );
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( '<b class="is-bad">Off</b> Order notices are held: this plugin sends none until Ordryx\'s key is back', $html );
		$this->assertStringNotContainsString( 'Order notices on', $html );
		$this->assertStringNotContainsString( 'nothing is lost', $html );
		$this->assertStringNotContainsString( 'The plugin keeps trying', $html );
	}

	public function test_order_notices_are_said_held_while_the_key_is_set_to_write() {
		$this->connected();
		$this->wpdb->keys[7]['permissions'] = 'write';
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( '<b class="is-bad">Off</b> Order notices are held: this plugin sends none until Ordryx\'s key is set back to Read.', $html );
		$this->assertStringNotContainsString( 'Order notices on', $html );
		$this->wpdb->keys[7]['permissions'] = 'read';
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringNotContainsString( 'Order notices are held', $html );
	}

	public function test_the_deleted_key_line_follows_the_routes_rule() {
		// (the screen and the shipped route agree: a key renamed or given
		// Read/Write is still there, and said to be changed instead)
		$this->connected();
		$this->wpdb->keys[7]['description'] = 'Our order reader';
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringNotContainsString( 'key was deleted', $html );
		$this->assertStringContainsString( 'key was changed in WooCommerce', $html );
		$this->wpdb->keys[7]['description'] = 'Ordryx - API (2026-10-22 18:00:00)';
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringNotContainsString( 'key was changed', $html );
		$this->assertStringContainsString( 'Read-only key, allowed by Avery Admin', $html );
	}

	public function test_a_write_key_is_said_on_the_tab_as_one_that_cant_read() {
		// ("Orders still flow" was said of a Write key, whose
		// reads WooCommerce refuses)
		$this->connected();
		$this->wpdb->keys[7]['permissions'] = 'write';
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( 'key was set to Write', $html );
		$this->assertStringContainsString( 'Set it back to Read there', $html );
		$this->assertStringNotContainsString( 'Orders still flow', $html );
		$this->assertStringNotContainsString( 'key was deleted', $html );
		$this->wpdb->keys[7]['permissions'] = 'read';
		$this->wpdb->keys[7]['user_id']     = 2; // (given to another person: as it was)
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( 'Read-only key, allowed by Avery Admin', $html );
	}

	public function test_the_blocked_sentence_names_both_routes() {
		$text = Ordryx_Admin::sentence( 'blocked' );
		$this->assertStringContainsString( '?rest_route=/ordryx/v1/proof', $text );
		$this->assertStringContainsString( '?rest_route=/ordryx/v1/shipped', $text );
	}

	public function test_the_connect_card_promises_no_sync_time() {
		// ("your Ordryx syncs within a minute", though Sync orders
		// automatically starts Off there)
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'pairing' ) );
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( 'What happens when you press Connect', $html );
		$this->assertStringNotContainsString( 'within a minute', $html );
		$this->assertStringContainsString( 'Sync orders automatically is on there', $html );
	}

	public function test_the_connect_card_says_what_the_plugin_changes() {
		// (the journey: at the moment of consent it said "Ordryx can't change
		// anything in your store", though an order shipped from Ordryx is marked
		// Completed here and WooCommerce emails the customer; the readme said so)
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'pairing' ) );
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringNotContainsString( 'can\'t change anything in your store', $html );
		$this->assertStringContainsString( 'The key only reads: Ordryx can\'t change your store with it.', $html );
		$this->assertStringContainsString( 'it marks the order Completed here, with its tracking if it has one and a private note saying who shipped it', $html );
		$this->assertStringContainsString( 'WooCommerce then emails the customer', $html );
		$this->assertStringContainsString( 'Once the store is disconnected, it deletes Ordryx\'s key.', $html );
	}

	public function test_plugins_screen_says_erasures_are_waiting() {
		// (deleting the plugin dropped erasures still queued; it now sends
		// them once more, and says beforehand that what's left then is lost)
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		$this->options[ Ordryx_Erasure::QUEUE ] = array( 5, 6 );
		$this->assertSame( '', $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) ); // (no Ordryx to send to)
		$this->connected();
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) );
		$this->assertStringContainsString( 'Ordryx: customer data erased here (2 orders) hasn\'t reached your Ordryx yet. Deactivating the plugin stops sending it; deleting the plugin sends it once more, and whatever your Ordryx can\'t take then is lost.', $html );
		$this->admin = false;
		$this->assertSame( '', $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) );
		$this->admin           = true;
		$this->options['home'] = 'https://staging.shop.example.com'; // (a copy: its erasures never go from there)
		$this->assertStringNotContainsString( 'erased here', $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) );
	}

	public function test_the_networks_plugins_screen_says_erasures_wait_on_its_sites() {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins-network' ) );
		Functions\when( 'get_sites' )->justReturn( array( 1, 3 ) );
		$this->connected();
		$this->options[ Ordryx_Erasure::QUEUE ] = array( 9 );
		$main  = $this->options;
		$this->options = array( 'date_format' => 'F j, Y' ); // (site 1: nothing of the plugin's)
		$sites = array( 1 => $this->options, 3 => $main );
		$now   = 1;
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) use ( &$sites, &$now ) {
				$sites[ $now ] = $this->options;
				$now           = $id;
				$this->options = $sites[ $id ];
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () use ( &$sites, &$now ) {
				$sites[ $now ] = $this->options;
				$now           = 1;
				$this->options = $sites[1];
			}
		);
		$html = $this->printed( array( 'Ordryx_Admin', 'network_notice' ) );
		$this->assertStringContainsString( 'customer data erased here (1 order)', $html );
		$this->assertSame( '', $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) ); // (not the site's Plugins screen)
	}

	public function test_back_from_the_allow_screen_needs_the_tab_and_an_administrator() {
		$_GET = array( 'page' => 'wc-settings', 'tab' => 'ordryx', 'success' => '1', 'user_id' => 'pr_8c1d2e' );
		$this->admin = false;
		Ordryx_Admin::back_from_allow(); // nothing happens
		$this->assertSame( array(), $this->account->calls );
		$this->admin = true;
		try {
			Ordryx_Admin::back_from_allow();
			$this->fail( 'should redirect' );
		} catch ( Redirected $e ) {
			$this->assertStringEndsWith( '&ordryx=stale', $e->getMessage() ); // no pairing waiting
		}
	}

	// ── a copy of the site ──────────

	public function test_a_copy_shows_both_addresses_and_offers_forget_here() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com/<b>';
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'This looks like a copy of the store', $html );
		$this->assertStringContainsString( '<code>https://staging.shop.example.com/&lt;b&gt;</code>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'but it was connected to Ordryx at <code>https://shop.example.com</code>', $html );
		$this->assertStringContainsString( 'so nothing from here reaches the store&#039;s Ordryx', str_replace( "'", '&#039;', $html ) );
		$this->assertStringContainsString( 'Nothing to do: nothing from here acts for the store.', $html );
		$this->assertStringContainsString( 'Then type a code from that Ordryx.', $html ); // (at an address of its own, it can)
		$this->assertStringContainsString( 'push this copy back over the live site, leave the connection as it is', $html );
		$this->assertStringContainsString( 'value="forget_here"', $html );
		$this->assertStringContainsString( 'Forget the connection here', $html );
		$this->assertStringContainsString( 'Don&#039;t disconnect', str_replace( "'", '&#039;', $html ) );
		// the store itself, moved here: Ordryx connects it at this address
		$this->assertStringContainsString( 'press The store moved… and type <code>https://staging.shop.example.com/&lt;b&gt;</code>', $html );
		$this->assertStringContainsString( 'it carries on at this address and lets <code>https://shop.example.com</code> go', $html );
		foreach ( array( 'value="pause"', 'value="resume"', 'value="sync"', 'value="flows"', 'value="disconnect"', 'value="allow"', 'value="pair"' ) as $form ) {
			$this->assertStringNotContainsString( $form, $html, $form );
		}
		$this->assertSame( array(), $this->account->calls ); // (checked within the minute: nothing asked)
	}

	public function test_the_tab_on_a_copy_asks_once_a_minute_and_shows_the_card() {
		$this->connected();
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$this->options['home'] = 'https://staging.shop.example.com';
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'This looks like a copy of the store', $html );
		$this->assertCount( 1, $this->account->to( '/api/woocommerce/v1/plugin/check' ) ); // (refused there: no look)
		$this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertCount( 1, $this->account->calls );
	}

	public function test_a_copy_says_what_erasures_wait_there() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4212 ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'Customer data erased on this site (2 orders) is waiting here', $html );
	}

	public function test_a_copy_by_its_environment_says_so() {
		$this->connected();
		$this->environment = 'staging';
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$html = str_replace( "'", '&#039;', $html );
		$this->assertStringContainsString( 'WordPress now says it&#039;s a <code>staging</code> site', $html );
		$this->assertStringContainsString( 'so nothing is sent to Ordryx from here', $html );
		// at the store's own address, a copy can't connect an Ordryx of its own:
		// a pairing would name the store's address, its proof asked of the store
		$this->assertStringContainsString( 'can&#039;t connect to an Ordryx of its own until it has an address of its own', $html );
		$this->assertStringNotContainsString( 'type a code from that Ordryx', $html );
		$this->assertStringContainsString( 'only stops this site holding the store&#039;s connection: nothing is sent to Ordryx, and the live store stays connected.', $html );
		$this->assertStringContainsString( 'If this is the live store', $html );
		$this->assertStringNotContainsString( 'The store itself moved here', $html );
		$this->assertStringContainsString( 'Set WordPress&#039;s environment type (WP_ENVIRONMENT_TYPE) to production.', $html );
		$this->assertStringContainsString( 'If it runs as <code>staging</code> on purpose, forget the connection here, then connect it again', $html );
	}

	public function test_forget_here_at_the_stores_own_address_says_only_the_store_can_connect_there() {
		$this->connected();
		$this->environment = 'staging';
		$this->assertStringEndsWith( '&ordryx=forgot_same', $this->act( array( 'do' => 'forget_here' ) ) );
		$this->assertSame( '', Ordryx_State::phase() );
		$_GET['ordryx'] = 'forgot_same';
		$html = str_replace( "'", '&#039;', $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'a copy needs an address of its own before it connects to an Ordryx. If this is the store itself, type a code from its Ordryx.', $html );
	}

	public function test_the_tab_on_a_copy_by_its_environment_asks_nothing() {
		// (at the store's own address: the account would answer it as the store's)
		$this->connected();
		$this->environment = 'staging';
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$this->assertStringContainsString( 'This looks like a copy of the store', $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		Ordryx_State::update( array( 'phase' => 'pairing' ) );
		$this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_a_shop_manager_on_a_copy_sees_it_without_the_button() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->admin = false;
		$html        = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'This looks like a copy of the store', $html );
		$this->assertStringContainsString( 'Only an Administrator can forget the connection here.', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
	}

	public function test_forget_here_needs_an_administrator_and_its_nonce() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->admin = false;
		$this->assertStringStartsWith( 'died:', $this->act( array( 'do' => 'forget_here' ) ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->admin = true;
		$this->assertStringEndsWith( '&ordryx=forgot', $this->act( array( 'do' => 'forget_here' ) ) );
		$this->assertSame( array( 'ordryx-tab', 'ordryx_nonce' ), $this->nonce );
		$this->assertSame( '', Ordryx_State::phase() );
		$_GET['ordryx'] = 'forgot';
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'Forgotten on this site.', $html );
		$this->assertStringContainsString( 'Enter the code from your Ordryx', $html );
		$this->assertStringContainsString( 'An earlier Ordryx key', $html ); // (kept: deleting it is a choice)
	}

	public function test_forget_here_on_the_store_itself_does_nothing_and_says_so() {
		$this->connected();
		$this->assertStringEndsWith( '&ordryx=not_copy', $this->act( array( 'do' => 'forget_here' ) ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertSame( 'This site doesn\'t look like a copy of the store any more, so nothing was forgotten.', Ordryx_Admin::sentence( 'not_copy' ) );
	}

	public function test_forget_here_without_the_accounts_word_says_nothing_was_forgotten() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->account->will( '/api/woocommerce/v1/plugin/check', 401, array( 'error' => 'time' ) );
		$this->assertStringEndsWith( '&ordryx=forget_failed', $this->act( array( 'do' => 'forget_here' ) ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$_GET['ordryx'] = 'forget_failed';
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'so nothing was forgotten. Try again in a minute.', $html );
		$this->assertStringNotContainsString( 'make a new code', $html ); // (the copy's card has no code form)
	}

	public function test_allow_on_a_copy_says_copy() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->assertStringEndsWith( '&ordryx=copy', $this->act( array( 'do' => 'allow', 'key_ok' => '1' ) ) );
		$_GET = array( 'page' => 'wc-settings', 'tab' => 'ordryx', 'success' => '1', 'user_id' => 'pr_8c1d2e' );
		try {
			Ordryx_Admin::back_from_allow();
			$this->fail( 'should redirect' );
		} catch ( Redirected $e ) {
			$this->assertStringEndsWith( '&ordryx=copy', $e->getMessage() );
		}
	}

	public function test_the_plugins_screen_says_a_copy_is_a_copy() {
		$this->connected();
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		Functions\when( 'plugin_basename' )->justReturn( 'ordryx-for-woocommerce/ordryx-for-woocommerce.php' );
		$this->assertSame( '', $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) );
		$this->options['home'] = 'https://staging.shop.example.com';
		$html = $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) );
		$this->assertStringContainsString( 'Ordryx: this site looks like a copy of https://shop.example.com, so nothing from here reaches the store&#039;s Ordryx (order changes and erasures stay here).', str_replace( "'", '&#039;', $html ) );
		$this->assertStringContainsString( 'Open the Ordryx tab', $html );
		$this->admin = false;
		$this->assertSame( '', $this->printed( array( 'Ordryx_Admin', 'plugins_notice' ) ) );
	}
}
