<?php
/**
 * A lock one request at a time can hold: a row in WordPress's options table,
 * which only one request can add.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * Built as Action Scheduler builds its own (ActionScheduler_OptionLock): the
 * option's unique name makes the insert the lock, a holder that died is taken
 * over once its time is up (only by the request whose update still finds the
 * value it read), and a holder lets go of its own value only, so one taken
 * over never deletes the next holder's lock.
 *
 * WordPress's add_option isn't a lock: it asks get_option first, then writes
 * with INSERT … ON DUPLICATE KEY UPDATE, so two requests can both "add" it.
 * Every read here goes to the table itself, never WordPress's option cache.
 */
class Ordryx_Lock {

	const PREFIX = 'ordryx_lock_';
	const NAP    = 50000; // (microseconds between tries while waiting)

	/**
	 * What each lock this request holds was taken with: name => value.
	 *
	 * @var array
	 */
	private static $held = array();

	/**
	 * Take a lock.
	 *
	 * @param string $name A short name ("forget", "ship_<ref>").
	 * @param int    $hold Seconds it's held at most (a request that dies lets go then).
	 * @param int    $wait Seconds to wait for it while another request holds it (0: not at all).
	 * @return bool Whether it's this request's now.
	 */
	public static function take( $name, $hold, $wait = 0 ) {
		$option = self::PREFIX . $name;
		$until  = microtime( true ) + max( 0, (float) $wait );
		for ( $try = 0; ; $try++ ) {
			$value = ( time() + max( 1, (int) $hold ) ) . ':' . bin2hex( random_bytes( 8 ) );
			if ( self::insert( $option, $value ) ) {
				return self::got( $name, $option, $value );
			}
			$now = self::value( $option );
			// Its holder died (its time is up): taken over by whichever request's
			// update still finds the value both read (the others' find another).
			if ( null !== $now && (int) strtok( $now, ':' ) < time() && self::swap( $option, $now, $value ) ) {
				return self::got( $name, $option, $value );
			}
			if ( null === $now && $try < 3 ) {
				continue; // (let go between the insert and the read: tried again at once)
			}
			if ( microtime( true ) >= $until ) {
				return false;
			}
			usleep( self::NAP );
		}
	}

	/**
	 * Add the lock's row (false: it's there already).
	 *
	 * @param string $option Its option name.
	 * @param string $value  "<until>:<random>".
	 * @return bool
	 */
	private static function insert( $option, $value ) {
		global $wpdb;
		$shown = $wpdb->suppress_errors( true ); // (a duplicate name is the lock being held: refused quietly)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock is the options table's unique name: no cache may stand in for it.
		$added = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option,
				'option_value' => $value,
				'autoload'     => 'off',
			),
			array( '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $shown );
		return (bool) $added;
	}

	/**
	 * Take over a dead holder's lock: only while its row still has the value read.
	 *
	 * @param string $option Its option name.
	 * @param string $was    The value read.
	 * @param string $value  This request's.
	 * @return bool
	 */
	private static function swap( $option, $was, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare and swap on the lock's row.
		$swapped = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $value ),
			array(
				'option_name'  => $option,
				'option_value' => $was,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		return 1 === (int) $swapped;
	}

	/**
	 * The lock is this request's.
	 *
	 * @param string $name   As taken.
	 * @param string $option Its option name.
	 * @param string $value  Its value.
	 * @return bool True.
	 */
	private static function got( $name, $option, $value ) {
		self::$held[ $name ] = $value;
		wp_cache_delete( $option, 'options' );
		return true;
	}

	/**
	 * Let go of a lock this request holds (another's is left as it is).
	 *
	 * @param string $name As taken.
	 */
	public static function release( $name ) {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		$option = self::PREFIX . $name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only the row this request's value is still in.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option,
				'option_value' => self::$held[ $name ],
			),
			array( '%s', '%s' )
		);
		unset( self::$held[ $name ] );
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * A lock's value as the table has it now (null: none).
	 *
	 * @param string $option Its option name.
	 * @return string|null
	 */
	private static function value( $option ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read past the option cache: another request's row.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Every lock row left (deleting the plugin), and the shipped route's locks
	 * from before 0.5.3 ("ordryx_shipping_<ref>").
	 */
	public static function delete_all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rows by name prefix, as WordPress has no option function for it.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%',
				$wpdb->esc_like( 'ordryx_shipping_' ) . '%'
			)
		);
	}
}
