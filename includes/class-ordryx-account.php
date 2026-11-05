<?php
/**
 * Calls to Ordryx's server (the account): pairing with a code, then calls
 * signed with the site secret.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * The account at account.ordryx.com, as this plugin talks to it.
 *
 * A signed call is a POST of a JSON body to /api/woocommerce/v1/plugin/<kind>
 * with three headers: X-Ordryx-Store (the store's id from pairing),
 * X-Ordryx-Time (Unix seconds) and X-Ordryx-Signature, the base64 of
 * HMAC-SHA256 over "<kind>\n<time>\n<body>" with the site secret. Every body
 * carries a random nonce, so no two calls are signed alike, and names this
 * site (its Site Address, over https), so Ordryx's server can refuse a call
 * from a copy of the site. Nothing in a signed call is order data.
 *
 * A copy of the site (a staging site, a host's one-click copy) holds the
 * secret too: nothing is signed from an address but the one the store was
 * connected at (Ordryx_Site), but a check an Administrator's tab asks for
 * there, which Ordryx's server refuses, knowing the store's address. A copy
 * at the store's own address, found by its environment type, signs nothing
 * at all: Ordryx's server can't tell it from the store, and would answer it
 * (and act on it) as the store's.
 */
class Ordryx_Account {

	const BASE    = 'https://account.ordryx.com';
	const TIMEOUT = 15;

	/**
	 * Kinds of signed call the plugin makes.
	 */
	const KINDS = array( 'nudge', 'check', 'pause', 'gone', 'cancel', 'page', 'forget' );

	/**
	 * The account's address: Ordryx's own, or (for development only) one set in
	 * wp-config.php as ORDRYX_ACCOUNT_URL, over https, this computer's too:
	 * WooCommerce posts the key only to an https callback_url, so an http one
	 * could never finish the Allow screen.
	 *
	 * @return string
	 */
	public static function base() {
		if ( defined( 'ORDRYX_ACCOUNT_URL' ) && is_string( ORDRYX_ACCOUNT_URL ) ) {
			$url   = untrailingslashit( ORDRYX_ACCOUNT_URL );
			$parts = wp_parse_url( $url );
			$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			$https = isset( $parts['scheme'] ) && 'https' === $parts['scheme'];
			if ( '' !== $host && $https && ! isset( $parts['user'] ) && ! isset( $parts['query'] ) ) {
				return $url;
			}
		}
		return self::BASE;
	}

	/**
	 * Where WooCommerce posts the key once the Administrator presses Approve.
	 *
	 * @return string
	 */
	public static function key_url() {
		return self::base() . '/api/woocommerce/v1/key';
	}

	/**
	 * Pair with a code (no secret yet: the code is the proof, its tries counted
	 * by the account).
	 *
	 * @param array $body    The code and what the store says about itself.
	 * @param int   $timeout Seconds to wait (the account asks the Ordryx first).
	 * @return array {status: int, body: array}; status 0 when nothing answered.
	 */
	public static function pair( array $body, $timeout = self::TIMEOUT ) {
		return self::post( '/api/woocommerce/v1/pair', wp_json_encode( $body ), array(), $timeout );
	}

	/**
	 * A call signed with the site secret, from the connected store only.
	 *
	 * @param string $kind    One of KINDS.
	 * @param array  $body    What the call says (never order data).
	 * @param int    $timeout Seconds to wait.
	 * @param bool   $looking A check an Administrator asked for at the tab (or
	 *                        Forget the connection here): sent from a copy of
	 *                        the site at another address too, for Ordryx's
	 *                        server to say whose address this is (never from
	 *                        a copy found by its environment type: it's at
	 *                        the store's address).
	 * @return array {status: int, body: array, copy: bool}; status 0 when
	 *               nothing answered or there's no secret to sign with; copy
	 *               when this site looks like a copy of the store, so nothing
	 *               was sent, or Ordryx's server refused it as one.
	 */
	public static function call( $kind, array $body = array(), $timeout = self::TIMEOUT, $looking = false ) {
		$state  = Ordryx_State::get();
		$secret = Ordryx_State::secret();
		$none   = array(
			'status' => 0,
			'body'   => array(),
			'copy'   => false,
		);
		if ( ! in_array( $kind, self::KINDS, true ) || '' === $secret || '' === $state['store'] ) {
			return $none;
		}
		$why = Ordryx_Site::why();
		if ( '' !== $why && ! ( $looking && 'check' === $kind && 'address' === $why ) ) {
			$none['copy'] = true; // (nothing built, nothing sent)
			return $none;
		}
		$site          = Ordryx_Site::address();
		$body['site']  = $site;
		$body['nonce'] = bin2hex( random_bytes( 16 ) );
		$json          = wp_json_encode( $body );
		$time          = (string) time();
		$headers       = array(
			'X-Ordryx-Store'     => $state['store'],
			'X-Ordryx-Time'      => $time,
			'X-Ordryx-Signature' => self::sign( $secret, $kind, $time, $json ),
		);

		$answer         = self::post( '/api/woocommerce/v1/plugin/' . $kind, $json, $headers, $timeout );
		$answer['copy'] = false;
		$told           = isset( $answer['body']['site'] ) ? $answer['body']['site'] : '';
		if ( 409 === $answer['status'] && isset( $answer['body']['error'] ) && 'site' === $answer['body']['error'] ) {
			// Not from the store's own address, says Ordryx's server: its address
			// for the store is kept, and nothing more is sent from this one.
			Ordryx_Site::refused( $state['store'], $site, $told );
			$answer['copy'] = true;
		} elseif ( 'check' === $kind && 200 === $answer['status'] && '' !== $told ) {
			Ordryx_Site::confirmed( $state['store'], $site, $told );
		}
		return $answer;
	}

	/**
	 * The signature of a call.
	 *
	 * @param string $secret The site secret (raw bytes).
	 * @param string $kind   The call's kind.
	 * @param string $time   Unix seconds, as sent.
	 * @param string $body   The body, exactly as sent.
	 * @return string
	 */
	public static function sign( $secret, $kind, $time, $body ) {
		return base64_encode( hash_hmac( 'sha256', $kind . "\n" . $time . "\n" . $body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the signature's encoding.
	}

	/**
	 * POST to the account, never following a redirect.
	 *
	 * @param string $path    Under the account's address.
	 * @param string $json    The body.
	 * @param array  $headers More headers.
	 * @param int    $timeout Seconds to wait.
	 * @return array {status: int, body: array}
	 */
	private static function post( $path, $json, array $headers, $timeout = self::TIMEOUT ) {
		$answer = wp_remote_post(
			self::base() . $path,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array_merge(
					array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
						'User-Agent'   => 'OrdryxForWooCommerce/' . ( defined( 'ORDRYX_VERSION' ) ? ORDRYX_VERSION : '' ),
					),
					$headers
				),
				'body'        => $json,
			)
		);
		if ( is_wp_error( $answer ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $answer ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $answer ),
			'body'   => is_array( $body ) ? $body : array(),
		);
	}
}
