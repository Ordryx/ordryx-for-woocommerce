<?php
/**
 * The dashboard's look across changes made elsewhere: an answer asked for
 * before a change is never kept after it, and the tab follows a pause made
 * in Ordryx.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

use Brain\Monkey\Functions;

class DashboardChangesTest extends Ordryx_Test_Case {

	const PAGE = '/api/woocommerce/v1/plugin/page';

	private function look( array $more = array() ) {
		return array_merge(
			array(
				'paused'         => false,
				'paused_since'   => null,
				'channels'       => array( array( 'key' => 'online', 'on' => true ) ),
				'shipped'        => array( 'completed' ),
				'sync_every'     => 15,
				'next_sync'      => null,
				'last_sync'      => null,
				'running'        => false,
				'first_sync_due' => false,
				'recent'         => array(),
			),
			$more
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->connected();
	}

	// A look whose answer lands after a Pause made from another tab.
	public function test_a_look_answered_across_a_pause_isnt_kept() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) ); // (the look, from before)
		$this->account->will( self::PAGE, 200, array( 'ok' => true ) ); // (the other tab's Pause)
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look( array( 'paused' => true ) ) ) );
		$nested = false;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$nested ) {
				$got  = $this->account->post( $url, $args );
				$body = json_decode( $args['body'], true );
				if ( ! $nested && isset( $body['ask'] ) && 'look' === $body['ask'] ) {
					$nested = true;
					// (another tab's Pause is done while this look's answer is on its way)
					$this->assertSame( 'done', Ordryx_Connection::ask( 'pause' ) );
				}
				return $got;
			}
		);
		Ordryx_Dashboard::look();
		$this->assertSame( 'paused', Ordryx_State::phase() );
		$look = Ordryx_Dashboard::look();
		$this->assertTrue( $look['paused'], 'the look kept is from before the Pause' );
	}

	// Same with a check (another request) that changes the phase mid-look.
	public function test_a_look_answered_across_a_check_that_changed_the_phase_isnt_kept() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'paused', 'key_id' => 7 ) );
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look( array( 'paused' => true ) ) ) );
		$nested = false;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$nested ) {
				$got  = $this->account->post( $url, $args );
				$body = json_decode( $args['body'], true );
				if ( ! $nested && isset( $body['ask'] ) && 'look' === $body['ask'] ) {
					$nested = true;
					$this->assertSame( 'paused', Ordryx_Connection::check() );
				}
				return $got;
			}
		);
		Ordryx_Dashboard::look();
		$look = Ordryx_Dashboard::look();
		$this->assertTrue( $look['paused'], 'the look kept is from before the check changed the phase' );
	}

	public function test_uninstall_leaves_no_look_behind() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) );
		Ordryx_Dashboard::look();
		$this->assertArrayHasKey( Ordryx_Dashboard::LOOK, $this->transients );
		Ordryx_Connection::uninstalled();
		$this->assertArrayNotHasKey( Ordryx_Dashboard::LOOK, $this->transients );
	}

	public function test_deactivation_forgets_the_look() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) );
		Ordryx_Dashboard::look();
		Ordryx_Connection::deactivated();
		$this->assertSame( 'paused', Ordryx_State::phase() );
		$this->assertArrayNotHasKey( Ordryx_Dashboard::LOOK, $this->transients );
	}

	public function test_a_look_answered_across_a_channel_switch_isnt_kept() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) ); // (online on)
		$this->account->will( self::PAGE, 200, array( 'ok' => true ) ); // (the other tab's switch: online off)
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look( array( 'channels' => array( array( 'key' => 'online', 'on' => false ) ) ) ) ) );
		$nested = false;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$nested ) {
				$got  = $this->account->post( $url, $args );
				$body = json_decode( $args['body'], true );
				if ( ! $nested && isset( $body['ask'] ) && 'look' === $body['ask'] ) {
					$nested = true;
					$this->assertSame( 'done', Ordryx_Connection::ask( 'flows', array( 'online' => false ) ) );
				}
				return $got;
			}
		);
		Ordryx_Dashboard::look();
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'aria-pressed="false">Online checkout', $html, 'shows Online checkout on after it was switched off' );
	}

	public function test_a_look_is_kept_half_a_minute() {
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) );
		Ordryx_Dashboard::look();
		$this->assertSame( 30, $this->transient_for[ Ordryx_Dashboard::LOOK ] );
	}

	public function test_a_pause_made_in_ordryx_shows_for_a_shop_manager_too() {
		$this->admin = false;
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look( array( 'paused' => true ) ) ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( 'paused', Ordryx_State::phase() );
		$this->assertStringContainsString( 'is-paused', $html );
		$this->assertStringNotContainsString( 'value="sync"', $html );
		Ordryx_Dashboard::forget();
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => $this->look() ) );
		$this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_a_pairing_refused_as_paused_keeps_its_own_sentence() {
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) {
				throw new Redirected( $url );
			}
		);
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Ordryx_State::forget();
		$this->account->will( '/api/woocommerce/v1/pair', 400, array( 'error' => 'paused' ) );
		$_POST = array( 'do' => 'pair', 'code' => 'R7WT-4HQN', 'terms' => '1' );
		try {
			Ordryx_Admin::act();
			$this->fail( 'should redirect' );
		} catch ( Redirected $e ) {
			$this->assertStringEndsWith( '&ordryx=paused', $e->getMessage() );
		}
	}
}
