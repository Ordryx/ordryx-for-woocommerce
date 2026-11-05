<?php
/**
 * The processor's fee: one field, ordryx_fees, for Ordryx's key only.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class FeesTest extends Ordryx_Test_Case {

	private function ask( array $fields = array( 'id', 'ordryx_fees' ) ) {
		return new WP_REST_Request( array( '_fields' => implode( ',', $fields ) ) );
	}

	private function as_ordryx( $key ) {
		$_SERVER['PHP_AUTH_USER'] = $key;
		$_SERVER['PHP_AUTH_PW']   = 'cs_secret';
	}

	public function test_woopayments_and_stripe_fees_come_as_kept() {
		$order = new WC_Order(
			array(
				'_wcpay_transaction_fee' => '1.17',
				'_wcpay_intent_currency' => 'usd',
				'_stripe_fee'            => 2.5,
				'_stripe_currency'       => 'CAD',
				'_billing_email'         => 'someone@example.com',
			)
		);
		$this->assertSame(
			array(
				array( 'processor' => 'WooPayments', 'amount' => '1.17', 'currency' => 'USD' ),
				array( 'processor' => 'Stripe', 'amount' => '2.5', 'currency' => 'CAD' ),
			),
			Ordryx_Fees::of( $order )
		);
	}

	public function test_a_woopayments_fee_on_a_payment_in_another_currency_is_never_added() {
		// (its fee may be in the account's currency, which the order doesn't keep)
		$fees = Ordryx_Fees::of( new WC_Order( array( '_wcpay_transaction_fee' => '0.80', '_wcpay_intent_currency' => 'EUR' ), 'EUR' ) );
		$this->assertSame( 'XXX', $fees[0]['currency'] );
	}

	public function test_stripes_older_key_and_tiny_amounts() {
		$fees = Ordryx_Fees::of( new WC_Order( array( 'Stripe Fee' => '0.30' ) ) );
		$this->assertSame( array( 'Stripe', '0.30', 'USD' ), array_values( $fees[0] ) );
		$fees = Ordryx_Fees::of( new WC_Order( array( '_stripe_fee' => '1.0E-5' ) ) );
		$this->assertSame( '0.00001', $fees[0]['amount'] );
	}

	public function test_a_stripe_currency_that_isnt_one_counts_as_none() {
		$fees = Ordryx_Fees::of( new WC_Order( array( '_stripe_fee' => '0.30', '_stripe_currency' => 'euro' ), 'EUR' ) );
		$this->assertSame( 'XXX', $fees[0]['currency'] );
	}

	public function test_an_order_with_no_fee_it_knows_has_none() {
		$this->assertSame( array(), Ordryx_Fees::of( new WC_Order() ) );
		$this->assertSame( array(), Ordryx_Fees::of( new WC_Order( array( '_stripe_fee' => 'n/a' ) ) ) );
		$this->assertSame( array(), Ordryx_Fees::of( new WC_Order( array( '_stripe_fee' => '-1' ) ) ) );
	}

	public function test_a_fee_without_its_currency_is_in_the_orders_when_thats_the_stores() {
		$fees = Ordryx_Fees::of( new WC_Order( array( '_stripe_fee' => '0.59' ), 'USD' ) );
		$this->assertSame( 'USD', $fees[0]['currency'] );
	}

	public function test_the_field_is_added_for_ordryxs_key_when_asked_for() {
		$key = $this->connected();
		$this->as_ordryx( $key );
		$order    = new WC_Order( array( '_stripe_fee' => '0.59' ) );
		$response = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask() );
		$this->assertSame( '0.59', $response->get_data()['ordryx_fees'][0]['amount'] );
	}

	public function test_another_key_never_sees_the_field() {
		$this->connected();
		$this->wpdb->add_key( 9, 'ck_' . str_repeat( 'e', 40 ), 1, 'Shipping app - API (2026-01-01 00:00:00)' );
		$this->as_ordryx( 'ck_' . str_repeat( 'e', 40 ) );
		$order    = new WC_Order( array( '_stripe_fee' => '0.59' ) );
		$response = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask() );
		$this->assertArrayNotHasKey( 'ordryx_fees', $response->get_data() );
	}

	public function test_not_when_ordryxs_key_didnt_ask_for_it() {
		$this->as_ordryx( $this->connected() );
		$order    = new WC_Order( array( '_stripe_fee' => '0.59' ) );
		$response = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'status' ) ) );
		$this->assertArrayNotHasKey( 'ordryx_fees', $response->get_data() );
		$response = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, new WP_REST_Request() );
		$this->assertArrayNotHasKey( 'ordryx_fees', $response->get_data() );
	}

	public function test_the_key_is_read_the_way_woocommerce_reads_it() {
		// (the query's first, else Basic auth's with its secret: round 2)
		$key = $this->connected();
		$_GET['consumer_key']    = $key;
		$_GET['consumer_secret'] = 'cs_secret';
		$this->assertTrue( Ordryx_Keys::asking() );
		$_GET['consumer_key']     = 'ck_' . str_repeat( 'f', 40 ); // another key authenticated…
		$_SERVER['PHP_AUTH_USER'] = $key; // …with Ordryx's in the header
		$_SERVER['PHP_AUTH_PW']   = 'x';
		$this->assertFalse( Ordryx_Keys::asking() );
		unset( $_GET['consumer_key'], $_GET['consumer_secret'], $_SERVER['PHP_AUTH_PW'] );
		$this->assertFalse( Ordryx_Keys::asking() ); // a user name with no secret isn't Basic auth
	}

	public function test_the_key_is_cleaned_as_woocommerce_cleans_it() {
		// (WooCommerce checks sanitize_text_field's key: one sent with a space
		// or a line break around it still authenticates as Ordryx's, so it is
		// Ordryx's here too)
		$key = $this->connected();
		$this->as_ordryx( $key . ' ' );
		$this->assertTrue( Ordryx_Keys::asking() );
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$_GET['consumer_key']    = "\n" . $key;
		$_GET['consumer_secret'] = 'cs_secret';
		$this->assertTrue( Ordryx_Keys::asking() );
		$_GET['consumer_key'] = array( $key ); // (not text: nobody's key)
		$this->assertFalse( Ordryx_Keys::asking() );
	}

	public function test_an_older_stripe_fee_without_its_currency_is_never_added_on_another_currencys_order() {
		$fees = Ordryx_Fees::of( new WC_Order( array( 'Stripe Fee' => '0.30' ), 'EUR' ) );
		$this->assertSame( 'XXX', $fees[0]['currency'] );
	}

	public function test_the_key_must_belong_to_whoever_is_signed_in() {
		$key           = $this->connected();
		$this->user_id = 2;
		$this->as_ordryx( $key );
		$this->assertFalse( Ordryx_Keys::asking() );
	}

	public function test_paid_comes_as_woocommerce_counts_it() {
		// (a status a plugin marks paid
		// was read as unpaid in Ordryx)
		$this->as_ordryx( $this->connected() );
		$paid = function ( $status ) {
			$order = new WC_Order( array(), 'USD', 5, $status );
			return Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'ordryx_paid' ) ) )->get_data();
		};
		$this->assertSame( array( 'id' => 5, 'ordryx_paid' => true ), $paid( 'processing' ) ); // (only what was asked for)
		$this->assertFalse( $paid( 'on-hold' )['ordryx_paid'] );
		\Brain\Monkey\Filters\expectApplied( 'woocommerce_order_is_paid_statuses' )->andReturn( array( 'processing', 'completed', 'packing' ) );
		$this->assertTrue( $paid( 'packing' )['ordryx_paid'] );
	}

	public function test_paid_only_for_ordryxs_key_when_asked() {
		$this->connected();
		$order = new WC_Order( array(), 'USD', 5, 'processing' );
		$this->as_ordryx( 'ck_' . str_repeat( 'e', 40 ) );
		$this->wpdb->add_key( 9, 'ck_' . str_repeat( 'e', 40 ), 1, 'Shipping app - API (2026-01-01 00:00:00)' );
		$this->assertArrayNotHasKey( 'ordryx_paid', Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'ordryx_paid' ) ) )->get_data() );
		$this->as_ordryx( 'ck_' . 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678' );
		$this->assertArrayNotHasKey( 'ordryx_paid', Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'ordryx_fees' ) ) )->get_data() );
		$both = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'ordryx_fees', 'ordryx_paid' ) ) )->get_data();
		$this->assertSame( array( 'id', 'ordryx_fees', 'ordryx_paid' ), array_keys( $both ) );
	}

	public function test_a_renamed_or_read_write_key_is_still_ordryxs() {
		// (WooCommerce lets the shop rename Ordryx's key or give it Read/Write: its
		// fees and paid were dropped without a word; a key of its own: asking()
		// remembers each key's answer for the request)
		$key = $this->connected( 'ck_' . str_repeat( 'b', 40 ) );
		$this->as_ordryx( $key );
		$this->wpdb->keys[7]['description'] = 'Our order reader';
		$this->wpdb->keys[7]['permissions'] = 'read_write';
		$order = new WC_Order( array( '_stripe_fee' => '0.59' ), 'USD', 5, 'processing' );
		$data  = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'ordryx_fees', 'ordryx_paid' ) ) )->get_data();
		$this->assertSame( '0.59', $data['ordryx_fees'][0]['amount'] );
		$this->assertTrue( $data['ordryx_paid'] );
	}

	public function test_a_key_given_to_another_person_is_still_ordryxs() {
		// (WooCommerce's Edit key screen gives a key to another
		// user; WooCommerce then authenticates it as them)
		$key                            = $this->connected( 'ck_' . str_repeat( 'c', 40 ) );
		$this->wpdb->keys[7]['user_id'] = 2;
		$this->user_id                  = 2;
		$this->as_ordryx( $key );
		$order = new WC_Order( array( '_stripe_fee' => '0.59' ), 'USD', 5, 'processing' );
		$data  = Ordryx_Fees::add( new WP_REST_Response( array( 'id' => 5 ) ), $order, $this->ask( array( 'id', 'ordryx_fees', 'ordryx_paid' ) ) )->get_data();
		$this->assertSame( '0.59', $data['ordryx_fees'][0]['amount'] );
		$this->assertTrue( $data['ordryx_paid'] );
	}

	public function test_asked_reads_fields_as_a_list_or_text() {
		$this->assertTrue( Ordryx_Fees::asked( 'id, ordryx_fees' ) );
		$this->assertTrue( Ordryx_Fees::asked( array( 'id', 'ordryx_fees' ) ) );
		$this->assertFalse( Ordryx_Fees::asked( 'id,ordryx_fees_x' ) );
		$this->assertFalse( Ordryx_Fees::asked( null ) );
	}
}
