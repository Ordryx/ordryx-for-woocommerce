<?php
/**
 * Staying connected: signed calls, the daily check, Resume and Disconnect,
 * the plugin switched off and deleted.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class ConnectionTest extends Ordryx_Test_Case {

	const CHECK = '/api/woocommerce/v1/plugin/check';

	public function test_a_signed_call_names_the_store_and_signs_kind_time_and_body() {
		$this->connected();
		Ordryx_Account::call( 'nudge' );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/nudge' );
		$this->assertTrue( $call['signed'] );
		$this->assertSame( 'st_4f9a', $call['headers']['X-Ordryx-Store'] );
		$expect = base64_encode( hash_hmac( 'sha256', "nudge\n" . $call['headers']['X-Ordryx-Time'] . "\n" . $call['raw'], $this->account->secret, true ) );
		$this->assertSame( $expect, $call['headers']['X-Ordryx-Signature'] );
		// the same body signed for another kind is refused
		$this->assertNotSame( $expect, Ordryx_Account::sign( $this->account->secret, 'pause', $call['headers']['X-Ordryx-Time'], $call['raw'] ) );
	}

	public function test_no_two_calls_are_signed_alike() {
		$this->connected();
		Ordryx_Account::call( 'nudge' );
		Ordryx_Account::call( 'nudge' );
		$calls = $this->account->to( '/api/woocommerce/v1/plugin/nudge' );
		$this->assertNotSame( $calls[0]['body']['nonce'], $calls[1]['body']['nonce'] );
		$this->assertTrue( $calls[1]['signed'] );
	}

	public function test_without_a_secret_nothing_is_sent() {
		$answer = Ordryx_Account::call( 'check' );
		$this->assertSame( 0, $answer['status'] );
		$this->assertSame( array(), $this->account->calls );
		$this->connected();
		$this->assertSame( 0, Ordryx_Account::call( 'anything' )['status'] );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_the_accounts_address_can_be_changed_for_development_only() {
		$this->assertSame( 'https://account.ordryx.com', Ordryx_Account::base() );
		$this->assertSame( 'https://account.ordryx.com/api/woocommerce/v1/key', Ordryx_Account::key_url() );
	}

	public function test_disconnected_by_ordryx_deletes_its_key_here_and_forgets() {
		$this->connected();
		$this->scheduled['ordryx_daily_check'] = array( 0, 'ordryx', 86400 );
		$this->account->will( self::CHECK, 200, array( 'state' => 'disconnected' ) );
		$this->assertSame( 'disconnected', Ordryx_Connection::check() );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertSame( '', Ordryx_State::secret() );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_an_account_that_doesnt_know_the_store_never_deletes_its_key() {
		$this->connected();
		$this->account->will( self::CHECK, 404, array( 'error' => 'store' ) );
		$this->assertSame( 'unknown', Ordryx_Connection::check() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
		$this->assertSame( 'unknown', Ordryx_State::phase() );
		$this->assertNotSame( '', Ordryx_State::secret() );
	}

	public function test_an_account_that_doesnt_answer_changes_nothing() {
		$this->connected();
		$this->account->will( self::CHECK, 503 );
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->account->down = true;
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->account->down = false;
		$this->account->will( self::CHECK, 200, array( 'state' => 'nonsense' ) );
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_only_a_200_saying_disconnected_deletes_the_key() {
		$this->connected();
		$this->account->will( self::CHECK, 409, array( 'state' => 'disconnected' ) );
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->account->will( self::CHECK, 404, array( 'error' => 'not found' ) ); // (a proxy's)
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_store_marked_unknown_is_checked_again_when_the_tab_opens() {
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'unknown', 'checked_at' => 0 ) );
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		$this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_resumed_from_ordryx_forgets_when_the_plugin_was_off() {
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'paused', 'off_since' => 1792700000 ) );
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		Ordryx_Connection::check();
		$this->assertSame( 0, Ordryx_State::get()['off_since'] );
	}

	public function test_disconnect_says_so_when_there_was_no_key_to_delete() {
		$this->connected();
		unset( $this->wpdb->keys[7] );
		$this->assertSame( 'done_nokey', Ordryx_Connection::ask( 'disconnect' ) );
	}

	public function test_a_check_answered_after_a_disconnect_changes_nothing() {
		// (the answer came back onto a forgotten connection)
		$this->connected();
		\Brain\Monkey\Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$answer = $this->account->post( $url, $args );
				Ordryx_State::forget(); // Disconnect pressed meanwhile
				return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'state' => 'connected' ) ) );
			}
		);
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_a_record_with_no_store_is_set_straight() {
		$this->options[ Ordryx_State::OPTION ] = array( 'phase' => 'connected' );
		$this->assertSame( 'disconnected', Ordryx_Connection::check() );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_resume_only_while_paused_and_disconnect_only_once_connected() {
		$this->connected();
		$this->assertSame( 'refused', Ordryx_Connection::ask( 'resume' ) );
		Ordryx_State::update( array( 'phase' => 'pairing' ) );
		$this->assertSame( 'refused', Ordryx_Connection::ask( 'disconnect' ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_the_key_in_use_is_never_offered_as_an_earlier_one() {
		$this->connected();
		Ordryx_State::keep_keys( array( 7 ) );
		$this->assertSame( array(), Ordryx_Connection::earlier_keys() );
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$this->assertSame( array(), Ordryx_Connection::earlier_keys() );
		Ordryx_State::update( array( 'phase' => 'connected', 'key_id' => 0 ) ); // not yet known which
		$this->assertSame( array(), Ordryx_Connection::earlier_keys() );
		Ordryx_State::forget();
		$this->assertSame( array( 7 ), Ordryx_Connection::earlier_keys() );
		$this->assertSame( array( 7 ), Ordryx_Connection::delete_earlier_keys() );
		$this->assertSame( array(), Ordryx_State::keys() );
	}

	public function test_an_unknown_stores_own_key_is_offered_and_goes_only_when_asked() {
		// (the account
		// let the store's record go while the plugin was switched off, its
		// removed Ordryx still held the key, and the tab offered no way to
		// delete it)
		$this->connected();
		$this->account->will( self::CHECK, 404, array( 'error' => 'store' ) );
		$this->assertSame( 'unknown', Ordryx_Connection::check() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys ); // (never deleted by a check)
		$this->assertSame( array( 7 ), Ordryx_Connection::earlier_keys() );
		$this->assertTrue( Ordryx_Connection::own_key_left() );
		$this->assertSame( array( 7 ), Ordryx_Connection::delete_earlier_keys() );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertSame( array(), Ordryx_State::keys() );
		$this->assertSame( array(), Ordryx_Connection::earlier_keys() );
		$this->assertFalse( Ordryx_Connection::own_key_left() );
		$this->assertSame( 'unknown', Ordryx_State::phase() ); // (a new code connects again)
	}

	public function test_a_key_still_in_use_is_never_called_the_connections_own_left() {
		$this->connected();
		$this->assertFalse( Ordryx_Connection::own_key_left() );
		Ordryx_State::keep_keys( array( 5, 7 ) );
		$this->wpdb->add_key( 5, 'ck_' . str_repeat( 'c', 40 ) );
		$this->assertSame( array( 5 ), Ordryx_Connection::earlier_keys() );
		$this->assertFalse( Ordryx_Connection::own_key_left() );
	}

	/**
	 * The account answers, after something else changed the connection meanwhile.
	 */
	private function meanwhile( callable $change, array $answer ) {
		\Brain\Monkey\Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $change, $answer ) {
				$this->account->post( $url, $args );
				$change();
				return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $answer ) );
			}
		);
	}

	public function test_deleting_the_plugin_deletes_the_key_before_telling_ordryx() {
		// (the Ordryx believes "gone" only once the store refuses its key)
		$this->connected();
		$key_then = null;
		\Brain\Monkey\Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$key_then ) {
				$key_then = isset( $this->wpdb->keys[7] );
				return $this->account->post( $url, $args );
			}
		);
		Ordryx_Connection::uninstalled();
		$this->assertFalse( $key_then );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/gone' );
		$this->assertTrue( $call['signed'] );
	}

	public function test_a_check_across_a_deactivation_keeps_the_pause() {
		$this->connected();
		$this->meanwhile(
			function () {
				Ordryx_State::update( array( 'phase' => 'paused', 'off_since' => 1792700000 ) );
			},
			array( 'state' => 'connected' )
		);
		$this->assertSame( 'unreachable', Ordryx_Connection::check() );
		$this->assertSame( 'paused', Ordryx_State::phase() );
	}

	public function test_cancel_never_forgets_a_connection_confirmed_meanwhile() {
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'pairing' ) );
		$this->meanwhile(
			function () {
				Ordryx_State::update( array( 'phase' => 'connected' ) ); // the Allow screen's return, in another tab
			},
			array( 'ok' => false )
		);
		Ordryx_Connection::cancel();
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_resume_answered_after_a_disconnect_makes_no_record() {
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$this->meanwhile(
			function () {
				Ordryx_Connection::disconnected(); // a check saw "disconnected"
			},
			array( 'ok' => true )
		);
		Ordryx_Connection::ask( 'resume' );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_disconnect_says_the_key_was_deleted_even_if_a_check_got_there_first() {
		$this->connected();
		$this->meanwhile(
			function () {
				Ordryx_Connection::disconnected();
			},
			array( 'ok' => true )
		);
		$this->assertSame( 'done', Ordryx_Connection::ask( 'disconnect' ) );
	}

	public function test_a_proof_the_server_couldnt_hear_starts_again_saying_why() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( self::CHECK, 200, array( 'state' => 'refused', 'why' => 'proof' ) );
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertStringContainsString( 'couldn&#039;t reach this site', str_replace( "'", '&#039;', $html ) );
	}

	public function test_a_key_the_ordryx_refused_starts_again_saying_so() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( self::CHECK, 200, array( 'state' => 'refused', 'why' => 'ordryx' ) );
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$html = str_replace( "'", '&#039;', $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertStringContainsString( 'Your Ordryx didn&#039;t accept that', $html );
		$this->assertStringNotContainsString( 'couldn&#039;t reach this site', $html );
	}

	public function test_a_key_the_ordryx_never_answered_starts_again_saying_so() {
		// (the account keeps why "unanswered" when the Ordryx never answered
		// with the key: it never heard it, so it can't have turned it down)
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( self::CHECK, 200, array( 'state' => 'refused', 'why' => 'unanswered' ) );
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$html = str_replace( "'", '&#039;', $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertStringContainsString( 'Your Ordryx didn&#039;t answer in time', $html );
		$this->assertStringContainsString( 'type a new code from Ordryx', $html );
		$this->assertStringNotContainsString( 'didn&#039;t accept that', $html );
		$this->assertStringNotContainsString( 'couldn&#039;t reach this site', $html );
	}

	public function test_a_refusal_s_why_picks_its_result() {
		foreach ( array( 'ordryx' => 'refused', 'unanswered' => 'unanswered', 'proof' => 'blocked', 'nonsense' => 'refused' ) as $why => $want ) {
			$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
			Ordryx_Connection::pair( 'R7WT-4HQN', true );
			$this->account->will( self::CHECK, 200, array( 'state' => 'refused', 'why' => $why ) );
			$this->assertSame( $want, Ordryx_Connection::check( true ), $why );
			$this->assertContains( $want, Ordryx_Admin::RESULTS );
		}
	}

	public function test_an_older_account_s_refusal_without_a_why_is_the_proof() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( self::CHECK, 200, array( 'state' => 'refused' ) );
		$this->assertSame( 'blocked', Ordryx_Connection::check( true ) );
	}

	public function test_a_check_follows_a_pause_in_ordryx() {
		$this->connected();
		$this->account->will( self::CHECK, 200, array( 'state' => 'paused' ) );
		$this->assertSame( 'paused', Ordryx_Connection::check() );
		$this->assertSame( 'paused', Ordryx_State::phase() );
	}

	public function test_resume_and_disconnect_are_asked_of_the_ordryx_by_an_administrator() {
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'paused', 'off_since' => 1792700000 ) );
		$this->assertSame( 'done', Ordryx_Connection::ask( 'resume' ) );
		Ordryx_State::keep_keys( array( 7 ) );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/page' );
		$this->assertSame( array( 'resume', 'Avery Admin', true ), array( $call['body']['ask'], $call['body']['by'], $call['body']['admin'] ) );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertSame( 0, Ordryx_State::get()['off_since'] );

		$this->account->will( '/api/woocommerce/v1/plugin/page', 403, array( 'ok' => false ) );
		$this->assertSame( 'refused', Ordryx_Connection::ask( 'disconnect' ) );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
		$this->account->down = true;
		$this->assertSame( 'unreachable', Ordryx_Connection::ask( 'disconnect' ) );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
		$this->account->down = false;
		$this->assertSame( 'done', Ordryx_Connection::ask( 'disconnect' ) );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertSame( 'refused', Ordryx_Connection::ask( 'sync' ) );
	}

	public function test_switching_the_plugin_off_pauses_ordryx_from_then() {
		$this->connected();
		$this->scheduled['ordryx_nudge'] = array( time() + 30, 'ordryx' );
		Ordryx_Connection::deactivated();
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/pause' );
		$this->assertTrue( $call['signed'] );
		$this->assertEqualsWithDelta( time(), $call['body']['at'], 2 );
		$state = Ordryx_State::get();
		$this->assertSame( 'paused', $state['phase'] );
		$this->assertGreaterThan( 0, $state['off_since'] );
		$this->assertSame( array(), $this->scheduled );
		$this->assertArrayHasKey( 7, $this->wpdb->keys ); // paused, not disconnected
	}

	public function test_switching_off_a_store_that_isnt_connected_tells_nobody() {
		Ordryx_Connection::deactivated();
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_deleting_the_plugin_deletes_ordryxs_key_and_everything_kept() {
		$this->connected();
		$this->wpdb->add_key( 9, 'ck_' . str_repeat( 'e', 40 ), 1, 'Shipping app - API (2026-01-01 00:00:00)' );
		Ordryx_Connection::uninstalled();
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/gone' );
		$this->assertTrue( $call['signed'] );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertArrayHasKey( 9, $this->wpdb->keys ); // another app's key stays
		foreach ( Ordryx_State::OPTIONS as $option ) {
			$this->assertArrayNotHasKey( $option, $this->options );
		}
	}

	public function test_an_ask_says_what_went_wrong_on_the_way() {
		// (a 503 "unlinked" showed the
		// firewall sentence)
		$this->connected();
		$page = '/api/woocommerce/v1/plugin/page';
		$this->account->will( $page, 503, array( 'error' => 'unlinked' ) );
		$this->assertSame( 'unlinked', Ordryx_Connection::ask( 'sync' ) ); // (never sent to the Ordryx)
		$this->account->will( $page, 504, array( 'error' => 'unlinked' ) );
		$this->assertSame( 'slow', Ordryx_Connection::ask( 'sync' ) ); // (sent, not answered: it may still do it)
		$this->account->will( $page, 429 );
		$this->assertSame( 'busy', Ordryx_Connection::ask( 'sync' ) );
		$this->account->will( $page, 503 );
		$this->assertSame( 'busy', Ordryx_Connection::ask( 'sync' ) ); // (the account's own busy)
		$this->account->will( $page, 502 );
		$this->assertSame( 'unsure', Ordryx_Connection::ask( 'sync' ) );
		$this->account->down = true;
		$this->assertSame( 'unreachable', Ordryx_Connection::ask( 'sync' ) );
	}

	public function test_a_nudge_in_flight_during_a_disconnect_never_brings_the_connection_back() {
		// (a nudge waited on the account, then wrote its whole cached record
		// back: Connected again, with the secret gone; only a database edit or
		// deleting the plugin got out)
		foreach ( array( false, true ) as $down ) {
			$this->requests();
			$this->connected();
			Ordryx_State::get(); // (this request has the record)
			$this->account->down = $down;
			$this->account->during(
				'/api/woocommerce/v1/plugin/nudge',
				function () {
					unset( $this->options[ Ordryx_State::OPTION ], $this->options[ Ordryx_State::SECRET ] ); // (Disconnect, in another tab)
				}
			);
			Ordryx_Nudge::send();
			$this->assertArrayNotHasKey( Ordryx_State::OPTION, $this->options );
			$this->assertSame( '', Ordryx_State::fresh()['phase'] );
		}
	}

	public function test_a_record_made_since_this_request_found_none_is_seen() {
		$this->requests();
		$this->assertSame( '', Ordryx_State::get()['phase'] ); // (none: remembered as none)
		$this->connected(); // (another request connected meanwhile)
		$this->assertSame( 'connected', Ordryx_State::fresh()['phase'] );
		// and a change merges onto it, never onto the blank this request had
		Ordryx_State::update( array( 'checked_at' => 5 ) );
		$this->assertSame( array( 'connected', $this->account->store, 5 ), array( $this->options[ Ordryx_State::OPTION ]['phase'], $this->options[ Ordryx_State::OPTION ]['store'], $this->options[ Ordryx_State::OPTION ]['checked_at'] ) );
	}

	public function test_switching_the_plugin_off_for_a_network_pauses_each_sites_store() {
		// (WordPress runs the deactivation once, as the main site: a store
		// connected on another site of the network kept syncing)
		$this->connected();
		$three = $this->options; // (site 3: the store)
		// (site 4: a store whose site activated the plugin itself, before it was
		// network-activated: WordPress keeps that, so it still runs there)
		$four                   = $three;
		$four['active_plugins'] = array( 'akismet/akismet.php', 'ordryx-for-woocommerce/ordryx-for-woocommerce.php' );
		$this->options          = array( 'date_format' => 'F j, Y' ); // (site 1: nothing of the plugin's)
		$sites                  = array(
			1 => $this->options,
			3 => $three,
			4 => $four,
		);
		$now                    = 1;
		$went                   = array();
		\Brain\Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'plugin_basename' )->justReturn( 'ordryx-for-woocommerce/ordryx-for-woocommerce.php' );
		\Brain\Monkey\Functions\when( 'get_sites' )->justReturn( array( 1, 3, 4 ) );
		\Brain\Monkey\Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) use ( &$sites, &$now, &$went ) {
				$sites[ $now ] = $this->options;
				$now           = $id;
				$went[]        = $id;
				$this->options = $sites[ $id ];
			}
		);
		\Brain\Monkey\Functions\when( 'restore_current_blog' )->alias(
			function () use ( &$sites, &$now ) {
				$sites[ $now ] = $this->options;
				$now           = 1;
				$this->options = $sites[1];
			}
		);
		Ordryx_Connection::deactivated( true );
		$this->assertSame( array( 1, 3, 4 ), $went );
		$this->assertCount( 1, $this->account->to( '/api/woocommerce/v1/plugin/pause' ) );
		$this->assertSame( 'paused', $sites[3][ Ordryx_State::OPTION ]['phase'] );
		$this->assertSame( 'connected', $sites[4][ Ordryx_State::OPTION ]['phase'] ); // (still on there: left as it is)
		$this->assertSame( 0, $sites[4][ Ordryx_State::OPTION ]['off_since'] );
		$this->assertArrayNotHasKey( Ordryx_State::OPTION, $sites[1] );
		$this->assertArrayNotHasKey( Ordryx_State::LOOK_GEN, $sites[1] ); // (nothing written on a site never connected)
	}

	public function test_a_networks_sites_share_30_seconds_for_telling_ordryx() {
		// (nothing checked the budget; without it, each site of
		// a large network waited on a slow account, well past a request's limit)
		$this->connected();
		$store         = $this->options;
		$this->options = array( 'date_format' => 'F j, Y' );
		$sites         = array(
			1 => $this->options,
			3 => $store,
			4 => $store,
			5 => $store,
		);
		$now           = 1;
		\Brain\Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'plugin_basename' )->justReturn( 'ordryx-for-woocommerce/ordryx-for-woocommerce.php' );
		\Brain\Monkey\Functions\when( 'get_sites' )->justReturn( array( 1, 3, 4, 5 ) );
		\Brain\Monkey\Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) use ( &$sites, &$now ) {
				$sites[ $now ] = $this->options;
				$now           = $id;
				$this->options = $sites[ $id ];
			}
		);
		\Brain\Monkey\Functions\when( 'restore_current_blog' )->alias(
			function () use ( &$sites, &$now ) {
				$sites[ $now ] = $this->options;
				$now           = 1;
				$this->options = $sites[1];
			}
		);
		foreach ( array( 15, 30 ) as $ahead ) { // (each pause takes 15 seconds to answer)
			$this->account->during(
				'/api/woocommerce/v1/plugin/pause',
				function () use ( $ahead ) {
					$this->clock_on( $ahead );
				}
			);
		}
		Ordryx_Connection::deactivated( true );
		$this->assertCount( 2, $this->account->to( '/api/woocommerce/v1/plugin/pause' ) );
		$this->assertSame( 'paused', $sites[3][ Ordryx_State::OPTION ]['phase'] );
		$this->assertSame( 'paused', $sites[4][ Ordryx_State::OPTION ]['phase'] );
		// (past the 30 seconds: only unscheduled, and its Ordryx finds the plugin silent)
		$this->assertSame( 'connected', $sites[5][ Ordryx_State::OPTION ]['phase'] );
		$this->assertSame( 0, $sites[5][ Ordryx_State::OPTION ]['off_since'] );
	}

	public function test_switching_off_during_a_disconnect_makes_no_record() {
		$this->requests();
		$this->connected();
		Ordryx_State::get();
		$this->account->during(
			'/api/woocommerce/v1/plugin/pause',
			function () {
				unset( $this->options[ Ordryx_State::OPTION ], $this->options[ Ordryx_State::SECRET ] );
			}
		);
		Ordryx_Connection::deactivated();
		$this->assertArrayNotHasKey( Ordryx_State::OPTION, $this->options );
	}

	public function test_only_ordryxs_own_read_key_is_ever_deleted() {
		$this->wpdb->add_key( 3, 'ck_' . str_repeat( 'c', 40 ), 1, 'Zapier - API (2026-01-01 00:00:00)' );
		$this->wpdb->add_key( 4, 'ck_' . str_repeat( 'd', 40 ), 1, 'Ordryx - API (2026-01-01 00:00:00)', 'read_write' );
		$this->assertFalse( Ordryx_Keys::delete( 3 ) );
		$this->assertFalse( Ordryx_Keys::delete( 4 ) );
		$this->assertFalse( Ordryx_Keys::delete( 0 ) );
		$this->assertCount( 2, $this->wpdb->keys );
	}

	public function test_the_daily_check_is_scheduled_once_a_store_is_known() {
		Ordryx_Connection::schedule_check();
		$this->assertSame( array(), $this->scheduled );
		$this->connected();
		Ordryx_Connection::schedule_check();
		$this->assertSame( 86400, $this->scheduled['ordryx_daily_check'][2] );
		$this->assertSame( 'ordryx', $this->scheduled['ordryx_daily_check'][1] );
	}

	// ── a key changed in WooCommerce ──────────

	private function key_changed_then_disconnected() {
		$this->connected();
		$this->wpdb->keys[7]['description'] = 'Zapier - API (2026-01-01 00:00:00)'; // (renamed)
	}

	public function test_a_check_that_hears_disconnected_leaves_a_changed_key_and_says_so() {
		$this->key_changed_then_disconnected();
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'disconnected' ) );
		$this->assertSame( 'disconnected', Ordryx_Connection::check() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys ); // (never deleted: it may be someone's now)
		$this->assertNotSame( '', Ordryx_Connection::key_left() );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'A key Ordryx used is still in this store', $html );
		$this->assertStringContainsString( 'whoever holds it can still read', $html );
		// revoked: nothing more to say
		unset( $this->wpdb->keys[7] );
		$this->assertSame( '', Ordryx_Connection::key_left() );
		$this->assertStringNotContainsString( 'A key Ordryx used', $this->printed( array( 'Ordryx_Admin', 'render' ) ) );
	}

	public function test_disconnecting_says_a_changed_key_was_left() {
		$this->key_changed_then_disconnected();
		$this->account->will( '/api/woocommerce/v1/plugin/page', 200, array( 'ok' => true ) );
		$this->assertSame( 'done_left', Ordryx_Connection::ask( 'disconnect' ) );
	}

	public function test_a_key_of_ordryxs_name_is_deleted_and_nothing_is_left() {
		$this->connected();
		$this->account->will( '/api/woocommerce/v1/plugin/page', 200, array( 'ok' => true ) );
		$this->assertSame( 'done', Ordryx_Connection::ask( 'disconnect' ) );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertSame( '', Ordryx_Connection::key_left() );
	}

	public function test_another_key_that_took_the_left_keys_id_isnt_it() {
		$this->key_changed_then_disconnected();
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'disconnected' ) );
		Ordryx_Connection::check();
		unset( $this->wpdb->keys[7] );
		$this->wpdb->add_key( 7, 'ck_' . str_repeat( 'f', 40 ), 1, 'Zapier - API (2026-01-01 00:00:00)' );
		$this->assertSame( '', Ordryx_Connection::key_left() );
	}

	// ── the daily check ──────────

	public function test_a_check_left_over_with_no_store_goes_and_only_it() {
		$this->scheduled[ Ordryx_Connection::CHECK_HOOK ] = array( time() + 60, Ordryx_Connection::GROUP );
		$this->scheduled[ Ordryx_Erasure::HOOK ]          = array( time() + 60, Ordryx_Connection::GROUP );
		$this->doing_ajax = true;
		Ordryx_Connection::schedule_check(); // (never on admin-ajax.php: the store's own pages use it)
		$this->assertArrayHasKey( Ordryx_Connection::CHECK_HOOK, $this->scheduled );
		$this->doing_ajax = false;
		Ordryx_Connection::schedule_check();
		$this->assertArrayNotHasKey( Ordryx_Connection::CHECK_HOOK, $this->scheduled );
		$this->assertArrayHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
	}

	public function test_a_check_answered_ends_late() {
		$this->connected();
		Ordryx_State::update( array( 'failing_since' => time() - 3600 ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 7 ) );
		Ordryx_Connection::check();
		$this->assertSame( 0, Ordryx_State::get()['failing_since'] );
	}

	public function test_gone_is_left_out_past_a_networks_shared_time() {
		$this->connected();
		Ordryx_Connection::uninstalled( Ordryx_Clock::now() + 5 );
		$this->assertSame( array(), $this->account->to( '/api/woocommerce/v1/plugin/gone' ) );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys ); // (its key still goes)
	}

	// ── a copy of the site ──────────

	/**
	 * This site, connected, as a copy at another address (a staging site).
	 */
	private function a_copy() {
		$key                   = $this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		return $key;
	}

	public function test_every_signed_call_names_this_sites_address() {
		$this->connected();
		$this->options['home'] = 'http://shop.example.com/'; // (the setting as typed: named over https)
		Ordryx_Account::call( 'nudge' );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/nudge' );
		$this->assertSame( 'https://shop.example.com', $call['body']['site'] );
		$this->assertTrue( $call['signed'] ); // (the signature covers it)
		$expect = base64_encode( hash_hmac( 'sha256', "nudge\n" . $call['headers']['X-Ordryx-Time'] . "\n" . $call['raw'], $this->account->secret, true ) );
		$this->assertSame( $expect, $call['headers']['X-Ordryx-Signature'] );
	}

	public function test_on_a_copy_no_signed_call_leaves_the_site() {
		$this->a_copy();
		foreach ( Ordryx_Account::KINDS as $kind ) {
			$this->assertSame( array( 'status' => 0, 'body' => array(), 'copy' => true ), Ordryx_Account::call( $kind ), $kind );
		}
		$this->assertSame( array(), $this->account->calls );
		// a check the tab asks for goes, for the account to say whose address this is
		$answer = Ordryx_Account::call( 'check', array(), 15, true );
		$this->assertSame( array( 409, true ), array( $answer['status'], $answer['copy'] ) );
		$this->assertSame( 0, Ordryx_Account::call( 'nudge', array(), 15, true )['status'] ); // (a check only)
		$this->assertCount( 1, $this->account->calls );
	}

	public function test_a_copy_at_the_stores_own_address_signs_nothing_at_all() {
		// (WordPress says staging now: the account can't tell it from the store,
		// so even the tab's check would be answered, and acted on, as the store's)
		$this->connected();
		$this->environment = 'staging';
		$this->assertSame( array( 'status' => 0, 'body' => array(), 'copy' => true ), Ordryx_Account::call( 'check', array(), 15, true ) );
		$this->assertSame( 'copy', Ordryx_Connection::check( true ) );
		Ordryx_State::update( array( 'phase' => 'pairing' ) ); // (the live store pairing: its refusal is never spent here)
		$this->assertSame( 'copy', Ordryx_Connection::check( true ) );
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 'pairing', Ordryx_State::phase() );
	}

	public function test_switching_the_plugin_off_on_a_copy_tells_nobody_and_changes_no_phase() {
		$this->a_copy();
		$this->scheduled['ordryx_nudge'] = array( time() + 30, 'ordryx' );
		Ordryx_Connection::deactivated();
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertSame( 0, Ordryx_State::get()['off_since'] );
		$this->assertSame( array(), $this->scheduled ); // (its own jobs stop all the same)
	}

	public function test_deleting_the_plugin_on_a_copy_deletes_only_its_own_and_tells_nobody() {
		$this->a_copy();
		$this->wpdb->add_key( 9, 'ck_' . str_repeat( 'e', 40 ), 1, 'Shipping app - API (2026-01-01 00:00:00)' );
		Ordryx_Connection::uninstalled();
		$this->assertSame( array(), $this->account->calls );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys ); // (this copy's copy of the key: the live store's own is in its database)
		$this->assertArrayHasKey( 9, $this->wpdb->keys );
		foreach ( Ordryx_State::OPTIONS as $option ) {
			$this->assertArrayNotHasKey( $option, $this->options );
		}
	}

	public function test_the_daily_check_on_a_copy_asks_nobody_and_schedules_nothing() {
		$this->a_copy();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		Ordryx_Connection::daily(); // (a job copied with the database)
		Ordryx_Connection::schedule_check();
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_copy_never_sends_its_admin_to_the_allow_screen() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true ); // (the live site, mid-pairing, copied)
		$this->assertNotSame( '', Ordryx_Connection::allow_url( 'https://shop.example.com/wp-admin/' ) );
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->assertSame( '', Ordryx_Connection::allow_url( 'https://staging.shop.example.com/wp-admin/' ) );
		$this->assertSame( 'copy', Ordryx_Connection::returned( '1', 'pr_8c1d2e' ) );
		$this->assertSame( 'pairing', Ordryx_State::phase() );
		$this->assertSame( '', Ordryx_State::get()['connected_by'] );
		$this->assertSame( 'copy', Ordryx_Connection::cancel() );
		$this->assertSame( 'pairing', Ordryx_State::phase() ); // (nothing forgotten from a copy's Cancel)
		$this->assertCount( 1, $this->account->calls ); // (the pairing, before it was a copy)
	}

	public function test_forget_here_forgets_only_the_connection_once_the_account_says_its_a_copy() {
		$this->a_copy();
		Ordryx_State::keep_keys( array( 7 ) );
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		$this->options['ordryx_shipments'] = array( 'x' => array() );
		$this->transients[ Ordryx_State::LOOK ] = array( 'store' => 'st_4f9a', 'look' => array() );
		$this->scheduled['ordryx_daily_check'] = array( 0, 'ordryx', 86400 );
		$this->assertSame( 'forgot', Ordryx_Connection::forget_here() );
		list( $call ) = $this->account->calls; // (a check, naming this address: refused)
		$this->assertSame( '/api/woocommerce/v1/plugin/check', $call['path'] );
		$this->assertSame( 'https://staging.shop.example.com', $call['body']['site'] );
		$this->assertCount( 1, $this->account->calls ); // (nothing told)
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertSame( '', Ordryx_State::secret() );
		$this->assertArrayNotHasKey( Ordryx_State::OPTION, $this->options );
		$this->assertArrayNotHasKey( Ordryx_State::LOOK, $this->transients );
		$this->assertSame( array(), $this->scheduled );
		// kept, in case this is the store itself, wrongly taken for a copy
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
		$this->assertSame( array( 7 ), Ordryx_State::keys() );
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() );
		$this->assertArrayHasKey( 'ordryx_shipments', $this->options );
		$this->assertSame( array( 7 ), Ordryx_Connection::earlier_keys() ); // (the tab offers to delete it)
	}

	public function test_forget_here_does_nothing_on_the_store_itself() {
		// (a page shown while it looked like a copy, pressed once it's the store
		// again: another tab's check, the environment type put back)
		$this->connected();
		$this->assertSame( 'not_copy', Ordryx_Connection::forget_here() );
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_forget_here_forgets_once_the_account_doesnt_know_the_store() {
		// (the live store connected again since, with a new store id, or was
		// disconnected a month ago: this site's record acts for nothing)
		foreach ( array( 404, 401 ) as $status ) {
			$this->a_copy();
			$this->account->calls = array();
			$this->account->will( self::CHECK, $status, array( 'error' => 'store' ) );
			$this->assertSame( 'forgot', Ordryx_Connection::forget_here(), (string) $status );
			$this->assertCount( 1, $this->account->calls );
			$this->assertSame( '', Ordryx_State::phase() );
			$this->assertSame( '', Ordryx_State::secret() );
			$this->assertArrayHasKey( 7, $this->wpdb->keys ); // (kept, as for any Forget here)
		}
	}

	public function test_forget_here_where_the_address_is_the_stores_but_wordpress_says_staging() {
		// (the account knows this address as the store's, and WordPress calls
		// the site staging now: a copy by its environment type, forgotten)
		$this->a_copy();
		$this->environment   = 'staging';
		$this->account->site = 'https://staging.shop.example.com';
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		$this->assertSame( 'forgot_same', Ordryx_Connection::forget_here() );
		$this->assertCount( 1, $this->account->calls );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_forget_here_keeps_the_connection_on_any_other_refusal() {
		// (a refused signature, a clock that's off, a proxy's 404: the account
		// hasn't said whose address this is)
		foreach ( array( array( 401, array( 'error' => 'signature' ) ), array( 401, array( 'error' => 'time' ) ), array( 404, array() ), array( 422, array() ) ) as $answer ) {
			$this->a_copy();
			$this->account->will( self::CHECK, $answer[0], $answer[1] );
			$this->assertSame( 'forget_failed', Ordryx_Connection::forget_here(), wp_json_encode( $answer ) );
			$this->assertSame( 'connected', Ordryx_State::phase() );
			$this->assertNotSame( '', Ordryx_State::secret() );
		}
	}

	public function test_forget_here_is_refused_when_the_account_says_its_the_store_or_doesnt_answer() {
		$this->a_copy();
		$this->account->down = true;
		$this->assertSame( 'unreachable', Ordryx_Connection::forget_here() );
		$this->account->down = false;
		$this->account->will( self::CHECK, 503 );
		$this->assertSame( 'unreachable', Ordryx_Connection::forget_here() );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		// the account knows this address as the store's (a second address of the
		// live site, say): it's the store, its address kept again
		$this->account->site = 'https://staging.shop.example.com';
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		$this->assertSame( 'here', Ordryx_Connection::forget_here() );
		$this->assertTrue( Ordryx_Site::here() );
		$this->assertNotSame( '', Ordryx_State::secret() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_forget_here_on_a_copy_at_the_same_address_asks_nobody() {
		// (WordPress says staging now: the account can't tell a copy at the same address)
		$this->connected();
		$this->environment = 'staging';
		$this->assertSame( 'forgot_same', Ordryx_Connection::forget_here() ); // (a copy there needs an address of its own to connect)
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_a_copy_answer_is_never_taken_for_no_answer() {
		// (the account refuses the address this site took for the store's: each
		// caller sees a copy, never "unreachable", "late" or a reason to retry)
		$this->connected();
		$this->account->site = 'https://elsewhere.example.com';
		$this->assertSame( 'copy', Ordryx_Connection::ask( 'sync' ) );
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->assertSame( 'copy', Ordryx_Connection::ask( 'disconnect' ) );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
		$this->assertSame( 'connected', Ordryx_State::phase() );

		$this->connected();
		$this->account->site = 'https://elsewhere2.example.com';
		Ordryx_State::update( array( 'checked_at' => 0 ) );
		$this->assertSame( 'copy', Ordryx_Connection::check() );
		$this->assertGreaterThan( 0, Ordryx_State::get()['checked_at'] ); // (asked at most once a minute)
		$this->assertSame( 'connected', Ordryx_State::phase() );

		$this->connected();
		$this->account->site = 'https://elsewhere3.example.com';
		Ordryx_Connection::deactivated();
		$this->assertCount( 1, $this->account->to( '/api/woocommerce/v1/plugin/pause' ) );
		$this->assertSame( 'connected', Ordryx_State::phase() ); // (no pause of its own from a copy's answer)
		$this->assertSame( 0, Ordryx_State::get()['off_since'] );

		$this->connected();
		$this->account->site = 'https://elsewhere4.example.com';
		Ordryx_State::update( array( 'phase' => 'pairing' ) );
		$this->assertSame( 'copy', Ordryx_Connection::cancel() );
		$this->assertSame( 'pairing', Ordryx_State::phase() );

		$this->connected();
		$this->account->site = 'https://elsewhere5.example.com';
		$this->assertSame( array( 'error' => 'copy' ), Ordryx_Dashboard::look() );
		$this->assertArrayNotHasKey( Ordryx_State::LOOK, $this->transients );
	}
}
