<?php
/**
 * Connecting and staying connected: pairing with a code, the Allow screen and
 * back, the daily check, Resume and Disconnect, the plugin switched off and
 * deleted, and a copy of the site forgetting the connection it holds.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * The connection's steps. Each returns a result code the settings tab turns
 * into a fixed sentence: nothing the far end says is ever shown as it said it.
 */
class Ordryx_Connection {

	const CHECK_HOOK  = 'ordryx_daily_check';
	const FORGET_HOOK = 'ordryx_forget'; // (Ordryx_Erasure's)
	const NUDGE_HOOK  = 'ordryx_nudge'; // (Ordryx_Nudge's)
	const GROUP       = 'ordryx';
	const PAIRING_TTL = 900;
	const CODE        = '/^[A-Z0-9]{4}-[A-Z0-9]{4}$/D';

	/**
	 * Errors the account answers a pairing with (anything else: "failed"):
	 * the code is wrong, run out or used; too many wrong codes for this store;
	 * the store is another Ordryx's; the Ordryx is paused for WooCommerce; the
	 * Ordryx didn't answer the account; Ordryx's terms weren't agreed to.
	 */
	const PAIR_ERRORS = array( 'code', 'tries', 'elsewhere', 'paused', 'unlinked', 'terms', 'refused' );

