<?php
/**
 * A site whose wp-config.php has no keys of its own: the secret is never kept
 * in the clear, so the store can't connect until it has them.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NoKeysTest extends Ordryx_Test_Case {

	protected $wp_config_keys = false;

	public function test_no_keys_no_connecting_and_nothing_sent() {
		$this->assertFalse( Ordryx_State::can_seal() );
		$this->assertSame( 'keys', Ordryx_Connection::refused() );
		$this->assertSame( 'keys', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
		$this->assertSame( array(), $this->account->to( '/api/woocommerce/v1/pair' ) );
		$this->assertArrayNotHasKey( Ordryx_State::SECRET, $this->options );
		$this->assertFalse( Ordryx_State::keep_secret( 'st_4f9a', base64_encode( str_repeat( 'z', 32 ) ) ) );
	}

	public function test_the_sample_phrase_is_no_key() {
		define( 'AUTH_KEY', 'put your unique phrase here' );
		define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );
		$this->assertFalse( Ordryx_State::can_seal() );
	}

	public function test_a_store_connected_before_still_signs_until_it_can_be_sealed() {
		// (0.5.9 kept it in the clear on a site with no keys: it goes on working)
		$this->options[ Ordryx_State::SECRET ] = base64_encode( str_repeat( 'z', 32 ) );
		$this->assertSame( str_repeat( 'z', 32 ), Ordryx_State::secret() );
		$this->assertFalse( Ordryx_State::secret_lost() );
	}
}
