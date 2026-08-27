<?php
/**
 * Plugin Name: Store Fulfillment Baseline
 * Description: Local-only post-payment physical/digital fulfillment reliability baseline with mock supplier and Action Scheduler.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'STORE_FULFILLMENT_GROUP', 'store-fulfillment-baseline' );

function store_fulfillment_is_allowed() {
	if ( function_exists( 'store_commerce_allows_mocks' ) ) return store_commerce_allows_mocks();
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
}

if ( ! store_fulfillment_is_allowed() ) return;

function store_fulfillment_tables() {
	global $wpdb;
	return array(
		'operations' => $wpdb->prefix . 'store_fulfillment_operations',
		'events'     => $wpdb->prefix . 'store_fulfillment_events',
		'exceptions' => $wpdb->prefix . 'store_fulfillment_exceptions',
	);
}

function store_fulfillment_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$tables = store_fulfillment_tables();
	$charset = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$tables['operations']} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		operation_key varchar(191) NOT NULL,
		order_id bigint unsigned NOT NULL,
		item_id bigint unsigned NOT NULL DEFAULT 0,
		operation_type varchar(50) NOT NULL,
		status varchar(40) NOT NULL,
		scenario varchar(50) NOT NULL DEFAULT 'happy_path',
		attempt_count int unsigned NOT NULL DEFAULT 0,
		supplier_order_id varchar(100) NULL,
		last_error_code varchar(80) NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY operation_key (operation_key),
		UNIQUE KEY supplier_order_id (supplier_order_id),
		KEY order_id (order_id)
	) $charset;" );
	dbDelta( "CREATE TABLE {$tables['events']} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		event_key varchar(191) NOT NULL,
		order_id bigint unsigned NOT NULL,
		event_type varchar(60) NOT NULL,
		payload_hash char(64) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY order_id (order_id)
	) $charset;" );
	dbDelta( "CREATE TABLE {$tables['exceptions']} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		exception_key varchar(191) NOT NULL,
		order_id bigint unsigned NOT NULL,
		item_id bigint unsigned NOT NULL DEFAULT 0,
		fulfillment_type varchar(30) NOT NULL,
		exception_type varchar(60) NOT NULL,
		status varchar(30) NOT NULL DEFAULT 'open',
		retryable tinyint(1) NOT NULL DEFAULT 0,
		safe_context longtext NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY exception_key (exception_key),
		KEY order_id (order_id),
		KEY status (status)
	) $charset;" );
	if ( ! get_option( 'store_fulfillment_callback_secret' ) ) update_option( 'store_fulfillment_callback_secret', wp_generate_password( 64, true, true ), false );
	update_option( 'store_fulfillment_schema_version', '1.0.0', false );
}
register_activation_hook( __FILE__, 'store_fulfillment_install' );

function store_physical_fulfillment_states() {
	return array( 'not_started','queued','submitting','submitted','accepted','processing','shipped','delivered','failed','cancelled','exception' );
}
function store_digital_fulfillment_states() { return array( 'not_started','processing','ready','failed','revoked' ); }

function store_fulfillment_set_item_state( WC_Order_Item_Product $item, $type, $state ) {
	$allowed = 'digital' === $type ? store_digital_fulfillment_states() : store_physical_fulfillment_states();
	if ( ! in_array( $state, $allowed, true ) ) throw new InvalidArgumentException( 'Invalid fulfillment state.' );
	$item->update_meta_data( '_store_' . $type . '_fulfillment_state', $state );
	$history = (array) $item->get_meta( '_store_' . $type . '_fulfillment_history', true );
	$last = $history ? end( $history ) : array();
	if ( ( $last['state'] ?? '' ) !== $state ) {
		$history[] = array( 'state'=>$state, 'timestamp'=>gmdate( 'c' ) );
		$item->update_meta_data( '_store_' . $type . '_fulfillment_history', $history );
	}
	$item->save();
	do_action( 'store_fulfillment_item_state_changed', $item->get_order_id(), $item->get_id(), $type, $state );
}

function store_fulfillment_operation_key( $type, $order_id, $item_id = 0 ) {
	$type = preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) $type ) );
	return $type . ':order_' . absint( $order_id ) . ( $item_id ? ':item_' . absint( $item_id ) : '' ) . ':v1';
}

function store_fulfillment_ensure_operation( $key, $order_id, $item_id, $type, $scenario ) {
	global $wpdb; $table = store_fulfillment_tables()['operations']; $now = current_time( 'mysql', true );
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (operation_key,order_id,item_id,operation_type,status,scenario,created_at,updated_at) VALUES (%s,%d,%d,%s,'queued',%s,%s,%s)", $key, $order_id, $item_id, $type, $scenario, $now, $now ) );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE operation_key=%s", $key ) );
}

function store_fulfillment_claim_operation( $key, array $from, $to = 'submitting' ) {
	global $wpdb; $table = store_fulfillment_tables()['operations'];
	$placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );
	$args = array_merge( array( $to, current_time( 'mysql', true ), $key ), $from );
	$sql = $wpdb->prepare( "UPDATE $table SET status=%s,attempt_count=attempt_count+1,updated_at=%s WHERE operation_key=%s AND status IN ($placeholders)", $args );
	return 1 === $wpdb->query( $sql );
}

function store_fulfillment_update_operation( $key, $status, array $fields = array() ) {
	global $wpdb; $table = store_fulfillment_tables()['operations'];
	$data = array_merge( array( 'status'=>$status, 'updated_at'=>current_time( 'mysql', true ) ), array_intersect_key( $fields, array_flip( array( 'supplier_order_id','last_error_code','scenario' ) ) ) );
	$wpdb->update( $table, $data, array( 'operation_key'=>$key ) );
	$operation=store_fulfillment_get_operation($key);if($operation&&function_exists('store_commerce_log'))store_commerce_log('fulfillment.operation',$operation->order_id,$status,array('operation_id'=>$key,'attempt'=>$operation->attempt_count,'status'=>$status));
}

function store_fulfillment_get_operation( $key ) {
	global $wpdb; $table = store_fulfillment_tables()['operations'];
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE operation_key=%s", $key ) );
}

function store_fulfillment_record_event( $event_key, $order_id, $type, array $safe_payload ) {
	global $wpdb; $table = store_fulfillment_tables()['events'];
	$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (event_key,order_id,event_type,payload_hash,created_at) VALUES (%s,%d,%s,%s,%s)", sanitize_text_field( $event_key ), $order_id, sanitize_key( $type ), hash( 'sha256', wp_json_encode( $safe_payload ) ), current_time( 'mysql', true ) ) );
	return 1 === $inserted;
}

function store_fulfillment_exception( $key, $order_id, $item_id, $type, $exception_type, $retryable, array $context = array() ) {
	global $wpdb; $table = store_fulfillment_tables()['exceptions']; $now = current_time( 'mysql', true );
	$safe = array_intersect_key( $context, array_flip( array( 'operation_key','attempt','customer_message' ) ) );
	$wpdb->query( $wpdb->prepare( "INSERT INTO $table (exception_key,order_id,item_id,fulfillment_type,exception_type,status,retryable,safe_context,created_at,updated_at) VALUES (%s,%d,%d,%s,%s,'open',%d,%s,%s,%s) ON DUPLICATE KEY UPDATE status='open',retryable=VALUES(retryable),safe_context=VALUES(safe_context),updated_at=VALUES(updated_at)", $key, $order_id, $item_id, $type, $exception_type, $retryable ? 1 : 0, wp_json_encode( $safe ), $now, $now ) );
}

function store_fulfillment_close_exception( $key ) {
	global $wpdb; $wpdb->update( store_fulfillment_tables()['exceptions'], array( 'status'=>'resolved', 'updated_at'=>current_time( 'mysql', true ) ), array( 'exception_key'=>$key ) );
}

function store_fulfillment_schedule( $hook, array $args, $delay = 0 ) {
	if ( ! function_exists( 'as_schedule_single_action' ) ) return 0;
	return as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, STORE_FULFILLMENT_GROUP, true );
}

function store_fulfillment_initialize_paid_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! $order->is_paid() ) return;
	foreach ( $order->get_items() as $item_id => $item ) {
		$product = $item->get_product(); if ( ! $product ) continue;
		if ( $product->is_downloadable() || $product->is_virtual() ) {
			if ( ! $item->get_meta( '_store_digital_fulfillment_state', true ) ) store_fulfillment_set_item_state( $item, 'digital', 'not_started' );
			store_fulfillment_schedule( 'store_fulfillment_digital', array( $order_id, $item_id ) );
		} else {
			if ( ! $item->get_meta( '_store_physical_fulfillment_state', true ) ) store_fulfillment_set_item_state( $item, 'physical', 'queued' );
			store_fulfillment_schedule( 'store_fulfillment_dispatch_physical', array( $order_id, $item_id ) );
		}
	}
}
add_action( 'woocommerce_payment_complete', 'store_fulfillment_initialize_paid_order', 20 );

// Digital delivery is intentionally completed by the asynchronous fulfillment job.
add_action( 'woocommerce_init', function () {
	remove_action( 'woocommerce_order_status_completed', 'wc_downloadable_product_permissions' );
	remove_action( 'woocommerce_order_status_processing', 'wc_downloadable_product_permissions' );
}, 1 );

function store_fulfillment_dispatch_physical( $order_id, $item_id ) {
	$order = wc_get_order( $order_id ); $item = $order ? $order->get_item( $item_id ) : false;
	if ( ! $order || ! $item || ! $order->is_paid() ) return;
	$scenario = sanitize_key( $order->get_meta( '_store_supplier_scenario', true ) ?: 'happy_path' );
	$key = store_fulfillment_operation_key( 'supplier.submit', $order_id );
	$operation = store_fulfillment_ensure_operation( $key, $order_id, $item_id, 'supplier_submit', $scenario );
	if ( ! store_fulfillment_claim_operation( $key, array( 'queued','retryable' ) ) ) return;
	store_fulfillment_set_item_state( $item, 'physical', 'submitting' );
	$operation = store_fulfillment_get_operation( $key );

	if ( 'supplier_rejection' === $scenario || 'availability_changed' === $scenario ) {
		$code = 'availability_changed' === $scenario ? 'availability_changed' : 'supplier_rejected';
		store_fulfillment_update_operation( $key, 'failed', array( 'last_error_code'=>$code ) );
		store_fulfillment_set_item_state( $item, 'physical', 'failed' );
		store_fulfillment_exception( $key . ':' . $code, $order_id, $item_id, 'physical', $code, false, array( 'operation_key'=>$key, 'customer_message'=>'We are reviewing a fulfillment issue.' ) );
		return;
	}
	if ( 'timeout_before_acceptance' === $scenario && 1 === (int) $operation->attempt_count ) {
		store_fulfillment_update_operation( $key, 'retryable', array( 'last_error_code'=>'timeout_before_acceptance' ) );
		store_fulfillment_set_item_state( $item, 'physical', 'exception' );
		store_fulfillment_exception( $key . ':timeout_before', $order_id, $item_id, 'physical', 'supplier_timeout_before_acceptance', true, array( 'operation_key'=>$key, 'attempt'=>1, 'customer_message'=>'Fulfillment is taking longer than expected.' ) );
		store_fulfillment_schedule( 'store_fulfillment_supplier_retry', array( $order_id, $item_id, 1 ), 60 );
		return;
	}
	$supplier_id = $operation->supplier_order_id ?: 'mock_supplier_' . $order_id;
	store_fulfillment_update_operation( $key, 'accepted', array( 'supplier_order_id'=>$supplier_id, 'last_error_code'=>null ) );
	store_fulfillment_set_item_state( $item, 'physical', 'submitted' );
	if ( 'delayed_acceptance' === $scenario ) {
		store_fulfillment_update_operation( $key, 'submitted' );
		store_fulfillment_schedule( 'store_fulfillment_supplier_reconcile', array( $order_id, $item_id ), 60 );
		return;
	}
	store_fulfillment_set_item_state( $item, 'physical', 'accepted' );
	if ( 'timeout_after_acceptance' === $scenario ) {
		store_fulfillment_update_operation( $key, 'uncertain', array( 'last_error_code'=>'timeout_after_acceptance' ) );
		store_fulfillment_set_item_state( $item, 'physical', 'exception' );
		store_fulfillment_exception( $key . ':timeout_after', $order_id, $item_id, 'physical', 'supplier_timeout_after_acceptance', true, array( 'operation_key'=>$key, 'customer_message'=>'We are confirming fulfillment status.' ) );
		store_fulfillment_schedule( 'store_fulfillment_supplier_reconcile', array( $order_id, $item_id ), 60 );
		return;
	}
	store_fulfillment_update_operation( $key, 'processing' );
	store_fulfillment_set_item_state( $item, 'physical', 'processing' );
}
add_action( 'store_fulfillment_dispatch_physical', 'store_fulfillment_dispatch_physical', 10, 2 );

function store_fulfillment_supplier_retry( $order_id, $item_id, $attempt ) {
	if ( $attempt > 3 ) return;
	store_fulfillment_dispatch_physical( $order_id, $item_id );
}
add_action( 'store_fulfillment_supplier_retry', 'store_fulfillment_supplier_retry', 10, 3 );

function store_fulfillment_supplier_reconcile( $order_id, $item_id ) {
	$order = wc_get_order( $order_id ); $item = $order ? $order->get_item( $item_id ) : false; if ( ! $item ) return;
	$key = store_fulfillment_operation_key( 'supplier.submit', $order_id );
	if ( ! store_fulfillment_claim_operation( $key, array( 'uncertain','submitted' ), 'reconciling' ) ) return;
	$operation = store_fulfillment_get_operation( $key );
	if ( $operation && $operation->supplier_order_id ) {
		store_fulfillment_update_operation( $key, 'processing', array( 'last_error_code'=>null ) );
		store_fulfillment_set_item_state( $item, 'physical', 'accepted' );
		store_fulfillment_set_item_state( $item, 'physical', 'processing' );
		store_fulfillment_close_exception( $key . ':timeout_after' );
	}
}
add_action( 'store_fulfillment_supplier_reconcile', 'store_fulfillment_supplier_reconcile', 10, 2 );

function store_fulfillment_digital( $order_id, $item_id ) {
	$order = wc_get_order( $order_id ); $item = $order ? $order->get_item( $item_id ) : false;
	if ( ! $order || ! $item || ! $order->is_paid() ) return;
	$key = store_fulfillment_operation_key( 'digital.fulfill', $order_id, $item_id );
	store_fulfillment_ensure_operation( $key, $order_id, $item_id, 'digital_fulfillment', sanitize_key( $order->get_meta( '_store_digital_scenario', true ) ?: 'happy_path' ) );
	if ( ! store_fulfillment_claim_operation( $key, array( 'queued' ), 'processing' ) ) return;
	store_fulfillment_set_item_state( $item, 'digital', 'processing' );
	if ( 'digital_failure' === $order->get_meta( '_store_digital_scenario', true ) ) {
		store_fulfillment_update_operation( $key, 'failed', array( 'last_error_code'=>'digital_fulfillment_failed' ) );
		store_fulfillment_set_item_state( $item, 'digital', 'failed' );
		store_fulfillment_exception( $key . ':failed', $order_id, $item_id, 'digital', 'digital_fulfillment_failure', false, array( 'operation_key'=>$key, 'customer_message'=>'Digital delivery is being reviewed.' ) );
		return;
	}
	$product = $item->get_product();
	foreach ( $product->get_downloads() as $download_id => $download ) {
		wc_downloadable_file_permission( $download_id, $product, $order, $item->get_quantity(), $item );
	}
	store_fulfillment_update_operation( $key, 'ready' );
	store_fulfillment_set_item_state( $item, 'digital', 'ready' );
}
add_action( 'store_fulfillment_digital', 'store_fulfillment_digital', 10, 2 );

function store_fulfillment_apply_tracking( $order_id, $item_id, array $event ) {
	$order = wc_get_order( $order_id ); $item = $order ? $order->get_item( $item_id ) : false; if ( ! $item ) return new WP_Error( 'missing_item', 'Order item not found.' );
	$event_key = sanitize_text_field( $event['event_key'] ?? '' ); $status = sanitize_key( $event['status'] ?? '' );
	if ( ! in_array( $status, array( 'shipped','delivered','exception' ), true ) || ! $event_key ) return new WP_Error( 'invalid_tracking', 'Invalid tracking event.' );
	if ( ! store_fulfillment_record_event( $event_key, $order_id, 'tracking_' . $status, array( 'item_id'=>$item_id, 'status'=>$status ) ) ) return array( 'duplicate'=>true );
	$url = esc_url_raw( $event['tracking_url'] ?? '', array( 'http','https' ) );
	if ( $url && ! wp_http_validate_url( $url ) ) $url = '';
	$item->update_meta_data( '_store_tracking_carrier', sanitize_text_field( $event['carrier'] ?? '' ) );
	$item->update_meta_data( '_store_tracking_number', sanitize_text_field( $event['tracking_number'] ?? '' ) );
	$item->update_meta_data( '_store_tracking_url', $url );
	if ( 'shipped' === $status ) $item->update_meta_data( '_store_shipped_at', gmdate( 'c' ) );
	if ( 'delivered' === $status ) $item->update_meta_data( '_store_delivered_at', gmdate( 'c' ) );
	store_fulfillment_set_item_state( $item, 'physical', $status );
	do_action( 'store_fulfillment_tracking_applied', $order_id, $item_id, $status, array( 'carrier'=>sanitize_text_field($event['carrier']??''), 'tracking_number'=>sanitize_text_field($event['tracking_number']??''), 'tracking_url'=>esc_url_raw($event['tracking_url']??''), 'shipment_status'=>$status ) );
	return array( 'duplicate'=>false, 'status'=>$status );
}

add_action( 'store_fulfillment_tracking_update', function ( $order_id, $item_id, $event ) { store_fulfillment_apply_tracking( $order_id, $item_id, (array) $event ); }, 10, 3 );

add_action( 'store_fulfillment_test_action_failure', function () {
	if ( store_fulfillment_is_allowed() ) throw new RuntimeException( 'Intentional local Action Scheduler failure test.' );
} );

function store_fulfillment_callback_authorized( WP_REST_Request $request ) {
	if ( ! store_fulfillment_is_allowed() ) return false;
	if(function_exists('store_commerce_rate_limit')&&!store_commerce_rate_limit($request,'fulfillment_callback'))return false;
	$secret = function_exists('store_commerce_secret')?store_commerce_secret('FULFILLMENT_CALLBACK_SECRET','store_fulfillment_callback_secret'):(string)get_option('store_fulfillment_callback_secret'); $provided = (string) $request->get_header( 'x-store-fulfillment-signature' );
	return $secret && $provided && hash_equals( hash_hmac( 'sha256', $request->get_body(), $secret ), $provided );
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'store-fulfillment/v1', '/tracking', array(
		'methods'=>'POST', 'permission_callback'=>'store_fulfillment_callback_authorized',
		'callback'=>function ( WP_REST_Request $request ) { $p=$request->get_json_params(); $result=store_fulfillment_apply_tracking( absint($p['order_id']??0), absint($p['item_id']??0), $p ); return is_wp_error($result)?$result:rest_ensure_response($result); },
	) );
} );

function store_fulfillment_customer_label( $type, $state ) {
	$physical = array( 'not_started'=>'Preparing','queued'=>'Processing','submitting'=>'Processing','submitted'=>'Processing','accepted'=>'Processing','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','failed'=>'Fulfillment issue','cancelled'=>'Fulfillment issue','exception'=>'Fulfillment issue' );
	$digital = array( 'not_started'=>'Preparing','processing'=>'Preparing','ready'=>'Available','failed'=>'Delivery issue','revoked'=>'Unavailable' );
	return ( 'digital' === $type ? $digital : $physical )[ $state ] ?? 'Preparing';
}
add_action( 'woocommerce_order_details_after_order_table', function ( $order ) {
	if ( ! $order instanceof WC_Order ) return;
	echo '<section class="store-fulfillment-status"><h2>' . esc_html__( 'Fulfillment status', 'store-fulfillment-baseline' ) . '</h2><ul>';
	foreach ( $order->get_items() as $item ) {
		$product=$item->get_product(); $type=( $product && ( $product->is_downloadable() || $product->is_virtual() ) )?'digital':'physical';
		$state=(string)$item->get_meta('_store_'.$type.'_fulfillment_state',true); $label=store_fulfillment_customer_label($type,$state?:'not_started');
		echo '<li><strong>'.esc_html($item->get_name()).'</strong>: '.esc_html($label);
		if('physical'===$type && 'shipped'===$state){$url=(string)$item->get_meta('_store_tracking_url',true); if($url)echo ' <a rel="nofollow noopener" href="'.esc_url($url).'">'.esc_html__('Track shipment','store-fulfillment-baseline').'</a>';}
		echo '</li>';
	}
	echo '</ul></section>';
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Fulfillment Exceptions', 'Fulfillment Exceptions', 'manage_woocommerce', 'store-fulfillment-exceptions', function () {
		global $wpdb; $table=store_fulfillment_tables()['exceptions']; $rows=$wpdb->get_results("SELECT order_id,fulfillment_type,exception_type,status,retryable,created_at,updated_at FROM $table ORDER BY id DESC LIMIT 100");
		echo '<div class="wrap"><h1>Fulfillment Exceptions</h1><table class="widefat striped"><thead><tr><th>Order</th><th>Fulfillment</th><th>Type</th><th>Status</th><th>Retryable</th><th>Updated</th></tr></thead><tbody>';
		foreach($rows as $row)echo '<tr><td>'.esc_html($row->order_id).'</td><td>'.esc_html($row->fulfillment_type).'</td><td>'.esc_html($row->exception_type).'</td><td>'.esc_html($row->status).'</td><td>'.esc_html($row->retryable?'Yes':'No').'</td><td>'.esc_html($row->updated_at).'</td></tr>';
		echo '</tbody></table></div>';
	} );
} );
