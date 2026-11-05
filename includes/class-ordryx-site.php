<?php
/**
 * Which site is the connected store: a copy of the site (a staging site, a
 * host's one-click copy) holds the connection too, so nothing is signed from
 * anywhere but the address it was connected at.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * This site's address, the address it was connected at, and whether they're
 * the same site.
 *
 * The address is the Site Address setting (Settings › General), read with
 * get_option( 'home' ): a copy's search-and-replace rewrites it, and a copy's
 * own WP_HOME in wp-config.php reaches it through WordPress's own filter.
 * Never home_url(), which a language or domain-mapping plugin may change from
 * one request to the next. On multisite each site has its own.
 *
 * What was kept at connecting ("site" in the connection's record) is base64,
 * so a migration tool's search-and-replace, which rewrites the site's address
 * everywhere in the database, can't rewrite it on a copy. Nothing about "a
 * copy" is ever stored: it's worked out at each use, so an address changed
 * and put back is the store again by itself.
 */
class Ordryx_Site {

	const PRODUCTION = 'production';

	/**
	 * This site's address now, as Settings › General has it.
	 *
	 * @return string
	 */
	public static function home() {
		return untrailingslashit( (string) get_option( 'home', '' ) );
	}

	/**
	 * This site's address as it names itself to Ordryx's server: its Site
	 * Address, over https (WordPress's own scheme depends on the request).
	 *
	 * @return string
	 */
	public static function address() {
		$home = self::home();
		return 0 === stripos( $home, 'http://' ) ? 'https://' . substr( $home, 7 ) : $home;
	}

	/**
	 * What WordPress says this site is (wp_get_environment_type): set in
	 * wp-config.php or the server, never in the database, so a copied
	 * database doesn't carry it.
	 *
	 * @return string
	 */
	public static function environment() {
		return (string) wp_get_environment_type();
	}

