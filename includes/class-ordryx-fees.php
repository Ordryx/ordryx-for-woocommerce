<?php
/**
 * Two fields in WooCommerce's order answer, for Ordryx's key only, each when
 * it's asked for: "ordryx_fees", the payment processor's fee on the order,
 * and "ordryx_paid", whether WooCommerce counts the order paid.
 *
 * @package Ordryx
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ordryx never asks for an order's own meta_data (it may hold other plugins'
 * customer data; a line's comes with its line, as WooCommerce's order screen
 * shows it). The processors keep their fee there, so the plugin reads
 * the few keys it knows and adds only those, as
 * [{processor, amount, currency}]: empty when the order has none it knows.
 * A fee in another currency than the order's is given as it is.
 *
 * Paid is WooCommerce's own rule, the order's is_paid(): Processing or
 * Completed, plus any status a plugin adds to the paid list
 * (woocommerce_order_is_paid_statuses: "Packing", "Awaiting shipment"), as
 * the woocommerce_order_is_paid filter has it. Per order, so there's no list
 * to keep in step.
 */
class Ordryx_Fees {

	const FIELD = 'ordryx_fees';
	const PAID  = 'ordryx_paid';

	/**
	 * Listen.
	 */
	public static function hook() {
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( __CLASS__, 'add' ), 10, 3 );
	}

	/**
	 * Add the field to an order's answer, when Ordryx's key asks for it.
	 *
	 * @param WP_REST_Response $response The answer so far.
	 * @param WC_Order         $order    The order.
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_REST_Response
	 */
	public static function add( $response, $order, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $order instanceof WC_Order ) {
			return $response;
		}
		$fields = $request instanceof WP_REST_Request ? $request->get_param( '_fields' ) : null;
		$fees   = self::asked( $fields );
		$paid   = self::asked( $fields, self::PAID );
		if ( ! ( $fees || $paid ) || ! Ordryx_Keys::asking() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( $fees ) {
			$data[ self::FIELD ] = self::of( $order );
		}
		if ( $paid ) {
			$data[ self::PAID ] = (bool) $order->is_paid();
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Whether the request named a field (Ordryx always names its fields).
	 *
	 * @param mixed  $fields The request's _fields.
	 * @param string $field  The field: FIELD (the default) or PAID.
	 * @return bool
	 */
	public static function asked( $fields, $field = self::FIELD ) {
		if ( is_string( $fields ) ) {
			$fields = explode( ',', $fields );
		}
		return is_array( $fields ) && in_array( $field, array_map( 'trim', $fields ), true );
	}

	/**
	 * The processors' fees on an order.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	public static function of( $order ) {
		$fees = array();
		// WooPayments: the fee in the payment's currency, as far as the order
		// says. Its fee may be in the account's own currency instead, which the
		// order doesn't keep: when the payment wasn't in the store's currency,
		// it goes as "XXX" (no currency), so Ordryx shows it but never adds it.
		$fee = self::amount( $order->get_meta( '_wcpay_transaction_fee', true ) );
		if ( null !== $fee ) {
			$paid_in = self::currency( $order->get_meta( '_wcpay_intent_currency', true ), $order );
			$fees[]  = array(
				'processor' => 'WooPayments',
				'amount'    => $fee,
				'currency'  => function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() !== $paid_in ? 'XXX' : $paid_in,
			);
		}
		// WooCommerce Stripe Payment Gateway: the fee in Stripe's balance currency
		// (an older order's under the gateway's earlier key).
		$fee = self::amount( $order->get_meta( '_stripe_fee', true ) );
		if ( null === $fee ) {
			$fee = self::amount( $order->get_meta( 'Stripe Fee', true ) );
		}
		if ( null !== $fee ) {
			// An older order may not say Stripe's currency: the order's own is
			// a guess only when it's the store's (Stripe settles in that).
			$kept   = $order->get_meta( '_stripe_currency', true );
			$named  = is_string( $kept ) && preg_match( '/^[A-Za-z]{3}$/D', trim( $kept ) );
			$fees[] = array(
				'processor' => 'Stripe',
				'amount'    => $fee,
				'currency'  => ! $named && function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() !== $order->get_currency()
					? 'XXX'
					: self::currency( $kept, $order ),
			);
		}
		return $fees;
	}

	/**
	 * A fee as a decimal string, or null when it isn't one.
	 *
	 * @param mixed $value As kept.
	 * @return string|null
	 */
	private static function amount( $value ) {
		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			return null;
		}
		$text = trim( (string) $value );
		if ( preg_match( '/^\d{1,12}(\.\d{1,8})?$/D', $text ) ) {
			return $text;
		}
		$text = rtrim( rtrim( sprintf( '%.8F', (float) $value ), '0' ), '.' );
		return preg_match( '/^\d{1,12}(\.\d{1,8})?$/D', $text ) ? $text : null;
	}

	/**
	 * A currency code, else the order's own.
	 *
	 * @param mixed    $value As kept.
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private static function currency( $value, $order ) {
		$code = is_string( $value ) ? strtoupper( trim( $value ) ) : '';
		return preg_match( '/^[A-Z]{3}$/D', $code ) ? $code : (string) $order->get_currency();
	}
}
