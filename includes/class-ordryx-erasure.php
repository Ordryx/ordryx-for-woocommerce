<?php
/**
 * A customer's personal data erased in WordPress (Tools › Erase Personal
 * Data, through WooCommerce's eraser for orders): the store's Ordryx is told
 * which orders, so it forgets their customer's name, email and ship-to at
 * once.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each order WooCommerce erases is queued (its id, nothing else) and sent to
 * Ordryx's server, which hands them to the store's Ordryx and keeps none of
 * them. Kept here, tried again every 5 minutes, until the Ordryx has them
 * (through a disconnect too: the Ordryx keeps the store's orders).
 *
 * The queue is changed under a lock (Ordryx_Lock "forget"), from the queue as
 * stored now, never this request's cached copy: an erasure queued while a
 * send waits on Ordryx's server, or by another eraser at the same moment (an
 * Administrator's, and WooCommerce's own clean-up of old orders), stays. An
 * erasure that can't get the lock within LOCK_WAIT (one left by a request
 * that died holds it LOCK_HOLD at most) is still added, onto the queue as
 * stored now, so two such erasers at once could still write over each other;
 * a send that can't get it removes nothing.
 */
class Ordryx_Erasure {

	const HOOK    = Ordryx_Connection::FORGET_HOOK;
	const QUEUE   = 'ordryx_forget';
	const RETRY   = 300;
	const AT_ONCE = 500;
	const MOST    = 20000;
	const LOCK    = 'forget';
	// The lock is held only to read and write the queue: a moment. Seconds a
	// writer waits for it, and holds it at most (a request that died).
	const LOCK_WAIT = 2;
	const LOCK_HOLD = 10;
	// Seconds the plugin's deletion waits for each last send (uninstall.php).
	const LAST_WAIT = 12;

