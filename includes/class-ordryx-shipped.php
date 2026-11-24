<?php
/**
 * An order shipped from Ordryx: Ordryx's server
 * relays it here, and the plugin marks the order shipped with its tracking.
 * Ordryx's own key stays read-only: this route is the only way anything is
 * written, and it writes only that.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /?rest_route=/ordryx/v1/shipped, public, and believed only twice over:
 *
 * - **Through Ordryx's server:** signed with the site secret over
 *   "relay-shipped\n<time>\n<body>" (X-Ordryx-Store, X-Ordryx-Time,
 *   X-Ordryx-Signature), within 5 minutes, for this store's id.
 * - **From this store's own Ordryx:** the message is that Ordryx's own text,
 *   signed with its Link key (Ed25519 over "ordryx-link/1\n
 *   woocommerce_store_shipped\n<sha256 of the text>"), whose public half
 *   Ordryx's server handed over at pairing. So the server alone can never
 *   mark an order shipped.
 *
 * And only when the message names this site's own address, on a site that
 * isn't a copy of the store (this_site): a copy holds the same secret and key.
 *
 * Then, for one order: its tracking where the store keeps tracking (the first
 * of WooCommerce's Fulfillments, Shipment Tracking, Advanced Shipment Tracking,
 * else a note to the customer, which WooCommerce emails), then its status to
 * the shipped one, which sends WooCommerce's own email, and a private note
 * saying who. Never an order that isn't open, one already shipped
 * ("already"), nor the same shipment twice (answered as before).
 *
 * Pause is Ordryx's: it never sends one while it's paused, and the message is
 * signed by its own key, so this plugin's own phase (which follows Ordryx's
 * only when the tab or a nudge asks) never refuses one. Ordryx's key deleted
 * in WooCommerce does ("nokey"): the tab says nothing flows then. So does a
 * secret this site can't open any more ("nosecret": wp-config.php's keys
 * changed), until the store is connected again with a new code.
 *
 * Only a tracking number (its spaces taken out: letters and digits, 40 at
 * most, a digit among them and 6 letters at most, a dash only between two
 * digits and only with one letter at most), a carrier's plain name and a
 * link to that carrier's own tracking page (LINKS) reach the customer:
 * anything else is left out, and the private note says so.
 */
class Ordryx_Shipped {

