<?php
/**
 * The Ordryx tab in WooCommerce › Settings: the code, both sides, Connect,
 * and where the connection stands.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings tab. Administrators connect, resume and disconnect; Shop
 * Managers see where it stands. The tab is drawn after WooCommerce's settings
 * form, so its forms are its own, posted to admin-post.php with their own
 * nonce.
 */
class Ordryx_Admin {

	const TAB    = 'ordryx';
	const ACTION = 'ordryx';
	const NONCE  = 'ordryx-tab';

	/**
	 * What each step's result says (never what the far end said).
	 */
	const TERMS_URL   = 'https://ordryx.com/terms';
	const PRIVACY_URL = 'https://ordryx.com/privacy';

	const RESULTS = array(
		'permalinks',
		'gone_nokey',
		'gone_left',
		'stood',
		'old_keys',
		'blocked',
		'format',
		'code',
		'tries',
		'paused',
		'unlinked',
		'agree',
		'move_agree',
		'terms',
		'slow',
		'busy',
		'unsure',
		'unreachable',
		'failed',
		'https',
		'woocommerce',
		'connected',
		'denied',
		'stale',
		'pairing',
		'unknown',
		'disconnected',
		'cancelled',
		'resumed',
		'gone',
		'refused',
		'unanswered',
		'silent',
		'welcome',
		'synced',
		'paused_now',
		'flows',
		'first_sync',
		'running',
		'sync_paused',
		'clock',
		'unreadable',
		'key_check',
		'forgot',
		'forgot_same',
		'copy',
		'here',
		'not_copy',
		'forget_failed',
	);

