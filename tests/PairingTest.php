<?php
/**
 * Pairing: the code, both sides, the Allow
 * screen and back.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class PairingTest extends Ordryx_Test_Case {

	const PAIR = '/api/woocommerce/v1/pair';

	public function test_a_code_is_eight_letters_and_numbers_however_typed() {
		$this->assertSame( 'R7WT-4HQN', Ordryx_Connection::code( 'r7wt4hqn' ) );
		$this->assertSame( 'R7WT-4HQN', Ordryx_Connection::code( ' R7WT - 4HQN ' ) );
		$this->assertSame( '', Ordryx_Connection::code( 'R7WT-4HQ' ) );
		$this->assertSame( '', Ordryx_Connection::code( 'R7WT-4HQNX' ) );
		$this->assertSame( '', Ordryx_Connection::code( '' ) );
	}

	public function test_a_good_code_keeps_the_secret_and_shows_both_sides() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );

		list( $call ) = $this->account->to( self::PAIR );
		$this->assertNull( $call['signed'] ); // the code is the proof, nothing signed yet
		$this->assertSame( 'R7WT-4HQN', $call['body']['code'] );
		$this->assertSame( 'https://shop.example.com', $call['body']['site'] );
		$this->assertSame( 'Radio Gear & Co', $call['body']['name'] );
		$this->assertSame( '11.2.1', $call['body']['woocommerce'] );
		$this->assertSame( 'Avery Admin', $call['body']['by'] );
		$this->assertFalse( $call['body']['move'] );
		$this->assertTrue( $call['body']['terms'] ); // (ticked on the code card)
		$this->assertSame( 0, $call['args']['redirection'] ); // never follows a redirect
		$this->assertStringStartsWith( 'https://account.ordryx.com/', $call['url'] );

		$state = Ordryx_State::get();
		$this->assertSame( 'pairing', $state['phase'] );
		$this->assertSame( 'pr_8c1d2e', $state['pairing'] );
		$this->assertSame( 'st_4f9a', $state['store'] );
		$this->assertSame( 'Bench Works', $state['ordryx']['name'] );
		$this->assertSame( 'R7WT-4HQN', $state['ordryx']['code'] );
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
		// neither option is loaded on every page
		$this->assertFalse( $this->autoload[ Ordryx_State::SECRET ] );
		$this->assertFalse( $this->autoload[ Ordryx_State::OPTION ] );
	}

	public function test_what_the_ordryx_says_about_itself_is_kept_as_plain_short_text() {
		$this->account->will(
			self::PAIR,
			200,
			$this->account->paired(
				array(
					'ordryx' => array(
						'name'    => '<script>alert(1)</script>' . str_repeat( 'x', 200 ),
						'address' => 'javascript:alert(1)',
						'code_by' => array( 'not' => 'text' ),
						'stores'  => -4,
					),
				)
			)
		);
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$ordryx = Ordryx_State::get()['ordryx'];
		$this->assertStringNotContainsString( '<', $ordryx['name'] );
		$this->assertLessThanOrEqual( 80, strlen( $ordryx['name'] ) );
		$this->assertSame( '', $ordryx['address'] ); // a host name or nothing
		$this->assertSame( '', $ordryx['code_by'] );
		$this->assertSame( 0, $ordryx['stores'] );
	}

	public function test_a_secret_that_isnt_32_bytes_keeps_nothing() {
		$this->account->will( self::PAIR, 200, $this->account->paired( array( 'secret' => base64_encode( 'short' ) ) ) );
		$this->assertSame( 'failed', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertSame( '', Ordryx_State::secret() );
		$this->account->will( self::PAIR, 200, $this->account->paired( array( 'store' => '../x' ) ) );
		$this->assertSame( 'failed', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( '', Ordryx_State::secret() );
	}

	public function test_the_accounts_refusals_are_known_codes_never_its_words() {
		foreach ( array( 'code', 'tries', 'paused', 'unlinked', 'terms', 'refused' ) as $error ) { // (refused: the Ordryx said no, its reason on its own page)
			$this->account->will( self::PAIR, 400, array( 'error' => $error, 'detail' => 'whatever' ) );
			$this->assertSame( $error, Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		}
		$this->account->will( self::PAIR, 400, array( 'error' => '<b>made up</b>' ) );
		$this->assertSame( 'failed', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->account->down = true;
		$this->assertSame( 'unreachable', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( '', Ordryx_State::phase() );
		foreach ( array_diff( Ordryx_Connection::PAIR_ERRORS, array( 'elsewhere' ) ) as $error ) { // (elsewhere: its own panel)
			$this->assertContains( $error, Ordryx_Admin::RESULTS );
		}
	}

	public function test_nothing_is_asked_until_the_terms_are_ticked() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'agree', Ordryx_Connection::pair( 'R7WT-4HQN', false ) );
		$this->assertSame( 'agree', Ordryx_Connection::pair( 'R7WT-4HQN', 1 ) ); // (only a real yes)
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( '', Ordryx_State::phase() );
	}

	public function test_a_store_another_ordryx_has_is_moved_only_when_asked() {
		$this->account->will( self::PAIR, 409, array( 'error' => 'elsewhere', 'other' => 'Other Shop' ) );
		$this->assertSame( 'elsewhere', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$state = Ordryx_State::get();
		$this->assertSame( 'Other Shop', $state['other_ordryx'] );
		$this->assertSame( 'R7WT-4HQN', $state['pending_code'] );
		$this->assertSame( '', $state['phase'] );

		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( $state['pending_code'], true, true ) );
		$calls = $this->account->to( self::PAIR );
		$this->assertTrue( $calls[1]['body']['move'] );
		$this->assertSame( '', Ordryx_State::get()['pending_code'] );
	}

	public function test_a_store_without_https_or_with_an_old_woocommerce_isnt_paired() {
		$this->home = 'http://shop.example.com';
		$this->assertSame( 'https', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_plain_permalinks_are_refused_before_pairing() {
		// (WooCommerce's Approve link needs pretty permalinks)
		$this->options['permalink_structure'] = '';
		$this->assertSame( 'permalinks', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( array(), $this->account->calls );
		$this->assertContains( 'permalinks', Ordryx_Admin::RESULTS );
	}

	public function test_a_check_while_pairing_keeps_what_both_sides_showed() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'pairing', 'ordryx' => array( 'name' => 'Bench Works', 'address' => 'bench-works.ordryx.com' ) ) );
		Ordryx_Connection::check();
		$ordryx = Ordryx_State::get()['ordryx'];
		$this->assertSame( 'Olive', $ordryx['code_by'] );
		$this->assertSame( 'R7WT-4HQN', $ordryx['code'] );
	}

	public function test_connecting_again_deletes_the_earlier_ordryx_key_once_the_new_one_is_confirmed() {
		// (an earlier key outlived a new connection)
		$this->connected();
		Ordryx_State::keep_keys( array( 7 ) );
		Ordryx_State::update( array( 'phase' => 'unknown', 'off_since' => 5, 'failing_since' => 6 ) );
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$state = Ordryx_State::get();
		$this->assertSame( array( 0, 0, 0, '' ), array( $state['key_id'], $state['off_since'], $state['failing_since'], $state['connected_by'] ) );
		$this->assertSame( array( 7 ), Ordryx_State::keys() ); // still listed (not offered while the new key isn't known)
		$this->assertSame( array(), Ordryx_Connection::earlier_keys() );

		$this->wpdb->add_key( 12, 'ck_' . str_repeat( 'b', 40 ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 12 ) );
		Ordryx_Connection::returned( '1', 'pr_8c1d2e' );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
		$this->assertArrayHasKey( 12, $this->wpdb->keys );
		$this->assertSame( array( 12 ), Ordryx_State::keys() );
		$this->assertSame( array(), Ordryx_Connection::earlier_keys() );
	}

	public function test_a_cancelled_pairing_never_forgets_an_earlier_key() {
		$this->connected();
		Ordryx_State::keep_keys( array( 7 ) );
		Ordryx_State::update( array( 'phase' => 'unknown' ) );
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		Ordryx_Connection::cancel();
		$this->assertSame( array( 7 ), Ordryx_Connection::earlier_keys() );
		$this->assertSame( array( 7 ), Ordryx_Connection::delete_keys() );
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_key_allowed_but_not_yet_confirmed_is_still_tracked() {
		// (round 2: an Allow whose check didn't answer, then Cancel)
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->wpdb->add_key( 15, 'ck_' . str_repeat( 'b', 40 ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 503 );
		$this->assertSame( 'unreachable', Ordryx_Connection::returned( '1', 'pr_8c1d2e' ) );
		Ordryx_Connection::cancel();
		$this->assertSame( array( 15 ), Ordryx_Connection::earlier_keys() );
	}

	public function test_a_cancel_after_the_key_was_taken_keeps_the_connection() {
		// (the key landed before the Cancel did)
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->wpdb->add_key( 12, 'ck_' . str_repeat( 'b', 40 ) );
		$this->account->will( '/api/woocommerce/v1/plugin/cancel', 409, array( 'error' => 'connected' ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 12 ) );
		// (never "Cancelled. Nothing was connected.")
		$this->assertSame( 'stood', Ordryx_Connection::cancel() );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertSame( 12, Ordryx_State::get()['key_id'] );
	}

	public function test_a_cancel_forgets_the_daily_check_too() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		Ordryx_Connection::schedule_check();
		$this->assertArrayHasKey( Ordryx_Connection::CHECK_HOOK, $this->scheduled );
		$this->assertSame( 'cancelled', Ordryx_Connection::cancel() );
		$this->assertArrayNotHasKey( Ordryx_Connection::CHECK_HOOK, $this->scheduled );
	}

	public function test_the_consent_screen_says_which_orders_ordryx_reads() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'orders still open at the store', $html );
		$this->assertStringNotContainsString( 'from now on', $html );
	}

	public function test_a_connected_store_isnt_paired_again() {
		$this->connected();
		$this->assertSame( 'connected', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_connect_needs_the_ordryxs_key_matched_by_eye_first() {
		\Brain\Monkey\Functions\when( 'check_admin_referer' )->justReturn( 1 );
		\Brain\Monkey\Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) {
				throw new Redirected( $url );
			}
		);
		$pk  = sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() );
		$key = rtrim( strtr( base64_encode( $pk ), '+/', '-_' ), '=' );
		$this->account->will( self::PAIR, 200, $this->account->paired( array( 'ordryx' => array( 'name' => 'Bench Works', 'address' => 'bench-works.ordryx.com', 'code_by' => 'Olive', 'stores' => 0, 'key' => $key ) ) ) );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( Ordryx_Shipped::fingerprint( $key ), $html );
		$this->assertStringContainsString( 'name="key_ok"', $html );
		$this->assertStringNotContainsString( 'wc-auth-route=authorize', $html ); // (the form, not a bare link)
		$go = function ( array $post ) {
			$_POST = $post;
			try {
				Ordryx_Admin::act();
			} catch ( Redirected $e ) {
				return $e->getMessage();
			}
			return '';
		};
		$this->assertStringEndsWith( '&ordryx=key_check', $go( array( 'do' => 'allow' ) ) );
		$this->assertEmpty( Ordryx_State::get()['ordryx']['key_checked'] ?? null );
		$this->assertStringEndsWith( '&ordryx=key_check', $go( array( 'do' => 'allow', 'key_ok' => '1', 'shown' => 'AAAA-BBBB-CCCC-DDDD' ) ) ); // (another key than the one now waiting)
		$this->assertStringContainsString( 'wc-auth-route=authorize', $go( array( 'do' => 'allow', 'key_ok' => '1', 'shown' => Ordryx_Shipped::fingerprint( $key ) ) ) );
		$this->assertTrue( Ordryx_State::get()['ordryx']['key_checked'] );
		// a check never sets it, nor a new pairing keeps it
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_State::forget();
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->assertEmpty( Ordryx_State::get()['ordryx']['key_checked'] ?? null );
	}

	public function test_connect_goes_to_woocommerces_allow_screen_for_a_read_key() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$url = Ordryx_Connection::allow_url( 'https://shop.example.com/wp-admin/admin.php?page=wc-settings&tab=ordryx' );
		$this->assertStringStartsWith( 'https://shop.example.com/?wc-auth-version=1&wc-auth-route=authorize&', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'read', $q['scope'] );
		$this->assertSame( 'Ordryx', rawurldecode( $q['app_name'] ) );
		$this->assertSame( 'pr_8c1d2e', rawurldecode( $q['user_id'] ) );
		$this->assertSame( 'https://account.ordryx.com/api/woocommerce/v1/key', rawurldecode( $q['callback_url'] ) );

		$state                  = Ordryx_State::get();
		$state['pairing_until'] = time() - 1; // run out
		$this->options[ Ordryx_State::OPTION ] = $state;
		$this->assertSame( '', Ordryx_Connection::allow_url( 'x' ) );
	}

	public function test_back_from_the_allow_screen_is_believed_once_the_account_confirms() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->wpdb->add_key( 12, 'ck_' . str_repeat( 'b', 40 ) );

		$this->assertSame( 'stale', Ordryx_Connection::returned( '1', 'pr_other' ) );
		$this->assertSame( 'denied', Ordryx_Connection::returned( '0', 'pr_8c1d2e' ) );
		$this->assertSame( 'pairing', Ordryx_State::phase() );

		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 12 ) );
		$this->assertSame( 'connected', Ordryx_Connection::returned( '1', 'pr_8c1d2e' ) );
		$state = Ordryx_State::get();
		$this->assertSame( 'connected', $state['phase'] );
		$this->assertSame( 12, $state['key_id'] );
		$this->assertSame( 'Avery Admin', $state['connected_by'] );
		$this->assertSame( '', $state['pairing'] );
		list( $check ) = $this->account->to( '/api/woocommerce/v1/plugin/check' );
		$this->assertTrue( $check['signed'] );
	}

	public function test_the_key_id_is_taken_only_for_ordryxs_own_read_key() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		$this->wpdb->add_key( 3, 'ck_' . str_repeat( 'c', 40 ), 1, 'Zapier - API (2026-01-01 00:00:00)' );
		$this->wpdb->add_key( 4, 'ck_' . str_repeat( 'd', 40 ), 1, 'Ordryx - API (2026-01-01 00:00:00)', 'read_write' );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 3 ) );
		Ordryx_Connection::returned( '1', 'pr_8c1d2e' );
		$this->assertSame( 0, Ordryx_State::get()['key_id'] );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 4 ) );
		Ordryx_Connection::check();
		$this->assertSame( 0, Ordryx_State::get()['key_id'] );
	}

	public function test_cancel_forgets_the_pairing_and_tells_the_account() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
		Ordryx_Connection::cancel();
		$this->assertSame( '', Ordryx_State::phase() );
		$this->assertSame( '', Ordryx_State::secret() );
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/cancel' );
		$this->assertTrue( $call['signed'] );
	}

	public function test_pairing_names_the_site_by_its_site_address_setting() {
		// (home_url() a language plugin changes per request; the setting doesn't)
		\Brain\Monkey\Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://fr.shop.example.com' . $path;
			}
		);
		$this->options['home'] = 'http://shop.example.com/'; // (as typed: named over https)
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		list( $call ) = $this->account->to( self::PAIR );
		$this->assertSame( 'https://shop.example.com', $call['body']['site'] );
		$this->assertSame( 'https://shop.example.com', Ordryx_Site::paired() );
		$this->assertSame( 'production', Ordryx_State::get()['site']['env'] );
		$this->assertTrue( Ordryx_Site::here() );
	}

	public function test_a_new_pairing_keeps_the_address_its_made_at() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		Ordryx_State::update( array( 'phase' => 'unknown' ) ); // (connecting the copy as its own store)
		$this->account->site = 'https://staging.shop.example.com';
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( 'https://staging.shop.example.com', Ordryx_Site::paired() );
		$this->assertTrue( Ordryx_Site::here() );
	}
}