	const PURPOSE = 'woocommerce_store_shipped';
	const KEPT    = 'ordryx_shipments';
	const KEEP    = 200;
	const WITHIN  = 300;
	const MOST    = 8192;
	const CLOSED  = array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' );
	const REF     = '/^[a-f0-9]{32}$/D';
	const BUSY    = 120;
	// A status WooCommerce couldn't change is tried again at most this often,
	// unless a person in Ordryx pressed Try again (the message's "again").
	const STATUS_WAIT = 3600;
	// The carriers' own tracking pages: the only links put in front of a customer.
	const LINKS = array(
		'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
		'https://www.ups.com/track?tracknum=',
		'https://www.fedex.com/fedextrack/?trknbr=',
		'https://www.dhl.com/global-en/home/tracking.html?tracking-id=',
	);
	// What reaches a customer: a tracking number (its spaces taken out) and a
	// carrier's name. A number is letters and digits, 40 at most, a digit among
	// them and 6 letters at most (a carrier's prefix and suffix: "1Z999AA1…",
	// "EC…US", PostNL's "3SDEVC…"), a dash only between two digits and only
	// with one letter at most: so words never come with it
	// ("CALL-1-800-555-0100-TO-PAY-DUTY", "CALL1-800-555-0100").
	// Ordryx's _NUMBER is the same rule (tests/fixtures/tracking-numbers.json).
	const NUMBER  = '/^(?=[^0-9]*[0-9])(?=(?:[^A-Za-z]*[A-Za-z]){0,6}[^A-Za-z]*$)(?!-|.*-$|.*[^0-9]-|.*-[^0-9])(?!(?=.*-)(?:[^A-Za-z]*[A-Za-z]){2})[A-Za-z0-9-]{1,40}$/D';
	const CARRIER = '/^[\p{L}\p{N}](?:[\p{L}\p{N} &\'-]{0,38}[\p{L}\p{N}])?$/uD';
	// Advanced Shipment Tracking knows a carrier by its own name (TrackShip's list).
	const AST_NAMES = array( 'DHL' => 'DHL Express' );

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
			'/shipped',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'answer' ),
				'permission_callback' => '__return_true', // (Ordryx's server, signed in as nobody: checked below)
			)
		);
	}

	/**
	 * Answer one.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function answer( $request ) {
		list( $status, $body ) = self::take(
			(string) $request->get_header( 'x_ordryx_store' ),
			(string) $request->get_header( 'x_ordryx_time' ),
			(string) $request->get_header( 'x_ordryx_signature' ),
			(string) $request->get_body()
		);
		$response              = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The answer for one request, as status and body.
	 *
	 * @param string $store     X-Ordryx-Store.
	 * @param string $sent      X-Ordryx-Time.
	 * @param string $signature X-Ordryx-Signature.
	 * @param string $raw       The body, as sent.
	 * @return array {0: int, 1: array}
	 */
	public static function take( $store, $sent, $signature, $raw ) {
		$state  = Ordryx_State::get();
		$secret = Ordryx_State::secret();
		$no     = array( 401, array( 'error' => 'signature' ) );
		if ( '' === $state['store'] || ! hash_equals( $state['store'], $store ) ) {
			return array( 404, array( 'result' => 'store' ) );
		}
		if ( '' === $secret ) {
			// A secret that's kept but can't be opened (wp-config.php's keys
			// changed) can check no signature, so nothing is done; but the
			// store isn't gone: the tab asks for a new code, and it's told
			// again once the store is connected again.
			return Ordryx_State::secret_lost() ? array( 409, array( 'result' => 'nosecret' ) ) : array( 404, array( 'result' => 'store' ) );
		}
		if ( ! preg_match( '/^\d{1,12}$/D', $sent ) || strlen( $raw ) > self::MOST * 2 ) {
			return $no;
		}
		$want = base64_encode( hash_hmac( 'sha256', "relay-shipped\n" . $sent . "\n" . $raw, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the signature's encoding.
		if ( ! hash_equals( $want, $signature ) || abs( time() - (int) $sent ) > self::WITHIN ) {
			return $no;
		}
		if ( ! in_array( $state['phase'], array( 'connected', 'paused' ), true ) ) {
			return array( 404, array( 'result' => 'store' ) );
		}
		$outer   = json_decode( $raw, true );
		$payload = is_array( $outer ) && isset( $outer['payload'] ) && is_string( $outer['payload'] ) ? $outer['payload'] : '';
		$sig     = is_array( $outer ) && isset( $outer['signature'] ) && is_string( $outer['signature'] ) ? $outer['signature'] : '';
		// The key an Administrator matched by eye with the Ordryx's own, at pairing.
		$key = ! empty( $state['ordryx']['key_checked'] ) && isset( $state['ordryx']['key'] ) ? (string) $state['ordryx']['key'] : '';
		if ( '' === $payload || strlen( $payload ) > self::MOST || ! self::signed_by( $key, $payload, $sig ) ) {
			return array( 409, array( 'result' => 'key' ) );
		}
		$msg = json_decode( $payload, true );
		if ( ! is_array( $msg ) || 1 !== ( isset( $msg['v'] ) ? $msg['v'] : 0 )
			|| ! isset( $msg['shipment'], $msg['order'], $msg['signed_at'], $msg['store'] )
			|| ! is_string( $msg['shipment'] ) || ! preg_match( self::REF, $msg['shipment'] )
			|| ! is_int( $msg['order'] ) || $msg['order'] <= 0 || ! is_int( $msg['signed_at'] )
			|| ! is_string( $msg['store'] ) || ! self::this_site( $msg['store'] ) ) {
			return array( 409, array( 'result' => 'key' ) );
		}
		if ( abs( time() - $msg['signed_at'] ) > self::WITHIN ) { // (a clock off: told again, never "key")
			return array( 409, array( 'result' => 'time' ) );
		}
		// One at a time per shipment (two sends of it at once never mark it
		// twice): a lock only one request can hold (Ordryx_Lock).
		$lock = 'ship_' . $msg['shipment'];
		if ( ! Ordryx_Lock::take( $lock, self::BUSY ) ) {
			return array( 409, array( 'result' => 'busy' ) );
		}
		try {
			$kept = self::kept( true );
			$was  = isset( $kept[ $msg['shipment'] ] ) ? $kept[ $msg['shipment'] ] : null;
			if ( $was && isset( $was['result'] ) && in_array( $was['result'], array( 'shipped', 'already' ), true ) ) {
				return array( 200, $was ); // (a repeat: answered as before, nothing done)
			}
			// (only after a repeat: one finished before is still answered as done)
			if ( Ordryx_Connection::key_gone() ) {
				return array( 409, array( 'result' => 'nokey' ) );
			}
			$tracked = $was && isset( $was['result'] ) && 'tracked' === $was['result'] ? $was : null;
			$again   = isset( $msg['again'] ) && true === $msg['again'];
			if ( $tracked && ! $again && isset( $tracked['failed_at'] ) && (int) $tracked['failed_at'] > time() - self::STATUS_WAIT ) {
				// (its status couldn't be changed lately: an Ordryx that sends it
				// again at every sync never adds WooCommerce's failure note each time)
				return array( 409, array( 'result' => 'status' ) );
			}
			list( $status, $body, $keep ) = self::ship( $msg, $tracked );
			if ( null !== $keep ) {
				self::keep( $msg['shipment'], $keep );
			}
			if ( 200 === $status && 'paused' === $state['phase'] ) {
				Ordryx_Dashboard::forget(); // (Ordryx was resumed: the tab asks it afresh, and follows)
			}
			return array( $status, $body );
		} finally {
			Ordryx_Lock::release( $lock );
		}
	}

	/**
	 * A key as a person compares it by eye (as Ordryx shows it: the first 16
	 * hex characters of its SHA-256, in fours).
	 *
	 * @param string $key Its public half, base64url.
	 * @return string
	 */
	public static function fingerprint( $key ) {
		$raw = self::unb64( (string) $key );
		if ( 32 !== strlen( $raw ) ) {
			return '';
		}
		return implode( '-', str_split( strtoupper( substr( hash( 'sha256', $raw ), 0, 16 ) ), 4 ) );
	}

	/**
	 * Whether the message is this store's own Ordryx's, by its Link key.
	 *
	 * @param string $key       Its public half, base64url as pairing gave it.
	 * @param string $payload   The message's text, exactly as signed.
	 * @param string $signature Base64url.
	 * @return bool
	 */
	public static function signed_by( $key, $payload, $signature ) {
		$pk  = self::unb64( $key );
		$sig = self::unb64( $signature );
		if ( 32 !== strlen( $pk ) || 64 !== strlen( $sig ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$message = "ordryx-link/1\n" . self::PURPOSE . "\n" . hash( 'sha256', $payload );
		try {
			return sodium_crypto_sign_verify_detached( $sig, $message, $pk );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Base64url without padding, decoded ("" when it isn't).
	 *
	 * @param string $text As given.
	 * @return string
	 */
	private static function unb64( $text ) {
		if ( ! is_string( $text ) || ! preg_match( '/^[A-Za-z0-9_-]{1,200}$/D', $text ) ) {
			return '';
		}
		$raw = base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the key's and signature's encoding.
		return false === $raw ? '' : $raw;
	}

	/**
	 * Whether an address is this store's: this site's own address, its Site
	 * Address now, by Ordryx_Site's rule (scheme, "www.", a default port and a
	 * trailing slash aside), and only while this site is the connected store
	 * (Ordryx_Site::here(): at the address it was connected at, and a live
	 * site if it was connected as one). Only https, as Ordryx names it.
	 *
	 * A copy of the site holds the same secret and the same key for Ordryx, so
	 * the message's address is all that ties it to one site, and it's this
	 * site's own address it must name, never one this site merely remembers:
	 * a relay meant for the live store, sent again to a copy (by Ordryx's
	 * server, or anything that saw it pass), would otherwise mark the copy's
	 * order shipped and email the real customer from there. The cost: a store
	 * whose Site Address moved takes no shipped orders until it's connected
	 * at its new address, as it signs nothing meanwhile.
	 *
	 * @param string $address As the message names it.
	 * @return bool
	 */
	private static function this_site( $address ) {
		return 0 === strpos( $address, 'https://' ) && Ordryx_Site::here() && Ordryx_Site::same( $address, Ordryx_Site::home() );
	}

	/**
	 * Mark one order shipped: its tracking first, then its status. A status
	 * WooCommerce couldn't change ("status") keeps the tracking done as
	 * "tracked", so the next try changes only the status: never a second note
	 * to the customer nor a second tracking item.
	 *
	 * @param array      $msg     The message, checked.
	 * @param array|null $tracked What an earlier try kept: {result: tracked, where}.
	 * @return array {0: int, 1: array, 2: array|null what to keep for the shipment}
	 */
	private static function ship( array $msg, $tracked = null ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $msg['order'] ) : false;
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return array( 404, array( 'result' => 'unknown' ), null );
		}
		$now     = $order->get_status();
		$shipped = isset( $msg['shipped'] ) && is_array( $msg['shipped'] ) ? array_filter( $msg['shipped'], 'is_string' ) : array();
		if ( 'completed' === $now || in_array( $now, $shipped, true ) ) {
			$done = array( 'result' => 'already' );
			return array( 200, $done, $done );
		}
		if ( in_array( $now, self::CLOSED, true ) ) {
			return array( 409, array( 'result' => 'closed' ), null );
		}
		if ( ! self::as_shipped( $order, isset( $msg['items'] ) ? $msg['items'] : null ) ) {
			return array( 409, array( 'result' => 'changed' ), null ); // (a line added or changed since)
		}
		$named    = self::text( isset( $msg['carrier'] ) ? $msg['carrier'] : '', 40 );
		$carrier  = self::carrier( $named );
		$given    = self::text( isset( $msg['tracking'] ) ? $msg['tracking'] : '', 60 );
		$tracking = self::number( $given );
		$url      = '' !== $tracking && isset( $msg['url'] ) && is_string( $msg['url'] ) ? self::carrier_url( $msg['url'], $tracking ) : '';
		$by       = self::text( isset( $msg['by'] ) ? $msg['by'] : '', 80 );
		$at       = isset( $msg['at'] ) && is_int( $msg['at'] ) ? $msg['at'] : time();
		if ( $tracked ) {
			$where = isset( $tracked['where'] ) && is_string( $tracked['where'] ) ? $tracked['where'] : 'none';
		} else {
			$where = '' === $tracking ? 'none' : self::track( $order, $carrier, $tracking, $url, $at );
			// (kept before the status changes: a request that dies there, as a
			// hook's fatal or a time limit can, adds no second note when sent again)
			if ( 'none' !== $where ) {
				self::keep(
					$msg['shipment'],
					array(
						'result'    => 'tracked',
						'where'     => $where,
						'failed_at' => 0,
					)
				);
			}
		}
		$note = '' !== $by
			/* translators: %s: who shipped it in Ordryx */
			? sprintf( __( 'Marked shipped by Ordryx (%s).', 'ordryx-for-woocommerce' ), $by )
			: __( 'Marked shipped by Ordryx.', 'ordryx-for-woocommerce' );
		if ( '' !== $given && '' === $tracking ) {
			$note .= ' ' . __( 'Its tracking number wasn\'t letters and digits (40 at most, a digit among them and 6 letters at most, dashes only between digits and with one letter at most), so it wasn\'t given to the customer.', 'ordryx-for-woocommerce' );
		}
		if ( '' !== $named && '' === $carrier && 'other' !== strtolower( $named ) ) {
			$note .= ' ' . __( 'Its carrier\'s name wasn\'t a plain name, so it wasn\'t given to the customer.', 'ordryx-for-woocommerce' );
		}
		if ( ! self::status_saved( $order, $now, $order->update_status( 'completed', $note ) ) ) {
			// (WooCommerce's own note on the order says why; the tracking stands)
			return array(
				409,
				array( 'result' => 'status' ),
				array(
					'result'    => 'tracked',
					'where'     => $where,
					'failed_at' => time(),
				),
			);
		}
		$done = array(
			'result' => 'shipped',
			'where'  => $where,
		);
		return array( 200, $done, $done );
	}

	/**
	 * Whether the order's status really changed: as WooCommerce has it stored
	 * now, never update_status()'s answer alone. WooCommerce (11.2) catches
	 * what a save throws (a hook of another plugin's, the order table's
	 * "Could not persist order"), adds its note "Error saving order ID N" and
	 * still answers true; a save that threw leaves the stored order, and its
	 * cache, as they were. So any status but the one before is saved, one
	 * another plugin moved it on to from Completed too: asked again, that
	 * would send a second Completed email.
	 *
	 * @param WC_Order $order  The order, as it was changed.
	 * @param string   $before Its status before.
	 * @param bool     $said   What update_status() answered.
	 * @return bool
	 */
	private static function status_saved( $order, $before, $said ) {
		if ( false === $said ) {
			return false;
		}
		$stored = wc_get_order( $order->get_id() );
		if ( ! $stored || ! is_a( $stored, 'WC_Order' ) ) {
			return false;
		}
		return $before !== $stored->get_status();
	}

	/**
	 * A tracking number as a customer may be given it, with its spaces taken
	 * out (as Ordryx keeps one typed): NUMBER's, so words sent with it never
	 * reach the customer's email; else "".
	 *
	 * @param string $given As the message gives it, cleaned.
	 * @return string
	 */
	private static function number( $given ) {
		$compact = str_replace( ' ', '', $given );
		return preg_match( self::NUMBER, $compact ) ? $compact : '';
	}

	/**
	 * A carrier's name as a customer may be shown it: letters and digits, with
	 * spaces, "&", "'" and "-" between (no dots, slashes or colons, so never a
	 * link), 4 words and 4 digits at most (so never a phone number or a
	 * sentence: "Call 1-800-555-0100 to pay duty"); "" for anything else, and
	 * for Ordryx's own "Other".
	 *
	 * @param mixed $value As the message gives it.
	 * @return string
	 */
	private static function carrier( $value ) {
		$name = self::text( $value, 40 );
		if ( ! preg_match( self::CARRIER, $name ) || 'other' === strtolower( $name ) ) {
			return '';
		}
		return preg_match_all( '/\p{N}/u', $name ) <= 4 && count( preg_split( '/\s+/u', $name ) ) <= 4 ? $name : '';
	}

	/**
	 * Whether the order's lines are still the ones Ordryx shipped: each line's
	 * id and count as the message lists them, and no other.
	 *
	 * @param WC_Order $order The order.
	 * @param mixed    $items {line id: count}, as the message gives them.
	 * @return bool
	 */
	private static function as_shipped( $order, $items ) {
		if ( ! is_array( $items ) ) {
			return false;
		}
		$now = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$now[ (string) $item_id ] = (int) $item->get_quantity();
		}
		$want = array();
		foreach ( $items as $id => $qty ) {
			if ( ! is_int( $qty ) ) {
				return false;
			}
			$want[ (string) $id ] = $qty;
		}
		ksort( $now );
		ksort( $want );
		return $now === $want;
	}

	/**
	 * A carrier's own tracking page for this number (LINKS, then the number:
	 * Ordryx's CARRIERS), else "": never another link, or another page on that
	 * carrier's site, in a note the customer is emailed.
	 *
	 * @param string $url      As the message gives it.
	 * @param string $tracking The tracking number.
	 * @return string
	 */
	private static function carrier_url( $url, $tracking ) {
		foreach ( self::LINKS as $link ) {
			if ( $link . rawurlencode( $tracking ) === $url ) {
				return esc_url_raw( $url, array( 'https' ) );
			}
		}
		return '';
	}

	/**
	 * The tracking, where this store keeps it: the first of these it has.
	 *
	 * @param WC_Order $order    The order.
	 * @param string   $carrier  The carrier's name ("" when it's not one to show).
	 * @param string   $tracking The tracking number.
	 * @param string   $url      The carrier's tracking page ("" if none).
	 * @param int      $at       When it shipped, Unix seconds.
	 * @return string Where it went: "fulfillments", "shipment-tracking", "ast" or "note".
	 */
	private static function track( $order, $carrier, $tracking, $url, $at ) {
		$id       = $order->get_id();
		$provider = '' !== $carrier ? $carrier : 'Other';
		if ( 'fulfillments' === self::tracking_home() ) {
			try {
				$f = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment();
				$f->set_entity_type( \WC_Order::class );
				$f->set_entity_id( (string) $id );
				$items = array();
				foreach ( $order->get_items() as $item_id => $item ) {
					$items[] = array(
						'item_id' => (int) $item_id,
						'qty'     => (int) $item->get_quantity(),
					);
				}
				$f->set_items( $items );
				$f->set_tracking_number( $tracking );
				$f->set_shipment_provider( $provider );
				if ( '' !== $url ) {
					$f->set_tracking_url( $url );
				}
				$f->set_status( 'fulfilled' );
				$f->save();
				return 'fulfillments';
			} catch ( \Throwable $e ) {
				unset( $e ); // (still in beta: the next way the store has)
			}
		}
		if ( function_exists( 'wc_st_add_tracking_number' ) ) {
			// (its date as Unix seconds, as its own docs take it)
			wc_st_add_tracking_number( $id, $tracking, $provider, $at, $url );
			return 'shipment-tracking';
		}
		if ( function_exists( 'ast_insert_tracking_number' ) ) {
			// Advanced Shipment Tracking (4.0.5) finds a carrier by its own name
			// (stored as given otherwise: kept, but with no link) and takes the
			// date as Y-m-d, the store's day it shipped.
			$named = isset( self::AST_NAMES[ $provider ] ) ? self::AST_NAMES[ $provider ] : $provider;
			ast_insert_tracking_number( $id, $tracking, $named, wp_date( 'Y-m-d', $at ), 0 );
			if ( self::ast_kept( $id, $tracking ) ) {
				return 'ast';
			}
			// (it kept nothing: the customer's note instead, never "ast" said for nothing)
		}
		$note = self::customer_note( $carrier, $tracking, $url );
		if ( ! self::noted( $id, $note ) ) {
			$order->add_order_note( $note, 1 );
		}
		return 'note';
	}

	/**
	 * Whether the customer already has this note: a request that died sending
	 * its email (before anything could be kept) adds no second one.
	 *
	 * @param int    $id   The order's id.
	 * @param string $note The note.
	 * @return bool
	 */
	private static function noted( $id, $note ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return false;
		}
		foreach ( wc_get_order_notes(
			array(
				'order_id' => $id,
				'type'     => 'customer',
			)
		) as $was ) {
			if ( isset( $was->content ) && $note === $was->content ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether Advanced Shipment Tracking now lists this tracking number on the
	 * order (believed when it has no way to ask).
	 *
	 * @param int    $id       The order's id.
	 * @param string $tracking The tracking number.
	 * @return bool
	 */
	private static function ast_kept( $id, $tracking ) {
		if ( ! function_exists( 'ast_get_tracking_items' ) ) {
			return true;
		}
		$items = ast_get_tracking_items( $id );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && isset( $item['tracking_number'] ) && (string) $item['tracking_number'] === $tracking ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The note to the customer, which WooCommerce emails.
	 *
	 * @param string $carrier  The carrier's name ("" when it's not one to show).
	 * @param string $tracking The tracking number.
	 * @param string $url      The carrier's tracking page ("" if none).
	 * @return string
	 */
	private static function customer_note( $carrier, $tracking, $url ) {
		if ( '' === $carrier ) {
			return '' !== $url
				/* translators: 1: the tracking number, 2: its tracking page */
				? sprintf( __( 'Your order has shipped. Tracking number: %1$s. Track it at %2$s', 'ordryx-for-woocommerce' ), $tracking, $url )
				/* translators: %s: the tracking number */
				: sprintf( __( 'Your order has shipped. Tracking number: %s.', 'ordryx-for-woocommerce' ), $tracking );
		}
		return '' !== $url
			/* translators: 1: the carrier, 2: the tracking number, 3: its tracking page */
			? sprintf( __( 'Your order has shipped with %1$s. Tracking number: %2$s. Track it at %3$s', 'ordryx-for-woocommerce' ), $carrier, $tracking, $url )
			/* translators: 1: the carrier, 2: the tracking number */
			: sprintf( __( 'Your order has shipped with %1$s. Tracking number: %2$s.', 'ordryx-for-woocommerce' ), $carrier, $tracking );
	}

	/**
	 * Where this store keeps tracking: "fulfillments" (WooCommerce's own, when
	 * switched on), "shipment-tracking", "ast" or "note".
	 *
	 * @return string
	 */
	public static function tracking_home() {
		if ( class_exists( \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::class )
			&& class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )
			&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'fulfillments' ) ) {
			return 'fulfillments';
		}
		if ( function_exists( 'wc_st_add_tracking_number' ) ) {
			return 'shipment-tracking';
		}
		if ( function_exists( 'ast_insert_tracking_number' ) ) {
			return 'ast';
		}
		return 'note';
	}

	/**
	 * The shipments taken lately: shipment id => the answer given (or, for
	 * one whose status WooCommerce couldn't change, {result: tracked, where,
	 * failed_at}).
	 *
	 * @param bool $fresh As stored now, never this request's cached copy.
	 * @return array
	 */
	private static function kept( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::KEPT, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$kept = get_option( self::KEPT, array() );
		return is_array( $kept ) ? $kept : array();
	}

	/**
	 * Keep a shipment's answer (the newest KEEP of them), onto the list as
	 * stored now, under a lock of its own: another shipment's, kept by another
	 * request at the same moment, stays. Without the lock (another request held
	 * it too long) it's still written, from a fresh read: never dropped.
	 *
	 * One still waiting on its status ("tracked") is never pushed out by
	 * shipments finished since: Ordryx stops sending it until a person presses
	 * Try again, maybe hundreds of shipments later, and without it the order
	 * would be tracked twice. The newest KEEP of those are
	 * kept besides.
	 *
	 * @param string $ref    Its id.
	 * @param array  $answer What was answered.
	 */
	private static function keep( $ref, array $answer ) {
		$locked = Ordryx_Lock::take( 'shipments', 10, 2 );
		try {
			$kept = self::kept( true );
			unset( $kept[ $ref ] ); // (newest last)
			$kept[ $ref ] = $answer;
			if ( count( $kept ) > self::KEEP ) {
				$done    = array();
				$waiting = array();
				foreach ( $kept as $id => $was ) {
					if ( is_array( $was ) && isset( $was['result'] ) && 'tracked' === $was['result'] ) {
						$waiting[ $id ] = $was;
					} else {
						$done[ $id ] = $was;
					}
				}
				$kept = array_slice( $waiting, -self::KEEP, null, true ) + array_slice( $done, -self::KEEP, null, true );
			}
			update_option( self::KEPT, $kept, false );
		} finally {
			if ( $locked ) {
				Ordryx_Lock::release( 'shipments' );
			}
		}
	}

	/**
	 * Plain text from the far end, one line, cut to fit.
	 *
	 * @param mixed $value As given.
	 * @param int   $most  Characters at most.
	 * @return string
	 */
	private static function text( $value, $most ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$line = sanitize_text_field( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $line, 0, $most ) : substr( $line, 0, $most );
	}
}
