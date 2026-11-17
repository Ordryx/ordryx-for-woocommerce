<?php
/**
 * What the plugin keeps: one option for where the connection stands and one
 * for the site secret, neither loaded on every page. The secret is sealed
 * with a key from wp-config.php, so a copy of the database alone (a leaked
 * backup, a read through another plugin's hole) can't sign for the store.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own record of its connection to an Ordryx.
 *
 * Phases: "" (not connected), "pairing" (a code was accepted: both sides shown,
 * waiting for Connect and WooCommerce's Allow screen), "connected", "paused"
 * (by Ordryx, or while the plugin was off) and "unknown" (Ordryx's server no
 * longer knows this store: connect again).
 */
class Ordryx_State {

	const OPTION = 'ordryx_state';
	const SECRET = 'ordryx_secret';

	/**
	 * Every key id WooCommerce made for an Ordryx here, kept apart from the
	 * record so forgetting a connection never forgets a key still in the store.
	 */
	const KEYS = 'ordryx_keys';

	/**
	 * The dashboard's look, kept a little while (a transient: Ordryx_Dashboard).
	 * Named here, so deleting the plugin (uninstall.php loads only this and the
	 * connection's classes) can remove it.
	 */
	const LOOK = 'ordryx_look';

	/**
	 * Counts each time the dashboard's look is forgotten (Ordryx_Dashboard), so
	 * an answer asked for before a change is never kept after it.
	 */
	const LOOK_GEN = 'ordryx_look_gen';

	/**
	 * Every option the plugin writes (deleted on uninstall).
	 */
	const OPTIONS = array( self::OPTION, self::SECRET, self::KEYS, self::KEY_LEFT, self::LOOK_GEN, 'ordryx_shipments', 'ordryx_forget' );

	/**
	 * The connection's own key, left in WooCommerce: changed there, so never
	 * deleted (Ordryx_Connection::delete_keys).
	 */
	const KEY_LEFT = 'ordryx_key_left';

	/**
	 * A sealed secret's mark: "s1." then base64 of the nonce and the box.
	 */
	const SEALED = 's1.';

	/**
	 * What wp-config-sample.php puts where a key should be.
	 */
	const SAMPLE_KEY = 'put your unique phrase here';

	/**
	 * Ids the account hands out: a store, a pairing.
	 */
	const ID = '/^[A-Za-z0-9_-]{1,64}$/D';

	/**
	 * The whole record, with every key present.
	 *
	 * @return array
	 */
	public static function get() {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array_merge( self::blank(), $state );
	}

