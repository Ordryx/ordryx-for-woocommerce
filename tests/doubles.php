<?php
/**
 * Stand-ins for the WordPress and WooCommerce objects the plugin touches.
 *
 * @package Ordryx
 */

// phpcs:ignoreFile -- test doubles shaped like WordPress's own classes.

class WP_Error {
	public function get_error_message() {
		return 'failed';
	}
}

class WP_User {
	public $ID           = 1;
	public $display_name = 'Avery Admin';
}

class WP_REST_Request {
	private $params;
	public function __construct( array $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}

class WP_REST_Response {
	private $data;
	public $status;
	public $headers = array();
	public function __construct( $data = array(), $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
	public function get_data() {
		return $this->data;
	}
	public function set_data( $data ) {
		$this->data = $data;
	}
}

class WC_Order {
	public $meta     = array();
	public $currency = 'USD';
	public $id       = 0;
	public $status   = 'processing';
	public $notes    = array();
	public $statuses = array();
	public function __construct( array $meta = array(), $currency = 'USD', $id = 0, $status = 'processing' ) {
		$this->meta     = $meta;
		$this->currency = $currency;
		$this->id       = $id;
		$this->status   = $status;
	}
	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_id() {
		return $this->id;
	}
	/**
	 * @var string|null A status set on this object but never saved: WooCommerce's
	 * update_status() calls set_status() before save(), so when the save
	 * throws the change stays in the object's pending changes; only a fresh
	 * read (wc_get_order(), fresh() here) gives the stored $status.
	 */
	public $pending = null;
	public function get_status() {
		return null !== $this->pending ? $this->pending : $this->status;
	}
	/** As a fresh wc_get_order() gives it: the stored order, nothing unsaved. */
	public function fresh() {
		$this->pending = null;
		return $this;
	}
	public $items = array();
	public function get_items() {
		$out = array();
		foreach ( $this->items as $id => $qty ) {
			$out[ $id ] = new WC_Order_Item_Double( $qty );
		}
		return $out;
	}
	/**
	 * @var bool|string Whether WooCommerce fails to save the status, as 11.2
	 * does (true): a hook threw while saving, or the order table's "Could not
	 * persist order"; WooCommerce catches it, adds its note, leaves the stored
	 * order as it was and still answers true. "false": the rarer failure
	 * update_status() does answer false to (an exception in set_status()).
	 */
	public $fail_status = false;

	/** @var bool Whether the request dies in update_status (a hook's fatal, a time limit). */
	public $dies = false;

	/** @var string A status another plugin moves it on to, in the same request ("": none). */
	public $moves_to = '';
	public function update_status( $to, $note = '' ) {
		if ( $this->dies ) {
			throw new \Error( 'died' );
		}
		if ( 'false' === $this->fail_status ) {
			$this->notes[] = array( 'Update status event failed.', 0 ); // (as WooCommerce adds)
			return false;
		}
		if ( $this->fail_status ) {
			$this->pending = $to; // (set_status() did; save() threw)
			$this->notes[] = array( 'Error saving order ID ' . $this->id, 0 ); // (as WooCommerce adds)
			return true;
		}
		$this->statuses[] = array( $to, $note );
		$this->status     = '' !== $this->moves_to ? $this->moves_to : $to;
		$this->pending    = null;
		return true;
	}
	/** WooCommerce's own rule: its paid statuses (a plugin's too), then its filter. */
	public function is_paid() {
		$paid = apply_filters( 'woocommerce_order_is_paid_statuses', array( 'processing', 'completed' ) );
		return apply_filters( 'woocommerce_order_is_paid', in_array( $this->get_status(), $paid, true ), $this );
	}
	public function add_order_note( $note, $customer = 0 ) {
		$this->notes[] = array( $note, $customer );
	}
}

/**
 * WooCommerce's key table, in memory: rows by key_id; and WordPress's options
 * table, the test's own options (shared by reference), for what the plugin
 * reads and writes there directly (Ordryx_Lock).
 */
class Fake_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $keys    = array();
	public $last    = '';
	/** @var array The options table's rows: name => value (the test's $options). */
	public $rows    = array();
	/** @var callable|null Called before each insert into the options table (another request, at that moment). */
	public $before_insert;
	/** @var callable|null Called before each update of the options table. */
	public $before_update;
	/** @var callable|null Called before each read of an option's row by name. */
	public $before_read;

