<?php
/**
 * Ordryx's key in WooCommerce: telling a request made with it, and deleting it.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * The read-only REST key WooCommerce's Allow screen made for Ordryx.
 *
 * WooCommerce names such a key "Ordryx - API (<date>)" after the app_name the
 * plugin sends; the plugin only ever touches a key named so, with read access.
 */
class Ordryx_Keys {

	const NAMED = 'Ordryx - API (';

	/**
	 * The key's row, by its id, if it's Ordryx's.
	 *
	 * @param int $key_id WooCommerce's key id.
	 * @return object|null
	 */
	public static function row( $key_id ) {
		$row = self::any( $key_id );
		if ( ! $row || 'read' !== $row->permissions || 0 !== strpos( (string) $row->description, self::NAMED ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * The key's row by its id, however it was changed since in WooCommerce
	 * (renamed, or given Read/Write): still the key Ordryx reads with. Only to
	 * tell whether it's there and whose request it is, never to delete it.
	 *
	 * For the connection's own key, only while the end of the key WooCommerce
	 * keeps in plain text is as it was when it was matched (mark()): a
	 * database that reuses a deleted key's id (MySQL 5.7's InnoDB after a
	 * restart) may give it to another key, which isn't Ordryx's.
	 * Never by its person: WooCommerce's Edit key screen gives a key to another
	 * user, and it still reads, now as them.
	 *
	 * @param int $key_id WooCommerce's key id.
	 * @return object|null
	 */
	public static function any( $key_id ) {
		global $wpdb;
		$key_id = (int) $key_id;
		if ( $key_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce's own key table, read by id.
			$wpdb->prepare(
				"SELECT key_id, user_id, description, permissions, consumer_key, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
				$key_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$state = Ordryx_State::get();
		$mark  = $state['key_mark'];
		// (a mark kept with its person too, by a test build of 0.5.3: its end alone)
		if ( (int) $state['key_id'] === $key_id && is_array( $mark ) && isset( $mark['end'] ) && self::mark( $row )['end'] !== (string) $mark['end'] ) {
			return null; // (another key took its id)
		}
		return $row;
	}

	/**
	 * What tells a key from one that later took its id: the last 7 characters
	 * of its consumer key, which WooCommerce keeps in plain text to show on its
	 * REST API screen (never the secret). Nothing WooCommerce lets the shop
	 * edit changes them (it has no way to make a key's consumer key again),
	 * and a new key has a new consumer key.
	 *
	 * @param object $row The key's row.
	 * @return array {end: string}
	 */
	public static function mark( $row ) {
		return array( 'end' => isset( $row->truncated_key ) ? (string) $row->truncated_key : '' );
	}

	/**
	 * Whether WooCommerce lets the key read: Read or Read/Write (it refuses
	 * every GET made with a Write key).
	 *
	 * @param object $row The key's row.
	 * @return bool
	 */
	public static function reads( $row ) {
		return in_array( (string) $row->permissions, array( 'read', 'read_write' ), true );
	}

	/**
	 * The newest Ordryx read key a person allowed.
	 *
	 * @param int $user_id The person.
	 * @return int Its id, or 0.
	 */
	public static function newest( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce's own key table.
			$wpdb->prepare(
				"SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE user_id = %d AND permissions = 'read' AND description LIKE %s ORDER BY key_id DESC LIMIT 1",
				(int) $user_id,
				$wpdb->esc_like( self::NAMED ) . '%'
			)
		);
	}

	/**
	 * Delete Ordryx's key (only a key that is Ordryx's).
	 *
	 * @param int $key_id WooCommerce's key id.
	 * @return bool Whether a key was deleted.
	 */
	public static function delete( $key_id ) {
		global $wpdb;
		if ( ! self::row( $key_id ) ) {
			return false;
		}
		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce's own key table; it has no delete function of its own.
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'key_id' => (int) $key_id ),
			array( '%d' )
		);
	}

	/**
	 * Whether the REST request being answered was made with Ordryx's key.
	 * WooCommerce has already checked the key and its secret by then; this
	 * only asks which key it was.
	 *
	 * @return bool
	 */
	public static function asking() {
		static $answers = array();
		$key            = self::consumer_key();
		if ( '' === $key || ! function_exists( 'wc_api_hash' ) || ! is_user_logged_in() ) {
			return false;
		}
		$key_id = (int) Ordryx_State::get()['key_id'];
		$asked  = $key_id . ':' . get_current_user_id() . ':' . wc_api_hash( $key );
		if ( ! isset( $answers[ $asked ] ) ) {
			// (by its id, user and hash: a key renamed or given Read/Write in
			// WooCommerce is still the one Ordryx reads with)
			$row               = self::any( $key_id );
			$answers[ $asked ] = $row && get_current_user_id() === (int) $row->user_id
				&& hash_equals( (string) $row->consumer_key, wc_api_hash( $key ) );
		}
		return $answers[ $asked ];
	}

	/**
	 * The consumer key WooCommerce authenticated the request with: the query's
	 * first, else Basic auth's (as WooCommerce reads them; Ordryx uses Basic),
	 * cleaned with sanitize_text_field as WooCommerce cleans it before its own
	 * check, so a request it took as made with Ordryx's key is Ordryx's here.
	 *
	 * @return string
	 */
	private static function consumer_key() {
		// (no nonce: this is WooCommerce's key authentication, not a form; the
		// key is only compared by its hash, never shown or stored)
		if ( ! empty( $_GET['consumer_key'] ) && ! empty( $_GET['consumer_secret'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = sanitize_text_field( wp_unslash( $_GET['consumer_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
		} else {
			$key = '';
		}
		return ( is_string( $key ) && preg_match( '/^ck_[a-f0-9]{40}$/D', $key ) ) ? $key : '';
	}
}
