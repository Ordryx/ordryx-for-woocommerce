<?php
/**
 * The connected store's dashboard on the Ordryx tab: what flows, drawn from
 * what the store's Ordryx says.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the store's Ordryx says about it ("look"), asked through Ordryx's
 * server and kept for half a minute, and the parts of the tab drawn from it.
 * Administrators change what flows (Pause, the sales channels); Shop Managers
 * see it all and may press Sync now. Everything from the far end
 * is checked for its type and size here too, and drawn escaped.
 */
class Ordryx_Dashboard {

	const LOOK      = Ordryx_State::LOOK;
	const LOOK_FOR  = 30;
	const CHANNELS  = array( 'online', 'pos', 'admin', 'other' );
	const KINDS     = array( 'synced', 'sync_failed', 'paused', 'resumed', 'channels', 'shipped', 'connected' );
	const STATUS_ID = '/^[a-z0-9_-]{1,40}$/D';

	/**
	 * The sales channels' names, in the order they're shown.
	 *
	 * @return array key => name.
	 */
	public static function channel_names() {
		return array(
			'online' => __( 'Online checkout', 'ordryx-for-woocommerce' ),
			'pos'    => __( 'Point of Sale', 'ordryx-for-woocommerce' ),
			'admin'  => __( 'Typed in by staff', 'ordryx-for-woocommerce' ),
			'other'  => __( 'Other apps', 'ordryx-for-woocommerce' ),
		);
	}