	/**
	 * An address as compared (the same rule as the account's site_key; the
	 * table both are tested with is tests/fixtures/site-keys.json): http or
	 * https, the scheme then ignored; the host in lower case, one leading
	 * "www." dropped while a dot remains after it; a port unless it's 80 or
	 * 443; the path as it is, trailing slashes dropped. Anything else
	 * (credentials, a query, a fragment, an empty, "." or ".." segment,
	 * anything percent-encoded, over 255 characters) is "".
	 *
	 * @param mixed $address An address.
	 * @return string "" when it isn't one.
	 */
	public static function key( $address ) {
		if ( ! is_string( $address ) || '' === $address || strlen( $address ) > 255 || 1 !== preg_match( '/^[^\p{C}\p{Z}]+$/Du', $address ) ) {
			return '';
		}
		$p = wp_parse_url( $address );
		if ( ! is_array( $p ) || ! isset( $p['scheme'], $p['host'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		foreach ( array( 'user', 'pass', 'query', 'fragment' ) as $part ) {
			if ( isset( $p[ $part ] ) && '' !== (string) $p[ $part ] ) {
				return '';
			}
		}
		$host = strtolower( $p['host'] );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/D', $host ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
		if ( false !== strpos( $path, '%' ) || ( '' !== $path && array_intersect( explode( '/', substr( $path, 1 ) ), array( '', '.', '..' ) ) ) ) {
			return '';
		}
		if ( 0 === strpos( $host, 'www.' ) && false !== strpos( substr( $host, 4 ), '.' ) ) {
			$host = substr( $host, 4 );
		}
		$port = isset( $p['port'] ) && ! in_array( (int) $p['port'], array( 0, 80, 443 ), true ) ? ':' . (int) $p['port'] : '';
		return $host . $port . $path;
	}

	/**
	 * Whether two addresses are the same site.
	 *
	 * @param mixed $one An address.
	 * @param mixed $two Another.
	 * @return bool
	 */
	public static function same( $one, $two ) {
		$key = self::key( $one );
		return '' !== $key && self::key( $two ) === $key;
	}

	/**
	 * What's kept at connecting: the address and what WordPress said the site
	 * was.
	 *
	 * @return array
	 */
	public static function record() {
		return array(
			'home' => self::encode( self::address() ),
			'env'  => self::environment(),
		);
	}

	/**
	 * The address this store was connected at ("" when none was kept).
	 *
	 * @return string
	 */
	public static function paired() {
		return self::decoded( 'home' );
	}

	/**
	 * Why this site isn't the connected store: "address" (its address isn't
	 * the one it was connected at, or Ordryx's server refused it) or
	 * "environment" (connected as a live site, which WordPress now says it
	 * isn't). "" when it is, or when nothing is connected.
	 *
	 * A store connected before 0.5.1 kept no environment: it's read as
	 * "production", WordPress's default and almost surely what it was, so a
	 * copy of it at the store's own address that WordPress calls staging is a
	 * copy at once.
	 *
	 * @return string
	 */
	public static function why() {
		$state = Ordryx_State::get();
		if ( '' === $state['store'] ) {
			return '';
		}
		$now     = self::key( self::home() );
		$paired  = self::decoded( 'home' );
		$refused = self::decoded( 'refused' );
		if ( '' !== $paired && self::key( $paired ) !== $now ) {
			return 'address';
		}
		if ( '' !== $refused && self::key( $refused ) === $now ) {
			return 'address'; // (the account's rule said "another site" where this one said "the same")
		}
		$site = is_array( $state['site'] ) ? $state['site'] : array();
		$env  = isset( $site['env'] ) ? $site['env'] : self::PRODUCTION;
		if ( self::PRODUCTION === $env && self::PRODUCTION !== self::environment() ) {
			return 'environment';
		}
		return '';
	}

	/**
	 * Whether this site looks like a copy of the connected store.
	 *
	 * @return bool
	 */
	public static function elsewhere() {
		return '' !== self::why();
	}

	/**
	 * Whether this site is the connected store (or nothing is connected).
	 *
	 * @return bool
	 */
	public static function here() {
		return '' === self::why();
	}

	/**
	 * Ordryx's server refused a call as not from the store's address (409
	 * {error: site, site}): its address for the store is kept, and so is the
	 * address it refused. Kept only for the connection the call was signed
	 * for (one made meanwhile, in another tab, is never touched).
	 *
	 * @param string $store The store id the call was signed for.
	 * @param string $named The address the call named.
	 * @param mixed  $told  The store's address, as the account has it.
	 */
	public static function refused( $store, $named, $told ) {
		$state = Ordryx_State::fresh();
		if ( $state['store'] !== $store ) {
			return;
		}
		$site            = is_array( $state['site'] ) ? $state['site'] : array();
		$site['refused'] = self::encode( (string) $named );
		if ( is_string( $told ) && 0 === strpos( $told, 'https://' ) && '' !== self::key( $told ) ) {
			$site['home'] = self::encode( $told );
		}
		Ordryx_State::update( array( 'site' => $site ) );
	}

	/**
	 * Ordryx's server answered a check, naming the store's address: when it's
	 * the address the call named, and still this site's, this site is the
	 * store, and its address is kept again (a store connected before the
	 * plugin kept one gets it now; one this site wrongly took for a copy is
	 * the store again). What WordPress said the site was at connecting is
	 * kept as it was, and a record without one gets "production", as why()
	 * reads it: never this site's own, because the account can't tell a copy
	 * at the same address, so its word would make such a copy the store.
	 *
	 * @param string $store The store id the call was signed for.
	 * @param string $named The address the call named.
	 * @param mixed  $told  The store's address, as the account has it.
	 */
	public static function confirmed( $store, $named, $told ) {
		$state = Ordryx_State::fresh();
		if ( $state['store'] !== $store || ! self::same( $named, $told ) || ! self::same( $named, self::address() ) ) {
			return;
		}
		$site         = is_array( $state['site'] ) ? $state['site'] : array();
		$site['home'] = self::encode( 0 === strpos( (string) $told, 'https://' ) ? (string) $told : (string) $named );
		unset( $site['refused'] );
		if ( ! isset( $site['env'] ) ) {
			$site['env'] = self::PRODUCTION;
		}
		Ordryx_State::update( array( 'site' => $site ) );
	}

	/**
	 * An address as kept: base64, out of a search-and-replace's reach.
	 *
	 * @param string $address An address.
	 * @return string
	 */
	private static function encode( $address ) {
		return base64_encode( $address ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- kept so a copy's search-and-replace of the site's address can't rewrite it.
	}

	/**
	 * An address kept in the record ("" for anything that isn't one).
	 *
	 * @param string $field "home" or "refused".
	 * @return string
	 */
	private static function decoded( $field ) {
		$site = Ordryx_State::get()['site'];
		$kept = is_array( $site ) && isset( $site[ $field ] ) && is_string( $site[ $field ] ) ? base64_decode( $site[ $field ], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- kept so a copy's search-and-replace of the site's address can't rewrite it.
		return ( false !== $kept && '' !== self::key( $kept ) ) ? $kept : '';
	}
}
