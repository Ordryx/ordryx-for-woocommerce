<?php
/**
 * The time the plugin's own time limits are kept by: the clock, in Unix
 * seconds. Tests move it on (to check the 30 seconds a network's
 * deactivation and the plugin's deletion share).
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * Only for budgets of seconds (Ordryx_Connection::deactivated, uninstall.php,
 * Ordryx_Erasure::last_send): signatures and what's sent keep time() itself.
 */
class Ordryx_Clock {

	/**
	 * Seconds the tests have moved the clock on by (0 in WordPress: nothing
	 * else sets it).
	 *
	 * @var int
	 */
	private static $ahead = 0;

	/**
	 * Now, in Unix seconds.
	 *
	 * @return int
	 */
	public static function now() {
		return time() + self::$ahead;
	}
}
