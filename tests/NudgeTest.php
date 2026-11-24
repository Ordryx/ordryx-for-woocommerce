<?php
/**
 * The nudge: "an order changed", at most once a minute, no order data.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class NudgeTest extends Ordryx_Test_Case {

	public function test_an_order_change_schedules_one_nudge() {
		$this->connected();
		Ordryx_Nudge::changed();
		Ordryx_Nudge::changed();
		$this->assertCount( 1, $this->scheduled );
		list( $when, $group ) = $this->scheduled['ordryx_nudge'];
		$this->assertEqualsWithDelta( time(), $when, 2 );
		$this->assertSame( 'ordryx', $group );
	}

	public function test_a_nudge_waits_a_minute_after_the_last() {
		$this->connected();
		Ordryx_State::update( array( 'sent_at' => time() - 20 ) );
		Ordryx_Nudge::changed();
		$this->assertEqualsWithDelta( time() + 40, $this->scheduled['ordryx_nudge'][0], 2 );
	}

	const PAGE  = '/api/woocommerce/v1/plugin/page';
	const NUDGE = '/api/woocommerce/v1/plugin/nudge';

	public function test_nothing_is_nudged_unless_connected_or_paused() {
		Ordryx_Nudge::changed();
		$this->connected();
		foreach ( array( 'pairing', 'unknown' ) as $phase ) {
			Ordryx_State::update( array( 'phase' => $phase ) );
			Ordryx_Nudge::changed();
			Ordryx_Nudge::send();
		}
		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( array(), $this->account->calls );
	}

	public function test_while_paused_an_order_change_asks_where_the_store_stands_first() {
		// (resumed in Ordryx, the plugin
		// stayed paused until the tab or the daily check asked)
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'paused', 'off_since' => 1792700000 ) );
		Ordryx_Nudge::changed();
		$this->assertEqualsWithDelta( time(), $this->scheduled['ordryx_nudge'][0], 2 );
		unset( $this->scheduled['ordryx_nudge'] );
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => array( 'paused' => false ) ) );
		Ordryx_Nudge::send();
		$this->assertSame( 'look', $this->account->to( self::PAGE )[0]['body']['ask'] );
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertSame( 0, Ordryx_State::get()['off_since'] );
		$this->assertCount( 1, $this->account->to( self::NUDGE ) );
	}

	public function test_still_paused_sends_nothing_and_asks_again_only_after_15_minutes() {
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'paused' ) );
		$this->account->will( self::PAGE, 200, array( 'ok' => true, 'data' => array( 'paused' => true ) ) );
		Ordryx_Nudge::send();
		$this->assertSame( 'paused', Ordryx_State::phase() );
		$this->assertSame( array(), $this->account->to( self::NUDGE ) );
		$asked = Ordryx_State::get()['paused_asked'];
		$this->assertEqualsWithDelta( time(), $asked, 2 );
		// the next change waits for the 15 minutes (at most four asks an hour)…
		Ordryx_Nudge::changed();
		$this->assertSame( $asked + Ordryx_Nudge::PAUSED_CHECK, $this->scheduled['ordryx_nudge'][0] );
		// …and one run early asks nothing, leaving the later one
		Ordryx_Nudge::send();
		$this->assertCount( 1, $this->account->to( self::PAGE ) );
		$this->assertSame( $asked + Ordryx_Nudge::PAUSED_CHECK, $this->scheduled['ordryx_nudge'][0] );
	}

	public function test_a_paused_store_whose_ordryx_doesnt_answer_stays_paused() {
		// (never the check, whose account answers "connected" when the Ordryx
		// doesn't answer: a store really paused would flip to Connected)
		$this->connected();
		Ordryx_State::update( array( 'phase' => 'paused', 'off_since' => 1792700000 ) );
		$this->account->will( self::PAGE, 504, array( 'error' => 'unlinked' ) );
		Ordryx_Nudge::send();
		$this->assertSame( 'paused', Ordryx_State::phase() );
		$this->assertSame( 1792700000, Ordryx_State::get()['off_since'] );
		$this->assertSame( array(), $this->account->to( self::NUDGE ) );
		$this->assertSame( array(), $this->account->to( '/api/woocommerce/v1/plugin/check' ) );
	}

	public function test_no_nudge_while_ordryxs_key_is_deleted() {
		// (its sync could only fail: one failed sync in Ordryx's activity per change)
		$this->connected();
		unset( $this->wpdb->keys[7] );
		Ordryx_Nudge::changed();
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_no_nudge_while_ordryxs_key_is_set_to_write() {
		// (WooCommerce refuses a Write key's reads, so its sync could only fail)
		$this->connected();
		$this->wpdb->keys[7]['permissions'] = 'write';
		Ordryx_Nudge::changed();
		$this->assertSame( array(), $this->scheduled );
		$this->wpdb->keys[7]['user_id'] = 2; // (given to another person: still nudged)
		$this->wpdb->keys[7]['permissions'] = 'read';
		Ordryx_Nudge::changed();
		$this->assertCount( 1, $this->scheduled );
	}

	public function test_a_nudge_carries_no_order_data() {
		$this->connected();
		Ordryx_Nudge::send();
		list( $call ) = $this->account->to( '/api/woocommerce/v1/plugin/nudge' );
		$this->assertTrue( $call['signed'] );
		$this->assertSame( array( 'site', 'nonce' ), array_keys( $call['body'] ) ); // (this site's address only)
		$this->assertSame( 'https://shop.example.com', $call['body']['site'] );
		$this->assertGreaterThan( 0, Ordryx_State::get()['sent_at'] );
	}

	public function test_a_nudge_the_server_didnt_take_is_tried_again_and_said_on_the_tab() {
		$this->connected();
		$this->account->down = true;
		Ordryx_Nudge::send();
		$since = Ordryx_State::get()['failing_since'];
		$this->assertGreaterThan( 0, $since );
		$this->assertEqualsWithDelta( time() + 300, $this->scheduled['ordryx_nudge'][0], 2 );
		unset( $this->scheduled['ordryx_nudge'] );
		Ordryx_Nudge::send();
		$this->assertSame( $since, Ordryx_State::get()['failing_since'] ); // since the first
		$this->account->down = false;
		Ordryx_Nudge::send();
		$this->assertSame( 0, Ordryx_State::get()['failing_since'] );
	}

	public function test_only_the_check_decides_the_account_no_longer_knows_the_store() {
		$this->connected();
		$this->account->will( '/api/woocommerce/v1/plugin/nudge', 401, array( 'error' => 'time' ) );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 200, array( 'state' => 'connected' ) );
		Ordryx_Nudge::send();
		$this->assertSame( 'connected', Ordryx_State::phase() );
		$this->assertArrayHasKey( 'ordryx_nudge', $this->scheduled ); // tried again

		unset( $this->scheduled['ordryx_nudge'] );
		$this->account->will( '/api/woocommerce/v1/plugin/nudge', 404 );
		$this->account->will( '/api/woocommerce/v1/plugin/check', 404, array( 'error' => 'store' ) );
		Ordryx_Nudge::send();
		$this->assertSame( 'unknown', Ordryx_State::phase() );
		$this->assertArrayNotHasKey( 'ordryx_nudge', $this->scheduled );
		$this->assertArrayHasKey( 7, $this->wpdb->keys );
	}

	public function test_a_failed_nudge_is_tried_again_while_its_own_action_is_running() {
		// (Action Scheduler counts the running action as scheduled)
		$this->connected();
		$this->running['ordryx_nudge'] = true;
		$this->account->down           = true;
		Ordryx_Nudge::send();
		$this->assertEqualsWithDelta( time() + 300, $this->scheduled['ordryx_nudge'][0], 2 );
	}

	public function test_nudges_never_pile_up_while_one_runs() {
		// (a running one answered "next scheduled" with true)
		$this->connected();
		$this->running['ordryx_nudge'] = true;
		Ordryx_Nudge::changed();
		$first = $this->scheduled['ordryx_nudge'];
		$this->scheduled['ordryx_nudge'][0] += 0; // still pending
		Ordryx_Nudge::changed();
		$this->assertSame( $first, $this->scheduled['ordryx_nudge'] );
	}

	public function test_a_nudge_within_a_minute_of_the_last_waits() {
		$this->connected();
		Ordryx_State::update( array( 'sent_at' => time() - 10 ) );
		Ordryx_Nudge::send();
		$this->assertSame( array(), $this->account->calls );
		$this->assertEqualsWithDelta( time() + 50, $this->scheduled['ordryx_nudge'][0], 2 );
	}

	public function test_an_order_changed_while_a_nudge_is_sent_gets_one_of_its_own() {
		$this->connected();
		$this->running['ordryx_nudge'] = true;
		Ordryx_Nudge::changed();
		$this->assertArrayHasKey( 'ordryx_nudge', $this->scheduled );
	}

	public function test_a_post_is_a_nudge_only_if_its_an_order() {
		$this->connected();
		\Brain\Monkey\Functions\when( 'get_post_type' )->alias(
			function ( $id ) {
				return 5 === $id ? 'shop_order' : 'post';
			}
		);
		Ordryx_Nudge::post_changed( 4 );
		$this->assertSame( array(), $this->scheduled );
		Ordryx_Nudge::post_changed( 5 );
		$this->assertArrayHasKey( 'ordryx_nudge', $this->scheduled );
	}

	public function test_a_copy_schedules_and_sends_no_nudge() {
		// (a copy of the site: its orders aren't the store's; a nudge copied with
		// the database runs and asks nobody)
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		Ordryx_Nudge::changed();
		$this->assertSame( array(), $this->scheduled );
		Ordryx_Nudge::send();
		$this->assertSame( array(), $this->account->calls );
		$this->assertSame( 0, Ordryx_State::get()['failing_since'] );
	}

	public function test_a_nudge_refused_as_a_copy_is_never_tried_again() {
		$this->connected();
		$this->account->site = 'https://elsewhere.example.com'; // (the account knows the store there)
		Ordryx_Nudge::send();
		$this->assertCount( 1, $this->account->calls );
		$this->assertSame( 0, Ordryx_State::get()['failing_since'] ); // (never "late")
		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( 0, Ordryx_State::get()['sent_at'] );
	}
}
