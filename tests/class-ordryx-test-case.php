<?php
/**
 * The base of every test: WordPress's functions through Brain Monkey, options
 * in memory, Action Scheduler recorded, and the fake account answering.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- tests.

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class Ordryx_Test_Case extends \PHPUnit\Framework\TestCase {

	/** @var array Options, as WordPress keeps them. */
	protected $options = array();

	/** @var array Transients, as WordPress keeps them (none run out in a test). */
	protected $transients = array();

	/** @var array How long each transient was kept for, in seconds. */
	protected $transient_for = array();

	/** @var array Which options were saved to load on every page. */
	protected $autoload = array();

	/** @var array Action Scheduler's actions: hook => [when, group]. */
	protected $scheduled = array();

	/** @var array Hooks whose action is running now (Action Scheduler's in-progress). */
	protected $running = array();

	/** @var Fake_Account */
	protected $account;

	/** @var Fake_Wpdb */
	protected $wpdb;

	/** @var string This site's address (its Site Address setting, and home_url()). */
	protected $home = 'https://shop.example.com';

	/** @var string What WordPress says this site is (wp_get_environment_type). */
	protected $environment = 'production';

	/**
	 * Whether wp-config.php has keys of its own (AUTH_KEY, SECURE_AUTH_KEY),
	 * as every installer writes. A class that sets it false runs each test in
	 * a PHP of its own: constants, once defined, stay.
	 *
	 * @var bool
	 */
	protected $wp_config_keys = true;

	/** @var bool Whether the person at the page is an Administrator. */
	protected $admin = true;

	/** @var int Who's signed in (0: nobody). */
	protected $user_id = 1;

	/**
	 * This request's option cache, as WordPress keeps one (null: none, so every
	 * read sees $this->options). Opt in with requests(); then a test that
	 * writes $this->options directly is another request writing meanwhile.
	 *
	 * @var array|null
	 */
	protected $cache = null;

	/** @var bool What wp_doing_ajax() answers. */
	protected $doing_ajax = false;

	/** @var array What wc_get_order_notes() answers: an order's customer notes. */
	protected $order_notes = array();

	/** @var array The options this request found missing (WordPress's "notoptions"). */
	protected $notoptions = array();

	/**
	 * Options read as one WordPress request reads them: each kept in this
	 * request's cache from its first read, one found missing kept missing,
	 * until wp_cache_delete( name or "notoptions", "options" ).
	 */
	protected function requests() {
		$this->cache      = array();
		$this->notoptions = array();
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( $this->wp_config_keys && ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'kR!7t]q2Zp#vN4e8Lw@1sX9bY6uC3mF0gH5jD^aQ&oI*lT(rE)yU-iO_pS+dV=cB' );
			define( 'SECURE_AUTH_KEY', 'wE3$rT6yU9iO2pA5sD8fG1hJ4kL7zX0cV|bN@mQ!wE#rT%yU^iO&pA*sD(fG)hJ' );
		}
		$this->account   = new Fake_Account();
		$this->wpdb      = new Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = array(
			'date_format'         => 'F j, Y',
			'time_format'         => 'g:i a',
			'permalink_structure' => '/%postname%/',
		);
		$this->wpdb->rows = &$this->options; // (the options table, read and written directly too)
		$this->cache      = null;
		$this->notoptions = array();
		$this->running   = array();
		$this->scheduled = array();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_GET['consumer_key'], $_GET['consumer_secret'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['page'], $_GET['tab'], $_GET['success'], $_GET['user_id'], $_GET['ordryx'], $_POST['do'], $_POST['code'] );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'wp_json_encode'        => function ( $v ) {
					return json_encode( $v );
				},
				'untrailingslashit'     => function ( $v ) {
					return rtrim( $v, '/\\' );
				},
				'wp_parse_url'          => function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				},
				'is_wp_error'           => function ( $v ) {
					return $v instanceof WP_Error;
				},
				'wp_remote_retrieve_body' => function ( $r ) {
					return is_array( $r ) ? $r['body'] : '';
				},
				'wp_remote_retrieve_response_code' => function ( $r ) {
					return is_array( $r ) ? $r['response']['code'] : '';
				},
				'wp_remote_post'        => function ( $url, $args ) {
					return $this->account->post( $url, $args );
				},
				'home_url'              => function ( $path = '' ) {
					return $this->home . $path;
				},
				'admin_url'             => function ( $path = '' ) {
					return 'https://shop.example.com/wp-admin/' . $path;
				},
				'add_query_arg'         => function ( $args, $url ) {
					return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode(
						'&',
						array_map(
							function ( $k, $v ) {
								return $k . '=' . $v;
							},
							array_keys( $args ),
							$args
						)
					);
				},
				'get_option'            => function ( $name, $default = false ) {
					if ( 'home' === $name && ! array_key_exists( $name, $this->options ) ) {
						return $this->home; // (Settings › General, unless a test sets it apart)
					}
					if ( null !== $this->cache ) {
						if ( array_key_exists( $name, $this->cache ) ) {
							return $this->cache[ $name ];
						}
						if ( isset( $this->notoptions[ $name ] ) ) {
							return $default;
						}
						if ( ! array_key_exists( $name, $this->options ) ) {
							$this->notoptions[ $name ] = true;
							return $default;
						}
						$this->cache[ $name ] = $this->options[ $name ];
					}
					return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
				},
				'wp_get_environment_type' => function () {
					return $this->environment;
				},
				'update_option'         => function ( $name, $value, $autoload = null ) {
					// (as WordPress: one this request found missing is added, with
					// INSERT … ON DUPLICATE KEY UPDATE, over whatever is there now)
					$this->options[ $name ]  = $value;
					$this->autoload[ $name ] = $autoload;
					if ( null !== $this->cache ) {
						$this->cache[ $name ] = $value;
						unset( $this->notoptions[ $name ] );
					}
					return true;
				},
				'add_option'            => function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
					if ( null !== $this->cache ) {
						// (WordPress asks its cache first, then writes with ON DUPLICATE KEY UPDATE)
						if ( ! isset( $this->notoptions[ $name ] ) && false !== get_option( $name ) ) {
							return false;
						}
						$this->options[ $name ] = $value;
						$this->cache[ $name ]   = $value;
						unset( $this->notoptions[ $name ] );
						return true;
					}
					if ( array_key_exists( $name, $this->options ) ) {
						return false; // (WordPress adds only a row that isn't there)
					}
					$this->options[ $name ] = $value;
					return true;
				},
				'delete_option'         => function ( $name ) {
					unset( $this->options[ $name ] );
					if ( null !== $this->cache ) {
						unset( $this->cache[ $name ] );
						$this->notoptions[ $name ] = true;
					}
					return true;
				},
				'wp_get_current_user'   => function () {
					return new WP_User();
				},
				'current_user_can'      => function ( $cap ) {
					return $this->admin || 'manage_woocommerce' === $cap;
				},
				'is_user_logged_in'     => function () {
					return $this->user_id > 0;
				},
				'get_current_user_id'   => function () {
					return $this->user_id;
				},
				'get_bloginfo'          => function ( $what ) {
					return 'version' === $what ? '7.0' : 'Radio Gear & Co';
				},
				'wp_specialchars_decode' => function ( $v ) {
					return htmlspecialchars_decode( $v, ENT_QUOTES );
				},
				'sanitize_text_field'   => function ( $v ) {
					if ( is_array( $v ) || is_object( $v ) ) {
						return ''; // (as WordPress's own)
					}
					return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) );
				},
				'sanitize_key'          => function ( $v ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) );
				},
				'wp_unslash'            => function ( $v ) {
					return $v;
				},
				'wc_api_hash'           => function ( $v ) {
					return hash_hmac( 'sha256', $v, 'wc-api' );
				},
				'wc_format_decimal'     => function ( $v ) {
					return (string) round( (float) $v, 8 );
				},
				'wp_date'               => function ( $format, $at ) {
					return gmdate( 'M j, Y g:i a', $at );
				},
				'wp_nonce_field'        => function () {
					echo '<input type="hidden" name="ordryx_nonce" value="nonce">';
				},
				'wp_doing_ajax'         => function () {
					return $this->doing_ajax;
				},
				'wc_get_order_notes'    => function () {
					return $this->order_notes;
				},
				'number_format_i18n'    => function ( $n ) {
					return number_format( $n );
				},
				'as_has_scheduled_action' => function ( $hook ) {
					return isset( $this->scheduled[ $hook ] ) || isset( $this->running[ $hook ] );
				},
				'as_get_scheduled_actions' => function ( $args ) {
					// (pending ones only, as the plugin asks: a running one isn't among them)
					return isset( $this->scheduled[ $args['hook'] ] ) && 'pending' === $args['status'] ? array( 1 ) : array();
				},
				'as_next_scheduled_action' => function ( $hook ) {
					if ( isset( $this->running[ $hook ] ) ) {
						return true; // (Action Scheduler answers a running one first)
					}
					if ( isset( $this->scheduled[ $hook ] ) ) {
						return $this->scheduled[ $hook ][0];
					}
					return isset( $this->running[ $hook ] );
				},
				'as_schedule_single_action' => function ( $when, $hook, $args = array(), $group = '', $unique = false ) {
					if ( $unique && ( isset( $this->scheduled[ $hook ] ) || isset( $this->running[ $hook ] ) ) ) {
						return 0; // (Action Scheduler refuses a unique one while one waits or runs)
					}
					$this->scheduled[ $hook ] = array( $when, $group );
					return 1;
				},
				'wp_cache_delete'       => function ( $key = '', $group = '' ) {
					if ( null !== $this->cache && 'options' === $group ) {
						if ( 'notoptions' === $key ) {
							$this->notoptions = array();
						} else {
							unset( $this->cache[ $key ] );
						}
					}
					return true;
				},
				'get_transient'         => function ( $name ) {
					return isset( $this->transients[ $name ] ) ? $this->transients[ $name ] : false;
				},
				'set_transient'         => function ( $name, $value, $for = 0 ) {
					$this->transients[ $name ] = $value;
					$this->transient_for[ $name ] = $for;
					return true;
				},
				'delete_transient'      => function ( $name ) {
					unset( $this->transients[ $name ] );
					return true;
				},
				'get_woocommerce_currency' => function () {
					return 'USD';
				},
				'as_schedule_recurring_action' => function ( $when, $every, $hook, $args = array(), $group = '' ) {
					$this->scheduled[ $hook ] = array( $when, $group, $every );
					return 1;
				},
				'as_unschedule_all_actions' => function ( $hook, $args = array(), $group = '' ) {
					foreach ( $this->scheduled as $h => $s ) {
						if ( $s[1] === $group && ( '' === $hook || $h === $hook ) ) {
							unset( $this->scheduled[ $h ] );
						}
					}
				},
			)
		);
	}

	protected function tearDown(): void {
		$this->clock_on( 0 );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Move the plugin's clock (Ordryx_Clock) on to this many seconds ahead.
	 */
	protected function clock_on( $ahead ) {
		$p = new ReflectionProperty( 'Ordryx_Clock', 'ahead' );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true ); // (a private property, before PHP 8.1)
		}
		$p->setValue( null, $ahead );
	}

	/**
	 * A store paired, allowed and connected, with Ordryx's key in WooCommerce.
	 */
	protected function connected( $key = 'ck_' . 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678' ) {
		$this->wpdb->add_key( 7, $key );
		$this->options[ Ordryx_State::SECRET ] = base64_encode( $this->account->secret );
		$this->options[ Ordryx_State::OPTION ] = array_merge(
			Ordryx_State::blank(),
			array(
				'phase'        => 'connected',
				'store'        => $this->account->store,
				'key_id'       => 7,
				'key_mark'     => array( 'end' => substr( $key, -7 ) ),
				'connected_by' => 'Avery Admin',
				'connected_at' => 1792700000,
				'checked_at'   => time(),
				'site'         => array(
					'home' => base64_encode( $this->home ),
					'env'  => $this->environment,
				),
				'ordryx'       => array(
					'name'    => 'Bench Works',
					'address' => 'bench-works.ordryx.com',
					'code_by' => '',
					'stores'  => 1,
				),
			)
		);
		return $key;
	}

	/**
	 * What a function printed.
	 */
	protected function printed( callable $fn ) {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}
}