	/**
	 * Listen.
	 */
	public static function hook() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'tab' ), 50 );
		add_action( 'woocommerce_settings_' . self::TAB, array( __CLASS__, 'no_save_button' ) );
		add_action( 'woocommerce_after_settings_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'act' ) );
		add_action( 'admin_init', array( __CLASS__, 'back_from_allow' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_notices', array( __CLASS__, 'plugins_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'network_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ORDRYX_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * The tab's address.
	 *
	 * @param array $args More query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'wc-settings',
					'tab'  => self::TAB,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The tab, last in WooCommerce › Settings.
	 *
	 * @param array $tabs WooCommerce's tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Ordryx', 'ordryx-for-woocommerce' );
		return $tabs;
	}

	/**
	 * The tab saves nothing through WooCommerce's form.
	 */
	public static function no_save_button() {
		$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own switch for its Save button.
	}

	/**
	 * "Settings" beside the plugin on the Plugins screen.
	 *
	 * @param array $links The plugin's links.
	 * @return array
	 */
	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ordryx-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Whether the page being shown is this tab.
	 *
	 * @return bool
	 */
	private static function on_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which page this is, nothing more.
		return isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && self::TAB === $_GET['tab'];
		// phpcs:enable
	}

	/**
	 * The tab's few styles.
	 *
	 * @param string $hook The admin page.
	 */
	public static function styles( $hook ) {
		if ( 'woocommerce_page_wc-settings' === $hook && self::on_tab() ) {
			wp_enqueue_style( 'ordryx-admin', plugins_url( 'assets/admin.css', ORDRYX_FILE ), array(), ORDRYX_VERSION );
		}
	}

	/**
	 * WooCommerce too old, or this site a copy of the store, said on the
	 * Plugins screen too (a store that moved goes quiet: this says why), and
	 * erasures still waiting to reach the Ordryx, before anyone deactivates
	 * or deletes the plugin.
	 */
	public static function plugins_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'woocommerce' === Ordryx_Connection::refused() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::sentence( 'woocommerce' ) ) . '</p></div>';
		}
		self::erasures_notice( Ordryx_Erasure::waiting() );
		if ( Ordryx_Erasure::full() ) {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: how many orders */
					__( 'Ordryx: %s orders\' erased customer data are waiting to reach an Ordryx, the most this plugin keeps, so later erasures aren\'t passed on. Connect the store so they\'re sent.', 'ordryx-for-woocommerce' ),
					number_format_i18n( Ordryx_Erasure::MOST )
				)
			) . '</p></div>';
		}
		if ( Ordryx_Site::elsewhere() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %s: the address the store was connected to Ordryx at */
					__( 'Ordryx: this site looks like a copy of %s, so nothing from here reaches the store\'s Ordryx (order changes and erasures stay here).', 'ordryx-for-woocommerce' ),
					self::paired_address()
				)
			) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the Ordryx tab', 'ordryx-for-woocommerce' ) . '</a></p></div>';
		}
	}

	/**
	 * The network's Plugins screen, where a network's plugins are deleted:
	 * erasures waiting on any of its sites (the first 100), said there too.
	 */
	public static function network_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins-network' !== $screen->id || ! current_user_can( 'manage_network_plugins' ) || ! function_exists( 'get_sites' ) ) {
			return;
		}
		$waiting = 0;
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$waiting += Ordryx_Erasure::waiting();
			restore_current_blog();
		}
		self::erasures_notice( $waiting );
	}

	/**
	 * Customer data erased here that hasn't reached the Ordryx yet.
	 *
	 * @param int $waiting How many orders.
	 */
	private static function erasures_notice( $waiting ) {
		if ( $waiting <= 0 ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %d: how many orders */
				_n(
					'Ordryx: customer data erased here (%d order) hasn\'t reached your Ordryx yet. Deactivating the plugin stops sending it; deleting the plugin sends it once more, and whatever your Ordryx can\'t take then is lost.',
					'Ordryx: customer data erased here (%d orders) hasn\'t reached your Ordryx yet. Deactivating the plugin stops sending it; deleting the plugin sends it once more, and whatever your Ordryx can\'t take then is lost.',
					$waiting,
					'ordryx-for-woocommerce'
				),
				$waiting
			)
		) . '</p></div>';
	}

	/**
	 * The address the store was connected at, to show (this site's own, when
	 * none was kept).
	 *
	 * @return string
	 */
	private static function paired_address() {
		$paired = Ordryx_Site::paired();
		return '' !== $paired ? $paired : Ordryx_Site::home();
	}

	/**
	 * Back from WooCommerce's Allow screen (WooCommerce adds success and
	 * user_id to the address it was given): settled once, then the plain tab.
	 */
	public static function back_from_allow() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce's redirect carries no nonce; the pairing id it returns is checked against the one waiting, and the result is only confirmed by asking the account.
		if ( ! self::on_tab() || ! isset( $_GET['success'], $_GET['user_id'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = Ordryx_Connection::returned(
			sanitize_text_field( wp_unslash( $_GET['success'] ) ),
			sanitize_text_field( wp_unslash( $_GET['user_id'] ) )
		);
		// phpcs:enable
		$shown  = array(
			'connected'   => 'welcome',
			'unreachable' => 'silent',
			'paused'      => '',
		);
		$result = isset( $shown[ $result ] ) ? $shown[ $result ] : $result;
		wp_safe_redirect( self::url( '' === $result ? array() : array( 'ordryx' => $result ) ) );
		exit;
	}

	/**
	 * A form on the tab was sent.
	 */
	public static function act() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- which step decides who may take it; the nonce is checked next, before anything is done.
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		// Sync now is a Shop Manager's too; everything else, an Administrator's.
		if ( ! current_user_can( 'sync' === $do ? 'manage_woocommerce' : 'manage_options' ) ) {
			wp_die( esc_html__( 'Only an Administrator can change Ordryx\'s connection.', 'ordryx-for-woocommerce' ), 403 );
		}
		check_admin_referer( self::NONCE, 'ordryx_nonce' );
		$result = 'failed';
		// The dashboard's refusals, each said in its own sentence (its asks only).
		$said = array(
			'first_sync' => 'first_sync',
			'running'    => 'running',
			'paused'     => 'sync_paused',
			'clock'      => 'clock',
			'unreadable' => 'unreadable',
		);
		switch ( $do ) {
			case 'pair':
				$typed  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
				$code   = Ordryx_Connection::code( $typed );
				$agreed = ! empty( $_POST['terms'] ); // (the code card's tick box)
				$result = '' === $code ? 'format' : Ordryx_Connection::pair( $code, $agreed );
				break;
			case 'move':
				$state = Ordryx_State::get();
				if ( '' === $state['pending_code'] || $state['pending_until'] < time() ) {
					$result = 'code';
					break;
				}
				// The terms ticked with the code, by this same person; else the
				// Move form's own tick box (a code kept by 0.5.3, or another
				// Administrator pressing Move).
				$agreed = self::move_agreed( $state ) || ! empty( $_POST['terms'] );
				$result = $agreed ? Ordryx_Connection::pair( $state['pending_code'], true, true ) : 'move_agree';
				break;
			case 'keep':
				Ordryx_State::update(
					array(
						'other_ordryx'  => '',
						'pending_code'  => '',
						'pending_until' => 0,
						'pending_terms' => 0,
					)
				);
				$result = 'cancelled';
				break;
			case 'cancel':
				$result = Ordryx_Connection::cancel();
				break;
			case 'forget_here':
				$result = Ordryx_Connection::forget_here();
				break;
			case 'resume':
				$result = Ordryx_Connection::ask( 'resume' );
				$result = 'done' === $result ? 'resumed' : $result;
				break;
			case 'disconnect':
				$result = Ordryx_Connection::ask( 'disconnect' );
				$says   = array(
					'done'       => 'gone',
					'done_nokey' => 'gone_nokey',
					'done_left'  => 'gone_left',
				);
				$result = isset( $says[ $result ] ) ? $says[ $result ] : $result;
				break;
			case 'old_keys':
				Ordryx_Connection::delete_earlier_keys();
				$result = 'old_keys';
				break;
			case 'allow':
				$state = Ordryx_State::get();
				$allow = Ordryx_Connection::allow_url( self::url() );
				if ( '' === $allow ) {
					$result = Ordryx_Site::elsewhere() ? 'copy' : 'code';
					break;
				}
				$shown = isset( $_POST['shown'] ) ? sanitize_text_field( wp_unslash( $_POST['shown'] ) ) : '';
				$key   = isset( $state['ordryx']['key'] ) ? (string) $state['ordryx']['key'] : '';
				if ( empty( $_POST['key_ok'] ) ) {
					$result = 'key_check';
					break;
				}
				if ( '' !== $key && ! hash_equals( Ordryx_Shipped::fingerprint( $key ), $shown ) ) {
					$result = 'key_check'; // (another pairing since the page showed it: look again)
					break;
				}
				$state['ordryx']['key_checked'] = ! empty( $state['ordryx']['key'] );
				Ordryx_State::update( array( 'ordryx' => $state['ordryx'] ) );
				wp_safe_redirect( $allow ); // (WooCommerce's own Allow screen, on this site)
				exit;
			case 'pause':
				$result = Ordryx_Connection::ask( 'pause' );
				$result = 'done' === $result ? 'paused_now' : ( isset( $said[ $result ] ) ? $said[ $result ] : $result );
				break;
			case 'flows':
				$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
				$on      = isset( $_POST['on'] ) ? sanitize_key( wp_unslash( $_POST['on'] ) ) : '';
				if ( in_array( $channel, Ordryx_Dashboard::CHANNELS, true ) && in_array( $on, array( '0', '1' ), true ) ) {
					$result = Ordryx_Connection::ask( 'flows', array( $channel => '1' === $on ) );
					$result = 'done' === $result ? 'flows' : ( isset( $said[ $result ] ) ? $said[ $result ] : $result );
				}
				break;
			case 'sync':
				$result = Ordryx_Connection::ask( 'sync' );
				$result = 'done' === $result ? 'synced' : ( isset( $said[ $result ] ) ? $said[ $result ] : $result );
				break;
		}
		if ( 'paired' === $result || 'elsewhere' === $result ) {
			$result = '';
		}
		wp_safe_redirect( self::url( '' === $result ? array() : array( 'ordryx' => $result ) ) );
		exit;
	}

	/**
	 * The fixed sentence for a result.
	 *
	 * @param string $result One of RESULTS.
	 * @return string
	 */
	public static function sentence( $result ) {
		switch ( $result ) {
			case 'format':
				return __( 'A code is eight letters and numbers, like R7WT-4HQN.', 'ordryx-for-woocommerce' );
			case 'code':
				return __( 'That code isn\'t right, or has run out or been used. Get a new one in Ordryx (Sales › Sales channels › WooCommerce) and type it here. Codes last 10 minutes and work once.', 'ordryx-for-woocommerce' );
			case 'agree':
				return __( 'Tick the box to agree to Ordryx\'s terms of service and privacy policy, then press Continue.', 'ordryx-for-woocommerce' );
			case 'move_agree':
				return __( 'Tick the box to agree to Ordryx\'s terms of service and privacy policy, then press Move it here.', 'ordryx-for-woocommerce' );
			case 'terms':
				return __( 'Ordryx\'s server needs you to agree to its terms first: tick the box under the code, then press Continue.', 'ordryx-for-woocommerce' );
			case 'tries':
				return __( 'Too many wrong codes for this store. Wait 10 minutes, then type the code from Ordryx again.', 'ordryx-for-woocommerce' );
			case 'paused':
				return __( 'That Ordryx has WooCommerce paused. Resume it in Ordryx (Sales › Sales channels), then make a new code.', 'ordryx-for-woocommerce' );
			case 'unlinked':
				return __( 'Your Ordryx isn\'t in touch with Ordryx\'s server right now, so nothing changed. Check that it\'s running and online, then try again.', 'ordryx-for-woocommerce' );
			case 'slow':
				return __( 'Your Ordryx didn\'t answer in time; it may still do it. Look again in a minute.', 'ordryx-for-woocommerce' );
			case 'busy':
				return __( 'Ordryx\'s server couldn\'t take that just now, so nothing changed. Try again in a minute.', 'ordryx-for-woocommerce' );
			case 'unsure':
				return __( 'Ordryx\'s server didn\'t say whether that was done. Look again in a minute.', 'ordryx-for-woocommerce' );
			case 'unreachable':
				return __( 'This site couldn\'t reach Ordryx\'s server, so nothing changed. Some hosts and security plugins block outgoing connections: allow account.ordryx.com, then try again.', 'ordryx-for-woocommerce' );
			case 'permalinks':
				return __( 'WooCommerce\'s Allow screen doesn\'t work with Plain permalinks. Pick any other setting under Settings › Permalinks, then try again.', 'ordryx-for-woocommerce' );
			case 'gone_nokey':
				return __( 'Disconnected. There was no Ordryx key in this store to delete; check WooCommerce › Settings › Advanced › REST API for any key named Ordryx.', 'ordryx-for-woocommerce' );
			case 'gone_left':
				return __( 'Disconnected, but Ordryx\'s key was renamed or changed in WooCommerce, so this plugin didn\'t delete it. See below.', 'ordryx-for-woocommerce' );
			case 'stood':
				return __( 'This store was already connected: WooCommerce\'s key reached Ordryx before the Cancel did. To undo it, press Disconnect.', 'ordryx-for-woocommerce' );
			case 'old_keys':
				return __( 'Deleted: that Ordryx key can\'t read this store any more.', 'ordryx-for-woocommerce' );
			case 'https':
				return __( 'This site doesn\'t use HTTPS, so WooCommerce won\'t give Ordryx a key. Turn HTTPS on (most hosts do it for free from their control panel), then try again.', 'ordryx-for-woocommerce' );
			case 'woocommerce':
				return sprintf(
					/* translators: 1: the WooCommerce version Ordryx needs, 2: this site's */
					__( 'Ordryx needs WooCommerce %1$s or later; this site has %2$s. Update WooCommerce on the Plugins screen, then come back.', 'ordryx-for-woocommerce' ),
					ORDRYX_WC_AT_LEAST,
					defined( 'WC_VERSION' ) ? WC_VERSION : __( 'none', 'ordryx-for-woocommerce' )
				);
			case 'connected':
				return __( 'This store is already connected to an Ordryx. Disconnect it below first.', 'ordryx-for-woocommerce' );
			case 'denied':
				return __( 'WooCommerce\'s key wasn\'t allowed, so nothing is connected. Press Connect to try again, or Cancel.', 'ordryx-for-woocommerce' );
			case 'stale':
				return __( 'That approval was for an earlier code. Type a new code from Ordryx to connect.', 'ordryx-for-woocommerce' );
			case 'pairing':
				return __( 'Ordryx hasn\'t received WooCommerce\'s key yet. Press Connect to try again.', 'ordryx-for-woocommerce' );
			case 'unknown':
				return __( 'Ordryx\'s server doesn\'t know this store any more, so nothing this plugin sends reaches an Ordryx. Type a new code from Ordryx to connect again; it carries on from where it stopped.', 'ordryx-for-woocommerce' );
			case 'disconnected':
				return __( 'This store isn\'t connected to an Ordryx.', 'ordryx-for-woocommerce' );
			case 'cancelled':
				return __( 'Cancelled. Nothing was connected.', 'ordryx-for-woocommerce' );
			case 'resumed':
				return __( 'Resumed. Orders flow to Ordryx again from now.', 'ordryx-for-woocommerce' );
			case 'gone':
				return __( 'Disconnected. Ordryx\'s key is deleted from this store.', 'ordryx-for-woocommerce' );
			case 'refused':
				return __( 'Your Ordryx didn\'t accept that. Check in Ordryx (Sales › Sales channels), then try again.', 'ordryx-for-woocommerce' );
			case 'unanswered':
				return __( 'Your Ordryx didn\'t answer in time, so WooCommerce\'s key went nowhere. Check that it\'s running and online, then type a new code from Ordryx.', 'ordryx-for-woocommerce' );
			case 'silent':
				return __( 'Approved, but Ordryx\'s server didn\'t answer to confirm it. This page checks again each time you open it.', 'ordryx-for-woocommerce' );
			case 'blocked':
				return __( 'Ordryx couldn\'t reach this site to confirm the connection, so WooCommerce\'s key went nowhere. A security plugin or your host\'s firewall may be blocking it: allow requests from Ordryx\'s server to ?rest_route=/ordryx/v1/proof and ?rest_route=/ordryx/v1/shipped (orders shipped from Ordryx come that way), then type a new code from Ordryx.', 'ordryx-for-woocommerce' );
			case 'welcome':
				return __( 'Connected. Your Ordryx reads this store\'s orders from now on, with a read-only key.', 'ordryx-for-woocommerce' );
			case 'synced':
				return __( 'Syncing now: what it finds reaches your Ordryx within a minute.', 'ordryx-for-woocommerce' );
			case 'paused_now':
				return __( 'Paused. Nothing flows to Ordryx until you resume; what ships or is cancelled meanwhile moves no stock there.', 'ordryx-for-woocommerce' );
			case 'flows':
				return __( 'Saved. It takes effect at the next sync.', 'ordryx-for-woocommerce' );
			case 'first_sync':
				return __( 'This store\'s first sync is run in Ordryx, once its starting stock is set: open Ordryx and press Sync now on the store\'s page (Sales › Sales channels).', 'ordryx-for-woocommerce' );
			case 'running':
				return __( 'A sync is running already: what it finds reaches your Ordryx within a minute.', 'ordryx-for-woocommerce' );
			case 'sync_paused':
				return __( 'Ordryx is paused for this store. Resume it first, then sync.', 'ordryx-for-woocommerce' );
			case 'key_check':
				return __( 'Check that the key shown here matches the one on your Ordryx\'s WooCommerce page (Sales › Sales channels › WooCommerce), tick that it does, then press Connect. If it doesn\'t match, press Cancel.', 'ordryx-for-woocommerce' );
			case 'unreadable':
				return __( 'Your Ordryx can\'t read this store just now, so nothing was synced. Check the store\'s page in Ordryx (Sales › Sales channels).', 'ordryx-for-woocommerce' );
			case 'clock':
				return __( 'Your Ordryx couldn\'t ask this store the time just now, and a change is timed by the store\'s clock: nothing changed. Try again in a moment.', 'ordryx-for-woocommerce' );
			case 'forgot':
				return __( 'Forgotten on this site. Ordryx wasn\'t told, so the store this site was copied from stays as it is. To connect this site, type a code from an Ordryx.', 'ordryx-for-woocommerce' );
			case 'forgot_same':
				return __( 'Forgotten on this site. Ordryx wasn\'t told, so the store stays connected. This site is at the store\'s own address: a copy needs an address of its own before it connects to an Ordryx. If this is the store itself, type a code from its Ordryx.', 'ordryx-for-woocommerce' );
			case 'copy':
				return __( 'Nothing was sent to Ordryx: this site looks like a copy of the connected store.', 'ordryx-for-woocommerce' );
			case 'here':
				return __( 'Ordryx\'s server knows this address as the connected store, so this isn\'t a copy. Nothing was forgotten.', 'ordryx-for-woocommerce' );
			case 'not_copy':
				return __( 'This site doesn\'t look like a copy of the store any more, so nothing was forgotten.', 'ordryx-for-woocommerce' );
			case 'forget_failed':
				return __( 'Ordryx\'s server didn\'t say whether this address is the store\'s, so nothing was forgotten. Try again in a minute.', 'ordryx-for-woocommerce' );
		}
		return __( 'Ordryx\'s server couldn\'t do that just now. Try again in a minute; if it keeps happening, make a new code in Ordryx.', 'ordryx-for-woocommerce' );
	}

	/**
	 * Draw the tab.
	 */
	public static function render() {
		$admin = current_user_can( 'manage_options' );
		if ( $admin && in_array( Ordryx_State::phase(), array( 'connected', 'paused', 'pairing', 'unknown' ), true ) ) {
			$state = Ordryx_State::get();
			// (while pairing, every time: an Allow refused never comes back here;
			// from a copy of the site, at most once a minute)
			if ( ( 'pairing' === $state['phase'] && Ordryx_Site::here() ) || $state['checked_at'] < time() - MINUTE_IN_SECONDS ) {
				$checked = Ordryx_Connection::check( true );
			}
		}
		$look = array();
		if ( Ordryx_Site::here() && in_array( Ordryx_State::phase(), array( 'connected', 'paused' ), true ) ) {
			$look = Ordryx_Dashboard::look(); // (it may hear the store was let go: asked first)
		}
		$state = Ordryx_State::get();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a result code to show, from a fixed list.
		$result = isset( $_GET['ordryx'] ) ? sanitize_key( wp_unslash( $_GET['ordryx'] ) ) : '';
		if ( isset( $checked ) && in_array( $checked, array( 'blocked', 'refused', 'unanswered' ), true ) ) {
			$result = $checked;
		}
		echo '<div class="ordryx">';
		if ( in_array( $result, self::RESULTS, true ) ) {
			$good = in_array( $result, array( 'welcome', 'resumed', 'gone', 'gone_nokey', 'stood', 'cancelled', 'old_keys', 'synced', 'paused_now', 'flows', 'forgot', 'forgot_same' ), true );
			printf(
				'<div class="notice inline %1$s" role="status"><p>%2$s</p></div>',
				esc_attr( $good ? 'notice-success' : 'notice-error' ),
				esc_html( self::sentence( $result ) )
			);
		}
		// (asked after the check and the look: either may have heard it)
		$why = Ordryx_Site::why();
		if ( '' !== $why ) {
			self::render_copy( $why, $admin );
			echo '</div>';
			return;
		}
		$refused = Ordryx_Connection::refused();
		if ( '' !== $refused && in_array( $state['phase'], array( '', 'unknown' ), true ) ) {
			echo '<div class="notice inline notice-error"><p>' . esc_html( self::sentence( $refused ) ) . '</p></div>';
		}
		switch ( $state['phase'] ) {
			case 'pairing':
				self::render_confirm( $state, $admin );
				break;
			case 'connected':
			case 'paused':
				self::render_connected( $state, $admin, $look );
				break;
			default:
				if ( 'unknown' === $state['phase'] ) {
					echo '<div class="notice inline notice-warning"><p>' . esc_html( self::sentence( 'unknown' ) ) . '</p></div>';
				}
				if ( '' !== $state['pending_code'] && $state['pending_until'] >= time() ) {
					self::render_elsewhere( $state, $admin );
				} else {
					self::render_code( $admin, '' === $refused );
				}
		}
		if ( $admin && Ordryx_Connection::earlier_keys() ) {
			self::render_earlier_keys();
		}
		if ( $admin && '' !== Ordryx_Connection::key_left() ) {
			self::render_key_left();
		}
		if ( ! in_array( $state['phase'], array( 'connected', 'paused' ), true ) ) {
			self::render_reads(); // (the dashboard has it beside What flows)
		}
		echo '</div>';
	}

	/**
	 * The start of a form on the tab.
	 *
	 * @param string $step What it does.
	 */
	public static function form( $step ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $step ) . '">';
		wp_nonce_field( self::NONCE, 'ordryx_nonce' );
	}

	/**
	 * Enter the code.
	 *
	 * @param bool $admin Whether this person may connect.
	 * @param bool $may   Whether the store can connect at all.
	 */
	private static function render_code( $admin, $may ) {
		echo '<section class="ordryx-card">';
		echo '<div class="ordryx-brand"><span class="ordryx-mark" aria-hidden="true">O</span> Ordryx</div>';
		echo '<h2>' . esc_html__( 'Enter the code from your Ordryx', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p class="ordryx-muted">';
		printf(
			/* translators: %s: an example code */
			esc_html__( 'Get it in Ordryx: Sales › Sales channels › WooCommerce, Get a code. It looks like %s.', 'ordryx-for-woocommerce' ),
			'<code>R7WT-4HQN</code>'
		);
		echo '</p>';
		if ( ! $admin ) {
			echo '<p>' . esc_html__( 'Only an Administrator can connect this store to Ordryx.', 'ordryx-for-woocommerce' ) . '</p>';
			echo '</section>';
			return;
		}
		self::form( 'pair' );
		echo '<p><label for="ordryx-code" class="ordryx-label">' . esc_html__( 'Link code', 'ordryx-for-woocommerce' ) . '</label>';
		echo '<input id="ordryx-code" name="code" class="ordryx-code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="9" placeholder="XXXX-XXXX" required' . ( $may ? '' : ' disabled' ) . '></p>';
		echo '<p class="ordryx-muted">' . esc_html__( 'Letters and numbers only; the dash is optional.', 'ordryx-for-woocommerce' ) . '</p>';
		// Agreed here, where the pair call starts (Ordryx's server keeps it
		// once the store connects).
		self::terms_box( $may );
		echo '<p><button type="submit" class="button button-primary"' . ( $may ? '' : ' disabled' ) . '>' . esc_html__( 'Continue', 'ordryx-for-woocommerce' ) . '</button></p>';
		echo '</form>';
		echo '<p class="ordryx-foot">' . esc_html__( 'Continue sends Ordryx\'s server the code, this site\'s address and name, its versions and your name. Ordryx can read nothing until you press Allow on WooCommerce\'s own screen.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Check both sides, then Connect.
	 *
	 * @param array $state The connection's record.
	 * @param bool  $admin Whether this person may connect.
	 */
	private static function render_confirm( array $state, $admin ) {
		$about  = Ordryx_Connection::about();
		$ordryx = $state['ordryx'];
		$user   = wp_get_current_user();
		echo '<section class="ordryx-card ordryx-wide">';
		echo '<h2>' . esc_html__( 'Connect this store to Ordryx?', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p class="ordryx-muted">' . esc_html__( 'Check that both names are right. If the Ordryx isn\'t yours, press Cancel.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<div class="ordryx-sides">';
		echo '<div class="ordryx-side"><span class="ordryx-over">' . esc_html__( 'This store', 'ordryx-for-woocommerce' ) . '</span>';
		echo '<strong>' . esc_html( $about['name'] ) . '</strong><code>' . esc_html( $about['site'] ) . '</code><ul>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: 1: WooCommerce's version, 2: how orders are stored */
				__( 'WooCommerce %1$s · order storage: %2$s', 'ordryx-for-woocommerce' ),
				$about['woocommerce'],
				$about['hpos'] ? __( 'high-performance', 'ordryx-for-woocommerce' ) : __( 'WordPress posts', 'ordryx-for-woocommerce' )
			)
		) . '</li>';
		echo '<li>' . esc_html(
			$admin
				/* translators: %s: the person's name */
				? sprintf( __( 'You: %s, Administrator', 'ordryx-for-woocommerce' ), $user->display_name )
				/* translators: %s: the person's name */
				: sprintf( __( 'You: %s', 'ordryx-for-woocommerce' ), $user->display_name )
		) . '</li>';
		echo '</ul></div>';
		echo '<div class="ordryx-side ordryx-dark"><span class="ordryx-over">' . esc_html__( 'This Ordryx', 'ordryx-for-woocommerce' ) . '</span>';
		echo '<strong>' . esc_html( isset( $ordryx['name'] ) ? $ordryx['name'] : '' ) . '</strong><code>' . esc_html( isset( $ordryx['address'] ) ? $ordryx['address'] : '' ) . '</code><ul>';
		if ( ! empty( $ordryx['code'] ) ) {
			echo '<li>' . esc_html(
				'' !== $ordryx['code_by']
					/* translators: 1: the code, 2: who made it in Ordryx */
					? sprintf( __( 'Code %1$s, made by %2$s', 'ordryx-for-woocommerce' ), $ordryx['code'], $ordryx['code_by'] )
					/* translators: %s: the code */
					: sprintf( __( 'Code %s', 'ordryx-for-woocommerce' ), $ordryx['code'] )
			) . '</li>';
		}
		echo '<li>' . esc_html(
			empty( $ordryx['stores'] )
				? __( 'Connected stores: none yet', 'ordryx-for-woocommerce' )
				/* translators: %d: how many stores */
				: sprintf( _n( 'Connected stores: %d', 'Connected stores: %d', (int) $ordryx['stores'], 'ordryx-for-woocommerce' ), (int) $ordryx['stores'] )
		) . '</li>';
		echo '</ul></div></div>';
		echo '<h3>' . esc_html__( 'What happens when you press Connect', 'ordryx-for-woocommerce' ) . '</h3><ol>';
		echo '<li>' . esc_html__( 'WooCommerce asks you to allow a read-only key for Ordryx. The key only reads: Ordryx can\'t change your store with it.', 'ordryx-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'This plugin tells Ordryx\'s server when an order changes, with no order details. Once the store\'s first sync has run in Ordryx and Sync orders automatically is on there, your Ordryx usually reads each change within a couple of minutes.', 'ordryx-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Ordryx reads the orders still open at the store\'s first sync and every new one, with their products. Older orders come in only if you ask, from Ordryx.', 'ordryx-for-woocommerce' ) . '</li>';
		// (Said here, at the moment of consent, as the readme says it: the key
		// is read-only, but this plugin changes the store in these two ways.)
		echo '<li>' . esc_html__( 'This plugin changes your store in two ways only. When you ship an order in Ordryx, it marks the order Completed here, with its tracking if it has one and a private note saying who shipped it. WooCommerce then emails the customer, as for any order marked Completed. Once the store is disconnected, it deletes Ordryx\'s key.', 'ordryx-for-woocommerce' ) . '</li>';
		echo '</ol>';
		$allow = Ordryx_Connection::allow_url( self::url() );
		$key   = isset( $ordryx['key'] ) ? (string) $ordryx['key'] : '';
		echo '<div class="ordryx-row">';
		if ( $admin && '' !== $allow && '' !== $key ) {
			// The key orders shipped from Ordryx are checked against: matched by
			// eye with what the Ordryx itself shows, so Ordryx's server
			// alone can never mark an order shipped.
			self::form( 'allow' );
			echo '<input type="hidden" name="shown" value="' . esc_attr( Ordryx_Shipped::fingerprint( $key ) ) . '">';
			echo '<p class="ordryx-key">' . esc_html__( 'Your Ordryx\'s key', 'ordryx-for-woocommerce' ) . ' <code>' . esc_html( Ordryx_Shipped::fingerprint( $key ) ) . '</code></p>';
			echo '<p><label><input type="checkbox" name="key_ok" value="1" required> ' . esc_html__( 'It matches the key on my Ordryx\'s WooCommerce page (Sales › Sales channels › WooCommerce)', 'ordryx-for-woocommerce' ) . '</label></p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Connect', 'ordryx-for-woocommerce' ) . '</button></p></form>';
		} elseif ( $admin && '' !== $allow ) {
			echo '<a class="button button-primary" href="' . esc_url( $allow ) . '">' . esc_html__( 'Connect', 'ordryx-for-woocommerce' ) . '</a>';
		} elseif ( $admin ) {
			echo '<span>' . esc_html__( 'This pairing has run out. Cancel, then type a new code from Ordryx.', 'ordryx-for-woocommerce' ) . '</span>';
		}
		echo '</div>';
		if ( $admin ) {
			self::form( 'cancel' );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Cancel', 'ordryx-for-woocommerce' ) . '</button></p></form>';
		}
		echo '</section>';
	}

	/**
	 * Ordryx's terms tick box: fixed addresses, never the far end's.
	 *
	 * @param bool $may Whether it may be ticked (else drawn disabled).
	 */
	private static function terms_box( $may ) {
		echo '<p><label><input type="checkbox" name="terms" value="1" required' . ( $may ? '' : ' disabled' ) . '> ';
		printf(
			/* translators: 1: a link to Ordryx's terms of service, 2: a link to its privacy policy */
			esc_html__( 'I agree to Ordryx\'s %1$s and %2$s', 'ordryx-for-woocommerce' ),
			'<a href="' . esc_url( self::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'terms of service', 'ordryx-for-woocommerce' ) . '</a>',
			'<a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'privacy policy', 'ordryx-for-woocommerce' ) . '</a>'
		);
		echo '</label></p>';
	}

	/**
	 * Whether the terms were ticked with the kept code by the person here now
	 * (``pending_terms``: who ticked them; none for a code kept by 0.5.3).
	 *
	 * @param array $state The connection's record.
	 * @return bool
	 */
	private static function move_agreed( array $state ) {
		$who = is_int( $state['pending_terms'] ) ? $state['pending_terms'] : 0;
		return $who > 0 && get_current_user_id() === $who;
	}

	/**
	 * Connected elsewhere: move it here, or leave it.
	 *
	 * @param array $state The connection's record.
	 * @param bool  $admin Whether this person may connect.
	 */
	private static function render_elsewhere( array $state, $admin ) {
		echo '<section class="ordryx-card"><h2>' . esc_html__( 'Already connected elsewhere', 'ordryx-for-woocommerce' ) . '</h2>';
		// (Ordryx's server doesn't say which: the store isn't proven to be this
		// site until WooCommerce's key is tried on it.)
		echo '<p>' . esc_html__( 'This store is connected to another Ordryx. One store connects to one Ordryx. Disconnect it there first, or move the store here, which disconnects the other once you allow the key.', 'ordryx-for-woocommerce' ) . '</p>';
		if ( $admin ) {
			echo '<div class="ordryx-row">';
			self::form( 'move' );
			if ( ! self::move_agreed( $state ) ) { // (not ticked with the code by this person)
				self::terms_box( true );
			}
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Move it here', 'ordryx-for-woocommerce' ) . '</button></form>';
			self::form( 'keep' );
			echo '<button type="submit" class="button">' . esc_html__( 'Cancel', 'ordryx-for-woocommerce' ) . '</button></form>';
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * Where a connected store stands: its dashboard. What
	 * flows comes from the store's Ordryx (Ordryx_Dashboard::look); the
	 * connection's own lines from this site.
	 *
	 * @param array $state The connection's record.
	 * @param bool  $admin Whether this person may change it.
	 * @param array $look  What the store's Ordryx says about it.
	 */
	private static function render_connected( array $state, $admin, array $look ) {
		$ordryx  = $state['ordryx'];
		$address = isset( $ordryx['address'] ) ? $ordryx['address'] : '';
		$paused  = 'paused' === $state['phase'];
		echo '<section class="ordryx-banner">';
		echo '<div class="ordryx-banner-text">';
		echo '<span class="ordryx-status ' . ( $paused ? 'is-paused' : 'is-on' ) . '">' . esc_html( $paused ? __( 'Paused', 'ordryx-for-woocommerce' ) : __( 'Connected', 'ordryx-for-woocommerce' ) ) . '</span>';
		/* translators: %s: the Ordryx's name */
		echo '<strong>' . esc_html( sprintf( __( '%s\'s Ordryx', 'ordryx-for-woocommerce' ), isset( $ordryx['name'] ) ? $ordryx['name'] : '' ) ) . '</strong>';
		$line = Ordryx_Dashboard::sync_line( $look, $paused );
		echo '<code>' . esc_html( '' !== $address ? $address . ' · ' . $line : $line ) . '</code>';
		echo '</div><div class="ordryx-banner-do">';
		if ( '' !== $address ) {
			echo '<a href="' . esc_url( 'https://' . $address ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Ordryx', 'ordryx-for-woocommerce' ) . '</a>';
		}
		Ordryx_Dashboard::render_buttons( $look, $paused, $admin );
		echo '</div></section>';
		if ( ! $admin ) {
			echo '<div class="notice inline notice-info"><p><b>' . esc_html__( 'Only Administrators can change what flows.', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html__( 'As a Shop Manager you can see everything here and press Sync now.', 'ordryx-for-woocommerce' ) . '</p></div>';
		}
		if ( $paused ) {
			$text = $state['off_since']
				? sprintf(
					/* translators: %s: when the plugin was switched off */
					__( 'Ordryx was paused when this plugin was switched off (%s). Orders placed while paused won\'t take stock in Ordryx. Resume when you\'re ready.', 'ordryx-for-woocommerce' ),
					self::when( $state['off_since'] )
				)
				: __( 'Paused, as if disconnected. Nothing flows. What ships or is cancelled meanwhile moves no stock in Ordryx; when you resume, it carries on from there.', 'ordryx-for-woocommerce' );
			echo '<div class="notice inline notice-warning"><p>' . esc_html( $text ) . '</p></div>';
		}
		echo '<div class="ordryx-grid"><div class="ordryx-main">';
		Ordryx_Dashboard::render_flows( $look, $paused, $admin, $address );
		echo '</div><div class="ordryx-aside">';

		echo '<section class="ordryx-card"><h2>' . esc_html__( 'Connection', 'ordryx-for-woocommerce' ) . '</h2><ul class="ordryx-checks">';
		$gone       = Ordryx_Connection::key_gone();
		$write_only = ! $gone && Ordryx_Connection::key_write_only();
		if ( $gone ) {
			// (the same rule the shipped route refuses by: the screen and it agree)
			echo '<li><b class="is-bad">' . esc_html__( 'No', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html__( 'Ordryx\'s key was deleted (WooCommerce › Settings › Advanced › REST API). Your Ordryx can\'t read orders, and orders shipped from Ordryx aren\'t marked shipped here, until you Disconnect below, then connect again with a new code from Ordryx. It carries on from where it stopped, and orders shipped from Ordryx meanwhile are marked shipped here then.', 'ordryx-for-woocommerce' ) . '</li>';
		} elseif ( $write_only ) {
			echo '<li><b class="is-bad">' . esc_html__( 'No', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html__( 'Ordryx\'s key was set to Write in WooCommerce › Settings › Advanced › REST API, and WooCommerce refuses a Write key\'s reads, so your Ordryx can\'t read orders. Set it back to Read there: it carries on from where it stopped. Until then Disconnect would leave this key, as this plugin deletes only a read-only key named Ordryx.', 'ordryx-for-woocommerce' ) . '</li>';
		} elseif ( Ordryx_Connection::key_changed() ) {
			echo '<li><b class="is-bad">' . esc_html__( 'Changed', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html__( 'Ordryx\'s key was changed in WooCommerce › Settings › Advanced › REST API (renamed, or given more than read access). Orders still flow, but this plugin deletes only a read-only key named Ordryx, so Disconnect would leave this one: set it back, or delete it there yourself once you disconnect.', 'ordryx-for-woocommerce' ) . '</li>';
		} else {
			$by = '' !== $state['connected_by'] ? $state['connected_by'] : __( 'an Administrator', 'ordryx-for-woocommerce' );
			echo '<li><b class="is-ok">' . esc_html__( 'OK', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html(
				$state['connected_at']
					/* translators: 1: who allowed it, 2: when */
					? sprintf( __( 'Read-only key, allowed by %1$s on %2$s', 'ordryx-for-woocommerce' ), $by, self::when( $state['connected_at'] ) )
					/* translators: %s: who allowed it */
					: sprintf( __( 'Read-only key, allowed by %s', 'ordryx-for-woocommerce' ), $by )
			) . '</li>';
		}
		if ( $gone || $write_only ) {
			// (Ordryx_Nudge::changed() sends none then, and a sync can only fail)
			echo '<li><b class="is-bad">' . esc_html__( 'Off', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html(
				$gone
					? __( 'Order notices are held: this plugin sends none until Ordryx\'s key is back (connect again, as above).', 'ordryx-for-woocommerce' )
					: __( 'Order notices are held: this plugin sends none until Ordryx\'s key is set back to Read.', 'ordryx-for-woocommerce' )
			) . '</li>';
		} elseif ( $state['failing_since'] ) {
			echo '<li><b class="is-bad">' . esc_html__( 'Late', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html(
				sprintf(
					/* translators: 1: since when, 2: what the Ordryx does meanwhile */
					__( 'This site hasn\'t reached Ordryx\'s server since %1$s, so order changes aren\'t reaching your Ordryx right away. The plugin keeps trying. %2$s', 'ordryx-for-woocommerce' ),
					self::when( $state['failing_since'] ),
					self::meanwhile( $look, $paused )
				)
			) . '</li>';
		} else {
			list( $mark, $line ) = self::notices_line( $look, $paused );
			echo '<li><b class="' . ( 'off' === $mark ? 'is-bad' : 'is-ok' ) . '">' . esc_html( 'off' === $mark ? __( 'Off', 'ordryx-for-woocommerce' ) : __( 'OK', 'ordryx-for-woocommerce' ) ) . '</b> ' . esc_html( $line ) . '</li>';
		}
		if ( empty( $ordryx['key'] ) || empty( $ordryx['key_checked'] ) ) {
			echo '<li><b class="is-bad">' . esc_html__( 'No', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html__( 'Orders shipped from Ordryx can\'t be marked shipped here yet: this store was connected before Ordryx could. Disconnect below, then connect again with a new code from Ordryx (it carries on from where it stopped).', 'ordryx-for-woocommerce' ) . '</li>';
		}
		if ( isset( $look['error'] ) ) {
			echo '<li><b class="is-bad">' . esc_html__( 'Late', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html__( 'Your Ordryx didn\'t answer just now, so what flows isn\'t shown. It may be off or offline; orders it misses meanwhile come in when it\'s back.', 'ordryx-for-woocommerce' ) . '</li>';
		}
		$about = Ordryx_Connection::about();
		echo '<li><b class="is-ok">' . esc_html__( 'OK', 'ordryx-for-woocommerce' ) . '</b> ' . esc_html(
			sprintf(
				/* translators: 1: WooCommerce's version, 2: how orders are stored */
				__( 'WooCommerce %1$s, %2$s', 'ordryx-for-woocommerce' ),
				$about['woocommerce'],
				$about['hpos'] ? __( 'high-performance order storage', 'ordryx-for-woocommerce' ) : __( 'orders stored as WordPress posts', 'ordryx-for-woocommerce' )
			)
		) . '</li></ul></section>';

		Ordryx_Dashboard::render_recent( $look );
		self::render_reads();

		echo '<section class="ordryx-card"><h2>' . esc_html__( 'Disconnect', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes Ordryx\'s key from this store and stops its order notices. Orders already in Ordryx stay; open ones stop counting toward the plan.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<p class="ordryx-muted">' . esc_html__( 'Deactivating the plugin pauses instead. Deleting the plugin disconnects.', 'ordryx-for-woocommerce' ) . '</p>';
		if ( $admin ) {
			self::form( 'disconnect' );
			echo '<p><label><input type="checkbox" required> ' . esc_html__( 'Yes, disconnect this store from Ordryx', 'ordryx-for-woocommerce' ) . '</label></p>';
			echo '<p><button type="submit" class="button ordryx-danger">' . esc_html__( 'Disconnect', 'ordryx-for-woocommerce' ) . '</button></p></form>';
		}
		echo '</section>';
		echo '</div></div>';
	}

	/**
	 * The Connection card's line about order notices, from what the store's
	 * Ordryx says: nudges change
	 * something only once its first sync has run and its Sync orders
	 * automatically is on, and never while it's paused.
	 *
	 * @param array $look   The look.
	 * @param bool  $paused Whether it's paused.
	 * @return array {0: "ok" or "off", 1: the sentence}
	 */
	private static function notices_line( array $look, $paused ) {
		if ( isset( $look['error'] ) ) {
			return array( 'ok', __( 'Order notices on: this plugin tells Ordryx when an order changes, with no order details.', 'ordryx-for-woocommerce' ) );
		}
		if ( $paused ) {
			return array( 'ok', __( 'Order notices on; your Ordryx is paused, so nothing is read until you resume.', 'ordryx-for-woocommerce' ) );
		}
		if ( $look['first_sync_due'] && 0 === $look['sync_every'] ) {
			// (a new store's usual state: Sync orders automatically starts Off,
			// so the first sync alone changes nothing)
			return array( 'off', __( 'Order notices on, but your Ordryx acts on them only once the store\'s first sync has run there and Sync orders automatically is on (it\'s off now: Settings › WooCommerce in Ordryx). Until then, order changes wait until someone presses Sync now (the first time, in Ordryx).', 'ordryx-for-woocommerce' ) );
		}
		if ( $look['first_sync_due'] ) {
			return array( 'ok', __( 'Order notices on: this plugin tells Ordryx when an order changes, with no order details. Your Ordryx acts on them once the store\'s first sync has run there.', 'ordryx-for-woocommerce' ) );
		}
		if ( 0 === $look['sync_every'] ) {
			return array( 'off', __( 'Sync orders automatically is off in your Ordryx, so order changes wait until someone presses Sync now (here, or on the store\'s page in Ordryx). To have them come in by themselves, turn it on in Ordryx: Settings › WooCommerce.', 'ordryx-for-woocommerce' ) );
		}
		return array( 'ok', __( 'Order notices on: this plugin tells Ordryx when an order changes, with no order details, and your Ordryx usually reads the change within a couple of minutes.', 'ordryx-for-woocommerce' ) );
	}

	/**
	 * What the store's Ordryx does while order notices don't reach it, by the
	 * same rules as notices_line (its timer syncs only once
	 * the store's first sync has run, and never while it's paused).
	 *
	 * @param array $look   The look.
	 * @param bool  $paused Whether it's paused.
	 * @return string
	 */
	private static function meanwhile( array $look, $paused ) {
		if ( isset( $look['error'] ) ) {
			return __( 'Meanwhile your Ordryx still reads the store when it syncs; nothing is lost.', 'ordryx-for-woocommerce' );
		}
		if ( $paused ) {
			return __( 'Your Ordryx is paused, so nothing is read until you resume.', 'ordryx-for-woocommerce' );
		}
		if ( $look['first_sync_due'] && 0 === $look['sync_every'] ) {
			return __( 'Orders come in once the store\'s first sync has run in Ordryx, then when someone presses Sync now, as Sync orders automatically is off there.', 'ordryx-for-woocommerce' );
		}
		if ( $look['first_sync_due'] ) {
			return __( 'Orders come in once the store\'s first sync has run in Ordryx.', 'ordryx-for-woocommerce' );
		}
		if ( 0 === $look['sync_every'] ) {
			return __( 'Meanwhile orders come in when someone presses Sync now; nothing is lost.', 'ordryx-for-woocommerce' );
		}
		return sprintf(
			/* translators: %s: how often, like "every 15 minutes" */
			__( 'Your Ordryx still syncs %s meanwhile; nothing is lost.', 'ordryx-for-woocommerce' ),
			Ordryx_Dashboard::every_words( $look['sync_every'] )
		);
	}

	/**
	 * This site looks like a copy of the connected store (a staging site, a
	 * host's one-click copy): nothing from here reaches the store's Ordryx,
	 * and nothing on this page acts for the store. Forget the connection here
	 * (for a copy at another address, one that connects to an Ordryx of its
	 * own); what to do if this is the store itself.
	 *
	 * @param string $why   Ordryx_Site::why().
	 * @param bool   $admin Whether this person may forget it.
	 */
	private static function render_copy( $why, $admin ) {
		$paired = self::paired_address();
		echo '<section class="ordryx-card ordryx-wide">';
		echo '<div class="ordryx-brand"><span class="ordryx-mark" aria-hidden="true">O</span> Ordryx</div>';
		echo '<h2>' . esc_html__( 'This looks like a copy of the store', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p>';
		if ( 'environment' === $why ) {
			printf(
				/* translators: 1: the environment WordPress says this site is, like "staging", 2: the address the store was connected at */
				esc_html__( 'This site was connected to Ordryx as a live site, and WordPress now says it\'s a %1$s site. A copy of a site (a staging site, a host\'s one-click copy) holds that connection too, so nothing is sent to Ordryx from here: order changes, erasures, switching the plugin off or deleting it on this site leave %2$s and its Ordryx as they are.', 'ordryx-for-woocommerce' ),
				'<code>' . esc_html( Ordryx_Site::environment() ) . '</code>',
				'<code>' . esc_html( $paired ) . '</code>'
			);
		} else {
			printf(
				/* translators: 1: this site's address, 2: the address the store was connected at */
				esc_html__( 'This site is at %1$s, but it was connected to Ordryx at %2$s. A copy of a site (a staging site, a host\'s one-click copy) holds that connection too, so nothing from here reaches the store\'s Ordryx: order changes, erasures, switching the plugin off or deleting it on this site leave %2$s and its Ordryx as they are.', 'ordryx-for-woocommerce' ),
				'<code>' . esc_html( Ordryx_Site::home() ) . '</code>',
				'<code>' . esc_html( $paired ) . '</code>'
			);
		}
		echo '</p>';
		$waiting = count( Ordryx_Erasure::queued() );
		if ( $waiting ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: how many orders */
					_n( 'Customer data erased on this site (%d order) is waiting here: it reaches Ordryx only from the connected store.', 'Customer data erased on this site (%d orders) is waiting here: it reaches Ordryx only from the connected store.', $waiting, 'ordryx-for-woocommerce' ),
					$waiting
				)
			) . '</p>';
		}

		echo '<h3>' . esc_html__( 'A copy of the store', 'ordryx-for-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'Nothing to do: nothing from here acts for the store. If you\'ll push this copy back over the live site, leave the connection as it is.', 'ordryx-for-woocommerce' ) . '</p>';
		if ( 'environment' === $why ) {
			// (Ordryx's server knows the store by its address: a pairing from
			// here names the store's, and its proof is asked of the store)
			echo '<p>' . esc_html__( 'This copy is at the store\'s own address, so it can\'t connect to an Ordryx of its own until it has an address of its own (Settings › General).', 'ordryx-for-woocommerce' ) . '</p>';
			echo '<p>' . esc_html__( 'Forgetting the connection here only stops this site holding the store\'s connection: nothing is sent to Ordryx, and the live store stays connected. It\'s worth doing before anyone sets WordPress\'s environment type back to production here, which would make this copy act for the store again.', 'ordryx-for-woocommerce' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: the address the store was connected at */
					__( 'To connect this site to an Ordryx of its own instead, forget the connection here. Only this site forgets it: Ordryx\'s server is asked only whether this address is the store\'s, and %s stays connected. Then type a code from that Ordryx.', 'ordryx-for-woocommerce' ),
					$paired
				)
			) . '</p>';
		}
		if ( $admin ) {
			self::form( 'forget_here' );
			echo '<p><label><input type="checkbox" name="sure" value="1" required> ' . esc_html__( 'Yes, forget the connection on this site', 'ordryx-for-woocommerce' ) . '</label></p>';
			echo '<p><button type="submit" class="button">' . esc_html__( 'Forget the connection here', 'ordryx-for-woocommerce' ) . '</button></p></form>';
		} else {
			echo '<p>' . esc_html__( 'Only an Administrator can forget the connection here.', 'ordryx-for-woocommerce' ) . '</p>';
		}

		if ( 'environment' === $why ) {
			echo '<h3>' . esc_html__( 'If this is the live store', 'ordryx-for-woocommerce' ) . '</h3>';
			echo '<p>';
			printf(
				/* translators: %s: the environment WordPress says this site is, like "staging" */
				esc_html__( 'It was connected as a live site (a store connected before the plugin\'s 0.5.1 is taken to have been one). Set WordPress\'s environment type (WP_ENVIRONMENT_TYPE) to production. If it runs as %s on purpose, forget the connection here, then connect it again with a code from its Ordryx: it carries on from where it stopped.', 'ordryx-for-woocommerce' ),
				'<code>' . esc_html( Ordryx_Site::environment() ) . '</code>'
			);
			echo '</p>';
		} else {
			echo '<h3>' . esc_html__( 'The store itself moved here', 'ordryx-for-woocommerce' ) . '</h3>';
			echo '<p>';
			printf(
				/* translators: 1: this site's address, 2: the address the store was connected at */
				esc_html__( 'If the store itself moved to %1$s: in its Ordryx, open Sales › Sales channels › WooCommerce, press The store moved… and type %1$s. Then forget the connection here and type the code Ordryx gives. Once Ordryx finds the store\'s orders here as they were, it carries on at this address and lets %2$s go. Don\'t disconnect %2$s in Ordryx first.', 'ordryx-for-woocommerce' ),
				'<code>' . esc_html( Ordryx_Site::home() ) . '</code>',
				'<code>' . esc_html( $paired ) . '</code>'
			);
			echo '</p>';
		}
		echo '</section>';
	}

	/**
	 * An Ordryx key from a connection that's over, still able to read the
	 * store: the connection's own, when Ordryx's server doesn't know the
	 * store any more (an Ordryx removed from its account may still hold it).
	 */
	private static function render_earlier_keys() {
		if ( Ordryx_Connection::own_key_left() ) {
			echo '<section class="ordryx-card"><h2>' . esc_html__( 'Ordryx\'s key is still in this store', 'ordryx-for-woocommerce' ) . '</h2>';
			echo '<p>' . esc_html__( 'Ordryx\'s server no longer knows this store, but the read-only key this store gave Ordryx is still here: the Ordryx that holds it can still read orders. Delete it unless that Ordryx should keep reading. Connecting again with a new code makes a new key.', 'ordryx-for-woocommerce' ) . '</p>';
			self::form( 'old_keys' );
			echo '<p><button type="submit" class="button ordryx-danger">' . esc_html__( 'Delete Ordryx\'s key', 'ordryx-for-woocommerce' ) . '</button></p></form>';
			echo '</section>';
			return;
		}
		echo '<section class="ordryx-card"><h2>' . esc_html__( 'An earlier Ordryx key', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'A read-only key from an earlier connection to Ordryx is still in this store: whoever holds it can still read orders. Delete it unless that Ordryx should keep reading.', 'ordryx-for-woocommerce' ) . '</p>';
		self::form( 'old_keys' );
		echo '<p><button type="submit" class="button ordryx-danger">' . esc_html__( 'Delete the earlier key', 'ordryx-for-woocommerce' ) . '</button></p></form>';
		echo '</section>';
	}

	/**
	 * The key this plugin wouldn't delete: changed in WooCommerce since.
	 */
	private static function render_key_left() {
		echo '<section class="ordryx-card"><h2>' . esc_html__( 'A key Ordryx used is still in this store', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: the end of the key, as WooCommerce's REST API screen shows it */
				__( 'The store was disconnected, but Ordryx\'s key was renamed or changed in WooCommerce, so this plugin didn\'t delete it: whoever holds it can still read this store. Unless you use it for something else now, revoke it under WooCommerce › Settings › Advanced › REST API (its consumer key ends in %s).', 'ordryx-for-woocommerce' ),
				Ordryx_Connection::key_left()
			)
		) . '</p></section>';
	}

	/**
	 * What Ordryx reads and keeps, said plainly.
	 */
	private static function render_reads() {
		echo '<section class="ordryx-card"><h2>' . esc_html__( 'What Ordryx reads', 'ordryx-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Orders (by number, with their lines, prices, fees, taxes and status) and products, read only.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'For orders waiting to ship, the ship-to name, address and phone, to buy labels. Cleared 90 days after each order is done (or, for one still open, after 90 days with nothing changed), or at once when WooCommerce erases a customer\'s data from their orders.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Who bought each order: the customer\'s name and email, kept with the order, and forgotten when WooCommerce erases them from it (WooCommerce › Settings › Accounts & Privacy: removing personal data from orders, on request or after a while).', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'What the customer typed or chose for each line, as WooCommerce\'s own order screen shows it under the line: a product add-on\'s field (the text to engrave, say) and a variation\'s options, never the hidden details kept there. Kept with the order\'s lines, and forgotten with the customer\'s name and email.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Once the store is disconnected (Disconnect here or in Ordryx, deleting this plugin, or connecting the store to another Ordryx), your Ordryx keeps its orders, as your shop\'s sales, but their ship-to, the customer\'s name and email and what they typed for a line go: at once for orders still waiting to ship, 29 days later for the rest, unless the store is connected to it again first.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Never the billing address or billing phone, and never customer accounts. WooCommerce\'s key could read them; Ordryx never asks for them.', 'ordryx-for-woocommerce' ) . '</p>';
		echo '</section>';
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