	public function prepare( $query, ...$args ) {
		return vsprintf(
			str_replace( '%s', "'%s'", $query ),
			array_map(
				function ( $a ) {
					return is_string( $a ) ? addslashes( $a ) : $a;
				},
				$args
			)
		);
	}

	public function suppress_errors( $on = true ) {
		return false;
	}

	/** INSERT: refused for a name that's there (the options table's unique key). */
	public function insert( $table, array $data, $format = null ) {
		if ( $this->options !== $table ) {
			return false;
		}
		if ( $this->before_insert ) {
			call_user_func( $this->before_insert, $data['option_name'] );
		}
		if ( array_key_exists( $data['option_name'], $this->rows ) ) {
			return false;
		}
		$this->rows[ $data['option_name'] ] = $data['option_value'];
		return 1;
	}

	/** UPDATE … WHERE every column given matches: the rows changed. */
	public function update( $table, array $data, array $where, $format = null, $where_format = null ) {
		$name = $where['option_name'];
		if ( $this->before_update ) {
			call_user_func( $this->before_update, $name );
		}
		if ( $this->options !== $table || ! array_key_exists( $name, $this->rows )
			|| ( isset( $where['option_value'] ) && $this->rows[ $name ] !== $where['option_value'] ) ) {
			return 0;
		}
		$this->rows[ $name ] = $data['option_value'];
		return 1;
	}

	public function query( $query ) {
		$this->last = $query;
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '(.*)' OR option_name LIKE '(.*)'$/", $query, $m ) ) {
			$n = 0;
			foreach ( array_keys( $this->rows ) as $name ) {
				foreach ( array( $m[1], $m[2] ) as $like ) {
					$prefix = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( substr( $like, 0, -1 ) ) );
					if ( 0 === strpos( $name, $prefix ) ) {
						unset( $this->rows[ $name ] );
						++$n;
						break;
					}
				}
			}
			return $n;
		}
		return 0;
	}

	public function get_row( $query ) {
		$this->last = $query;
		if ( preg_match( '/WHERE key_id = (\d+)/', $query, $m ) && isset( $this->keys[ (int) $m[1] ] ) ) {
			return (object) $this->keys[ (int) $m[1] ];
		}
		return null;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_var( $query ) {
		$this->last = $query;
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(.*)' LIMIT 1$/", $query, $o ) ) {
			$name = stripslashes( $o[1] );
			if ( $this->before_read ) {
				call_user_func( $this->before_read, $name );
			}
			return array_key_exists( $name, $this->rows ) ? $this->rows[ $name ] : null;
		}
		if ( ! preg_match( '/WHERE user_id = (\d+)/', $query, $m ) ) {
			return null;
		}
		$found = null;
		foreach ( $this->keys as $id => $row ) {
			if ( (int) $m[1] === $row['user_id'] && 'read' === $row['permissions'] && 0 === strpos( $row['description'], 'Ordryx - API (' ) ) {
				$found = max( (int) $found, $id );
			}
		}
		return $found;
	}

	public function delete( $table, array $where, array $format ) {
		if ( $this->options === $table ) {
			$name = $where['option_name'];
			if ( ! array_key_exists( $name, $this->rows ) || ( isset( $where['option_value'] ) && $this->rows[ $name ] !== $where['option_value'] ) ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			return 1;
		}
		if ( 'wp_woocommerce_api_keys' !== $table || ! isset( $this->keys[ $where['key_id'] ] ) ) {
			return 0;
		}
		unset( $this->keys[ $where['key_id'] ] );
		return 1;
	}

	/**
	 * A key as WooCommerce's Allow screen makes it.
	 */
	public function add_key( $key_id, $consumer_key, $user_id = 1, $description = 'Ordryx - API (2026-10-22 18:00:00)', $permissions = 'read' ) {
		$this->keys[ $key_id ] = array(
			'key_id'        => $key_id,
			'user_id'       => $user_id,
			'description'   => $description,
			'permissions'   => $permissions,
			'consumer_key'  => hash_hmac( 'sha256', $consumer_key, 'wc-api' ),
			'truncated_key' => substr( $consumer_key, -7 ),
		);
	}
}

/** A redirect or wp_die, thrown so a test sees where a step ended. */
class Redirected extends Exception {}

class WC_Order_Item_Double {
	private $qty;
	public function __construct( $qty ) {
		$this->qty = $qty;
	}
	public function get_quantity() {
		return $this->qty;
	}
}
