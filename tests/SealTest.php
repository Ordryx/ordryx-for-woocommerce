<?php
/**
 * The site secret, sealed with a key from wp-config.php: a copy of the
 * database alone can't sign for the store.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class SealTest extends Ordryx_Test_Case {

	const PAIR  = '/api/woocommerce/v1/pair';
	const CHECK = '/api/woocommerce/v1/plugin/check';

	private function paired() {
		$this->account->will( self::PAIR, 200, $this->account->paired() );
		$this->assertSame( 'paired', Ordryx_Connection::pair( 'R7WT-4HQN', true ) );
	}

	public function test_the_secret_is_kept_sealed_never_in_the_clear() {
		$this->paired();
		$kept = $this->options[ Ordryx_State::SECRET ];
		$this->assertStringStartsWith( 's1.', $kept );
		$this->assertStringNotContainsString( base64_encode( $this->account->secret ), $kept );
		$this->assertStringNotContainsString( $this->account->secret, base64_decode( substr( $kept, 3 ) ) );
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
		$this->assertFalse( Ordryx_State::secret_lost() );
		// each sealing its own nonce: the same secret kept again reads differently
		Ordryx_State::keep_secret( 'st_4f9a', base64_encode( $this->account->secret ) );
		$this->assertNotSame( $kept, $this->options[ Ordryx_State::SECRET ] );
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
	}

	public function test_a_secret_kept_in_the_clear_before_is_sealed_when_first_read() {
		$this->options[ Ordryx_State::SECRET ] = base64_encode( $this->account->secret ); // (0.5.9)
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
		$this->assertStringStartsWith( 's1.', $this->options[ Ordryx_State::SECRET ] );
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
	}

	public function test_a_damaged_or_foreign_seal_opens_to_nothing() {
		foreach ( array( 's1.', 's1.!!!', 's1.' . base64_encode( str_repeat( 'x', 72 ) ), 's1.' . base64_encode( str_repeat( 'x', 71 ) ) ) as $bad ) {
			$this->options[ Ordryx_State::SECRET ] = $bad;
			$this->assertSame( '', Ordryx_State::secret(), $bad );
		}
		$this->assertTrue( Ordryx_State::secret_lost() );
	}

	public function test_a_seal_from_other_keys_asks_nothing_and_says_connect_again() {
		$this->paired();
		Ordryx_State::update( array( 'phase' => 'connected' ) );
		// sealed on another site (or before wp-config.php's keys changed)
		$key   = hash_hkdf( 'sha256', 'AUTH_KEY=' . str_repeat( 'a', 64 ) . "\nSECURE_AUTH_KEY=" . str_repeat( 'b', 64 ) . "\n", 32, 'ordryx_secret seal v1' );
		$nonce = random_bytes( 24 );
		$this->options[ Ordryx_State::SECRET ] = 's1.' . base64_encode( $nonce . sodium_crypto_secretbox( $this->account->secret, $nonce, $key ) );

		$this->assertSame( '', Ordryx_State::secret() );
		$this->assertTrue( Ordryx_State::secret_lost() );
		$this->assertSame( 'unknown', Ordryx_Connection::check() );
		$this->assertSame( 'unknown', Ordryx_State::phase() );
		$this->assertSame( array(), $this->account->to( self::CHECK ) ); // nothing signed, nothing sent
		$this->assertSame( 0, Ordryx_Account::call( 'check' )['status'] );
		// a new code connects again, sealed with this site's keys
		$this->paired();
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
		$this->assertFalse( Ordryx_State::secret_lost() );
	}

	/**
	 * (in a PHP of its own: ORDRYX_SECRET_KEY, once defined, stays)
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ordryx_secret_key_seals_instead_of_wordpress_keys() {
		$this->paired(); // sealed with AUTH_KEY and SECURE_AUTH_KEY
		define( 'ORDRYX_SECRET_KEY', str_repeat( 'k', 40 ) );
		$this->assertTrue( Ordryx_State::secret_lost() ); // (another key now)
		$this->paired();
		$this->assertSame( $this->account->secret, Ordryx_State::secret() );
	}
}