	/**
	 * The record as it's stored now, never this request's copy: what another
	 * request (another tab, another person) changed meanwhile shows.
	 *
	 * @return array
	 */
	public static function fresh() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( self::SECRET, 'options' );
		// (a request that first found no record keeps "none" there: another may
		// have made one since)
		wp_cache_delete( 'notoptions', 'options' );
		return self::get();
	}

	/**
	 * A record with nothing in it.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'phase'         => '',
			'store'         => '',
			'pairing'       => '',
			'pairing_until' => 0,
			'ordryx'        => array(),
			'key_id'        => 0,
			// The key's plain end, as it was matched (Ordryx_Keys::mark):
			// a key that took its id later isn't it.
			'key_mark'      => array(),
			'connected_by'  => '',
			'connected_at'  => 0,
			'sent_at'       => 0,
			'failing_since' => 0,
			'off_since'     => 0,
			'checked_at'    => 0,
			// When a nudge last asked whether a paused store was resumed in
			// Ordryx (Ordryx_Nudge::PAUSED_CHECK apart at least).
			'paused_asked'  => 0,
			'other_ordryx'  => '',
			'pending_code'  => '',
			'pending_until' => 0,
			// Who ticked Ordryx's terms with that code (a user id, 0: none, as a
			// code kept by 0.5.3): Move it here sends them only for that person.
			'pending_terms' => 0,
			// Where it was connected (Ordryx_Site::record): a copy of the site
			// holds the connection too, and never signs for the store.
			'site'          => array(),
		);
	}

	/**
	 * Change some of the record, as it's stored now: never onto this request's
	 * copy, which a call that waited on Ordryx's server may hold from before a
	 * Disconnect in another tab (written back, it brought the connection back
	 * with its secret gone).
	 *
	 * @param array       $changes Keys to set.
	 * @param string|null $store   Only while the record is still this store's
	 *                             (the one the caller started with): a change
	 *                             about a connection that's gone is dropped.
	 * @return array The record after.
	 */
	public static function update( array $changes, $store = null ) {
		$now = self::fresh();
		if ( null !== $store && $now['store'] !== $store ) {
			return $now;
		}
		$state = array_merge( $now, array_intersect_key( $changes, self::blank() ) );
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/**
	 * The phase now.
	 *
	 * @return string
	 */
	public static function phase() {
		return (string) self::get()['phase'];
	}

	/**
	 * Whether this store has an Ordryx it tells about order changes.
	 *
	 * @return bool
	 */
	public static function connected() {
		return 'connected' === self::phase();
	}

	/**
	 * The site secret (raw bytes), or "" when there's none or it can't be
	 * opened here (secret_lost). One kept in the clear (0.5.9 and earlier) is
	 * sealed the first time it's read where it can be.
	 *
	 * @return string
	 */
	public static function secret() {
		$kept = get_option( self::SECRET, '' );
		if ( ! is_string( $kept ) || '' === $kept ) {
			return '';
		}
		if ( 0 === strpos( $kept, self::SEALED ) ) {
			return self::open( substr( $kept, strlen( self::SEALED ) ) );
		}
		$raw = base64_decode( $kept, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a secret kept before 0.5.10.
		if ( false === $raw || 32 !== strlen( $raw ) ) {
			return '';
		}
		$sealed = self::seal( $raw );
		if ( '' !== $sealed ) {
			update_option( self::SECRET, $sealed, false );
		}
		return $raw;
	}

	/**
	 * Whether a secret is kept that can't be opened here: wp-config.php's
	 * keys changed since it was sealed (or ORDRYX_SECRET_KEY did), so nothing
	 * can be signed until the store connects again.
	 *
	 * @return bool
	 */
	public static function secret_lost() {
		$kept = get_option( self::SECRET, '' );
		return is_string( $kept ) && 0 === strpos( $kept, self::SEALED ) && '' === self::secret();
	}

	/**
	 * Whether a secret can be kept sealed here (wp-config.php has keys of its
	 * own, and libsodium is there: it is in every WordPress since 5.2).
	 *
	 * @return bool
	 */
	public static function can_seal() {
		return '' !== self::seal_key() && function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * The key a secret is sealed with: ORDRYX_SECRET_KEY if wp-config.php
	 * defines one (at least 32 characters: it outlives a change of
	 * WordPress's own keys), else from WordPress's AUTH_KEY and
	 * SECURE_AUTH_KEY there. Never from the database: WordPress keeps keys
	 * there when wp-config.php has none, and a copy of the database would
	 * hold them too. "" when there's no such key.
	 *
	 * @return string 32 bytes, or "".
	 */
	private static function seal_key() {
		$ikm = '';
		if ( defined( 'ORDRYX_SECRET_KEY' ) && is_string( ORDRYX_SECRET_KEY ) && strlen( ORDRYX_SECRET_KEY ) >= 32 ) {
			$ikm = 'ORDRYX_SECRET_KEY=' . ORDRYX_SECRET_KEY;
		} else {
			foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $name ) {
				$value = defined( $name ) ? constant( $name ) : '';
				if ( ! is_string( $value ) || strlen( $value ) < 32 || self::SAMPLE_KEY === $value ) {
					return '';
				}
				$ikm .= $name . '=' . $value . "\n";
			}
		}
		return hash_hkdf( 'sha256', $ikm, 32, 'ordryx_secret seal v1' );
	}

	/**
	 * The secret sealed, as it's stored ("s1." and base64), or "".
	 *
	 * @param string $raw 32 bytes.
	 * @return string
	 */
	private static function seal( $raw ) {
		$key = self::seal_key();
		if ( '' === $key || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$nonce = random_bytes( 24 );
		return self::SEALED . base64_encode( $nonce . sodium_crypto_secretbox( $raw, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stored as text.
	}

	/**
	 * A sealed secret opened, or "" (another key, or damaged).
	 *
	 * @param string $sealed What follows "s1.".
	 * @return string
	 */
	private static function open( $sealed ) {
		$key = self::seal_key();
		$box = base64_decode( $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stored as text.
		if ( '' === $key || false === $box || strlen( $box ) !== 24 + 16 + 32 || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		try {
			$raw = sodium_crypto_secretbox_open( substr( $box, 24 ), substr( $box, 0, 24 ), $key );
		} catch ( \Throwable $e ) {
			return '';
		}
		return ( is_string( $raw ) && 32 === strlen( $raw ) ) ? $raw : '';
	}

	/**
	 * Keep the store id and secret the account gave at pairing.
	 *
	 * @param string $store  The store's id on the account.
	 * @param string $secret 32 random bytes, base64.
	 * @return bool Whether both were well formed and the secret could be
	 *              sealed (nothing kept otherwise: never in the clear).
	 */
	public static function keep_secret( $store, $secret ) {
		$raw = is_string( $secret ) ? base64_decode( $secret, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the secret as the account gave it.
		if ( ! is_string( $store ) || ! preg_match( self::ID, $store ) || false === $raw || 32 !== strlen( $raw ) ) {
			return false;
		}
		$sealed = self::seal( $raw );
		if ( '' === $sealed ) {
			return false;
		}
		update_option( self::SECRET, $sealed, false );
		self::update( array( 'store' => $store ) );
		return true;
	}

	/**
	 * The key ids kept (each one an Ordryx key WooCommerce made here).
	 *
	 * @return int[]
	 */
	public static function keys() {
		$kept = get_option( self::KEYS, array() );
		return is_array( $kept ) ? array_values( array_unique( array_filter( array_map( 'intval', $kept ) ) ) ) : array();
	}

	/**
	 * Keep the key ids, replacing the list.
	 *
	 * @param int[] $ids Key ids.
	 */
	public static function keep_keys( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( $ids ) {
			update_option( self::KEYS, $ids, false );
		} else {
			delete_option( self::KEYS );
		}
	}

	/**
	 * Forget the connection: the record (the address it was connected at too)
	 * and the secret, never the key ids.
	 */
	public static function forget() {
		delete_option( self::OPTION );
		delete_option( self::SECRET );
	}
}
