<?php
/**
 * The proof that a pairing came from this site's own plugin: Ordryx's server
 * asks it once, when WooCommerce's key for the pairing arrives, before the
 * key goes anywhere.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * GET /?rest_route=/ordryx/v1/proof&store=<id>&nonce=<hex>, public: while a
 * pairing waits here, its store id and the server's nonce signed with the
 * pairing's secret (HMAC-SHA256 over "proof\n<store>\n<nonce>", base64). Only
 * the plugin that paired, which only an Administrator can make pair, holds
 * that secret, so another site naming this one's address can't answer it,
 * nor can anyone holding a read key for this site. Nothing else is said: any
 * other store id, no pairing waiting, or a copy of the site (Ordryx_Site,
 * which signs nothing for the store) is a plain 404.
 */
class Ordryx_Proof {

	const NONCE = '/^[a-f0-9]{32}$/D';

	/**
	 * Listen.
	 */
	public static function hook() {
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
	}

	/**
	 * The route.
	 */
	public static function route() {
		register_rest_route(
			'ordryx/v1',
			'/proof',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'answer' ),
				'permission_callback' => '__return_true', // (Ordryx's server, signed in as nobody)
				'args'                => array(
					'store' => array(
						'type'     => 'string',
						'required' => true,
					),
					'nonce' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The proof, for the pairing waiting here only.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function answer( $request ) {
		$store = (string) $request->get_param( 'store' );
		$nonce = (string) $request->get_param( 'nonce' );
		$proof = self::of( $store, $nonce );
		if ( '' === $proof ) {
			return new WP_REST_Response( array( 'code' => 'ordryx_no_pairing' ), 404 );
		}
		$response = new WP_REST_Response( array( 'proof' => $proof ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The proof for a store id and a nonce, or "" when no such pairing waits
	 * here, or this site looks like a copy of the one pairing (the account
	 * asks only the address it pairs, which a copy elsewhere never is).
	 *
	 * @param string $store The store id the server asks about.
	 * @param string $nonce Its nonce.
	 * @return string
	 */
	public static function of( $store, $nonce ) {
		$state  = Ordryx_State::get();
		$secret = Ordryx_State::secret();
		if ( 'pairing' !== $state['phase'] || '' === $secret || '' === $state['store']
			|| ! preg_match( Ordryx_State::ID, $store ) || ! preg_match( self::NONCE, $nonce )
			|| ! hash_equals( $state['store'], $store ) || Ordryx_Site::elsewhere() ) {
			return '';
		}
		return base64_encode( hash_hmac( 'sha256', "proof\n" . $store . "\n" . $nonce, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the proof's encoding.
	}
}
