<?php
/**
 * The proof route: the pairing waiting here, signed with its secret, and
 * nothing for anything else.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class ProofTest extends Ordryx_Test_Case {

	const NONCE = '0123456789abcdef0123456789abcdef';

	private function paired() {
		$this->account->will( '/api/woocommerce/v1/pair', 200, $this->account->paired() );
		Ordryx_Connection::pair( 'R7WT-4HQN', true );
	}

	public function test_the_pairing_waiting_here_is_proven_with_its_secret() {
		$this->paired();
		$got  = Ordryx_Proof::answer( new WP_REST_Request( array( 'store' => 'st_4f9a', 'nonce' => self::NONCE ) ) );
		$want = base64_encode( hash_hmac( 'sha256', "proof\nst_4f9a\n" . self::NONCE, $this->account->secret, true ) );
		$this->assertSame( 200, $got->status );
		$this->assertSame( array( 'proof' => $want ), $got->get_data() );
		$this->assertSame( 'no-store', $got->headers['Cache-Control'] );
	}

	public function test_another_store_id_or_no_pairing_gets_nothing() {
		$this->paired();
		$this->assertSame( '', Ordryx_Proof::of( 'st_other', self::NONCE ) );
		$this->assertSame( '', Ordryx_Proof::of( 'st_4f9a', 'short' ) );
		$this->assertSame( 404, Ordryx_Proof::answer( new WP_REST_Request( array( 'store' => 'st_other', 'nonce' => self::NONCE ) ) )->status );
		$this->connected(); // (connected: no pairing waits)
		$this->assertSame( '', Ordryx_Proof::of( 'st_4f9a', self::NONCE ) );
		Ordryx_State::forget();
		$this->assertSame( '', Ordryx_Proof::of( 'st_4f9a', self::NONCE ) );
	}

	public function test_a_copy_of_the_site_signs_no_proof() {
		// (a copy made while the store was pairing holds that pairing and its
		// secret: like every other signature, it isn't made there)
		$this->paired();
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->assertSame( '', Ordryx_Proof::of( 'st_4f9a', self::NONCE ) );
		$this->assertSame( 404, Ordryx_Proof::answer( new WP_REST_Request( array( 'store' => 'st_4f9a', 'nonce' => self::NONCE ) ) )->status );
		$this->options['home'] = 'https://shop.example.com';
		$this->environment     = 'staging'; // (a copy at the store's own address)
		$this->assertSame( '', Ordryx_Proof::of( 'st_4f9a', self::NONCE ) );
		$this->environment = 'production';
		$this->assertNotSame( '', Ordryx_Proof::of( 'st_4f9a', self::NONCE ) ); // (the store again)
	}
}
