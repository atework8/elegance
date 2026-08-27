<?php
/**
 * Plugin Name: Store Payment Reliability Test
 * Description: Local-only deterministic WooCommerce payment, reconciliation, event-idempotency, and refund test provider.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

function store_payment_test_is_allowed() {
	if ( function_exists( 'store_commerce_allows_mocks' ) ) return store_commerce_allows_mocks();
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
}

if ( ! store_payment_test_is_allowed() ) return;

function store_payment_test_states() {
	return array( 'not_started', 'pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded', 'uncertain' );
}

function store_payment_test_set_state( WC_Order $order, $state ) {
	if ( ! in_array( $state, store_payment_test_states(), true ) ) {
		throw new InvalidArgumentException( 'Unsupported payment state.' );
	}
	$order->update_meta_data( '_store_payment_state', $state );
	$order->save();
}

function store_payment_test_apply_event( WC_Order $order, array $event ) {
	$event_id = sanitize_text_field( $event['id'] ?? '' );
	$type     = sanitize_key( $event['type'] ?? '' );
	$reference= sanitize_text_field( $event['reference'] ?? '' );
	if ( '' === $event_id || '' === $type ) return new WP_Error( 'invalid_event', 'Provider event ID and type are required.' );
	if ( ! in_array( $type, array( 'payment_succeeded','payment_failed','payment_cancelled','payment_pending' ), true ) ) return new WP_Error( 'unsupported_event', 'Unsupported provider event type.' );
	$claim = function_exists( 'store_commerce_claim_event' ) ? store_commerce_claim_event( 'payment', $event_id, $type, $order->get_id() ) : array( 'claimed'=>true, 'key'=>'' );
	if ( ! $claim['claimed'] ) return array( 'duplicate'=>true, 'state'=>$order->get_meta('_store_payment_state',true) );

	$processed = (array) $order->get_meta( '_store_processed_payment_events', true );
	if ( isset( $processed[ $event_id ] ) ) {
		return array( 'duplicate' => true, 'state' => $order->get_meta( '_store_payment_state', true ) );
	}

	$processed[ $event_id ] = array( 'type' => $type, 'processed_at' => time() );
	$order->update_meta_data( '_store_processed_payment_events', $processed );
	$order->save();

	if ( 'payment_succeeded' === $type ) {
		if ( ! $order->is_paid() ) {
			$order->update_meta_data( '_store_payment_completion_count', (int) $order->get_meta( '_store_payment_completion_count', true ) + 1 );
			$order->save();
			$order->payment_complete( $reference );
			store_payment_test_set_state( $order, 'paid' );
			do_action( 'store_mock_payment_paid_once', $order->get_id(), $event_id );
		}
	} elseif ( 'payment_failed' === $type ) {
		if ( ! $order->is_paid() ) {
			$order->update_status( 'failed', 'Mock provider confirmed payment failure.' );
			store_payment_test_set_state( $order, 'failed' );
		}
	} elseif ( 'payment_cancelled' === $type ) {
		if ( ! $order->is_paid() ) {
			$order->update_status( 'pending', 'Mock provider confirmed customer cancellation. The order remains safely retryable.' );
			store_payment_test_set_state( $order, 'cancelled' );
		}
	} elseif ( 'payment_pending' === $type ) {
		if ( ! $order->is_paid() ) {
			$order->update_status( 'on-hold', 'Mock provider reports payment pending.' );
			store_payment_test_set_state( $order, 'pending' );
		}
	}
	if ( $claim['key'] && function_exists( 'store_commerce_finish_event' ) ) store_commerce_finish_event( $claim['key'], 'complete' );
	return array( 'duplicate' => false, 'state' => $order->get_meta( '_store_payment_state', true ) );
}

function store_payment_test_reconcile( WC_Order $order ) {
	if ( 'uncertain' !== $order->get_meta( '_store_payment_state', true ) && 'pending' !== $order->get_meta( '_store_payment_state', true ) ) {
		return new WP_Error( 'not_reconcilable', 'Order is not awaiting reconciliation.' );
	}
	$order->update_meta_data( '_store_reconciliation_count', (int) $order->get_meta( '_store_reconciliation_count', true ) + 1 );
	$outcome = $order->get_meta( '_store_mock_provider_outcome', true );
	$order->save();
	if ( 'paid' === $outcome ) {
		return store_payment_test_apply_event( $order, array(
			'id' => 'reconcile-paid-' . $order->get_id(), 'type' => 'payment_succeeded',
			'reference' => $order->get_meta( '_store_provider_reference', true ),
		) );
	}
	if ( 'not_paid' === $outcome ) {
		return store_payment_test_apply_event( $order, array( 'id' => 'reconcile-failed-' . $order->get_id(), 'type' => 'payment_failed' ) );
	}
	return array( 'duplicate' => false, 'state' => $order->get_meta( '_store_payment_state', true ) );
}

function store_payment_test_refund_event( WC_Order $order, array $event ) {
	$event_id = sanitize_text_field( $event['id'] ?? '' );
	$amount = wc_format_decimal( $event['amount'] ?? 0 );
	$processed = (array) $order->get_meta( '_store_processed_refund_events', true );
	if ( isset( $processed[ $event_id ] ) ) {
		return wc_get_order( $processed[ $event_id ] );
	}
	if ( '' === $event_id || $amount <= 0 || $amount > $order->get_remaining_refund_amount() ) {
		return new WP_Error( 'invalid_refund', 'Refund amount exceeds the eligible amount or the event is invalid.' );
	}
	$refund = wc_create_refund( array(
		'order_id' => $order->get_id(), 'amount' => $amount,
		'reason' => 'Development payment reliability refund', 'refund_payment' => false, 'restock_items' => false,
	) );
	if ( is_wp_error( $refund ) ) return $refund;
	$processed[ $event_id ] = $refund->get_id();
	$order->update_meta_data( '_store_processed_refund_events', $processed );
	$order->update_meta_data( '_store_payment_state', $order->get_remaining_refund_amount() > 0 ? 'partially_refunded' : 'refunded' );
	$order->save();
	return $refund;
}

add_action( 'store_mock_payment_paid_once', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->update_meta_data( '_store_paid_side_effect_count', (int) $order->get_meta( '_store_paid_side_effect_count', true ) + 1 );
		$order->save();
	}
} );

add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
	if ( store_payment_test_is_allowed() ) $gateways[] = 'WC_Gateway_Store_Payment_Reliability_Test';
	return $gateways;
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || ! store_payment_test_is_allowed() ) return;

	class WC_Gateway_Store_Payment_Reliability_Test extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'store_payment_reliability_test';
			$this->method_title = 'Development Payment Reliability Test';
			$this->method_description = 'Local-only deterministic payment reliability scenarios. Never enable on a live storefront.';
			$this->title = 'Development Payment Reliability Test';
			$this->description = 'Development-only simulated payment. No money or payment credentials are collected.';
			$this->has_fields = true;
			$this->enabled = 'yes';
		}

		public function payment_fields() {
			echo '<p>' . esc_html( $this->description ) . '</p><label for="store-payment-scenario">' . esc_html__( 'Test scenario', 'store-payment-reliability-test' ) . '</label>';
			echo '<select id="store-payment-scenario" name="store_payment_scenario">';
			foreach ( array( 'success', 'failure', 'cancelled', 'pending', 'timeout_before_charge', 'timeout_after_charge', 'delayed_confirmation', 'duplicate_provider_event' ) as $scenario ) {
				echo '<option value="' . esc_attr( $scenario ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $scenario ) ) ) . '</option>';
			}
			echo '</select>';
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$scenario = sanitize_key( wp_unslash( $_POST['store_payment_scenario'] ?? $order->get_meta( '_store_payment_scenario', true ) ?: 'success' ) );
			$reference = 'mock_' . wp_generate_uuid4();
			$order->set_payment_method( $this->id );
			$order->set_payment_method_title( $this->title );
			$order->update_meta_data( '_store_payment_scenario', $scenario );
			$order->update_meta_data( '_store_provider_reference', $reference );
			$order->update_meta_data( '_store_charge_attempt_count', (int) $order->get_meta( '_store_charge_attempt_count', true ) + 1 );
			$order->save();

			if ( 'success' === $scenario ) {
				store_payment_test_apply_event( $order, array( 'id' => 'success-' . $order_id, 'type' => 'payment_succeeded', 'reference' => $reference ) );
			} elseif ( 'failure' === $scenario ) {
				store_payment_test_apply_event( $order, array( 'id' => 'failure-' . $order_id, 'type' => 'payment_failed' ) );
				wc_add_notice( 'The development provider declined this payment. You may retry from the order payment page.', 'error' );
				return array( 'result' => 'failure' );
			} elseif ( 'cancelled' === $scenario ) {
				store_payment_test_apply_event( $order, array( 'id' => 'cancelled-' . $order_id, 'type' => 'payment_cancelled' ) );
				wc_add_notice( 'The development payment was cancelled. No charge was made.', 'notice' );
				return array( 'result' => 'failure' );
			} elseif ( 'pending' === $scenario || 'delayed_confirmation' === $scenario || 'duplicate_provider_event' === $scenario ) {
				store_payment_test_apply_event( $order, array( 'id' => 'pending-' . $order_id, 'type' => 'payment_pending' ) );
			} elseif ( 'timeout_before_charge' === $scenario ) {
				store_payment_test_set_state( $order, 'not_started' );
				$order->add_order_note( 'Mock timeout occurred before a charge was submitted.' );
				wc_add_notice( 'The test provider timed out before charging. Payment may be retried.', 'error' );
				return array( 'result' => 'failure' );
			} elseif ( 'timeout_after_charge' === $scenario ) {
				$order->update_status( 'on-hold', 'Provider accepted the request but the immediate result was lost. Reconciliation is required before retry.' );
				store_payment_test_set_state( $order, 'uncertain' );
			}
			WC()->cart && WC()->cart->empty_cart();
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}
	}
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'store-payment-test/v1', '/event', array(
		'methods' => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			if ( ! store_payment_test_is_allowed() ) return false;
			if(function_exists('store_commerce_rate_limit')&&!store_commerce_rate_limit($request,'payment_callback'))return false;
			$secret = function_exists('store_commerce_secret')?store_commerce_secret('PAYMENT_WEBHOOK_SECRET','store_payment_test_webhook_secret'):(string)get_option('store_payment_test_webhook_secret');
			$provided = (string) $request->get_header( 'x-store-test-signature' );
			return $secret && $provided && hash_equals( hash_hmac( 'sha256', $request->get_body(), $secret ), $provided );
		},
		'callback' => function ( WP_REST_Request $request ) {
			$payload = $request->get_json_params();
			$order = wc_get_order( absint( $payload['order_id'] ?? 0 ) );
			if ( ! $order ) return new WP_Error( 'missing_order', 'Order was not found.', array( 'status' => 404 ) );
			$result = store_payment_test_apply_event( $order, $payload );
			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		},
	) );
} );

register_activation_hook( __FILE__, function () {
	if ( ! get_option( 'store_payment_test_webhook_secret' ) ) {
		update_option( 'store_payment_test_webhook_secret', wp_generate_password( 64, true, true ), false );
	}
} );
