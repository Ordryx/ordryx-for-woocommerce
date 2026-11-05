<?php
/**
 * Ordryx_Lock: one request at a time holds it, a dead holder's is taken over
 * once, and a holder lets go only of its own.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

class LockTest extends Ordryx_Test_Case {

	const ROW = Ordryx_Lock::PREFIX . 'test';

	protected function tearDown(): void {
		Ordryx_Lock::release( 'test' );
		parent::tearDown();
	}

	public function test_only_one_request_takes_a_lock() {
		$this->assertTrue( Ordryx_Lock::take( 'test', 60 ) );
		$mine = $this->options[ self::ROW ];
		$this->assertMatchesRegularExpression( '/^\d+:[0-9a-f]{16}$/', $mine );
		$this->assertFalse( Ordryx_Lock::take( 'test', 60 ) ); // (the insert is refused: the name is there)
		$this->assertSame( $mine, $this->options[ self::ROW ] );
		Ordryx_Lock::release( 'test' );
		$this->assertArrayNotHasKey( self::ROW, $this->options );
		$this->assertTrue( Ordryx_Lock::take( 'test', 60 ) );
	}

	public function test_a_dead_holders_lock_is_taken_over_once() {
		$this->options[ self::ROW ] = ( time() - 1 ) . ':dead';
		$this->assertTrue( Ordryx_Lock::take( 'test', 60 ) );
		$this->assertNotSame( ( time() - 1 ) . ':dead', $this->options[ self::ROW ] );
		$this->assertFalse( Ordryx_Lock::take( 'test', 60 ) ); // (not again: it's held, and alive)
	}

	public function test_a_takeover_another_request_made_first_wins_over_this_one() {
		$this->options[ self::ROW ] = ( time() - 1 ) . ':dead';
		$this->wpdb->before_update  = function ( $name ) {
			$this->options[ $name ] = ( time() + 60 ) . ':theirs'; // (another took it over between the read and the update)
		};
		$this->assertFalse( Ordryx_Lock::take( 'test', 60 ) );
		$this->assertSame( ( time() + 60 ) . ':theirs', $this->options[ self::ROW ] );
	}

	public function test_release_leaves_another_holders_lock_in_place() {
		$this->assertTrue( Ordryx_Lock::take( 'test', 1 ) );
		$this->options[ self::ROW ] = ( time() + 60 ) . ':next'; // (ran out, and taken over by another)
		Ordryx_Lock::release( 'test' );
		$this->assertSame( ( time() + 60 ) . ':next', $this->options[ self::ROW ] );
	}

	public function test_take_waits_for_a_lock_held_briefly_then_gets_it() {
		$this->options[ self::ROW ] = ( time() + 60 ) . ':brief';
		$tries                      = 0;
		$this->wpdb->before_insert  = function ( $name ) use ( &$tries ) {
			if ( 3 === ++$tries ) {
				unset( $this->options[ $name ] ); // (its holder let go)
			}
		};
		$this->assertTrue( Ordryx_Lock::take( 'test', 60, 2 ) );
		$this->assertSame( 3, $tries );
	}

	public function test_a_lock_let_go_between_its_insert_and_its_read_is_taken_at_once() {
		$this->options[ self::ROW ] = ( time() + 60 ) . ':leaving'; // (held at the insert…)
		$this->wpdb->before_read    = function ( $name ) {
			unset( $this->options[ $name ] ); // (…and let go before it's read)
			$this->wpdb->before_read = null;
		};
		$started = microtime( true );
		$this->assertTrue( Ordryx_Lock::take( 'test', 60 ) ); // (no wait asked for, and none needed)
		$this->assertLessThan( 0.04, microtime( true ) - $started );
	}

	public function test_deleting_the_plugin_deletes_every_lock_left() {
		$this->options[ Ordryx_Lock::PREFIX . 'forget' ]                 = '1:x';
		$this->options[ 'ordryx_shipping_' . str_repeat( 'a', 32 ) ]     = time(); // (0.5.2's)
		$this->options['ordryx_lockout']                                 = 'not ours';
		Ordryx_Lock::delete_all();
		$this->assertArrayNotHasKey( Ordryx_Lock::PREFIX . 'forget', $this->options );
		$this->assertArrayNotHasKey( 'ordryx_shipping_' . str_repeat( 'a', 32 ), $this->options );
		$this->assertSame( 'not ours', $this->options['ordryx_lockout'] );
	}
}
