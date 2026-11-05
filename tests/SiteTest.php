<?php
/**
 * A copy of the site: a staging site
 * or a host's one-click copy holds the store's connection too, and never signs
 * for it. The address it was connected at is kept out of a search-and-
 * replace's reach; Ordryx's server has the last word.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class SiteTest extends Ordryx_Test_Case {

	const NUDGE = '/api/woocommerce/v1/plugin/nudge';
	const CHECK = '/api/woocommerce/v1/plugin/check';

	/**
	 * The table the account's site_key is tested with too.
	 */
	private static function table() {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/site-keys.json' ), true );
	}

	public function test_an_address_is_the_same_whatever_its_scheme_www_case_default_port_or_slash() {
		$table = self::table();
		foreach ( $table['keys'] as list( $address, $key ) ) {
			$this->assertSame( $key, Ordryx_Site::key( $address ), $address );
		}
		foreach ( $table['same'] as list( $one, $two ) ) {
			$this->assertTrue( Ordryx_Site::same( $one, $two ), $one . ' ' . $two );
		}
	}

	public function test_another_host_path_or_port_is_another_site() {
		$table = self::table();
		foreach ( $table['different'] as list( $one, $two ) ) {
			$this->assertFalse( Ordryx_Site::same( $one, $two ), $one . ' ' . $two );
		}
		foreach ( $table['invalid'] as $address ) {
			$this->assertSame( '', Ordryx_Site::key( $address ), $address );
			$this->assertFalse( Ordryx_Site::same( $address, $address ), $address );
		}
		$this->assertSame( '', Ordryx_Site::key( array( 'https://shop.example.com' ) ) );
	}

	/**
	 * Every option rewritten as wp search-replace does (serialized arrays
	 * included), the Site Address with them.
	 */
	private function search_and_replace( $from, $to ) {
		$walk = function ( $value ) use ( &$walk, $from, $to ) {
			if ( is_array( $value ) ) {
				return array_map( $walk, $value );
			}
			return is_string( $value ) ? str_replace( $from, $to, $value ) : $value;
		};
		$this->options['home'] = $this->home;
		$this->options         = array_map( $walk, $this->options );
	}

	public function test_pairing_keeps_the_address_where_search_and_replace_cant_reach() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertTrue( Ordryx_Site::here() );
		$this->search_and_replace( 'shop.example.com', 'staging.shop.example.com' );
		$this->assertSame( 'https://staging.shop.example.com', Ordryx_Site::home() );
		$this->assertSame( 'https://shop.example.com', Ordryx_Site::paired() );
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->assertSame( 'address', Ordryx_Site::why() );
	}

	public function test_wp_home_in_a_copys_wp_config_makes_it_a_copy() {
		$this->connected();
		$this->options['home'] = 'https://shop.ddev.site'; // (WordPress's own filter gives get_option the constant)
		$this->assertSame( 'address', Ordryx_Site::why() );
		$this->assertSame( 0, Ordryx_Account::call( 'nudge' )['status'] );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_an_address_put_back_is_the_store_again() {
		$this->connected();
		$this->options['home'] = 'https://shop.example.com/oops';
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->options['home'] = 'http://www.shop.example.com/';
		$this->assertTrue( Ordryx_Site::here() ); // (nothing about a copy was ever stored)
		Ordryx_Account::call( 'nudge' );
		$this->assertCount( 1, $this->account->to( self::NUDGE ) );
	}

	public function test_no_store_is_never_a_copy() {
		$this->options['home'] = 'https://elsewhere.example.com';
		$this->options[ Ordryx_State::OPTION ] = array( 'site' => array( 'home' => base64_encode( 'https://shop.example.com' ) ) );
		$this->assertTrue( Ordryx_Site::here() );
		$this->assertSame( '', Ordryx_Site::why() );
	}

	public function test_an_empty_site_address_sends_nothing() {
		// (a WP_HOME built from the request's host, in a run with no host)
		$this->connected();
		$this->options['home'] = 'https://';
		$this->assertTrue( Ordryx_Site::elsewhere() );
		Ordryx_Nudge::send();
		$this->assertSame( array(), $this->account->calls );
	}

	/**
	 * A store connected before the plugin kept its address (0.5.0 and older).
	 */
	private function connected_without_an_address() {
		$this->connected();
		$state = Ordryx_State::get();
		unset( $state['site'] );
		$this->options[ Ordryx_State::OPTION ] = $state;
	}

	public function test_without_an_address_kept_the_account_decides() {
		$this->connected_without_an_address();
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->assertTrue( Ordryx_Site::here() ); // (nothing to compare: the account knows)
		Ordryx_Nudge::send();
		list( $call ) = $this->account->to( self::NUDGE );
		$this->assertSame( 'https://staging.shop.example.com', $call['body']['site'] );
		// refused as a copy: the account's address kept, and nothing more leaves
		$this->assertSame( 'https://shop.example.com', Ordryx_Site::paired() );
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->assertSame( 0, Ordryx_State::get()['failing_since'] ); // (never "late")
		$this->assertArrayNotHasKey( 'ordryx_nudge', $this->scheduled ); // (never tried again)
		$this->account->calls = array();
		Ordryx_Nudge::changed();
		Ordryx_Nudge::send();
		Ordryx_Connection::check();
		Ordryx_Connection::deactivated();
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_without_an_address_kept_the_store_gets_one_at_its_check() {
		$this->connected_without_an_address();
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		$this->assertSame( 'connected', Ordryx_Connection::check() );
		$this->assertSame( 'https://shop.example.com', Ordryx_Site::paired() );
		$this->assertSame( 'production', Ordryx_State::get()['site']['env'] );
	}

	public function test_a_store_connected_before_0_5_1_is_taken_for_a_live_site() {
		// (it kept no environment: read as production, WordPress's default, so
		// a copy at the store's own address that WordPress calls staging is a
		// copy at once, and its own check never makes it the store)
		$this->connected_without_an_address();
		$this->environment = 'staging';
		$this->assertSame( 'environment', Ordryx_Site::why() );
		Ordryx_Nudge::changed();
		Ordryx_Nudge::send();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		Ordryx_Erasure::send();
		$this->assertSame( 'copy', Ordryx_Connection::check() );
		$this->assertSame( 'copy', Ordryx_Connection::check( true ) );
		Ordryx_Connection::deactivated();
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 'environment', Ordryx_Site::why() );
		$this->assertArrayNotHasKey( 'env', Ordryx_State::get()['site'] ); // (never given this site's own)
		$this->assertSame( 'connected', Ordryx_State::phase() );
	}

	public function test_the_accounts_word_never_gives_the_record_this_sites_environment() {
		// (a record without one, at an address the account knows as the
		// store's: it can't tell a copy there, so the record gets production)
		$this->connected_without_an_address();
		Ordryx_State::update( array( 'site' => array( 'home' => base64_encode( 'https://old.example.com' ) ) ) );
		$this->environment = 'staging';
		$this->assertSame( 'address', Ordryx_Site::why() );
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		$this->assertSame( 'copy', Ordryx_Connection::check( true ) );
		$this->assertCount( 1, $this->account->calls );
		$this->assertSame( 'https://shop.example.com', Ordryx_Site::paired() );
		$this->assertSame( 'production', Ordryx_State::get()['site']['env'] );
		$this->assertSame( 'environment', Ordryx_Site::why() );
	}

	public function test_a_409_after_the_connection_changed_keeps_nothing() {
		$this->connected_without_an_address();
		$this->options['home'] = 'https://staging.shop.example.com';
		\Brain\Monkey\Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$answer = $this->account->post( $url, $args );
				$state  = Ordryx_State::get();
				$state['store'] = 'st_new'; // (a new code, in another tab, meanwhile)
				$this->options[ Ordryx_State::OPTION ] = $state;
				return $answer;
			}
		);
		$answer = Ordryx_Account::call( 'nudge' );
		$this->assertTrue( $answer['copy'] );
		$this->assertSame( array(), Ordryx_State::get()['site'] );
		$this->assertTrue( Ordryx_Site::here() );
	}

	public function test_the_accounts_address_is_kept_only_if_its_an_https_address() {
		$this->connected_without_an_address();
		foreach ( array( 'http://shop.example.com', 'javascript:alert(1)', array( 'x' ), 'https://shop.example.com/?a=1' ) as $told ) {
			$this->account->will( self::NUDGE, 409, array( 'error' => 'site', 'site' => $told ) );
			$this->assertTrue( Ordryx_Account::call( 'nudge' )['copy'] );
			$this->assertSame( '', Ordryx_Site::paired() );
			Ordryx_State::update( array( 'site' => array() ) );
		}
	}

	public function test_a_rule_that_disagrees_with_the_accounts_stops_at_once() {
		// (this site's rule says the same site, the account's says another: the
		// address it refused is kept, so nothing more is sent from it)
		$this->connected();
		$this->account->will( self::NUDGE, 409, array( 'error' => 'site', 'site' => 'https://shop.example.com' ) );
		Ordryx_Nudge::send();
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->account->calls = array();
		Ordryx_State::update( array( 'sent_at' => 0 ) );
		Ordryx_Nudge::send();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		Ordryx_Erasure::send();
		$this->assertSame( array(), $this->account->calls );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'This looks like a copy of the store', $html );
		// until the account says this address is the store's, at the tab's check
		$this->account->will( self::CHECK, 200, array( 'state' => 'connected' ) );
		$this->assertSame( 'connected', Ordryx_Connection::check( true ) );
		$this->assertTrue( Ordryx_Site::here() );
	}

	public function test_the_accounts_word_that_this_is_the_store_heals_a_wrong_guess() {
		$this->connected();
		$state         = Ordryx_State::get();
		$state['site'] = array(
			'home' => base64_encode( 'https://old.example.com' ), // (a record this site took wrongly)
			'env'  => 'production',
		);
		$this->options[ Ordryx_State::OPTION ] = $state;
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->assertSame( 'copy', Ordryx_Connection::check() ); // (the daily check: nothing sent)
		$this->assertSame( array(), $this->account->calls );
		$this->account->will( self::CHECK, 200, array( 'state' => 'paused' ) );
		$this->assertSame( 'paused', Ordryx_Connection::check( true ) ); // (the tab's: the account knows this address)
		$this->assertTrue( Ordryx_Site::here() );
		$this->assertSame( 'https://shop.example.com', Ordryx_Site::paired() );
		$this->assertSame( 'paused', Ordryx_State::phase() ); // (and the store's answer followed)
	}

	public function test_an_answer_that_doesnt_name_the_store_is_never_taken_for_its_word() {
		// (an account before 0.25.9 answers a copy's check as the store's)
		$this->connected();
		$this->options['home']    = 'https://staging.shop.example.com';
		$this->account->echo_site = false;
		$this->account->site      = 'https://staging.shop.example.com'; // (it refuses nothing)
		$this->account->will( self::CHECK, 200, array( 'state' => 'disconnected' ) );
		$this->assertSame( 'copy', Ordryx_Connection::check( true ) );
		$this->assertTrue( Ordryx_Site::elsewhere() );
		$this->assertSame( 'connected', Ordryx_State::phase() ); // (never followed here)
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_live_site_now_said_to_be_staging_is_a_copy_at_the_same_address() {
		$this->connected();
		$this->environment = 'staging';
		$this->assertSame( 'environment', Ordryx_Site::why() );
		$this->assertTrue( Ordryx_Account::call( 'nudge' )['copy'] );
		// the account can't tell a copy at the same address: not even the tab's
		// check is sent (it would be answered as the store's)
		$this->assertSame( 'copy', Ordryx_Connection::check( true ) );
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 'environment', Ordryx_Site::why() );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_store_connected_in_development_keeps_working_wherever_it_goes_live() {
		$this->environment = 'development';
		$this->connected();
		$this->environment = 'production';
		$this->assertTrue( Ordryx_Site::here() );
		$this->environment = 'local';
		$this->assertTrue( Ordryx_Site::here() );
	}
}
