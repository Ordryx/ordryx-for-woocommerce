<?php
/**
 * The connected store's dashboard: what flows, drawn from the
 * Ordryx's look; Administrators change it, Shop Managers see it and sync.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

use Brain\Monkey\Functions;

class DashboardTest extends Ordryx_Test_Case {

	const PAGE = '/api/woocommerce/v1/plugin/page';

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
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->connected();
	}

	private function look( array $more = array() ) {
		return array_merge(
			array(
				'paused'         => false,
				'paused_since'   => null,
				'channels'       => array(
					array( 'key' => 'online', 'on' => true ),
					array( 'key' => 'pos', 'on' => true ),
					array( 'key' => 'admin', 'on' => true ),
					array( 'key' => 'other', 'on' => false ),
				),
				'shipped'        => array( 'completed' ),
				'sync_every'     => 15,
				'next_sync'      => '2026-10-23T18:15:00+00:00',
				'last_sync'      => array(
					'at'             => '2026-10-23T18:00:00+00:00',
					'status'         => 'success',
					'automatic'      => true,
					'new_orders'     => 2,
					'shipped_orders' => 1,
				),
				'running'        => false,
				'first_sync_due' => false,
				'recent'         => array(
					array( 'at' => '2026-10-23T18:00:00+00:00', 'kind' => 'synced', 'text' => 'Synced WooCommerce: 2 new, 1 shipped' ),
				),
			),
			$more
		);
	}

	private function will_look( array $more = array() ) {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'detail' => '', 'data' => $this->look( $more ) ) );
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

	public function test_an_administrator_sees_what_flows_and_may_change_it() {
		$this->will_look();
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		list( $call ) = $this->account->to( self::PAGE );
		$this->assertSame( array( 'look', '', true ), array( $call['body']['ask'], $call['body']['by'], $call['body']['admin'] ) );
		$this->assertStringContainsString( 'What flows', $html );
		$this->assertStringContainsString( 'aria-pressed="true">Online checkout', $html );
		$this->assertStringContainsString( 'aria-pressed="false">Other apps', $html );
		$this->assertStringContainsString( 'name="channel" value="other"><input type="hidden" name="on" value="1">', $html );
		$this->assertStringContainsString( 'value="pause"', $html );
		$this->assertStringContainsString( 'value="sync"', $html );
		$this->assertStringContainsString( 'marked Completed.', $html );
		$this->assertStringContainsString( 'Synced WooCommerce: 2 new, 1 shipped', $html );
		$this->assertStringContainsString( 'last sync', $html );
		$this->assertStringContainsString( 'https://bench-works.ordryx.com/sales/stores/woocommerce', $html );
	}

	public function test_what_never_goes_says_the_ship_to_phone_is_kept() {
		// (only the billing phone never goes: the ship-to's goes on the label)
		$this->will_look();
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'Billing addresses and phones, and accounts', $html );
		$this->assertStringContainsString( 'waiting to ship (its name, address and phone)', $html );
		$this->assertStringContainsString( 'Once the store is disconnected, they go, at once for orders still waiting to ship', $html );
		$this->assertStringContainsString( 'Never the billing address or billing phone', $html );
		$this->assertStringNotContainsString( 'phone number', $html );
	}

	public function test_a_shop_manager_sees_it_all_and_may_only_sync() {
		$this->admin = false;
		$this->will_look();
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		list( $call ) = $this->account->to( self::PAGE );
		$this->assertFalse( $call['body']['admin'] );
		$this->assertStringContainsString( 'Only Administrators can change what flows.', $html );
		$this->assertStringContainsString( 'value="sync"', $html );
		foreach ( array( 'pause', 'resume', 'flows', 'disconnect' ) as $step ) {
			$this->assertStringNotContainsString( 'name="do" value="' . $step . '"', $html, $step );
		}
		$this->assertStringContainsString( 'aria-disabled="true" data-on="0">Other apps', $html );
	}

	public function test_the_look_is_kept_half_a_minute_and_asked_afresh_after_a_change() {
		$this->will_look();
		$this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertCount( 1, $this->account->to( self::PAGE ) );
		try {
			$this->act( array( 'do' => 'sync' ) );
		} catch ( Exception $e ) {
			// (redirected)
		}
		$this->will_look( array( 'running' => true ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertCount( 3, $this->account->to( self::PAGE ) ); // (look, sync, look)
		$this->assertStringContainsString( 'syncing now', $html );
	}

	public function test_an_ordryx_that_doesnt_answer_shows_no_flows_to_change() {
		$this->account->will( self::PAGE, 504, array( 'error' => 'unlinked' ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'didn&#039;t answer just now', $html );
		$this->assertStringContainsString( 'another status picked in Ordryx', $html );
		$this->assertStringNotContainsString( 'value="sync"', $html );
		$this->assertStringNotContainsString( 'name="channel"', $html );
		$this->assertStringContainsString( 'value="pause"', $html ); // (Pause asks the Ordryx itself)
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_an_ordryx_that_let_the_store_go_is_believed_through_the_check() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => array( 'here' => false ) ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'disconnected' ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertStringContainsString( 'Enter the code from your Ordryx', $html );
		$this->assertStringNotContainsString( 'What flows', $html );
	}

	public function test_the_first_sync_is_ordryxs_and_no_button_offers_it() {
		$this->will_look( array( 'first_sync_due' => true, 'last_sync' => null, 'next_sync' => null ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringNotContainsString( 'value="sync"', $html );
		$this->assertStringContainsString( 'the first sync is run in Ordryx', $html );
	}

	public function test_whatever_comes_back_is_drawn_escaped_and_only_its_own_fields() {
		$this->will_look(
			array(
				'recent'   => array(
					array( 'at' => 'x', 'kind' => 'synced', 'text' => '<img src=x onerror=alert(1)>Synced' ),
					array( 'at' => '2026-10-23T18:00:00+00:00', 'kind' => '<b>', 'text' => 'odd kind' ),
				),
				'shipped'  => array( 'completed', '"><script>', 7 ),
				'channels' => array( array( 'key' => 'evil', 'on' => true ), array( 'key' => 'pos', 'on' => 'yes' ) ),
			)
		);
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'odd kind', $html );
		$this->assertStringNotContainsString( 'evil', $html );
		$this->assertStringNotContainsString( 'Point of Sale</button>', $html );
		$look = Ordryx_Dashboard::look();
		$this->assertSame( array( 'completed' ), $look['shipped'] );
		$this->assertSame( 0, $look['recent'][0]['at'] );
	}

	public function test_a_shop_manager_may_sync_and_nothing_else() {
		$this->admin = false;
		$this->account->will( self::PAGE, 200, array( 'ok' => true ) );
		$this->assertStringEndsWith( '&ordryx=synced', $this->act( array( 'do' => 'sync' ) ) );
		list( $call ) = $this->account->to( self::PAGE );
		$this->assertSame( array( 'sync', 'Avery Admin', false ), array( $call['body']['ask'], $call['body']['by'], $call['body']['admin'] ) );
		foreach ( array( 'pause', 'flows', 'resume', 'disconnect' ) as $step ) {
			$this->assertStringStartsWith( 'died:', $this->act( array( 'do' => $step, 'channel' => 'pos', 'on' => '0' ) ), $step );
		}
		$this->assertCount( 1, $this->account->to( self::PAGE ) );
	}

	public function test_a_channel_switch_sends_only_that_channel() {
		$this->assertStringEndsWith( '&ordryx=flows', $this->act( array( 'do' => 'flows', 'channel' => 'pos', 'on' => '0' ) ) );
		list( $call ) = $this->account->to( self::PAGE );
		$this->assertSame( array( 'pos' => false ), $call['body']['channels'] );
		$this->assertStringEndsWith( '&ordryx=failed', $this->act( array( 'do' => 'flows', 'channel' => 'carrier', 'on' => '1' ) ) );
		$this->assertStringEndsWith( '&ordryx=failed', $this->act( array( 'do' => 'flows', 'channel' => 'pos', 'on' => 'yes' ) ) );
		$this->assertCount( 1, $this->account->to( self::PAGE ) );
	}

	public function test_pause_pauses_here_once_ordryx_says_so() {
		$this->account->will( self::PAGE, 200, array( 'ok' => false, 'why' => 'clock' ) );
		$this->assertStringEndsWith( '&ordryx=clock', $this->act( array( 'do' => 'pause' ) ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertStringEndsWith( '&ordryx=paused_now', $this->act( array( 'do' => 'pause' ) ) );
		$this->assertSame( 'paused', Ordryx_State::phase() );
		$this->assertSame( 'refused', Ordryx_Connection::ask( 'pause' ) ); // (paused already)
		$this->assertSame( 'paused', Ordryx_Connection::ask( 'sync' ) );
		$this->assertStringEndsWith( '&ordryx=sync_paused', $this->act( array( 'do' => 'sync' ) ) );
	}

	public function test_a_refusal_is_said_in_the_plugins_own_words() {
		foreach ( array( 'first_sync' => 'first_sync', 'running' => 'running', 'paused' => 'sync_paused', 'nonsense' => 'refused' ) as $why => $result ) {
			$this->account->will( self::PAGE, 200, array( 'ok' => false, 'why' => $why, 'detail' => '<b>far end</b>' ) );
			$this->assertStringEndsWith( '&ordryx=' . $result, $this->act( array( 'do' => 'sync' ) ), $why );
		}
		$_GET['ordryx'] = 'first_sync';
		$this->will_look();
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'first sync is run in Ordryx, once its starting stock is set', $html );
		$this->assertStringNotContainsString( 'far end', $html );
	}

	private function tab( array $more = array() ) {
		Ordryx_Dashboard::forget();
		$this->will_look( $more );
		return str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
	}

	public function test_automatic_sync_off_is_said_on_the_tab() {
		// (the plugin promised a sync
		// within a minute; Sync orders automatically starts Off in Ordryx, and
		// its order notices change nothing until it's on)
		$html = $this->tab( array( 'sync_every' => 0, 'next_sync' => null ) );
		$this->assertStringContainsString( 'automatic sync off', $html );
		$this->assertStringContainsString( '<b class="is-bad">Off</b> Sync orders automatically is off in your Ordryx, so order changes wait until someone presses Sync now', $html );
		$this->assertStringContainsString( 'Settings › WooCommerce', $html );
		Ordryx_State::update( array( 'failing_since' => 1792700000 ) );
		$html = $this->tab( array( 'sync_every' => 0, 'next_sync' => null ) );
		$this->assertStringContainsString( 'Meanwhile orders come in when someone presses Sync now; nothing is lost.', $html );
		$this->assertStringNotContainsString( 'every 15 minutes', $html );
	}

	public function test_the_late_line_names_the_ordryxs_own_interval() {
		Ordryx_State::update( array( 'failing_since' => 1792700000 ) );
		foreach ( array( 15 => 'every 15 minutes', 240 => 'every 4 hours', 1440 => 'once a day', 45 => 'every 45 minutes' ) as $every => $words ) {
			$html = $this->tab( array( 'sync_every' => $every ) );
			$this->assertStringContainsString( "Your Ordryx still syncs {$words} meanwhile; nothing is lost.", $html );
		}
		Ordryx_Dashboard::forget();
		$this->account->will( self::PAGE, 504, array( 'error' => 'unlinked' ) );
		$html = str_replace( '&#039;', "'", $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertStringContainsString( 'Meanwhile your Ordryx still reads the store when it syncs; nothing is lost.', $html );
	}

	public function test_the_late_line_never_promises_a_sync_the_ordryx_wont_run() {
		// (the timer doesn't sync before the store's first sync,
		// nor while it's paused; a failing_since left from before the pause)
		Ordryx_State::update( array( 'failing_since' => 1792700000 ) );
		$html = $this->tab( array( 'first_sync_due' => true ) );
		$this->assertStringContainsString( 'The plugin keeps trying. Orders come in once the store\'s first sync has run in Ordryx.', $html );
		$this->assertStringNotContainsString( 'still syncs every', $html );
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$html = $this->tab( array( 'paused' => true ) );
		$this->assertStringContainsString( 'The plugin keeps trying. Your Ordryx is paused, so nothing is read until you resume.', $html );
		$this->assertStringNotContainsString( 'still syncs every', $html );
		$this->assertStringNotContainsString( 'nothing is lost', $html );
	}

	public function test_the_notices_line_says_what_the_ordryx_does_with_them() {
		$html = $this->tab();
		$this->assertStringContainsString( 'and your Ordryx usually reads the change within a couple of minutes', $html );
		$this->assertStringNotContainsString( 'automatic sync off', $html );
		$html = $this->tab( array( 'first_sync_due' => true ) );
		$this->assertStringContainsString( 'Your Ordryx acts on them once the store\'s first sync has run there.', $html );
		$this->assertStringNotContainsString( 'within a couple of minutes', $html );
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$html = $this->tab( array( 'paused' => true ) );
		$this->assertStringContainsString( 'your Ordryx is paused, so nothing is read until you resume', $html );
		$this->assertStringNotContainsString( 'within a couple of minutes', $html );
	}

	public function test_a_new_store_with_automatic_sync_off_is_said_so_before_its_first_sync() {
		// (a new store's usual state, its first sync due and
		// Sync orders automatically Off, was told its notices would be acted
		// on once the first sync had run)
		$html = $this->tab( array( 'first_sync_due' => true, 'sync_every' => 0, 'next_sync' => null ) );
		$this->assertStringNotContainsString( 'Your Ordryx acts on them once the store\'s first sync has run there.', $html );
		$this->assertStringContainsString( '<b class="is-bad">Off</b> Order notices on, but your Ordryx acts on them only once the store\'s first sync has run there and Sync orders automatically is on (it\'s off now: Settings › WooCommerce in Ordryx).', $html );
		Ordryx_State::update( array( 'failing_since' => 1792700000 ) );
		$html = $this->tab( array( 'first_sync_due' => true, 'sync_every' => 0, 'next_sync' => null ) );
		$this->assertStringContainsString( 'The plugin keeps trying. Orders come in once the store\'s first sync has run in Ordryx, then when someone presses Sync now, as Sync orders automatically is off there.', $html );
	}

	public function test_paused_offers_resume_not_pause() {
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$this->will_look( array( 'paused' => true, 'paused_since' => '2026-10-23T18:00:00+00:00' ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'value="resume"', $html );
		$this->assertStringNotContainsString( 'value="pause"', $html );
		$this->assertStringNotContainsString( 'value="sync"', $html );
		$this->assertStringContainsString( 'paused since', $html );
	}
}