	/**
	 * What the store's Ordryx says about it now, or why it couldn't be asked.
	 *
	 * @param bool $fresh Ask again even if it was asked within LOOK_FOR seconds.
	 * @return array The look's fields, or {error: "unreachable"} when nothing
	 *               came back ("copy": this site looks like a copy of the
	 *               store, so nothing was asked).
	 */
	public static function look( $fresh = false ) {
		$state = Ordryx_State::get();
		if ( ! in_array( $state['phase'], array( 'connected', 'paused' ), true ) ) {
			return array( 'error' => 'unreachable' );
		}
		$kept = get_transient( self::LOOK );
		if ( ! $fresh && is_array( $kept ) && isset( $kept['store'], $kept['look'] ) && $kept['store'] === $state['store'] ) {
			return $kept['look'];
		}
		$before = array( Ordryx_Connection::moment(), self::generation() );
		$answer = Ordryx_Account::call(
			'page',
			array(
				'ask'   => 'look',
				'by'    => '',
				'admin' => current_user_can( 'manage_options' ),
			),
			10
		);
		$body   = $answer['body'];
		if ( $answer['copy'] ) {
			return array( 'error' => 'copy' );
		}
		if ( 200 !== $answer['status'] || empty( $body['ok'] ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return array( 'error' => 'unreachable' );
		}
		if ( isset( $body['data']['here'] ) && false === $body['data']['here'] ) {
			// (that Ordryx let the store go: the check hears it and deletes the key)
			Ordryx_Connection::check();
			return array( 'error' => 'unreachable' );
		}
		$look = self::clean( $body['data'] );
		if ( array( Ordryx_Connection::moment(), self::generation() ) !== $before ) {
			// Changed while it was asked (a Pause, a switch, a check, from
			// another tab or person): shown this once, never kept.
			return $look;
		}
		$phase = $before[0]['phase'];
		if ( ( 'paused' === $phase ) !== $look['paused'] ) {
			// (paused or resumed in Ordryx: the tab follows, a Shop Manager's too)
			Ordryx_State::update(
				$look['paused'] ? array( 'phase' => 'paused' ) : array(
					'phase'     => 'connected',
					'off_since' => 0,
				)
			);
		}
		set_transient(
			self::LOOK,
			array(
				'store' => $state['store'],
				'look'  => $look,
			),
			self::LOOK_FOR
		);
		return $look;
	}

	/**
	 * Forget the look kept (something changed: the next one is asked afresh).
	 */
	public static function forget() {
		update_option( Ordryx_State::LOOK_GEN, self::generation() + 1, false );
		delete_transient( self::LOOK );
	}

	/**
	 * How many times the look was forgotten, as stored now.
	 *
	 * @return int
	 */
	private static function generation() {
		wp_cache_delete( Ordryx_State::LOOK_GEN, 'options' );
		wp_cache_delete( 'notoptions', 'options' ); // (it may have been made since this request found none)
		return (int) get_option( Ordryx_State::LOOK_GEN, 0 );
	}

	/**
	 * The look's fields, each of its type and cut to size; anything else dropped.
	 *
	 * @param array $data As the account passed it on.
	 * @return array
	 */
	public static function clean( array $data ) {
		$look     = array(
			'paused'         => isset( $data['paused'] ) && true === $data['paused'],
			'running'        => isset( $data['running'] ) && true === $data['running'],
			'first_sync_due' => isset( $data['first_sync_due'] ) && true === $data['first_sync_due'],
			'paused_since'   => self::time( isset( $data['paused_since'] ) ? $data['paused_since'] : null ),
			'next_sync'      => self::time( isset( $data['next_sync'] ) ? $data['next_sync'] : null ),
			'sync_every'     => isset( $data['sync_every'] ) && is_int( $data['sync_every'] ) ? max( 0, min( 10080, $data['sync_every'] ) ) : 0,
			'channels'       => array(),
			'shipped'        => array(),
			'last_sync'      => null,
			'recent'         => array(),
		);
		$channels = isset( $data['channels'] ) && is_array( $data['channels'] ) ? $data['channels'] : array();
		foreach ( $channels as $c ) {
			if ( is_array( $c ) && isset( $c['key'], $c['on'] ) && in_array( $c['key'], self::CHANNELS, true ) && is_bool( $c['on'] ) ) {
				$look['channels'][ $c['key'] ] = $c['on'];
			}
		}
		$shipped = isset( $data['shipped'] ) && is_array( $data['shipped'] ) ? array_slice( $data['shipped'], 0, 20 ) : array();
		foreach ( $shipped as $s ) {
			if ( is_string( $s ) && preg_match( self::STATUS_ID, $s ) ) {
				$look['shipped'][] = $s;
			}
		}
		if ( isset( $data['last_sync'] ) && is_array( $data['last_sync'] ) ) {
			$last              = $data['last_sync'];
			$look['last_sync'] = array(
				'at'             => self::time( isset( $last['at'] ) ? $last['at'] : null ),
				'status'         => isset( $last['status'] ) && in_array( $last['status'], array( 'success', 'partial', 'error' ), true ) ? $last['status'] : 'error',
				'automatic'      => isset( $last['automatic'] ) && true === $last['automatic'],
				'new_orders'     => isset( $last['new_orders'] ) && is_int( $last['new_orders'] ) ? max( 0, $last['new_orders'] ) : 0,
				'shipped_orders' => isset( $last['shipped_orders'] ) && is_int( $last['shipped_orders'] ) ? max( 0, $last['shipped_orders'] ) : 0,
			);
		}
		$recent = isset( $data['recent'] ) && is_array( $data['recent'] ) ? array_slice( $data['recent'], 0, 6 ) : array();
		foreach ( $recent as $r ) {
			if ( is_array( $r ) && isset( $r['kind'] ) && in_array( $r['kind'], self::KINDS, true ) ) {
				$look['recent'][] = array(
					'at'   => self::time( isset( $r['at'] ) ? $r['at'] : null ),
					'kind' => $r['kind'],
					'text' => self::text( isset( $r['text'] ) ? $r['text'] : '', 200 ),
				);
			}
		}
		return $look;
	}

	/**
	 * A moment from the far end, as Unix seconds (0: none, or not a time).
	 *
	 * @param mixed $value ISO 8601 text.
	 * @return int
	 */
	private static function time( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 40 || ! preg_match( '/^\d{4}-\d{2}-\d{2}T/', $value ) ) {
			return 0;
		}
		$at = strtotime( $value );
		return false === $at ? 0 : $at;
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

	/**
	 * The banner's second line: when it last synced, or why it hasn't.
	 *
	 * @param array $look   The look.
	 * @param bool  $paused Whether it's paused.
	 * @return string
	 */
	public static function sync_line( array $look, $paused ) {
		if ( isset( $look['error'] ) ) {
			return __( 'Your Ordryx didn\'t answer just now', 'ordryx-for-woocommerce' );
		}
		if ( $paused ) {
			return $look['paused_since']
				/* translators: %s: when it was paused */
				? sprintf( __( 'paused since %s', 'ordryx-for-woocommerce' ), self::when( $look['paused_since'] ) )
				: __( 'paused', 'ordryx-for-woocommerce' );
		}
		if ( $look['running'] ) {
			return __( 'syncing now', 'ordryx-for-woocommerce' );
		}
		if ( $look['first_sync_due'] || ! $look['last_sync'] ) {
			return __( 'not synced yet: the first sync is run in Ordryx', 'ordryx-for-woocommerce' );
		}
		$last = $look['last_sync'];
		$line = 'error' === $last['status']
			/* translators: %s: when */
			? sprintf( __( 'last sync failed, %s', 'ordryx-for-woocommerce' ), self::when( $last['at'] ) )
			/* translators: %s: when */
			: sprintf( __( 'last sync %s', 'ordryx-for-woocommerce' ), self::when( $last['at'] ) );
		if ( 0 === $look['sync_every'] ) {
			/* translators: %s: the last sync */
			$line = sprintf( __( '%s · automatic sync off', 'ordryx-for-woocommerce' ), $line );
		} elseif ( $look['next_sync'] ) {
			/* translators: 1: the last sync, 2: when the next is due */
			$line = sprintf( __( '%1$s · next %2$s', 'ordryx-for-woocommerce' ), $line, self::when( $look['next_sync'] ) );
		}
		return $line;
	}

	/**
	 * How often the store's Ordryx syncs by itself, in words ("every 15
	 * minutes"), from the look's sync_every (minutes; 0: it doesn't).
	 *
	 * @param int $every Minutes.
	 * @return string
	 */
	public static function every_words( $every ) {
		$named = array(
			15   => __( 'every 15 minutes', 'ordryx-for-woocommerce' ),
			30   => __( 'every 30 minutes', 'ordryx-for-woocommerce' ),
			60   => __( 'every hour', 'ordryx-for-woocommerce' ),
			240  => __( 'every 4 hours', 'ordryx-for-woocommerce' ),
			1440 => __( 'once a day', 'ordryx-for-woocommerce' ),
		);
		$every = (int) $every;
		if ( isset( $named[ $every ] ) ) {
			return $named[ $every ];
		}
		/* translators: %d: how many minutes */
		return sprintf( _n( 'every %d minute', 'every %d minutes', $every, 'ordryx-for-woocommerce' ), $every );
	}

	/**
	 * The banner's buttons: Sync now (anyone at the tab), Pause or Resume (an
	 * Administrator's).
	 *
	 * @param array $look   The look.
	 * @param bool  $paused Whether it's paused.
	 * @param bool  $admin  Whether this person may change what flows.
	 */
	public static function render_buttons( array $look, $paused, $admin ) {
		$known = ! isset( $look['error'] );
		if ( $known && ! $paused && ! $look['first_sync_due'] ) {
			Ordryx_Admin::form( 'sync' );
			echo '<button type="submit" class="button ordryx-on-dark"' . ( $look['running'] ? ' disabled' : '' ) . '>' . esc_html__( 'Sync now', 'ordryx-for-woocommerce' ) . '</button></form>';
		}
		if ( $admin ) {
			Ordryx_Admin::form( $paused ? 'resume' : 'pause' );
			echo '<button type="submit" class="button ordryx-on-dark ordryx-strong">' . esc_html( $paused ? __( 'Resume', 'ordryx-for-woocommerce' ) : __( 'Pause', 'ordryx-for-woocommerce' ) ) . '</button></form>';
		}
	}

	/**
	 * What flows: orders in from each sales channel, shipped and cancelled,
	 * stock levels (later), and what never goes.
	 *
	 * @param array  $look    The look.
	 * @param bool   $paused  Whether it's paused.
	 * @param bool   $admin   Whether this person may change what flows.
	 * @param string $address The Ordryx's address ("" if not known).
	 */
	public static function render_flows( array $look, $paused, $admin, $address ) {
		$known = ! isset( $look['error'] );
		echo '<section class="ordryx-card ordryx-flows' . ( $paused ? ' is-paused' : '' ) . '" aria-labelledby="ordryx-flows-h">';
		echo '<h2 id="ordryx-flows-h">' . esc_html__( 'What flows', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p class="ordryx-muted">' . esc_html__( 'Changes take effect at the next sync. Administrators change them here; in Ordryx, only its owner.', 'ordryx-for-woocommerce' ) . '</p>';

		// Orders, from each sales channel.
		self::row_start( 'in', __( 'Orders', 'ordryx-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'Every order still open when you connected, and every new one: what was ordered, how many and for how much, with fees and taxes, so Ordryx can plan and make it. Products and variations come too, to link each to an item.', 'ordryx-for-woocommerce' ) . '</p>';
		if ( $known ) {
			echo '<div class="ordryx-chips" role="group" aria-label="' . esc_attr__( 'Sales channels', 'ordryx-for-woocommerce' ) . '"><span class="ordryx-muted">' . esc_html__( 'From', 'ordryx-for-woocommerce' ) . '</span>';
			foreach ( self::channel_names() as $key => $name ) {
				if ( ! isset( $look['channels'][ $key ] ) ) {
					continue;
				}
				$on = $look['channels'][ $key ];
				if ( $admin ) {
					Ordryx_Admin::form( 'flows' );
					echo '<input type="hidden" name="channel" value="' . esc_attr( $key ) . '"><input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '">';
					echo '<button type="submit" class="ordryx-chip" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . esc_html( $name ) . '</button></form>';
				} else {
					echo '<span class="ordryx-chip" aria-disabled="true" data-on="' . ( $on ? '1' : '0' ) . '">' . esc_html( $name ) . '<span class="screen-reader-text"> ' . esc_html( $on ? __( '(on)', 'ordryx-for-woocommerce' ) : __( '(off)', 'ordryx-for-woocommerce' ) ) . '</span></span>';
				}
			}
			echo '</div>';
		}
		echo '<p class="ordryx-muted">' . esc_html__( 'A channel left out is ignored altogether: its sales don\'t take stock in Ordryx either. "Other apps" are orders other plugins or apps made through WooCommerce\'s API; one from anywhere else (a subscription renewal) counts as Online checkout.', 'ordryx-for-woocommerce' ) . '</p>';
		self::row_end( __( 'Always', 'ordryx-for-woocommerce' ) );

		// Shipped, refunds and cancellations.
		self::row_start( 'in', __( 'Shipped, refunds and cancellations', 'ordryx-for-woocommerce' ) );
		$names = array();
		foreach ( $known ? $look['shipped'] : array() as $status ) {
			$names[] = self::status_name( $status );
		}
		echo '<p>' . esc_html(
			$names
				? sprintf(
					/* translators: %s: the order statuses that mean shipped, as a list */
					__( 'Stock leaves Ordryx only when an order is marked %s. A cancelled order stops counting toward the plan; a refund never puts stock back by itself.', 'ordryx-for-woocommerce' ),
					implode( __( ' or ', 'ordryx-for-woocommerce' ), $names )
				)
				: __( 'Stock leaves Ordryx only when an order is marked Completed, or another status picked in Ordryx as shipped. A cancelled order stops counting toward the plan; a refund never puts stock back by itself.', 'ordryx-for-woocommerce' )
		) . '</p>';
		echo '<p class="ordryx-muted">' . esc_html__( 'WooCommerce keeps one stock count per product, so Ordryx takes it from its main location.', 'ordryx-for-woocommerce' ) . '</p>';
		if ( $admin && '' !== $address ) {
			echo '<p><a href="' . esc_url( 'https://' . $address . '/sales/stores/woocommerce' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Change which statuses mean shipped, in Ordryx', 'ordryx-for-woocommerce' ) . '</a></p>';
		}
		self::row_end( __( 'With orders', 'ordryx-for-woocommerce' ) );

		// Shipped from Ordryx, with its tracking.
		self::row_start( 'out', __( 'Shipped and tracking', 'ordryx-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'An order shipped from Ordryx (a label bought there, or marked shipped with its tracking) is marked shipped here too, which sends WooCommerce\'s own email. Only this plugin writes it, and only that: Ordryx\'s key stays read-only.', 'ordryx-for-woocommerce' ) . '</p>';
		$homes = array(
			'fulfillments'      => __( 'Its tracking goes into WooCommerce\'s Fulfillments.', 'ordryx-for-woocommerce' ),
			'shipment-tracking' => __( 'Its tracking goes into WooCommerce Shipment Tracking.', 'ordryx-for-woocommerce' ),
			'ast'               => __( 'Its tracking goes into Advanced Shipment Tracking.', 'ordryx-for-woocommerce' ),
			'note'              => __( 'Its tracking goes to the customer as an order note with the carrier\'s link.', 'ordryx-for-woocommerce' ),
		);
		echo '<p class="ordryx-muted">' . esc_html( $homes[ Ordryx_Shipped::tracking_home() ] ) . '</p>';
		self::row_end( __( 'Always', 'ordryx-for-woocommerce' ) );

		// Stock levels: not in this version.
		self::row_start( 'out', __( 'Stock levels', 'ordryx-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'Ordryx setting how many WooCommerce shows as in stock, so you don\'t oversell. Not in this version: the key stays read-only.', 'ordryx-for-woocommerce' ) . '</p>';
		self::row_end( __( 'Later', 'ordryx-for-woocommerce' ) );

		// What never goes.
		self::row_start( 'never', __( 'Billing addresses and phones, and accounts', 'ordryx-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'Ordryx keeps who bought each order (the customer\'s name and email) and the ship-to of an order waiting to ship (its name, address and phone), for its label: the ship-to until 90 days after the order is done. Once the store is disconnected, both go, at once for orders still waiting to ship and 29 days later for the rest. Never the billing address or billing phone, nor a customer\'s account.', 'ordryx-for-woocommerce' ) . '</p>';
		self::row_end( __( 'Never kept', 'ordryx-for-woocommerce' ) );
		echo '</section>';
	}

	/**
	 * A row of What flows, up to its body.
	 *
	 * @param string $way   "in", "out" or "never".
	 * @param string $title Its title.
	 */
	private static function row_start( $way, $title ) {
		$words = array(
			'in'    => __( 'IN →', 'ordryx-for-woocommerce' ),
			'out'   => __( '← OUT', 'ordryx-for-woocommerce' ),
			'never' => __( 'NEVER', 'ordryx-for-woocommerce' ),
		);
		echo '<div class="ordryx-flow is-' . esc_attr( $way ) . '">';
		echo '<span class="ordryx-way">' . esc_html( $words[ $way ] ) . '</span>';
		echo '<div class="ordryx-flow-body"><h3>' . esc_html( $title ) . '</h3>';
	}

	/**
	 * The end of a row of What flows.
	 *
	 * @param string $side What the Ordryx side says.
	 */
	private static function row_end( $side ) {
		echo '</div><span class="ordryx-side-word">' . esc_html( $side ) . '</span></div>';
	}

	/**
	 * An order status's name, as this store calls it.
	 *
	 * @param string $status Its id, without "wc-".
	 * @return string
	 */
	private static function status_name( $status ) {
		$all = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		if ( isset( $all[ 'wc-' . $status ] ) && is_string( $all[ 'wc-' . $status ] ) ) {
			return $all[ 'wc-' . $status ];
		}
		return 'completed' === $status ? __( 'Completed', 'ordryx-for-woocommerce' ) : $status;
	}

	/**
	 * Recent activity: the store's syncs and switches in Ordryx (what
	 * happened, never who did it).
	 *
	 * @param array $look The look.
	 */
	public static function render_recent( array $look ) {
		if ( isset( $look['error'] ) || ! $look['recent'] ) {
			return;
		}
		$words = array(
			'synced'      => __( 'In', 'ordryx-for-woocommerce' ),
			'sync_failed' => __( 'Fail', 'ordryx-for-woocommerce' ),
		);
		echo '<section class="ordryx-card" aria-labelledby="ordryx-recent-h"><h2 id="ordryx-recent-h">' . esc_html__( 'Recent activity', 'ordryx-for-woocommerce' ) . '</h2><ul class="ordryx-recent">';
		foreach ( $look['recent'] as $r ) {
			$word = isset( $words[ $r['kind'] ] ) ? $words[ $r['kind'] ] : __( 'Set', 'ordryx-for-woocommerce' );
			echo '<li><b class="is-' . esc_attr( $r['kind'] ) . '">' . esc_html( $word ) . '</b><span>' . esc_html( $r['text'] ) . '</span>';
			if ( $r['at'] ) {
				echo '<time datetime="' . esc_attr( gmdate( 'c', $r['at'] ) ) . '">' . esc_html( self::when( $r['at'] ) ) . '</time>';
			}
			echo '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * A moment, in the site's own date and time format.
	 *
	 * @param int $at Unix seconds.
	 * @return string
	 */
	private static function when( $at ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $at );
	}
}
