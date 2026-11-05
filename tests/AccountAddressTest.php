<?php
/**
 * The account's address for development (ORDRYX_ACCOUNT_URL): https only,
 * this computer's too (WooCommerce posts the key only to an https
 * callback_url). Each in its own process (a constant can't be unset).
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AccountAddressTest extends Ordryx_Test_Case {

	public function test_https_elsewhere_is_taken() {
		define( 'ORDRYX_ACCOUNT_URL', 'https://account.staging.ordryx.com/' );
		$this->assertSame( 'https://account.staging.ordryx.com', Ordryx_Account::base() );
	}

	public function test_http_to_this_computer_is_not_taken() {
		// (the local store found it: WooCommerce refuses an http callback_url,
		// "The callback_url needs to be over SSL", so Allow could never finish)
		define( 'ORDRYX_ACCOUNT_URL', 'http://127.0.0.1:9600' );
		$this->assertSame( 'https://account.ordryx.com', Ordryx_Account::base() );
	}

	public function test_https_to_this_computer_is_taken() {
		define( 'ORDRYX_ACCOUNT_URL', 'https://127.0.0.1:9112' );
		$this->assertSame( 'https://127.0.0.1:9112', Ordryx_Account::base() );
		$this->assertSame( 'https://127.0.0.1:9112/api/woocommerce/v1/key', Ordryx_Account::key_url() );
	}

	public function test_http_localhost_is_not_taken_either() {
		define( 'ORDRYX_ACCOUNT_URL', 'http://localhost:9600' );
		$this->assertSame( 'https://account.ordryx.com', Ordryx_Account::base() );
	}

	public function test_http_elsewhere_is_not() {
		define( 'ORDRYX_ACCOUNT_URL', 'http://account.example.com' );
		$this->assertSame( 'https://account.ordryx.com', Ordryx_Account::base() );
	}

	public function test_credentials_or_a_query_are_not() {
		define( 'ORDRYX_ACCOUNT_URL', 'https://u:p@account.example.com' );
		$this->assertSame( 'https://account.ordryx.com', Ordryx_Account::base() );
	}
}