	/**
	 * Listen.
	 */
	public static function hook() {
		// WooCommerce's own eraser, once per order it anonymises.
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'erased' ), 20, 1 );
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
	}

	/**
	 * An order's personal data was erased: its id queued, a send scheduled.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function erased( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		// (queued even while disconnected: an Ordryx keeps a store's orders, so
		// it's sent once the store is connected again)
		$id = (int) $order->get_id();
		if ( $id <= 0 ) {
			return;
		}
		// (without the lock, which can't be held this long, it's still added
		// onto the queue as stored now. Past MOST it isn't: the Plugins screen
		// says so, full())
		$locked = Ordryx_Lock::take( self::LOCK, self::LOCK_HOLD, self::LOCK_WAIT );
		try {
			$queue = self::queued( true );
			if ( ! in_array( $id, $queue, true ) && count( $queue ) < self::MOST ) {
				$queue[] = $id;
				update_option( self::QUEUE, $queue, false );
			}
		} finally {
			if ( $locked ) {
				Ordryx_Lock::release( self::LOCK );
			}
		}
		if ( self::sendable() ) {
			self::schedule( time() );
		}
	}

	/**
	 * The order ids waiting to be sent.
	 *
	 * @param bool $fresh As stored now, never this request's cached copy (a
	 *                    request that first found none keeps "none" too).
	 * @return int[]
	 */
	public static function queued( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::QUEUE, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? array_values( array_filter( array_map( 'intval', $queue ) ) ) : array();
	}

	/**
	 * How many erasures wait to reach a connected store's Ordryx (0 on a copy
	 * of the site, or with no Ordryx to send to): the Plugins screen says so.
	 *
	 * @return int
	 */
	public static function waiting() {
		return self::sendable() ? count( self::queued( true ) ) : 0;
	}

	/**
	 * Whether the queue is full, so later erasures aren't kept for the Ordryx.
	 *
	 * @return bool
	 */
	public static function full() {
		return count( self::queued( true ) ) >= self::MOST;
	}

	/**
	 * Schedule a send, unless one is waiting already.
	 *
	 * @param int $when Unix seconds.
	 */
	private static function schedule( $when ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$waiting = function_exists( 'as_get_scheduled_actions' ) && as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => Ordryx_Connection::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! $waiting ) {
			as_schedule_single_action( $when, self::HOOK, array(), Ordryx_Connection::GROUP );
		}
	}

	/**
	 * Send what's queued (Action Scheduler's job), AT_ONCE at a time. Sent ids
	 * leave the queue only once Ordryx's server says the Ordryx has them; a
	 * store Ordryx's server no longer knows keeps nothing to send to.
	 */
	public static function send() {
		$queue = self::queued( true );
		if ( ! $queue ) {
			return;
		}
		if ( ! self::sendable() ) {
			return; // (kept: Ordryx keeps the store's orders, so they're sent once it's connected again)
		}
		$batch  = array_slice( $queue, 0, self::AT_ONCE );
		$answer = Ordryx_Account::call( 'forget', array( 'orders' => $batch ), 30 );
		if ( $answer['copy'] ) {
			return; // (refused as a copy of the site: kept, never tried again from here)
		}
		if ( 200 === $answer['status'] && ! empty( $answer['body']['ok'] ) ) {
			if ( self::drop( $batch ) ) {
				self::schedule( time() ); // (more waiting: sent next)
			}
			return;
		}
		// (a store Ordryx's server doesn't know: kept, never a reason to delete
		// anything, and tried again later like any other failure)
		self::schedule( time() + self::RETRY );
	}

	/**
	 * The ids the Ordryx has now leave the queue, under its lock, from the
	 * queue as stored now (any queued meanwhile stay). Without the lock
	 * nothing leaves: the batch goes again, which the Ordryx takes as before.
	 *
	 * @param int[] $batch The ids sent.
	 * @return bool Whether anything is left to send.
	 */
	private static function drop( array $batch ) {
		if ( ! Ordryx_Lock::take( self::LOCK, self::LOCK_HOLD, self::LOCK_WAIT ) ) {
			return true;
		}
		try {
			$left = array_values( array_diff( self::queued( true ), $batch ) );
			if ( $left ) {
				update_option( self::QUEUE, $left, false );
			} else {
				delete_option( self::QUEUE );
			}
			return (bool) $left;
		} finally {
			Ordryx_Lock::release( self::LOCK );
		}
	}

	/**
	 * The plugin being deleted: what's queued is sent once more, as much as
	 * fits by $until (AT_ONCE at a time, LAST_WAIT each), never from a copy.
	 * Nothing is kept: the queue goes with the plugin, and what the Ordryx
	 * can't take now is lost (the readme says so).
	 *
	 * @param int $until Unix seconds.
	 */
	public static function last_send( $until ) {
		if ( ! self::sendable() ) {
			return;
		}
		$queue = self::queued( true );
		while ( $queue && Ordryx_Clock::now() + self::LAST_WAIT <= $until ) {
			$answer = Ordryx_Account::call( 'forget', array( 'orders' => array_slice( $queue, 0, self::AT_ONCE ) ), self::LAST_WAIT );
			if ( $answer['copy'] || 200 !== $answer['status'] || empty( $answer['body']['ok'] ) ) {
				return;
			}
			$queue = array_slice( $queue, self::AT_ONCE );
		}
	}

	/**
	 * Anything still queued gets a send (the daily check, and connecting
	 * again: a send lost to a deactivation or a timeout is never stranded).
	 */
	public static function rearm() {
		// (never while a send is running either: its own retry keeps the 5
		// minutes, which an admin page mid-send would otherwise cut short)
		$busy = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), Ordryx_Connection::GROUP );
		if ( self::queued() && self::sendable() && ! $busy ) {
			self::schedule( time() );
		}
	}

	/**
	 * Whether there's an Ordryx to send to now (never a send scheduled while
	 * there isn't: the daily check and connecting again re-arm it). Never from
	 * a copy of the site: what's erased there waits there.
	 *
	 * @return bool
	 */
	private static function sendable() {
		$state = Ordryx_State::get();
		return '' !== $state['store'] && in_array( $state['phase'], array( 'connected', 'paused' ), true ) && Ordryx_Site::here();
	}
}
