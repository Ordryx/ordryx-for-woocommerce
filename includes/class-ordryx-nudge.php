<?php
/**
 * The nudge: "an order changed", sent to Ordryx's server, which tells the
 * store's Ordryx. It carries no order data at all, not even which order. The
 * Ordryx syncs on it (soon after, once a burst of changes is over) only once
 * the store's first sync has run there and its Sync orders automatically is
 * on; otherwise it waits for someone's Sync now.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order changes, heard from WooCommerce, sent on through Action Scheduler at
 * most once a minute and tried again while Ordryx's server doesn't answer.
 *
 * While the store is paused here, an order change first asks the store's
 * Ordryx where it stands (at most every PAUSED_CHECK): Pause and Resume are
 * the Ordryx's, and a Resume there reaches this plugin only when something
 * asks.
 */
class Ordryx_Nudge {

	const HOOK         = Ordryx_Connection::NUDGE_HOOK;
	const EVERY        = 60;
	const RETRY        = 300;
	const PAUSED_CHECK = 900;

	/**
	 * Order hooks, for orders kept in WooCommerce's own tables (HPOS) and in
	 * WordPress posts alike.
	 */
	const ORDER_HOOKS = array(
		'woocommerce_new_order',
		'woocommerce_update_order',
		'woocommerce_order_status_changed',
		'woocommerce_order_refunded',
		'woocommerce_trash_order',
		'woocommerce_untrash_order',
		'woocommerce_delete_order',
	);

	/**
	 * Post hooks, for an order kept as a post (trashed or deleted as a post).
	 */
	const POST_HOOKS = array( 'wp_trash_post', 'untrashed_post', 'before_delete_post' );

	/**
	 * Listen.
	 */
	public static function hook() {
		foreach ( self::ORDER_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'changed' ), 20, 0 );
		}
		foreach ( self::POST_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'post_changed' ), 20, 1 );
		}
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
	}

	/**
	 * A post changed: a nudge only if it's an order.
	 *
	 * @param int $post_id The post.
	 */
	public static function post_changed( $post_id ) {
		if ( 'shop_order' === get_post_type( (int) $post_id ) ) {
			self::changed();
		}
	}

	/**
	 * An order changed: one nudge is scheduled, unless one is already waiting.
	 * (One being sent doesn't count: the change may come after it was read.)
	 * None on a copy of the site: its orders aren't the store's; none while
	 * Ordryx's key is deleted here, or set to Write (WooCommerce refuses its
	 * reads): its sync could only fail. While paused,
	 * one at most every PAUSED_CHECK, to ask whether Ordryx resumed.
	 */
	public static function changed() {
		$state = Ordryx_State::get();
		if ( ! in_array( $state['phase'], array( 'connected', 'paused' ), true ) || Ordryx_Site::elsewhere()
			|| ! function_exists( 'as_schedule_single_action' ) || self::waiting() || Ordryx_Connection::key_gone()
			|| Ordryx_Connection::key_write_only() ) {
			return;
		}
		self::schedule( self::due( $state ) );
	}

	/**
	 * When a nudge may go: a minute after the last; while paused, not before
	 * the last ask of where the store stands is PAUSED_CHECK old.
	 *
	 * @param array $state The record.
	 * @return int Unix seconds.
	 */
	private static function due( array $state ) {
		$due = max( time(), (int) $state['sent_at'] + self::EVERY );
		if ( 'paused' === $state['phase'] ) {
			$due = max( $due, (int) $state['paused_asked'] + self::PAUSED_CHECK );
		}
		return $due;
	}

	/**
	 * Whether a nudge is waiting to be sent. Asked for pending ones only:
	 * Action Scheduler's "has scheduled" and "next scheduled" count the one
	 * running now too.
	 *
	 * @return bool
	 */
	private static function waiting() {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => Ordryx_Connection::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * Schedule a nudge (never "unique": that would refuse while one runs).
	 *
	 * @param int $when Unix seconds.
	 */
	private static function schedule( $when ) {
		as_schedule_single_action( $when, self::HOOK, array(), Ordryx_Connection::GROUP );
	}

	/**
	 * Send it (Action Scheduler's job). A nudge Ordryx's server didn't take is
	 * tried again in 5 minutes; the Ordryx's own timer still syncs meanwhile.
	 */
	public static function send() {
		$state = Ordryx_State::get();
		if ( ! in_array( $state['phase'], array( 'connected', 'paused' ), true ) ) {
			return;
		}
		if ( '' === $state['store'] ) {
			Ordryx_Connection::check(); // (a record with nothing to sign with: set straight)
			return;
		}
		if ( Ordryx_Site::elsewhere() ) {
			return; // (a copy of the site's, copied with its jobs: never sent, never tried again)
		}
		$due = self::due( $state );
		if ( $due > time() ) {
			// Still within a minute of the last (or of the last ask while
			// paused): one later nudge covers it.
			if ( ! self::waiting() ) {
				self::schedule( $due );
			}
			return;
		}
		if ( 'paused' === $state['phase'] && ! self::resumed( $state['store'] ) ) {
			return; // (still paused, or not known: nothing sent)
		}
		$answer = Ordryx_Account::call( 'nudge' );
		if ( $answer['copy'] ) {
			return; // (refused as a copy of the site: never tried again, never "late")
		}
		// (each onto the record as stored now, and only while it's still this
		// store's: a Disconnect while the account answered stands)
		if ( 200 === $answer['status'] ) {
			Ordryx_State::update(
				array(
					'sent_at'       => time(),
					'failing_since' => 0,
				),
				$state['store']
			);
			return;
		}
		$now = Ordryx_State::fresh();
		Ordryx_State::update( array( 'failing_since' => $now['failing_since'] ? $now['failing_since'] : time() ), $state['store'] );
		if ( in_array( $answer['status'], array( 401, 404 ), true ) ) {
			// Only the check decides the account no longer knows this store.
			$stand = Ordryx_Connection::check();
			if ( ! in_array( $stand, array( 'connected', 'unreachable' ), true ) ) {
				return;
			}
		}
		if ( ! self::waiting() ) {
			self::schedule( time() + self::RETRY );
		}
	}

	/**
	 * A paused store: whether its Ordryx says it's resumed, asked through the
	 * dashboard's look (which moves the phase as the Ordryx says; no answer
	 * changes nothing, never "connected" for an Ordryx that didn't say so).
	 *
	 * @param string $store The store the nudge was for.
	 * @return bool
	 */
	private static function resumed( $store ) {
		Ordryx_State::update( array( 'paused_asked' => time() ), $store );
		Ordryx_Dashboard::look( true );
		$now = Ordryx_State::fresh();
		return $now['store'] === $store && 'connected' === $now['phase'];
	}
}
