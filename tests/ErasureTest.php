<?php
/**
 * A customer's data erased in WordPress: the orders WooCommerce erased
 * are told to the store's Ordryx, by id only, until it has them.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class ErasureTest extends Ordryx_Test_Case {

	const FORGET = '/api/woocommerce/v1/plugin/forget';

	public function test_an_erased_order_is_told_by_its_id_only() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) ); // (once each)
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4212 ) );
		$this->assertSame( array( 4211, 4212 ), Ordryx_Erasure::queued() );
		$this->assertArrayHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
		Ordryx_Erasure::send();
		list( $call ) = $this->account->to( self::FORGET );
		$this->assertTrue( $call['signed'] );
		$this->assertSame( array( 'orders', 'site', 'nonce' ), array_keys( $call['body'] ) );
		$this->assertSame( array( 4211, 4212 ), $call['body']['orders'] );
		$this->assertSame( array(), Ordryx_Erasure::queued() );
	}

	public function test_the_last_send_stops_once_its_time_is_up() {
		// (nothing checked the budget the plugin's deletion
		// shares among a network's sites; each send waits 12 seconds at most)
		$this->connected();
		$this->options[ Ordryx_Erasure::QUEUE ] = range( 1, 3 * Ordryx_Erasure::AT_ONCE );
		Ordryx_Erasure::last_send( Ordryx_Clock::now() + Ordryx_Erasure::LAST_WAIT - 1 );
		$this->assertSame( array(), $this->account->to( self::FORGET ) ); // (no time for one)
		foreach ( array( 10, 20 ) as $ahead ) { // (each send takes 10 seconds to answer)
			$this->account->during(
				self::FORGET,
				function () use ( $ahead ) {
					$this->clock_on( $ahead );
				}
			);
		}
		Ordryx_Erasure::last_send( Ordryx_Clock::now() + 30 );
		$sent = $this->account->to( self::FORGET );
		$this->assertCount( 2, $sent ); // (a third would end past the 30 seconds)
		$this->assertSame( range( 1, Ordryx_Erasure::AT_ONCE ), $sent[0]['body']['orders'] );
		$this->assertSame( range( Ordryx_Erasure::AT_ONCE + 1, 2 * Ordryx_Erasure::AT_ONCE ), $sent[1]['body']['orders'] );
	}

	public function test_kept_and_tried_again_until_the_ordryx_has_them() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] );
		$this->account->will( self::FORGET, 504, array( 'error' => 'unlinked' ) );
		Ordryx_Erasure::send();
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() );
		$this->assertGreaterThan( time() + 60, $this->scheduled[ Ordryx_Erasure::HOOK ][0] );
		$this->account->down = true;
		Ordryx_Erasure::send();
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() );
		$this->account->down = false;
		Ordryx_Erasure::send();
		$this->assertSame( array(), Ordryx_Erasure::queued() );
	}

	public function test_an_erasure_while_disconnected_waits_for_connecting_again() {
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4210 ) ); // (not connected)
		$this->assertSame( array( 4210 ), Ordryx_Erasure::queued() );
		$this->assertArrayNotHasKey( Ordryx_Erasure::HOOK, $this->scheduled ); // (nobody to send to yet)
		$this->assertSame( array(), $this->account->calls );
		$this->options[ Ordryx_Erasure::QUEUE ] = array();
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		$this->account->will( self::FORGET, 404, array( 'error' => 'store' ) );
		Ordryx_Erasure::send();
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() ); // (kept: the Ordryx keeps the orders)
	}

	public function test_kept_through_a_disconnect_and_sent_once_connected_again() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		Ordryx_Connection::deactivated(); // (unschedules the plugin's actions)
		$this->assertArrayNotHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
		Ordryx_State::forget();
		Ordryx_Erasure::send();
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() );
		$this->connected();
		Ordryx_Connection::schedule_check(); // (connected again)
		$this->assertArrayHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
		Ordryx_Erasure::send();
		$this->assertSame( array(), Ordryx_Erasure::queued() );
	}

	public function test_a_long_queue_goes_a_few_hundred_at_a_time() {
		$this->connected();
		$this->options[ Ordryx_Erasure::QUEUE ] = range( 1, Ordryx_Erasure::AT_ONCE + 3 );
		Ordryx_Erasure::send();
		list( $call ) = $this->account->to( self::FORGET );
		$this->assertCount( Ordryx_Erasure::AT_ONCE, $call['body']['orders'] );
		$this->assertSame( array( Ordryx_Erasure::AT_ONCE + 1, Ordryx_Erasure::AT_ONCE + 2, Ordryx_Erasure::AT_ONCE + 3 ), Ordryx_Erasure::queued() );
	}

	public function test_no_job_is_made_at_every_page_while_it_cant_be_sent() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		$this->account->will( self::FORGET, 404, array( 'error' => 'store' ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] ); // (Action Scheduler ran it)
		Ordryx_Erasure::send();
		$this->assertGreaterThan( time() + 60, $this->scheduled[ Ordryx_Erasure::HOOK ][0] ); // (later)
		Ordryx_State::update( array( 'phase' => 'unknown' ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] );
		for ( $i = 0; $i < 3; $i++ ) {
			Ordryx_Connection::schedule_check(); // (admin_init)
		}
		$this->assertArrayNotHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
		$this->assertCount( 1, $this->account->to( self::FORGET ) );
	}

	public function test_an_admin_page_during_a_send_keeps_its_five_minutes() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		$this->account->will( self::FORGET, 504, array( 'error' => 'unlinked' ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] ); // (claimed by the runner)
		$this->running[ Ordryx_Erasure::HOOK ] = true;
		Ordryx_Connection::schedule_check(); // (the Heartbeat during the send's wait)
		Ordryx_Erasure::send();
		unset( $this->running[ Ordryx_Erasure::HOOK ] );
		$this->assertGreaterThan( time() + 60, $this->scheduled[ Ordryx_Erasure::HOOK ][0] );
	}

	public function test_a_copy_keeps_erasures_and_never_sends_them() {
		$this->connected();
		$this->options['home'] = 'https://staging.shop.example.com';
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() ); // (kept on the copy)
		$this->assertArrayNotHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
		Ordryx_Erasure::send(); // (a send copied with the database)
		Ordryx_Erasure::rearm();
		$this->assertSame( array(), $this->account->calls );
		$this->assertArrayNotHasKey( Ordryx_Erasure::HOOK, $this->scheduled );
	}

	public function test_an_erasure_queued_while_a_send_is_in_flight_stays() {
		// (the queue was read again from
		// this request's option cache once the forget was answered, and an
		// erasure queued meanwhile was deleted with the batch)
		$this->requests();
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] ); // (the runner took it)
		$this->account->during(
			self::FORGET,
			function () {
				$this->options[ Ordryx_Erasure::QUEUE ][] = 3; // (another request: WooCommerce erasing another page)
			}
		);
		Ordryx_Erasure::send();
		$this->assertSame( array( 3 ), $this->options[ Ordryx_Erasure::QUEUE ] );
		$this->assertEqualsWithDelta( time(), $this->scheduled[ Ordryx_Erasure::HOOK ][0], 2 ); // (sent next)
		$this->assertArrayNotHasKey( Ordryx_Lock::PREFIX . 'forget', $this->options ); // (let go)
	}

	public function test_erasures_from_two_requests_at_once_are_both_kept() {
		// (a request that first found no queue added its own over another's)
		$this->requests();
		$this->connected();
		$this->assertSame( array(), Ordryx_Erasure::queued() ); // (this request: none, and remembers it)
		$this->options[ Ordryx_Erasure::QUEUE ] = array( 5 ); // (another request's eraser)
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 6 ) );
		$this->assertSame( array( 5, 6 ), $this->options[ Ordryx_Erasure::QUEUE ] );
		$this->options[ Ordryx_Erasure::QUEUE ][] = 7; // (and again, after this request's own write)
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 8 ) );
		$this->assertSame( array( 5, 6, 7, 8 ), $this->options[ Ordryx_Erasure::QUEUE ] );
	}

	public function test_a_send_that_cant_take_the_lock_keeps_its_batch_to_send_again() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] );
		$held = ( time() + 60 ) . ':held';
		$this->account->during(
			self::FORGET,
			function () use ( $held ) {
				$this->options[ Ordryx_Lock::PREFIX . 'forget' ] = $held; // (another request, stuck)
			}
		);
		Ordryx_Erasure::send();
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() ); // (nothing removed: the Ordryx takes it again as before)
		$this->assertEqualsWithDelta( time(), $this->scheduled[ Ordryx_Erasure::HOOK ][0], 3 ); // (and sent again now)
		// an erasure without the lock is still added, from the queue as stored now
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4212 ) );
		$this->assertSame( array( 4211, 4212 ), Ordryx_Erasure::queued() );
		$this->assertSame( $held, $this->options[ Ordryx_Lock::PREFIX . 'forget' ] ); // (theirs, left alone)
	}

	public function test_an_erasure_refused_as_a_copy_waits_untried() {
		$this->connected();
		Ordryx_Erasure::erased( new WC_Order( array(), 'USD', 4211 ) );
		unset( $this->scheduled[ Ordryx_Erasure::HOOK ] );
		$this->account->site = 'https://elsewhere.example.com';
		Ordryx_Erasure::send();
		$this->assertCount( 1, $this->account->to( self::FORGET ) );
		$this->assertSame( array( 4211 ), Ordryx_Erasure::queued() );
		$this->assertArrayNotHasKey( Ordryx_Erasure::HOOK, $this->scheduled ); // (never tried again from here)
	}
}
