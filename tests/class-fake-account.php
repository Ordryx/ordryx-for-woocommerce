<?php
/**
 * Ordryx's server, faked: it answers the plugin's calls the way
 * the account does, and refuses a signed call whose
 * signature, time or store is wrong, or that names another site than the
 * store's (409 {error: site, site}), as the account does.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- a test double.

class Fake_Account {

	/** @var string The store's id the fake hands out. */
	public $store = 'st_4f9a';

	/** @var string The site secret it hands out (raw bytes). */
	public $secret;

	/** @var string The store's address, as the account has it from pairing. */
	public $site = 'https://shop.example.com';

	/** @var bool Whether a check's answer names the store's address (the account 0.25.9 on). */
	public $echo_site = true;

	/** @var array Every call: [path, headers, body (decoded), raw body, signed ok]. */
	public $calls = array();

	/** @var array path => list of [status, body] to answer with, in turn. */
	public $answers = array();

	/** @var bool Whether nothing answers (a network failure). */
	public $down = false;

	/** @var array Signatures already seen (the account refuses a repeat). */
	private $seen = array();

	/** @var array path => list of callables, each run once while a call to that path waits for its answer. */
	private $meanwhile = array();

	/**
	 * Run this while the next call to a path waits for its answer (another
	 * request, another tab, at that moment).
	 */
	public function during( $path, callable $fn ) {
		$this->meanwhile[ $path ][] = $fn;
	}

	public function __construct() {
		$this->secret = str_repeat( "\x5a", 32 );
	}

	/**
	 * The answer the account gives a good pairing.
	 */
	public function paired( array $more = array() ) {
		return array_merge(
			array(
				'store'      => $this->store,
				'secret'     => base64_encode( $this->secret ),
				'pairing'    => 'pr_8c1d2e',
				'expires_in' => 900,
				'ordryx'     => array(
					'name'    => 'Bench Works',
					'address' => 'bench-works.ordryx.com',
					'code_by' => 'Olive',
					'stores'  => 0,
				),
			),
			$more
		);
	}

	/**
	 * Answer the next call to a path with this.
	 */
	public function will( $path, $status, array $body = array() ) {
		$this->answers[ $path ][] = array( $status, $body );
	}

	/**
	 * The calls made to a path.
	 */
	public function to( $path ) {
		return array_values(
			array_filter(
				$this->calls,
				function ( $c ) use ( $path ) {
					return $c['path'] === $path;
				}
			)
		);
	}

	/**
	 * wp_remote_post, as the account would answer it.
	 */
	public function post( $url, array $args ) {
		$this->args[] = $args;
		$path         = (string) parse_url( $url, PHP_URL_PATH );
		$headers      = $args['headers'];
		$signed       = null;
		if ( isset( $headers['X-Ordryx-Signature'] ) ) {
			$kind   = substr( $path, strlen( '/api/woocommerce/v1/plugin/' ) );
			$expect = base64_encode( hash_hmac( 'sha256', $kind . "\n" . $headers['X-Ordryx-Time'] . "\n" . $args['body'], $this->secret, true ) );
			$signed = hash_equals( $expect, $headers['X-Ordryx-Signature'] )
				&& $headers['X-Ordryx-Store'] === $this->store
				&& abs( time() - (int) $headers['X-Ordryx-Time'] ) <= 300
				&& ! isset( $this->seen[ $expect ] );
			$this->seen[ $expect ] = true;
		}
		$this->calls[] = array(
			'path'    => $path,
			'url'     => $url,
			'headers' => $headers,
			'body'    => json_decode( $args['body'], true ),
			'raw'     => $args['body'],
			'signed'  => $signed,
			'args'    => $args,
		);
		if ( ! empty( $this->meanwhile[ $path ] ) ) {
			call_user_func( array_shift( $this->meanwhile[ $path ] ) );
		}
		if ( $this->down ) {
			return new WP_Error();
		}
		if ( false === $signed ) {
			return $this->answer( 401, array( 'error' => 'signature' ) );
		}
		$sent   = json_decode( $args['body'], true );
		$status = 200;
		$body   = array( 'ok' => true );
		if ( ! empty( $this->answers[ $path ] ) ) {
			// (an answer a test set is given as it is: the account's, or a proxy's in front of it)
			list( $status, $body ) = array_shift( $this->answers[ $path ] );
		} elseif ( $signed && ( ! isset( $sent['site'] ) || ! Ordryx_Site::same( $sent['site'], $this->site ) ) ) {
			return $this->answer( 409, array( 'error' => 'site', 'site' => $this->site ) );
		}
		if ( $signed && '/api/woocommerce/v1/plugin/check' === $path && $this->echo_site && ! isset( $body['site'] ) ) {
			$body['site'] = $this->site; // (every check answer names it)
		}
		return $this->answer( $status, $body );
	}

	private function answer( $status, array $body ) {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => json_encode( $body ),
		);
	}

	/** @var array The arguments of every wp_remote_post. */
	public $args = array();
}
