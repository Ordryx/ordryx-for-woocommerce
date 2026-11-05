<?php
/**
 * WooCommerce's Fulfillments, as far as the plugin uses them: switched off
 * unless a test says so.
 *
 * @package Ordryx
 */

namespace Automattic\WooCommerce\Utilities {
	class FeaturesUtil {
		public static $on = false;
		public static function feature_is_enabled( $feature ) {
			return self::$on && 'fulfillments' === $feature;
		}
	}
}

namespace Automattic\WooCommerce\Admin\Features\Fulfillments {
	class Fulfillment {
		public static $saved = array();
		public static $breaks = false;
		public $got = array();
		public function __call( $name, $args ) {
			$this->got[ $name ] = $args[0];
		}
		public function save() {
			if ( self::$breaks ) {
				throw new \RuntimeException( 'changed in a new version' );
			}
			self::$saved[] = $this->got;
		}
	}
}
