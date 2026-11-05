<?php
/**
 * An order shipped from Ordryx: believed only through Ordryx's
 * server and signed by this store's own Ordryx, then marked shipped once with
 * its tracking where the store keeps it.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

use Brain\Monkey\Functions;

class ShippedTest extends Ordryx_Test_Case {

	/** @var string The Ordryx's Link key pair (sodium). */
	private $pair;

	/** @var array Orders by id. */
	private $orders = array();

	protected function setUp(): void {
		parent::setUp();
		$this->connected();
		$this->pair = sodium_crypto_sign_keypair();
		$state      = Ordryx_State::get();
		$state['ordryx']['key'] = self::b64( sodium_crypto_sign_publickey( $this->pair ) );
		$state['ordryx']['key_checked'] = true;
		$this->options[ Ordryx_State::OPTION ] = $state;
		$this->orders = array( 4211 => new WC_Order( array(), 'USD', 4211, 'processing' ) );
		$this->orders[4211]->items = array( 501 => 2 );
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				// (a fresh read: the stored order, never a change left unsaved)
				return isset( $this->orders[ $id ] ) ? $this->orders[ $id ]->fresh() : false;
			}
		);
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
				'wc-shipped'    => 'Shipped',
			)
		);
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	/** An order waiting to ship, as Ordryx lists its lines. */
	private function order( $id ) {
		$order        = new WC_Order( array(), 'USD', $id, 'processing' );
		$order->items = array( 501 => 2 );
		return $order;
	}

	private static function b64( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private function message( array $more = array() ) {
		return json_encode(
			array_merge(
				array(
					'v'         => 1,
					'install'   => 'in_1',
					'store'     => 'https://shop.example.com',
					'shipment'  => str_repeat( 'a', 32 ),
					'order'     => 4211,
					'number'    => '#4211',
					'status'    => 'completed',
					'shipped'   => array( 'completed' ),
					'items'     => array( '501' => 2 ),
					'carrier'   => 'USPS',
					'tracking'  => '9400100000000000000000',
					'url'       => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400100000000000000000',
					'at'        => time() - 60,
					'by'        => 'Sam',
					'signed_at' => time(),
				),
				$more
			)
		);
	}

	/** The request as Ordryx's server relays it: the message signed by the Ordryx, the whole by the site secret. */
	private function relay( $payload, array $opts = array() ) {
		$key   = isset( $opts['key'] ) ? $opts['key'] : sodium_crypto_sign_secretkey( $this->pair );
		$sig   = self::b64( sodium_crypto_sign_detached( "ordryx-link/1\nwoocommerce_store_shipped\n" . hash( 'sha256', $payload ), $key ) );
		$raw   = json_encode( array( 'payload' => $payload, 'signature' => isset( $opts['sig'] ) ? $opts['sig'] : $sig ) );
		$sent  = (string) ( isset( $opts['time'] ) ? $opts['time'] : time() );
		$outer = base64_encode( hash_hmac( 'sha256', "relay-shipped\n" . $sent . "\n" . $raw, isset( $opts['secret'] ) ? $opts['secret'] : $this->account->secret, true ) );
		return Ordryx_Shipped::take( isset( $opts['store'] ) ? $opts['store'] : $this->account->store, $sent, $outer, $raw );
	}

	public function test_a_shipped_order_is_marked_shipped_once_with_a_note_for_its_tracking() {
		list( $status, $body ) = $this->relay( $this->message() );
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), array( $status, $body ) );
		$o = $this->orders[4211];
		$this->assertSame( 'completed', $o->status );
		$this->assertSame( 'Marked shipped by Ordryx (Sam).', $o->statuses[0][1] );
		$this->assertCount( 1, $o->notes );
		$this->assertSame( 1, $o->notes[0][1] ); // (to the customer)
		$this->assertStringContainsString( '9400100000000000000000', $o->notes[0][0] );
		// a repeat: answered as before, nothing done again
		$o->status = 'processing';
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message() ) );
		$this->assertCount( 1, $o->statuses );
	}

	public function test_only_through_ordryxs_server_and_only_signed_by_this_stores_ordryx() {
		$other = sodium_crypto_sign_keypair();
		$this->assertSame( 409, $this->relay( $this->message(), array( 'key' => sodium_crypto_sign_secretkey( $other ) ) )[0] ); // (the server's own key: refused)
		$this->assertSame( 401, $this->relay( $this->message(), array( 'secret' => str_repeat( 'x', 32 ) ) )[0] );
		$this->assertSame( 401, $this->relay( $this->message(), array( 'time' => time() - 600 ) )[0] );
		$this->assertSame( 404, $this->relay( $this->message(), array( 'store' => 'st_other' ) )[0] );
		$this->assertSame( 409, $this->relay( $this->message(), array( 'sig' => 'bad' ) )[0] );
		$this->assertSame( 409, $this->relay( $this->message( array( 'store' => 'https://another.example.com' ) ) )[0] );
		$this->assertSame( array( 409, array( 'result' => 'time' ) ), $this->relay( $this->message( array( 'signed_at' => time() - 900 ) ) ) ); // (a clock off: never "key")
		$this->assertSame( 'processing', $this->orders[4211]->status );
	}

	public function test_a_key_nobody_matched_by_eye_marks_nothing() {
		$state = Ordryx_State::get();
		$state['ordryx']['key_checked'] = false; // (handed over by the server, never compared)
		$this->options[ Ordryx_State::OPTION ] = $state;
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 'processing', $this->orders[4211]->status );
	}

	public function test_a_store_paired_before_ordryx_could_sign_says_so() {
		$state = Ordryx_State::get();
		unset( $state['ordryx']['key'] );
		$this->options[ Ordryx_State::OPTION ] = $state;
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message() ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'connect again with a new code', $html );
	}

	public function test_an_order_already_shipped_closed_or_unknown_changes_nothing() {
		$this->orders[4211]->status = 'shipped';
		$this->assertSame( array( 200, array( 'result' => 'already' ) ), $this->relay( $this->message( array( 'shipped' => array( 'completed', 'shipped' ) ) ) ) );
		$this->orders[4211]->status = 'refunded';
		$this->assertSame( array( 409, array( 'result' => 'closed' ) ), $this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ) ) ) ) );
		$this->assertSame( array( 404, array( 'result' => 'unknown' ) ), $this->relay( $this->message( array( 'shipment' => str_repeat( 'c', 32 ), 'order' => 99 ) ) ) );
		$this->assertSame( array(), $this->orders[4211]->statuses );
	}

	public function test_the_plugins_own_paused_phase_never_refuses_what_its_ordryx_sends() {
		// (resumed in Ordryx, the tab still
		// said Paused for up to a day, and every shipment was refused "paused".
		// Ordryx never sends one while it's paused, signed by its own key.)
		Ordryx_State::update( array( 'phase' => 'paused', 'off_since' => time() - 3600 ) );
		$gen = (int) ( $this->options[ Ordryx_State::LOOK_GEN ] ?? 0 );
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 'completed', $this->orders[4211]->status );
		$this->assertSame( $gen + 1, $this->options[ Ordryx_State::LOOK_GEN ] ); // (the tab asks afresh, and follows)
		$this->assertSame( 'paused', Ordryx_State::phase() ); // (never moved by the message itself)
	}

	public function test_a_deleted_ordryx_key_marks_nothing_and_is_told_again_later() {
		// (the tab says nothing flows once the shop deletes Ordryx's key)
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		unset( $this->wpdb->keys[7] );
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->assertSame( array( 409, array( 'result' => 'nokey' ) ), $this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212 ) ) ) );
		$this->assertSame( 'processing', $this->orders[4212]->status );
		$this->assertSame( array(), $this->orders[4212]->notes );
		// a repeat of one finished before is still answered as done
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message() ) );
	}

	public function test_another_key_that_took_the_deleted_keys_id_isnt_ordryxs() {
		// (MySQL 5.7's InnoDB, after a restart, gives a new key
		// the id of a deleted newest one)
		unset( $this->wpdb->keys[7] );
		$this->wpdb->add_key( 7, 'ck_' . str_repeat( 'e', 40 ), 1, 'Zapier - API (2026-10-27 09:00:00)', 'read_write' );
		$this->assertTrue( Ordryx_Connection::key_gone() );
		$this->assertFalse( Ordryx_Connection::key_changed() );
		$this->assertSame( array( 409, array( 'result' => 'nokey' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 'processing', $this->orders[4211]->status );
		// (nor deleted as Ordryx's, even named so)
		$this->wpdb->keys[7]['description'] = 'Ordryx - API (2026-10-27 09:00:00)';
		$this->wpdb->keys[7]['permissions'] = 'read';
		$this->assertNull( Ordryx_Keys::row( 7 ) );
		$this->assertFalse( Ordryx_Keys::delete( 7 ) );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_check_marks_the_key_it_matches() {
		unset( $this->options[ Ordryx_State::OPTION ]['key_mark'] ); // (connected before the mark was kept)
		$this->assertSame( array(), Ordryx_State::get()['key_mark'] );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 7 ) );
		Ordryx_Connection::check();
		$this->assertSame( array( 'end' => '2345678' ), Ordryx_State::get()['key_mark'] );
	}

	public function test_a_key_given_to_another_person_is_still_ordryxs() {
		// (WooCommerce's Edit key screen gives a key to another
		// user, as a shop does before deleting the one who connected, whose keys
		// WooCommerce deletes with them; the key still reads, now as them)
		$this->wpdb->keys[7]['user_id'] = 2;
		$this->assertFalse( Ordryx_Connection::key_gone() );
		$this->assertFalse( Ordryx_Connection::key_changed() );
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		$this->assertSame( 'completed', $this->orders[4211]->status );
		// (a mark kept with its person by a test build of 0.5.3: its end alone)
		Ordryx_State::update(
			array(
				'key_mark' => array(
					'user' => 1,
					'end'  => '2345678',
				),
			)
		);
		$this->assertFalse( Ordryx_Connection::key_gone() );
		$this->assertSame( 'done', Ordryx_Connection::ask( 'disconnect' ) ); // (not "done_nokey")
		$this->assertArrayNotHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_write_key_still_ships_but_isnt_said_to_read() {
		// (WooCommerce refuses every read made with a Write key)
		$this->wpdb->keys[7]['permissions'] = 'write';
		$this->assertFalse( Ordryx_Connection::key_gone() );
		$this->assertFalse( Ordryx_Connection::key_changed() );
		$this->assertTrue( Ordryx_Connection::key_write_only() );
		$this->assertSame( 200, $this->relay( $this->message() )[0] ); // (the plugin writes it, never the key)
		$this->wpdb->keys[7]['permissions'] = 'read_write';
		$this->assertFalse( Ordryx_Connection::key_write_only() );
		$this->assertTrue( Ordryx_Connection::key_changed() );
	}

	public function test_a_renamed_or_read_write_key_still_ships() {
		// (WooCommerce lets the shop rename Ordryx's key or give it Read/Write: it
		// still reads, so it isn't deleted)
		$this->wpdb->keys[7]['description'] = 'Our order reader';
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		$this->wpdb->keys[7]['permissions'] = 'read_write';
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->assertSame( 200, $this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212 ) ) )[0] );
	}

	public function test_with_no_key_id_yet_a_listed_key_counts() {
		Ordryx_State::update( array( 'key_id' => 0 ) );
		$this->options[ Ordryx_State::KEYS ] = array( 7 );
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		unset( $this->wpdb->keys[7] );
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$second = $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212 ) );
		$this->assertSame( array( 409, array( 'result' => 'nokey' ) ), $this->relay( $second ) );
		unset( $this->options[ Ordryx_State::KEYS ] ); // (nothing listed: not known, so not gone)
		$this->assertSame( 200, $this->relay( $second )[0] );
	}

	/**
	 * (in a PHP of its own: the tracking plugin's function, once defined, stays)
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_tracking_goes_to_a_tracking_plugin_when_the_store_has_one() {
		$got = array();
		Functions\when( 'wc_st_add_tracking_number' )->alias(
			function ( $id, $number, $provider, $date, $url ) use ( &$got ) {
				$got[] = array( $id, $number, $provider, $url, $date );
			}
		);
		$at             = time() - 60;
		list( , $body ) = $this->relay( $this->message( array( 'at' => $at ) ) );
		$this->assertSame( 'shipment-tracking', $body['where'] );
		$this->assertSame( 4211, $got[0][0] );
		$this->assertSame( array( '9400100000000000000000', 'USPS' ), array( $got[0][1], $got[0][2] ) );
		$this->assertSame( $at, $got[0][4] ); // (Unix seconds, as its own docs take it)
		$this->assertSame( array(), $this->orders[4211]->notes ); // (no note when a tracking plugin has it)
		// a number reaches it with its spaces taken out, words
		// sent with one never (round 2)
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212, 'carrier' => 'UPS', 'tracking' => '1Z 999 AA1 0123 4567 84', 'url' => '' ) ) );
		$this->assertSame( '1Z999AA10123456784', $got[1][1] );
		$this->orders[4213]        = new WC_Order( array(), 'USD', 4213, 'processing' );
		$this->orders[4213]->items = array( 501 => 2 );
		list( , $body ) = $this->relay( $this->message( array( 'shipment' => str_repeat( 'c', 32 ), 'order' => 4213, 'tracking' => 'CALL 1-800-555-0100 TO PAY DUTY NOW' ) ) );
		$this->assertSame( 'none', $body['where'] );
		$this->assertCount( 2, $got );
	}

	public function test_no_tracking_number_just_ships_it() {
		list( , $body ) = $this->relay( $this->message( array( 'tracking' => '', 'url' => '' ) ) );
		$this->assertSame( 'none', $body['where'] );
		$this->assertSame( 'completed', $this->orders[4211]->status );
	}

	public function test_what_the_far_end_sends_is_cleaned_and_a_status_unknown_here_becomes_completed() {
		$this->relay(
			$this->message(
				array(
					'status'  => 'delivered',
					'by'      => "<b>Sam</b>\n",
					'carrier' => '<script>x</script>',
				)
			)
		);
		$o = $this->orders[4211];
		$this->assertSame( 'completed', $o->status );
		$this->assertStringNotContainsString( '<', $o->statuses[0][1] . $o->notes[0][0] );
	}

	public function test_only_completed_whatever_status_the_message_names() {
		// (the readme and the consent screen say Completed; "shipped" is registered here)
		$this->relay( $this->message( array( 'status' => 'shipped' ) ) );
		$this->assertSame( 'completed', $this->orders[4211]->status );
	}

	public function test_a_carrier_with_a_phone_number_or_a_sentence_isnt_a_carrier() {
		foreach ( array( 'Call 1-800-555-0100 now', 'Please pay the duty now' ) as $i => $name ) {
			$id                  = 4300 + $i;
			$this->orders[ $id ] = $this->order( $id );
			$this->relay( $this->message( array( 'shipment' => str_repeat( (string) $i, 32 ), 'order' => $id, 'carrier' => $name, 'url' => '' ) ) );
			$this->assertSame( 'Your order has shipped. Tracking number: 9400100000000000000000.', $this->orders[ $id ]->notes[0][0] );
		}
	}

	public function test_real_carrier_names_still_pass() {
		foreach ( array( 'Canada Post', 'J&T Express', '17TRACK', '4PX', 'Royal Mail Group Ltd' ) as $i => $name ) {
			$id                  = 4300 + $i;
			$this->orders[ $id ] = $this->order( $id );
			$this->relay( $this->message( array( 'shipment' => str_repeat( (string) $i, 32 ), 'order' => $id, 'carrier' => $name, 'url' => '' ) ) );
			$this->assertStringContainsString( 'with ' . $name . '.', $this->orders[ $id ]->notes[0][0] );
		}
	}

	public function test_only_the_carriers_own_page_for_this_number_is_linked() {
		$cases = array(
			'https://www.fedex.com/CALL-18005550100-TO-PAY', // (the carrier's site, another page)
			'https://www.ups.com/track?tracknum=1Z999',      // (its page, another number)
			'https://www.ups.com.evil.example/track?tracknum=9400100000000000000000',
		);
		foreach ( $cases as $i => $url ) {
			$id                  = 4300 + $i;
			$this->orders[ $id ] = $this->order( $id );
			$this->relay( $this->message( array( 'shipment' => str_repeat( (string) $i, 32 ), 'order' => $id, 'url' => $url ) ) );
			$this->assertStringNotContainsString( 'http', $this->orders[ $id ]->notes[0][0] );
		}
	}

	public function test_a_request_that_dies_changing_the_status_adds_no_second_note() {
		$this->orders[4211]->dies = true;
		try {
			$this->relay( $this->message() );
			$this->fail( 'it should have died' );
		} catch ( \Error $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$this->orders[4211]->dies = false;
		list( $status ) = $this->relay( $this->message() );
		$this->assertSame( 200, $status );
		$this->assertSame( 'completed', $this->orders[4211]->status );
		$sent = array_filter(
			$this->orders[4211]->notes,
			function ( $note ) {
				return 1 === $note[1];
			}
		);
		$this->assertCount( 1, $sent );
	}

	public function test_a_note_the_customer_already_has_isnt_added_again() {
		// (a request that died sending its email, before anything was kept)
		$this->order_notes = array( (object) array( 'content' => 'Your order has shipped with USPS. Tracking number: 9400100000000000000000. Track it at https://tools.usps.com/go/TrackConfirmAction?tLabels=9400100000000000000000' ) );
		list( $status, $body ) = $this->relay( $this->message() );
		$this->assertSame( array( 200, 'note' ), array( $status, $body['where'] ) );
		$this->assertSame( array(), $this->orders[4211]->notes );
	}

	public function test_a_status_another_plugin_moves_it_on_to_counts_as_saved() {
		// (never "couldn't change the status": Try again would send a second Completed email)
		$this->orders[4211]->moves_to = 'delivered';
		list( $status ) = $this->relay( $this->message() );
		$this->assertSame( 200, $status );
		$this->assertSame( 'delivered', $this->orders[4211]->status );
		$this->assertCount( 1, $this->orders[4211]->statuses );
	}

	public function test_tracking_goes_into_woocommerces_fulfillments_when_theyre_on() {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::$on        = true;
		\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::$saved = array();
		try {
			list( $status, $body ) = $this->relay( $this->message() );
		} finally {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$on = false;
		}
		$this->assertSame( array( 200, 'fulfillments' ), array( $status, $body['where'] ) );
		$saved = \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::$saved;
		$this->assertCount( 1, $saved );
		$this->assertSame( '9400100000000000000000', $saved[0]['set_tracking_number'] );
		$this->assertSame( 'USPS', $saved[0]['set_shipment_provider'] );
		$this->assertSame( array( array( 'item_id' => 501, 'qty' => 2 ) ), $saved[0]['set_items'] );
		$this->assertSame( 'fulfilled', $saved[0]['set_status'] );
		$this->assertSame( array(), $this->orders[4211]->notes ); // (no note as well)
	}

	public function test_fulfillments_that_break_fall_back_to_the_note() {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::$on          = true;
		\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::$breaks = true;
		try {
			list( , $body ) = $this->relay( $this->message() );
		} finally {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$on          = false;
			\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::$breaks = false;
		}
		$this->assertSame( 'note', $body['where'] );
		$this->assertCount( 1, $this->orders[4211]->notes );
	}

	public function test_the_dashboard_names_where_tracking_goes() {
		$this->account->will( '/api/woocommerce/v1/plugin/page', 200, array( 'ok' => true, 'data' => array( 'paused' => false ) ) );
		$html = $this->printed( array( 'Ordryx_Admin', 'render' ) );
		$this->assertStringContainsString( 'Shipped and tracking', $html );
		$this->assertStringContainsString( 'order note with the carrier', $html );
	}

	public function test_only_pairing_gives_the_ordryxs_key_never_a_check() {
		$key = self::b64( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected', 'key_id' => 7, 'ordryx' => array( 'name' => 'X', 'key' => $key ) ) );
		Ordryx_Connection::check();
		$this->assertNotSame( $key, Ordryx_State::get()['ordryx']['key'] );
	}

	public function test_an_order_changed_since_it_shipped_in_ordryx_is_refused() {
		$this->orders[4211]->items = array( 501 => 2, 502 => 1 ); // (a line added in WooCommerce)
		$this->assertSame( array( 409, array( 'result' => 'changed' ) ), $this->relay( $this->message() ) );
		$this->orders[4211]->items = array( 501 => 5 ); // (its count raised)
		$this->assertSame( array( 409, array( 'result' => 'changed' ) ), $this->relay( $this->message() ) );
		$this->assertSame( array( 409, array( 'result' => 'changed' ) ), $this->relay( $this->message( array( 'items' => null ) ) ) );
		$this->assertSame( array(), $this->orders[4211]->statuses );
	}

	public function test_two_sends_at_once_mark_it_once() {
		$lock = Ordryx_Lock::PREFIX . 'ship_' . str_repeat( 'a', 32 );
		$this->options[ $lock ] = ( time() + 60 ) . ':another'; // (another request holds it)
		$this->assertSame( array( 409, array( 'result' => 'busy' ) ), $this->relay( $this->message() ) );
		$this->assertSame( array(), $this->orders[4211]->statuses );
		$this->assertSame( ( time() + 60 ) . ':another', $this->options[ $lock ] ); // (theirs, left as it is)
		$this->options[ $lock ] = ( time() - 1 ) . ':died'; // (one that died)
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		$this->assertArrayNotHasKey( $lock, $this->options );
	}

	public function test_two_shipments_kept_at_once_are_both_kept() {
		// (the list of shipments done is written onto the list as stored now:
		// another request's, kept while this one was marking, stays)
		$this->requests();
		$this->relay( $this->message() ); // (this request has read the list)
		$this->options[ Ordryx_Shipped::KEPT ][ str_repeat( 'c', 32 ) ] = array( 'result' => 'shipped', 'where' => 'note' ); // (another request)
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212 ) ) );
		$this->assertSame( array( str_repeat( 'a', 32 ), str_repeat( 'c', 32 ), str_repeat( 'b', 32 ) ), array_keys( $this->options[ Ordryx_Shipped::KEPT ] ) );
	}

	public function test_a_repeat_of_one_done_is_answered_as_done_even_once_paused() {
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message() ) );
	}

	public function test_a_tracking_number_thats_more_than_letters_and_digits_never_reaches_the_customer() {
		// (carrier Other's number went into WooCommerce's email to the customer as typed)
		list( $status, $body ) = $this->relay( $this->message( array( 'carrier' => 'Other', 'tracking' => 'Pay duty at https://evil.example/pay', 'url' => '' ) ) );
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'none' ) ), array( $status, $body ) );
		$o = $this->orders[4211];
		$this->assertSame( array(), $o->notes ); // (nothing to the customer)
		$this->assertSame( 'completed', $o->status );
		$this->assertSame( 'Marked shipped by Ordryx (Sam). Its tracking number wasn\'t letters and digits (40 at most, a digit among them and 6 letters at most, dashes only between digits and with one letter at most), so it wasn\'t given to the customer.', $o->statuses[0][1] );
		$this->assertStringNotContainsString( 'evil', $o->statuses[0][1] );
		// letters and digits, a digit among them, 6 letters at most, a dash
		// between digits (with one letter at most): given, with its spaces
		// taken out
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212, 'carrier' => 'Canada Post', 'tracking' => 'D 1001-1234', 'url' => '' ) ) );
		$this->assertSame( 'Your order has shipped with Canada Post. Tracking number: D1001-1234.', $this->orders[4212]->notes[0][0] );
		$this->assertSame( 'Marked shipped by Ordryx (Sam).', $this->orders[4212]->statuses[0][1] );
		$this->orders[4213]        = new WC_Order( array(), 'USD', 4213, 'processing' );
		$this->orders[4213]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'c', 32 ), 'order' => 4213, 'tracking' => str_repeat( '9', 41 ) ) ) );
		$this->assertSame( array(), $this->orders[4213]->notes ); // (41: too long, and its link with it)
	}

	public function test_words_sent_as_a_tracking_number_never_reach_the_customer() {
		// (words, digits and dashes passed, spaces and all;
		// round 2: joined by dashes, with the spaces taken out, they still read
		// as a sentence; round 3: a short word and a phone number, a dash
		// between every two digits, still passed)
		$id = 4211;
		foreach ( array( 'CALL 1-800-555-0100 TO PAY DUTY NOW', 'CALL-1-800-555-0100-TO-PAY-DUTY', 'PAY-DUTY-AT-18005550100', 'PAY-DUTY-NOW', 'CALL 1-800-555-0100', 'TEXT1-888-555-0199' ) as $n => $typed ) {
			if ( $n ) {
				$this->orders[ $id ]        = new WC_Order( array(), 'USD', $id, 'processing' );
				$this->orders[ $id ]->items = array( 501 => 2 );
			}
			$this->relay( $this->message( array( 'shipment' => str_repeat( (string) $n, 32 ), 'order' => $id, 'carrier' => 'Other', 'tracking' => $typed, 'url' => '' ) ) );
			$this->assertSame( array(), $this->orders[ $id ]->notes, $typed );
			$this->assertSame( 'completed', $this->orders[ $id ]->status );
			$this->assertStringContainsString( 'Its tracking number wasn\'t letters and digits', $this->orders[ $id ]->statuses[0][1] );
			++$id;
		}
	}

	public function test_the_one_table_of_tracking_numbers() {
		// (Ordryx's rule is tested with the same table: test_shipped.py)
		$table = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/tracking-numbers.json' ), true );
		foreach ( $table['given'] as $n ) {
			$this->assertSame( 1, preg_match( Ordryx_Shipped::NUMBER, $n ), $n );
		}
		foreach ( $table['refused'] as $n ) {
			$this->assertSame( 0, preg_match( Ordryx_Shipped::NUMBER, $n ), $n );
		}
	}

	public function test_a_carrier_name_that_could_be_a_link_is_left_out() {
		$this->relay( $this->message( array( 'carrier' => 'evil.example', 'url' => '' ) ) );
		$this->assertSame( 'Your order has shipped. Tracking number: 9400100000000000000000.', $this->orders[4211]->notes[0][0] );
		// (the private note says so, as the readme promises)
		$this->assertSame( 'Marked shipped by Ordryx (Sam). Its carrier\'s name wasn\'t a plain name, so it wasn\'t given to the customer.', $this->orders[4211]->statuses[0][1] );
		$this->assertStringNotContainsString( 'evil', $this->orders[4211]->statuses[0][1] );
		// Ordryx's own "Other" reads badly in the sentence: left out too
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212, 'carrier' => 'Other' ) ) );
		$this->assertSame( 'Your order has shipped. Tracking number: 9400100000000000000000. Track it at https://tools.usps.com/go/TrackConfirmAction?tLabels=9400100000000000000000', $this->orders[4212]->notes[0][0] );
		$this->assertSame( 'Marked shipped by Ordryx (Sam).', $this->orders[4212]->statuses[0][1] ); // ("Other": nothing left out)
	}

	public function test_a_status_woocommerce_couldnt_change_is_refused_and_tracked_once() {
		// (update_status() failing was ignored and "shipped" answered)
		$o              = $this->orders[4211];
		$o->fail_status = true;
		$this->assertSame( array( 409, array( 'result' => 'status' ) ), $this->relay( $this->message() ) );
		$this->assertCount( 2, $o->notes ); // (the customer's tracking note, and WooCommerce's own failure note)
		$this->assertSame( 1, $o->notes[0][1] );
		$this->assertSame( 'processing', $o->status );
		// told again at once by an Ordryx that sends it at every sync: not tried
		// again (no more failure notes), until an hour has passed…
		$this->assertSame( array( 409, array( 'result' => 'status' ) ), $this->relay( $this->message() ) );
		$this->assertCount( 2, $o->notes );
		// …or a person pressed Try again in Ordryx
		$o->fail_status = false;
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message( array( 'again' => true ) ) ) );
		$this->assertCount( 2, $o->notes ); // (no second note to the customer)
		$this->assertSame( 'completed', $o->status );
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message() ) ); // (a repeat)
	}

	public function test_a_status_woocommerce_answered_true_to_but_never_saved_is_refused() {
		// (WooCommerce 11.2 catches what a save throws and
		// update_status() still answers true; only the stored order says)
		$o              = $this->orders[4211];
		$o->fail_status = true;
		$this->assertSame( array( 409, array( 'result' => 'status' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 'processing', $o->status );
		// (the order in hand said completed, its change left unsaved, as
		// WooCommerce's does: only the stored order, read afresh, says otherwise)
		$this->assertSame( 'Error saving order ID 4211', $o->notes[1][0] );
		$this->assertSame( 'tracked', $this->options[ Ordryx_Shipped::KEPT ][ str_repeat( 'a', 32 ) ]['result'] );
		// and the rarer false it does answer
		$this->orders[4212]              = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items       = array( 501 => 2 );
		$this->orders[4212]->fail_status = 'false';
		$this->assertSame( array( 409, array( 'result' => 'status' ) ), $this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212 ) ) ) );
	}

	public function test_a_status_another_plugin_moves_on_from_is_still_shipped() {
		// (a hook moving Completed on to a status Ordryx counts shipped)
		$o = new class( array(), 'USD', 4211, 'processing' ) extends WC_Order {
			public function update_status( $to, $note = '' ) {
				parent::update_status( $to, $note );
				$this->status = 'shipped';
				return true;
			}
		};
		$o->items            = array( 501 => 2 );
		$this->orders[4211] = $o;
		$this->assertSame( 200, $this->relay( $this->message( array( 'shipped' => array( 'shipped' ) ) ) )[0] );
	}

	public function test_a_shipment_waiting_on_its_status_outlives_200_later_ones() {
		// (the cap dropped it, and Try again then added a second
		// tracking note, which WooCommerce emails)
		$o              = $this->orders[4211];
		$o->fail_status = true;
		$this->relay( $this->message() );
		$this->assertCount( 2, $o->notes );
		for ( $i = 1; $i <= Ordryx_Shipped::KEEP; $i++ ) { // (shipped since)
			$this->options[ Ordryx_Shipped::KEPT ][ sprintf( '%032x', $i ) ] = array( 'result' => 'shipped', 'where' => 'note' );
		}
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212 ) ) );
		$kept = $this->options[ Ordryx_Shipped::KEPT ];
		$this->assertCount( Ordryx_Shipped::KEEP + 1, $kept );
		$this->assertSame( 'tracked', $kept[ str_repeat( 'a', 32 ) ]['result'] );
		$this->assertArrayNotHasKey( sprintf( '%032x', 1 ), $kept ); // (the oldest finished one went)
		$this->assertArrayHasKey( str_repeat( 'b', 32 ), $kept );
		$o->fail_status = false;
		$this->assertSame( array( 200, array( 'result' => 'shipped', 'where' => 'note' ) ), $this->relay( $this->message( array( 'again' => true ) ) ) );
		$this->assertCount( 2, $o->notes ); // (no second note to the customer)
		$this->assertCount( Ordryx_Shipped::KEEP, $this->options[ Ordryx_Shipped::KEPT ] ); // (finished now: one of the 200)
	}

	public function test_a_status_that_failed_over_an_hour_ago_is_tried_again() {
		$o              = $this->orders[4211];
		$o->fail_status = true;
		$this->relay( $this->message() );
		$ref = str_repeat( 'a', 32 );
		$this->options[ Ordryx_Shipped::KEPT ][ $ref ]['failed_at'] = time() - Ordryx_Shipped::STATUS_WAIT - 1;
		$o->fail_status = false;
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		$this->assertSame( 'completed', $o->status );
		$this->assertCount( 2, $o->notes );
	}

	/**
	 * (in a PHP of its own: the tracking plugin's function, once defined, stays)
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_advanced_shipment_tracking_gets_a_date_and_its_provider_names() {
		// (AST 4.0.5 takes the date as Y-m-d, or Unix seconds when numeric,
		// and finds a carrier by its own name: "DHL Express", never "DHL")
		$got = array();
		Functions\when( 'wp_date' )->alias(
			function ( $format, $at ) {
				return gmdate( $format, $at );
			}
		);
		Functions\when( 'ast_insert_tracking_number' )->alias(
			function ( ...$args ) use ( &$got ) {
				$got[] = $args;
			}
		);
		Functions\when( 'ast_get_tracking_items' )->alias(
			function () use ( &$got ) {
				return array_map(
					function ( $a ) {
						return array( 'tracking_number' => $a[1] );
					},
					$got
				);
			}
		);
		$at = time() - 60;
		list( , $body ) = $this->relay( $this->message( array( 'at' => $at ) ) );
		$this->assertSame( 'ast', $body['where'] );
		$this->assertSame( array( 4211, '9400100000000000000000', 'USPS', gmdate( 'Y-m-d', $at ), 0 ), $got[0] );
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'order' => 4212, 'carrier' => 'DHL', 'tracking' => '1234567890', 'url' => '' ) ) );
		$this->assertSame( 'DHL Express', $got[1][2] );
		$this->assertSame( array(), $this->orders[4211]->notes );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ast_that_kept_nothing_falls_back_to_the_note() {
		Functions\when( 'ast_insert_tracking_number' )->justReturn( null );
		Functions\when( 'ast_get_tracking_items' )->justReturn( array() );
		list( , $body ) = $this->relay( $this->message() );
		$this->assertSame( 'note', $body['where'] );
		$this->assertSame( 1, $this->orders[4211]->notes[0][1] );
		$this->assertStringContainsString( '9400100000000000000000', $this->orders[4211]->notes[0][0] );
	}

	public function test_only_a_carriers_own_tracking_page_reaches_the_customer() {
		$this->relay( $this->message( array( 'url' => 'https://evil.example.com/track?n=1' ) ) );
		$note = $this->orders[4211]->notes[0][0];
		$this->assertStringNotContainsString( 'evil', $note );
		$this->assertStringContainsString( '9400100000000000000000', $note );
	}

	public function test_the_fingerprint_is_the_one_ordryx_shows() {
		// (linkkeys.fingerprint: SHA-256 of the raw key, first 16 hex, upper, in fours)
		$pk = sodium_crypto_sign_publickey( $this->pair );
		$this->assertSame(
			implode( '-', str_split( strtoupper( substr( hash( 'sha256', $pk ), 0, 16 ) ), 4 ) ),
			Ordryx_Shipped::fingerprint( self::b64( $pk ) )
		);
		$this->assertSame( '', Ordryx_Shipped::fingerprint( 'short' ) );
	}

	public function test_a_shipped_naming_this_store_however_spelled_is_taken() {
		$this->assertSame( 200, $this->relay( $this->message( array( 'store' => 'https://www.shop.example.com/' ) ) )[0] );
		$this->orders[4212]        = new WC_Order( array(), 'USD', 4212, 'processing' );
		$this->orders[4212]->items = array( 501 => 2 );
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message( array( 'shipment' => str_repeat( 'd', 32 ), 'order' => 4212, 'store' => 'http://shop.example.com' ) ) ) ); // (Ordryx names it over https)
		$this->assertSame( 'processing', $this->orders[4212]->status );
	}

	public function test_a_copy_at_another_address_takes_no_relay_meant_for_the_store() {
		// a staging copy: the store's secret, key and record, its own Site
		// Address; the live store's relay sent again to it (within the 5
		// minutes) marks nothing and tells the customer nothing
		$this->options['home'] = 'https://staging.shop.example.com';
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message() ) );
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message( array( 'shipment' => str_repeat( 'b', 32 ), 'store' => 'https://staging.shop.example.com' ) ) ) ); // (nor one naming the copy: it isn't the store)
		$o = $this->orders[4211];
		$this->assertSame( 'processing', $o->status );
		$this->assertSame( array(), $o->notes );
		$this->assertSame( array(), $o->statuses );
		$this->assertArrayNotHasKey( Ordryx_Shipped::KEPT, $this->options );
		// a store whose Site Address really moved is the same: nothing until
		// it's connected at its new address
		$this->options['home'] = 'https://newshop.example.com';
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 'processing', $o->status );
		// the address put back: the store again, by itself
		$this->options['home'] = 'https://shop.example.com';
		$this->assertSame( 200, $this->relay( $this->message() )[0] );
		$this->assertSame( 'completed', $o->status );
	}

	public function test_a_copy_at_the_stores_own_address_that_wordpress_calls_staging_takes_no_relay() {
		$this->environment = 'staging';
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 'processing', $this->orders[4211]->status );
		$this->assertSame( array(), $this->orders[4211]->notes );
	}

	public function test_a_store_connected_before_its_address_was_kept_compares_its_site_address() {
		$state = Ordryx_State::get();
		unset( $state['site'] );
		$this->options[ Ordryx_State::OPTION ] = $state;
		$this->options['home']                 = 'https://newshop.example.com';
		$this->assertSame( array( 409, array( 'result' => 'key' ) ), $this->relay( $this->message() ) );
		$this->assertSame( 200, $this->relay( $this->message( array( 'store' => 'https://newshop.example.com' ) ) )[0] );
	}
}