	/**
	 * Listen (the daily check is made sure of in the admin only, never on the
	 * store's own pages: schedule_check leaves out admin-ajax.php, which they use).
	 */
	public static function hook() {
		add_action( self::CHECK_HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_check' ) );
	}

	/**
	 * The daily check, while there's an Ordryx to ask (never from a copy of the
	 * site: it sends nothing). With none, one left over goes: Action Scheduler
	 * schedules a recurring action's next run once it finishes, so a check that
	 * heard "disconnected" comes back after unscheduling itself.
	 */
	public static function schedule_check() {
		if ( wp_doing_ajax() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( '' === Ordryx_State::get()['store'] ) {
			if ( as_has_scheduled_action( self::CHECK_HOOK, array(), self::GROUP ) ) {
				as_unschedule_all_actions( self::CHECK_HOOK, array(), self::GROUP );
			}
			return;
		}
		if ( Ordryx_Site::elsewhere() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::CHECK_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CHECK_HOOK, array(), self::GROUP, true );
		}
		Ordryx_Erasure::rearm(); // (erasures still waiting: sent again)
	}

	/**
	 * A code as typed, made "XXXX-XXXX" (letters and numbers only, any case).
	 *
	 * @param string $typed What was typed.
	 * @return string The code, or "" if it isn't one.
	 */
	public static function code( $typed ) {
		$raw = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $typed ) );
		if ( 8 !== strlen( $raw ) ) {
			return '';
		}
		return substr( $raw, 0, 4 ) . '-' . substr( $raw, 4 );
	}

	/**
	 * Why this store can't connect now, before anything is asked.
	 *
	 * @return string "" when it can; else "https", "woocommerce",
	 *                "permalinks" (with Plain permalinks, WooCommerce's Allow
	 *                screen sends Approve to an address it doesn't answer) or
	 *                "keys" (wp-config.php has no keys to seal the secret with).
	 */
	public static function refused() {
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return 'https';
		}
		if ( ! Ordryx_State::can_seal() ) {
			return 'keys';
		}
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, ORDRYX_WC_AT_LEAST, '<' ) ) {
			return 'woocommerce';
		}
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return 'permalinks';
		}
		return '';
	}

	/**
	 * What this store says about itself when pairing.
	 *
	 * @return array
	 */
	public static function about() {
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		return array(
			'site'        => Ordryx_Site::address(), // (the Site Address setting: what every signed call names)
			'name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'wordpress'   => get_bloginfo( 'version' ),
			'plugin'      => ORDRYX_VERSION,
			'hpos'        => (bool) $hpos,
		);
	}

	/**
	 * Pair with a code: the account checks it with the Ordryx that made it and
	 * answers with that Ordryx's name and the site secret.
	 *
	 * @param string $code   "XXXX-XXXX".
	 * @param bool   $agreed The Administrator ticked Ordryx's terms of service
	 *                       and privacy policy (sent as terms: true; nothing
	 *                       is asked without it).
	 * @param bool   $move   Move the store here from the Ordryx it's connected to.
	 * @return string "paired", "https", "woocommerce", "format", "agree",
	 *                "connected", "unreachable", "failed" or one of PAIR_ERRORS.
	 */
	public static function pair( $code, $agreed, $move = false ) {
		$refused = self::refused();
		if ( '' !== $refused ) {
			return $refused;
		}
		if ( ! preg_match( self::CODE, (string) $code ) ) {
			return 'format';
		}
		if ( true !== $agreed ) {
			return 'agree';
		}
		if ( in_array( Ordryx_State::phase(), array( 'connected', 'paused' ), true ) ) {
			return 'connected';
		}
		$user   = wp_get_current_user();
		$answer = Ordryx_Account::pair(
			array_merge(
				self::about(),
				array(
					'code'  => $code,
					'move'  => (bool) $move,
					'by'    => $user->display_name,
					'terms' => true,
				)
			),
			45
		);
		$body   = $answer['body'];
		if ( 0 === $answer['status'] ) {
			return 'unreachable';
		}
		if ( 200 !== $answer['status'] ) {
			$error = isset( $body['error'] ) ? (string) $body['error'] : '';
			if ( 'elsewhere' === $error ) {
				Ordryx_State::update(
					array(
						'other_ordryx'  => self::text( isset( $body['other'] ) ? $body['other'] : '', 80 ),
						'pending_code'  => $code,
						'pending_until' => time() + 600,
						// (ticked with that code, by this person: Move it here
						// sends it again only for them)
						'pending_terms' => (int) get_current_user_id(),
					)
				);
			}
			return in_array( $error, self::PAIR_ERRORS, true ) ? $error : 'failed';
		}
		$pairing = isset( $body['pairing'] ) ? (string) $body['pairing'] : '';
		$ordryx  = isset( $body['ordryx'] ) && is_array( $body['ordryx'] ) ? $body['ordryx'] : array();
		if ( ! preg_match( Ordryx_State::ID, $pairing )
			|| ! Ordryx_State::keep_secret( isset( $body['store'] ) ? $body['store'] : '', isset( $body['secret'] ) ? $body['secret'] : '' ) ) {
			return 'failed';
		}
		// A new connection starts afresh: who allowed it, its key, its notices,
		// and the address it's made at (a copy of the site never signs for it).
		// An earlier key stays listed, deleted once the new one is confirmed.
		Ordryx_State::update(
			array_merge(
				array_diff_key( Ordryx_State::blank(), array_flip( array( 'store' ) ) ),
				array(
					'phase'         => 'pairing',
					'pairing'       => $pairing,
					'pairing_until' => time() + self::PAIRING_TTL,
					'ordryx'        => self::ordryx( $ordryx, $code ),
					'site'          => Ordryx_Site::record(),
				)
			)
		);
		return 'paired';
	}

	/**
	 * WooCommerce's Allow screen for Ordryx's read-only key, coming back to the
	 * settings tab (Plain permalinks are refused before pairing: WooCommerce's
	 * Approve wouldn't come back).
	 *
	 * @param string $back The settings tab's address.
	 * @return string "" when there's no pairing waiting, or this site looks
	 *                like a copy of the one pairing (the live site would answer
	 *                the proof, and the copy's key use up its pairing).
	 */
	public static function allow_url( $back ) {
		$state = Ordryx_State::get();
		if ( 'pairing' !== $state['phase'] || $state['pairing_until'] < time() || Ordryx_Site::elsewhere() ) {
			return '';
		}
		return add_query_arg(
			array(
				'wc-auth-version' => 1,
				'wc-auth-route'   => 'authorize',
				'app_name'        => rawurlencode( 'Ordryx' ),
				'scope'           => 'read',
				'user_id'         => rawurlencode( $state['pairing'] ),
				'return_url'      => rawurlencode( $back ),
				'callback_url'    => rawurlencode( Ordryx_Account::key_url() ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Back from the Allow screen: WooCommerce says whether the key was approved
	 * (and only approves once the Ordryx took it). Believed only for the pairing
	 * waiting here, and only once the account confirms.
	 *
	 * @param string $success "1" or "0", as WooCommerce sent it.
	 * @param string $pairing The pairing id WooCommerce sent back.
	 * @return string "connected", "denied", "stale", "copy" or the check's
	 *                result.
	 */
	public static function returned( $success, $pairing ) {
		$state = Ordryx_State::get();
		if ( 'pairing' !== $state['phase'] || '' === $state['pairing'] || ! hash_equals( $state['pairing'], (string) $pairing ) ) {
			return 'stale';
		}
		if ( Ordryx_Site::elsewhere() ) {
			return 'copy'; // (nothing kept, nothing asked)
		}
		if ( '1' !== (string) $success ) {
			return 'denied';
		}
		$user = wp_get_current_user();
		Ordryx_State::update(
			array(
				'connected_by' => $user->display_name,
				'connected_at' => time(),
			)
		);
		// The key WooCommerce just made is listed now, whatever the check
		// says, so a Cancel or a new code never leaves it untracked.
		$newest = Ordryx_Keys::newest( $user->ID );
		if ( $newest ) {
			Ordryx_State::keep_keys( array_merge( Ordryx_State::keys(), array( $newest ) ) );
		}
		return self::check();
	}

	/**
	 * Cancel a pairing (before Connect, or after the Allow screen's Deny).
	 *
	 * @return string "cancelled", or "copy" (this site looks like a copy of the
	 *                one pairing: nothing sent, nothing forgotten).
	 */
	public static function cancel() {
		$before = self::moment();
		if ( 'pairing' === $before['phase'] ) {
			$answer = Ordryx_Account::call( 'cancel', array(), 10 );
			if ( $answer['copy'] ) {
				return 'copy';
			}
			if ( 409 === $answer['status'] && isset( $answer['body']['error'] ) && 'connected' === $answer['body']['error'] ) {
				// WooCommerce's key was taken before the Cancel got there: the
				// connection stands, and the check says so.
				self::check();
				return 'stood';
			}
			if ( self::moment() === $before ) {
				Ordryx_State::forget();
				self::unschedule();
			} elseif ( in_array( Ordryx_State::phase(), array( 'connected', 'paused' ), true ) ) {
				return 'stood'; // (the Allow screen's return in another tab)
			}
		}
		return 'cancelled';
	}

	/**
	 * Where the connection stands, to tell whether it changed while a call
	 * was waiting for its answer (another request, another tab).
	 *
	 * @return array
	 */
	public static function moment() {
		$state = Ordryx_State::fresh();
		return array(
			'store'     => $state['store'],
			'secret'    => Ordryx_State::secret(),
			'phase'     => $state['phase'],
			'off_since' => $state['off_since'],
			'key_id'    => $state['key_id'],
		);
	}

	/**
	 * The daily check (Action Scheduler's job).
	 */
	public static function daily() {
		self::check();
		Ordryx_Erasure::rearm();
	}

	/**
	 * Ask the account where this store stands, and follow it. Run daily, when
	 * the settings tab opens (at most once a minute) and after the Allow screen.
	 *
	 * From a copy of the site at another address, only the tab's check is
	 * sent (Ordryx's server, knowing the store's address, refuses it, or says
	 * this site is the store after all), and what it says about the store is
	 * never followed here. From a copy found by its environment type, nothing:
	 * it's at the store's address, so the answer would be the store's.
	 *
	 * @param bool $looking An Administrator at the tab asked.
	 * @return string "connected", "paused", "pairing", "disconnected",
	 *                "blocked" (the site's proof wasn't heard), "refused"
	 *                (the Ordryx didn't take the key), "unanswered" (the
	 *                Ordryx never answered with the key), "unknown",
	 *                "copy" (this site looks like a copy of the store) or
	 *                "unreachable".
	 */
	public static function check( $looking = false ) {
		$state = Ordryx_State::get();
		if ( '' === $state['store'] ) {
			if ( '' !== $state['phase'] ) {
				Ordryx_State::forget(); // (no store to ask: nothing to be connected to)
			}
			return 'disconnected';
		}
		$why = Ordryx_Site::why();
		if ( '' !== $why && ! ( $looking && 'address' === $why ) ) {
			return 'copy'; // (the daily check, a nudge's or the Allow screen's, or a copy at the store's own address: nothing sent)
		}
		if ( Ordryx_State::secret_lost() ) {
			// wp-config.php's keys changed since it was sealed: nothing can be
			// signed, so nothing is asked; a new code connects again.
			Ordryx_State::update( array( 'phase' => 'unknown' ) );
			return 'unknown';
		}
		$before = self::moment();
		$answer = Ordryx_Account::call( 'check', array(), Ordryx_Account::TIMEOUT, $looking );
		$body   = $answer['body'];
		// The answer took a while: if the connection changed meanwhile
		// (Disconnect, Cancel, a new code, Pause, Resume), it's about one
		// that's gone; the next check asks again.
		if ( self::moment() !== $before ) {
			return 'unreachable';
		}
		Ordryx_State::update( array( 'checked_at' => time() ) );
		if ( $answer['copy'] || Ordryx_Site::elsewhere() ) {
			return 'copy'; // (refused as a copy, or still one: the store's answer, never followed here)
		}
		if ( self::unknown_store( $answer ) ) {
			// The account says it doesn't know this store: never a reason to
			// delete anything here, only to connect again; the tab offers to
			// delete Ordryx's key (earlier_keys). A refused signature, a clock
			// that's off or a proxy's 404 is only "unreachable".
			Ordryx_State::update( array( 'phase' => 'unknown' ) );
			return 'unknown';
		}
		if ( 200 !== $answer['status'] ) {
			return 'unreachable';
		}
		$stand = isset( $body['state'] ) ? (string) $body['state'] : '';
		if ( 'disconnected' === $stand ) {
			self::disconnected();
			return 'disconnected';
		}
		if ( 'refused' === $stand && 'pairing' === $state['phase'] ) {
			// Ordryx's server couldn't hear this site's proof (a firewall or a
			// security plugin in the way), the Ordryx didn't take the key, or
			// it never answered: WooCommerce's key went nowhere either way. An
			// account before 0.25.14 said only the first, without a why.
			Ordryx_State::forget();
			self::unschedule();
			$why = isset( $body['why'] ) ? (string) $body['why'] : 'proof';
			if ( 'unanswered' === $why ) {
				return 'unanswered';
			}
			return 'proof' === $why ? 'blocked' : 'refused';
		}
		if ( ! in_array( $stand, array( 'pairing', 'connected', 'paused' ), true ) ) {
			return 'unreachable';
		}
		$changes = array(
			'phase'         => $stand,
			'failing_since' => 0, // (Ordryx's server just answered: "Late" no longer)
		);
		if ( isset( $body['ordryx'] ) && is_array( $body['ordryx'] ) ) {
			// (onto the record as it's stored now: a tick made while this check was
			// asked stays)
			$changes['ordryx'] = array_merge( Ordryx_State::fresh()['ordryx'], self::ordryx( $body['ordryx'], '' ) );
		}
		$key_id = isset( $body['key_id'] ) ? (int) $body['key_id'] : 0;
		$row    = 'pairing' !== $stand && $key_id ? Ordryx_Keys::row( $key_id ) : null;
		if ( $row ) {
			// This Ordryx's key: every earlier Ordryx key here goes.
			$changes['key_id']   = $key_id;
			$changes['key_mark'] = Ordryx_Keys::mark( $row );
			foreach ( Ordryx_State::keys() as $old ) {
				if ( $old !== $key_id ) {
					Ordryx_Keys::delete( $old );
				}
			}
			Ordryx_State::keep_keys( array( $key_id ) );
		}
		if ( 'pairing' !== $stand ) {
			$changes['pairing']       = '';
			$changes['pairing_until'] = 0;
		}
		if ( 'connected' === $stand ) {
			$changes['off_since'] = 0;
		}
		Ordryx_State::update( $changes );
		if ( $stand !== $state['phase'] ) {
			Ordryx_Dashboard::forget(); // (paused or resumed in Ordryx: the look kept is old)
		}
		if ( 'pairing' !== $stand ) {
			self::schedule_check();
		}
		return $stand;
	}

	/**
	 * Something asked of the Ordryx from the settings tab: an Administrator's
	 * Resume, Disconnect, Pause or sales channels, or anyone's Sync now (the
	 * Ordryx refuses a change from anyone else: the account passes on who).
	 *
	 * @param string $ask      "resume", "disconnect", "pause", "flows" or "sync".
	 * @param array  $channels For "flows": channel => on.
	 * @return string "done", "done_nokey" (disconnected, but there was no
	 *                Ordryx key here to delete), "done_left" (disconnected, but
	 *                its key was changed in WooCommerce, so it stays),
	 *                "first_sync", "running",
	 *                "paused", "clock" or "unreadable" (refused, saying
	 *                why), "refused", "copy" (this site looks like a copy of
	 *                the store: nothing sent), "unreachable" (nothing
	 *                answered), "unlinked" (the Ordryx isn't in touch with
	 *                Ordryx's server: nothing sent to it), "slow" (sent, not
	 *                answered in time: it may still be done), "busy"
	 *                (Ordryx's server couldn't take it: nothing done) or
	 *                "unsure" (any other trouble on the way).
	 */
	public static function ask( $ask, array $channels = array() ) {
		$when = array(
			'resume'     => array( 'paused' ),
			'disconnect' => array( 'connected', 'paused', 'unknown' ),
			'pause'      => array( 'connected' ),
			'flows'      => array( 'connected', 'paused' ),
			'sync'       => array( 'connected' ),
		);
		if ( ! isset( $when[ $ask ] ) ) {
			return 'refused';
		}
		$phase = Ordryx_State::phase();
		if ( ! in_array( $phase, $when[ $ask ], true ) ) {
			return 'sync' === $ask && 'paused' === $phase ? 'paused' : 'refused';
		}
		$body = array(
			'ask'   => $ask,
			'by'    => wp_get_current_user()->display_name,
			'admin' => current_user_can( 'manage_options' ),
		);
		if ( 'flows' === $ask ) {
			$body['channels'] = $channels;
		}
		$before = self::moment();
		$had    = $before['key_id'] && Ordryx_Keys::row( $before['key_id'] );
		$answer = Ordryx_Account::call( 'page', $body, 20 );
		if ( $answer['copy'] ) {
			return 'copy';
		}
		Ordryx_Dashboard::forget(); // (whatever it did: the next look is asked afresh)
		$trouble = self::trouble( $answer );
		if ( '' !== $trouble ) {
			return $trouble;
		}
		if ( 200 !== $answer['status'] || empty( $answer['body']['ok'] ) ) {
			$why = isset( $answer['body']['why'] ) ? $answer['body']['why'] : '';
			return in_array( $why, array( 'first_sync', 'running', 'paused', 'clock', 'unreadable' ), true ) ? $why : 'refused';
		}
		if ( 'disconnect' === $ask ) {
			// (Its keys go whatever happened meanwhile: Ordryx has let go.)
			self::disconnected();
			if ( '' !== self::key_left() ) {
				return 'done_left';
			}
			return $had && ! Ordryx_Keys::row( $before['key_id'] ) ? 'done' : 'done_nokey';
		}
		if ( in_array( $ask, array( 'resume', 'pause' ), true ) && self::moment() === $before ) {
			Ordryx_State::update(
				'resume' === $ask
					? array(
						'phase'     => 'connected',
						'off_since' => 0,
					)
					: array( 'phase' => 'paused' )
			);
		}
		return 'done';
	}

	/**
	 * What an ask's answer says went wrong on the way, if anything: the
	 * account's 503 "unlinked" was never sent to the Ordryx, its 504 was and
	 * wasn't answered in time (the Ordryx may still do it), a 429 or its own
	 * 503 is the account too busy to take it. Never the firewall sentence for
	 * an answer that came.
	 *
	 * @param array $answer Ordryx_Account::call's answer.
	 * @return string "" (an answer to read), "unreachable", "unlinked",
	 *                "slow", "busy" or "unsure".
	 */
	private static function trouble( array $answer ) {
		$status = (int) $answer['status'];
		$error  = isset( $answer['body']['error'] ) ? $answer['body']['error'] : '';
		if ( 0 === $status ) {
			return 'unreachable';
		}
		if ( 503 === $status && 'unlinked' === $error ) {
			return 'unlinked';
		}
		if ( 504 === $status && 'unlinked' === $error ) {
			return 'slow';
		}
		if ( 429 === $status || 503 === $status ) {
			return 'busy';
		}
		return $status >= 500 ? 'unsure' : '';
	}

	/**
	 * Whether Ordryx's key was deleted in WooCommerce (WooCommerce › Settings ›
	 * Advanced › REST API): the connection's key is gone, or, while it isn't
	 * known yet which key that is, every Ordryx key listed here is. A key
	 * renamed or given Read/Write is still there (it still reads). Nothing
	 * listed at all isn't known, so it isn't gone.
	 *
	 * @return bool
	 */
	public static function key_gone() {
		$key_id = (int) Ordryx_State::get()['key_id'];
		if ( $key_id > 0 ) {
			return null === Ordryx_Keys::any( $key_id );
		}
		$listed = Ordryx_State::keys();
		if ( ! $listed ) {
			return false;
		}
		foreach ( $listed as $id ) {
			if ( null !== Ordryx_Keys::any( $id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether Ordryx's key is still there but was changed in WooCommerce
	 * (renamed, or given Read/Write): it still reads, but this plugin deletes
	 * only a read-only key named Ordryx.
	 *
	 * @return bool
	 */
	public static function key_changed() {
		$key_id = (int) Ordryx_State::get()['key_id'];
		$row    = $key_id > 0 ? Ordryx_Keys::any( $key_id ) : null;
		return $row && Ordryx_Keys::reads( $row ) && null === Ordryx_Keys::row( $key_id );
	}

	/**
	 * Whether Ordryx's key is still there but was set to Write in WooCommerce:
	 * WooCommerce refuses every read made with a Write key, so the Ordryx can't
	 * read orders until it's set back to Read.
	 *
	 * @return bool
	 */
	public static function key_write_only() {
		$key_id = (int) Ordryx_State::get()['key_id'];
		$row    = $key_id > 0 ? Ordryx_Keys::any( $key_id ) : null;
		return $row && ! Ordryx_Keys::reads( $row );
	}

	/**
	 * Ordryx no longer has this store: delete its keys here and forget it.
	 *
	 * @return bool Whether the connection's own key was there to delete.
	 */
	public static function disconnected() {
		$current = (int) Ordryx_State::get()['key_id'];
		$deleted = self::delete_keys();
		self::unschedule();
		Ordryx_State::forget();
		Ordryx_Dashboard::forget();
		return $current > 0 && in_array( $current, $deleted, true );
	}

	/**
	 * Delete every Ordryx key WooCommerce made here (an earlier connection's
	 * too), and forget their ids. The connection's own key, once renamed or
	 * given Read/Write in WooCommerce, isn't deleted (someone may use it for
	 * something else now), but it can still read: it's remembered, so the tab
	 * can say so (key_left).
	 *
	 * @return int[] The ids deleted.
	 */
	public static function delete_keys() {
		$deleted = array();
		$own     = (int) Ordryx_State::get()['key_id'];
		$ids     = array_merge( Ordryx_State::keys(), array( $own ) );
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( Ordryx_Keys::delete( $id ) ) {
				$deleted[] = $id;
			}
		}
		$left = $own && ! in_array( $own, $deleted, true ) ? Ordryx_Keys::any( $own ) : null;
		if ( $left ) {
			update_option(
				Ordryx_State::KEY_LEFT,
				array(
					'id'  => $own,
					'end' => Ordryx_Keys::mark( $left )['end'],
				),
				false
			);
		}
		Ordryx_State::keep_keys( array() );
		return $deleted;
	}

	/**
	 * The end of a key this plugin left (delete_keys), as WooCommerce's REST
	 * API screen shows it, while that same key is still there; else "".
	 *
	 * @return string
	 */
	public static function key_left() {
		$left = get_option( Ordryx_State::KEY_LEFT, array() );
		if ( ! is_array( $left ) || empty( $left['id'] ) || empty( $left['end'] ) ) {
			return '';
		}
		$row = Ordryx_Keys::any( (int) $left['id'] );
		return $row && Ordryx_Keys::mark( $row )['end'] === (string) $left['end'] ? (string) $left['end'] : '';
	}

	/**
	 * Ordryx keys still in the store from a connection that's over: every one
	 * listed while nothing is connected, or while Ordryx's server doesn't know
	 * the store ("unknown"), the connection's own too; else every one but the
	 * connection's own, and none while it's not yet known which that is (a
	 * key in use is never offered).
	 *
	 * An unknown store's own key is offered because an Ordryx may still hold
	 * it: one removed from its Ordryx account keeps its key and reads on, and
	 * once the server has let the store's record go, a check can no longer
	 * tell the plugin "disconnected". Only an Administrator can tell whether
	 * that Ordryx should keep reading, so it's offered, never deleted here.
	 *
	 * @return int[]
	 */
	public static function earlier_keys() {
		$state   = Ordryx_State::get();
		$current = (int) $state['key_id'];
		$over    = in_array( $state['phase'], array( '', 'unknown' ), true );
		if ( ! $over && 0 === $current ) {
			return array();
		}
		$ids = $over ? array_merge( Ordryx_State::keys(), array( $current ) ) : Ordryx_State::keys();
		return array_values(
			array_filter(
				array_unique( array_filter( $ids ) ),
				static function ( $id ) use ( $current, $over ) {
					return ( $over || $id !== $current ) && null !== Ordryx_Keys::row( $id );
				}
			)
		);
	}

	/**
	 * Whether the earlier keys offered include the connection's own (a store
	 * Ordryx's server doesn't know: the tab says so).
	 *
	 * @return bool
	 */
	public static function own_key_left() {
		$current = (int) Ordryx_State::get()['key_id'];
		return $current > 0 && in_array( $current, self::earlier_keys(), true );
	}

	/**
	 * Delete the earlier keys (the tab's button).
	 *
	 * @return int[] The ids deleted.
	 */
	public static function delete_earlier_keys() {
		$earlier = self::earlier_keys();
		foreach ( $earlier as $id ) {
			Ordryx_Keys::delete( $id );
		}
		Ordryx_State::keep_keys( array_diff( Ordryx_State::keys(), $earlier ) );
		return $earlier;
	}

	/**
	 * The plugin switched off: Ordryx pauses from now (as Disconnect does for
	 * stock), and nothing is scheduled while it's off. On a copy of the site,
	 * nothing is sent and nothing changes: the store it was copied from stays
	 * as it is.
	 *
	 * Switched off for a whole multisite network, WordPress runs this once, as
	 * the main site: each site's store pauses, as that site (the sites share
	 * 30 seconds for telling Ordryx; one past it is still unscheduled, and its
	 * Ordryx finds the plugin silent). A site where the plugin was activated
	 * on its own keeps running it, so its store is left as it is.
	 *
	 * @param bool $network_wide Switched off for the whole network.
	 */
	public static function deactivated( $network_wide = false ) {
		if ( $network_wide && is_multisite() && function_exists( 'get_sites' ) ) {
			$until = Ordryx_Clock::now() + 30;
			$file  = plugin_basename( ORDRYX_FILE );
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				// (one that activated the plugin itself still runs it; one with no
				// store has at most a check left over to unschedule)
				if ( ! in_array( $file, (array) get_option( 'active_plugins', array() ), true ) ) {
					if ( '' === Ordryx_State::get()['store'] ) {
						self::unschedule();
					} else {
						self::deactivated_here( Ordryx_Clock::now() + 10 <= $until );
					}
				}
				restore_current_blog();
			}
			return;
		}
		self::deactivated_here( true );
	}

	/**
	 * The plugin switched off on this site.
	 *
	 * @param bool $tell Whether there's time to tell Ordryx.
	 */
	private static function deactivated_here( $tell ) {
		$state = Ordryx_State::get();
		if ( ! $tell ) {
			$state['phase'] = ''; // (nothing sent: only unscheduled)
		}
		if ( in_array( $state['phase'], array( 'connected', 'paused' ), true ) && Ordryx_Site::here() ) {
			$user   = wp_get_current_user();
			$answer = Ordryx_Account::call(
				'pause',
				array(
					'at' => time(),
					'by' => $user ? $user->display_name : '',
				),
				10
			);
			if ( ! $answer['copy'] ) {
				// (only while it's still that store's: a Disconnect in another tab
				// while the account answered stands)
				Ordryx_State::update(
					array(
						'phase'     => 'paused',
						'off_since' => time(),
					),
					$state['store']
				);
			}
		}
		Ordryx_Dashboard::forget();
		self::unschedule();
	}

	/**
	 * The plugin deleted: Ordryx's key goes, erasures still waiting are sent
	 * once more, everything the plugin kept goes, and the account is told (a
	 * reason for the Ordryx to check, never proof: it believes it once the
	 * store refuses its key). On a copy of the site, only its own copies of
	 * those go: nothing is sent.
	 *
	 * @param int $until Unix seconds the erasures' last send may run to (a
	 *                   network's sites share one budget: uninstall.php).
	 */
	public static function uninstalled( $until = 0 ) {
		// The key first, so the Ordryx finds it refused when it checks.
		self::delete_keys();
		self::unschedule();
		if ( '' !== Ordryx_State::get()['store'] && Ordryx_Site::here() ) {
			// (what can't be sent now is lost: nothing is left to send it)
			Ordryx_Erasure::last_send( $until ? $until : Ordryx_Clock::now() + Ordryx_Erasure::LAST_WAIT );
			// (only a reason for the Ordryx to check: left out past a network's
			// shared time, as it finds the key refused anyway)
			if ( ! $until || Ordryx_Clock::now() + 10 <= $until ) {
				$user = wp_get_current_user();
				Ordryx_Account::call( 'gone', array( 'by' => $user ? $user->display_name : '' ), 10 );
			}
		}
		foreach ( Ordryx_State::OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( Ordryx_State::LOOK );
		Ordryx_Lock::delete_all();
	}

	/**
	 * Forget the connection here, on a copy of the site: its record and secret
	 * only, never telling the account, so the store it was copied from stays
	 * connected. Ordryx's key in this site's WooCommerce, the key ids, the
	 * erasures waiting and the shipments done stay (this may be the store
	 * itself, wrongly taken for a copy: then connecting again with a new code
	 * carries on from where it stopped; the tab offers to delete a key left).
	 *
	 * Where the address is what differs, Ordryx's server, which knows the
	 * store's, has its say first: only its "not the store's address" forgets,
	 * or its "no such store" (the store connected again since, with a new id,
	 * or disconnected long enough ago that its record is gone: this one acts
	 * for nothing). A copy found by its environment type forgets without
	 * asking (it's at the store's address: the account can't tell).
	 *
	 * @return string "forgot", "forgot_same" (forgotten at the store's own
	 *                address, as a copy by its environment type), "here"
	 *                (Ordryx's server says this is the store),
	 *                "not_copy" (this site doesn't look like a copy any more:
	 *                nothing asked), "unreachable" or "forget_failed".
	 */
	public static function forget_here() {
		$why = Ordryx_Site::why();
		if ( '' === $why ) {
			return 'not_copy';
		}
		if ( 'address' === $why ) {
			$answer = Ordryx_Account::call( 'check', array(), Ordryx_Account::TIMEOUT, true );
			if ( ! $answer['copy'] && ! self::unknown_store( $answer ) ) {
				$now = Ordryx_Site::why();
				if ( '' === $now ) {
					return 'here'; // (its address is kept again: the store, after all)
				}
				if ( 'address' === $now ) {
					return ( 0 === $answer['status'] || $answer['status'] >= 500 ) ? 'unreachable' : 'forget_failed';
				}
				// (the store's address after all, but WordPress calls it staging
				// now: a copy by its environment type, which forgets without asking)
			}
		}
		// (at the store's own address, only the store itself can connect again:
		// a copy needs an address of its own first, and the result says so)
		$same = 'environment' === Ordryx_Site::why();
		// (the connection's own key listed with the rest, so it's never lost track of)
		Ordryx_State::keep_keys( array_merge( Ordryx_State::keys(), array( (int) Ordryx_State::get()['key_id'] ) ) );
		Ordryx_State::forget();
		self::unschedule();
		Ordryx_Dashboard::forget();
		return $same ? 'forgot_same' : 'forgot';
	}

	/**
	 * Whether the account answered that it doesn't know the store a call was
	 * signed for (401 or 404 {error: store}). Not a refused signature, a clock
	 * that's off, or a 404 without that error (a proxy's).
	 *
	 * @param array $answer Ordryx_Account::call's answer.
	 * @return bool
	 */
	private static function unknown_store( array $answer ) {
		return in_array( $answer['status'], array( 401, 404 ), true )
			&& isset( $answer['body']['error'] ) && 'store' === $answer['body']['error'];
	}

	/**
	 * Nothing of the plugin's left scheduled.
	 *
	 * Without Action Scheduler loaded, its pending actions in this site's own
	 * table are cancelled there directly, as Action Scheduler cancels them:
	 * deleting the plugin from a network's admin clears each site from the
	 * main one, where WooCommerce may not be active, and a site's pending
	 * daily check would otherwise keep failing there, with no plugin left to
	 * run it.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Action Scheduler's own table, while Action Scheduler isn't loaded to ask.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return; // (WooCommerce never ran on this site)
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}actionscheduler_actions SET status = 'canceled' WHERE status = 'pending' AND hook IN (%s, %s, %s)",
				self::CHECK_HOOK,
				self::FORGET_HOOK,
				self::NUDGE_HOOK
			)
		);
		// phpcs:enable
	}

	/**
	 * What the plugin keeps of the Ordryx's own description of itself: short
	 * plain text, an address that's a host name. At pairing every field; from a
	 * check, only those it gave.
	 *
	 * @param array  $given As the account sent it.
	 * @param string $code  The code it was paired with.
	 * @return array
	 */
	private static function ordryx( array $given, $code ) {
		$kept = array();
		if ( '' !== $code ) {
			$kept['code'] = $code;
			// The Ordryx's own Link key, public half, given only at pairing (never
			// by a check): each "shipped" must be signed with it.
			$key         = isset( $given['key'] ) && is_string( $given['key'] ) ? $given['key'] : '';
			$kept['key'] = preg_match( '/^[A-Za-z0-9_-]{43}$/D', $key ) ? $key : '';
		}
		foreach ( array( 'name', 'code_by' ) as $field ) {
			if ( array_key_exists( $field, $given ) || '' !== $code ) {
				$kept[ $field ] = self::text( isset( $given[ $field ] ) ? $given[ $field ] : '', 80 );
			}
		}
		if ( array_key_exists( 'address', $given ) || '' !== $code ) {
			$address         = isset( $given['address'] ) && is_string( $given['address'] ) ? strtolower( $given['address'] ) : '';
			$kept['address'] = preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/D', $address ) && strlen( $address ) <= 253 ? $address : '';
		}
		if ( array_key_exists( 'stores', $given ) || '' !== $code ) {
			$kept['stores'] = isset( $given['stores'] ) ? max( 0, (int) $given['stores'] ) : 0;
		}
		return $kept;
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
