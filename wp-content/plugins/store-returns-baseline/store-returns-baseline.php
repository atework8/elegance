<?php
/**
 * Plugin Name: Store Returns Baseline
 * Description: Native WooCommerce return/refund request workflow for the local development store.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

function store_returns_tables() {
	global $wpdb;
	return array(
		'requests'   => $wpdb->prefix . 'store_return_requests',
		'events'     => $wpdb->prefix . 'store_return_events',
		'exceptions' => $wpdb->prefix . 'store_return_exceptions',
	);
}

function store_returns_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = store_returns_tables();
	$c = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$t['requests']} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		request_key varchar(191) NOT NULL,
		order_id bigint unsigned NOT NULL,
		item_id bigint unsigned NOT NULL,
		customer_id bigint unsigned NOT NULL,
		request_type varchar(32) NOT NULL,
		quantity decimal(12,4) NOT NULL,
		reason varchar(100) NOT NULL,
		customer_note text NULL,
		status varchar(32) NOT NULL DEFAULT 'requested',
		refund_id bigint unsigned NULL,
		policy_snapshot text NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY request_key (request_key),
		KEY order_id (order_id),
		KEY customer_id (customer_id),
		KEY status (status)
	) $c;" );
	dbDelta( "CREATE TABLE {$t['events']} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		event_key varchar(191) NOT NULL,
		request_id bigint unsigned NOT NULL,
		event_type varchar(50) NOT NULL,
		status varchar(20) NOT NULL,
		object_id bigint unsigned NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY request_id (request_id)
	) $c;" );
	dbDelta( "CREATE TABLE {$t['exceptions']} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		exception_key varchar(191) NOT NULL,
		request_id bigint unsigned NULL,
		order_id bigint unsigned NOT NULL,
		item_id bigint unsigned NULL,
		exception_type varchar(60) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'open',
		safe_context text NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY exception_key (exception_key),
		KEY status (status)
	) $c;" );
	add_option( 'store_returns_physical_window_days', 30 );
	add_option( 'store_returns_digital_policy', 'review' );
	add_option( 'store_returns_digital_refund_access', 'retain' );
	update_option( 'store_returns_schema_version', '1.0.0', false );
}
register_activation_hook( __FILE__, 'store_returns_install' );

function store_returns_exception( $key, $order_id, $item_id, $type, $context = array(), $request_id = 0 ) {
	global $wpdb;
	$t = store_returns_tables();
	$now = current_time( 'mysql', true );
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['exceptions']} (exception_key,request_id,order_id,item_id,exception_type,status,safe_context,created_at,updated_at) VALUES (%s,%d,%d,%d,%s,'open',%s,%s,%s)", $key, $request_id, $order_id, $item_id, $type, wp_json_encode( $context ), $now, $now ) );
}

function store_returns_get_request( $id ) {
	global $wpdb;
	$t = store_returns_tables();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['requests']} WHERE id=%d", $id ) );
}

function store_returns_item_type( $item ) {
	$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : false;
	return $product && ( $product->is_downloadable() || $product->is_virtual() ) ? 'digital_refund' : 'physical_return';
}

function store_returns_item_eligibility( $order, $item, $quantity = 1 ) {
	if ( ! $order || ! $item instanceof WC_Order_Item_Product || ! $order->is_paid() ) return new WP_Error( 'not_paid', 'The order is not eligible.' );
	$qty = wc_stock_amount( $quantity );
	$available = max( 0, $item->get_quantity() + $order->get_qty_refunded_for_item( $item->get_id() ) );
	if ( $qty <= 0 || $qty > $available ) return new WP_Error( 'invalid_quantity', 'The requested quantity is not available.' );
	$type = store_returns_item_type( $item );
	if ( 'physical_return' === $type ) {
		if ( 'delivered' !== $item->get_meta( '_store_physical_fulfillment_state', true ) ) return new WP_Error( 'not_delivered', 'This item is not yet eligible for return.' );
		$date = $order->get_date_completed() ?: $order->get_date_paid() ?: $order->get_date_created();
		$days = max( 1, absint( get_option( 'store_returns_physical_window_days', 30 ) ) );
		if ( ! $date || time() > $date->getTimestamp() + DAY_IN_SECONDS * $days ) return new WP_Error( 'window_closed', 'The return window has closed.' );
	} else {
		if ( 'disabled' === get_option( 'store_returns_digital_policy', 'review' ) ) return new WP_Error( 'digital_disabled', 'Digital refund requests are unavailable.' );
		if ( 'ready' !== $item->get_meta( '_store_digital_fulfillment_state', true ) ) return new WP_Error( 'not_ready', 'This digital item is not yet eligible.' );
	}
	return array( 'type' => $type, 'available' => $available );
}

function store_returns_create_request( $order_id, $item_id, $quantity, $reason, $note, $customer_id ) {
	global $wpdb;
	$order = wc_get_order( $order_id );
	$item = $order ? $order->get_item( $item_id ) : false;
	if ( ! $order || ! $item ) {
		store_returns_exception( "request.missing.$order_id.$item_id", $order_id, $item_id, 'missing_item' );
		return new WP_Error( 'missing_item', 'Order item not found.' );
	}
	if ( ! $customer_id || (int) $order->get_customer_id() !== (int) $customer_id ) return new WP_Error( 'forbidden', 'You cannot request a return for this order.' );
	$eligible = store_returns_item_eligibility( $order, $item, $quantity );
	if ( is_wp_error( $eligible ) ) {
		store_returns_exception( 'request.invalid.' . md5( "$order_id:$item_id:$quantity:" . $eligible->get_error_code() ), $order_id, $item_id, $eligible->get_error_code() );
		return $eligible;
	}
	$reason = sanitize_text_field( $reason );
	if ( '' === $reason ) return new WP_Error( 'reason_required', 'Please choose a reason.' );
	$key = 'return.request:' . hash( 'sha256', implode( '|', array( $order_id, $item_id, wc_format_decimal( $quantity ), $eligible['type'] ) ) );
	$t = store_returns_tables(); $now = current_time( 'mysql', true );
	$policy = array( 'physical_window_days' => absint( get_option( 'store_returns_physical_window_days', 30 ) ), 'digital_policy' => get_option( 'store_returns_digital_policy', 'review' ), 'digital_access' => get_option( 'store_returns_digital_refund_access', 'retain' ) );
	$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['requests']} (request_key,order_id,item_id,customer_id,request_type,quantity,reason,customer_note,status,policy_snapshot,created_at,updated_at) VALUES (%s,%d,%d,%d,%s,%f,%s,%s,'requested',%s,%s,%s)", $key, $order_id, $item_id, $customer_id, $eligible['type'], $quantity, $reason, sanitize_textarea_field( $note ), wp_json_encode( $policy ), $now, $now ) );
	if ( ! $ok ) {
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['requests']} WHERE request_key=%s", $key ) );
		store_returns_exception( 'request.duplicate.' . md5( $key ), $order_id, $item_id, 'duplicate_request', array(), $existing );
		return new WP_Error( 'duplicate_request', 'A request already exists for this item and quantity.', array( 'request_id' => (int) $existing ) );
	}
	$id = (int) $wpdb->insert_id;
	store_returns_send_email( 'requested', store_returns_get_request( $id ) );
	return $id;
}

function store_returns_set_status( $request_id, $status ) {
	global $wpdb;
	$r = store_returns_get_request( $request_id );
	$allowed = array( 'approved','rejected','awaiting_return','received','closed' );
	if ( ! $r || ! in_array( $status, $allowed, true ) ) return new WP_Error( 'invalid_transition', 'Invalid return transition.' );
	$transitions = array( 'requested'=>array('approved','rejected'), 'approved'=>array('awaiting_return','received','rejected'), 'awaiting_return'=>array('received'), 'received'=>array('closed'), 'refunded'=>array('closed') );
	if ( empty( $transitions[$r->status] ) || ! in_array( $status, $transitions[$r->status], true ) ) return new WP_Error( 'invalid_transition', 'That status change is not allowed.' );
	$t=store_returns_tables(); $wpdb->update( $t['requests'], array('status'=>$status,'updated_at'=>current_time('mysql',true)), array('id'=>$request_id), array('%s','%s'), array('%d') );
	if(function_exists('store_commerce_log'))store_commerce_log('return.status',$r->order_id,'success',array('operation_id'=>'return_'.$request_id,'status'=>$status));
	if ( in_array( $status, array('approved','rejected','received'), true ) ) store_returns_send_email( $status, store_returns_get_request( $request_id ) );
	return true;
}

function store_returns_claim_event( $key, $request_id, $type ) {
	global $wpdb; $t=store_returns_tables(); $now=current_time('mysql',true);
	$ok=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$t['events']} (event_key,request_id,event_type,status,created_at,updated_at) VALUES (%s,%d,%s,'processing',%s,%s)",$key,$request_id,$type,$now,$now));
	return $ok ? true : $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['events']} WHERE event_key=%s",$key));
}

function store_returns_revoke_downloads( $request ) {
	global $wpdb; $key='digital.revoke:'.$request->id.':v1'; $claim=store_returns_claim_event($key,$request->id,'digital_revoke');
	if ( true !== $claim ) return 'complete' === $claim->status;
	try {
		$order=wc_get_order($request->order_id);$item=$order?$order->get_item($request->item_id):false;if(!$item)throw new RuntimeException('Order item is unavailable.');$product_id=$item->get_product_id();
		$ids=$wpdb->get_col($wpdb->prepare("SELECT permission_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id=%d AND product_id=%d",$request->order_id,$product_id));
		foreach($ids as $id){$d=new WC_Customer_Download($id);$d->delete(true);}
		if($item && function_exists('store_fulfillment_set_item_state')) store_fulfillment_set_item_state($item,'digital','revoked');
		$t=store_returns_tables();$wpdb->update($t['events'],array('status'=>'complete','updated_at'=>current_time('mysql',true)),array('event_key'=>$key)); return true;
	} catch(Throwable $e){store_returns_exception($key.'.failed',$request->order_id,$request->item_id,'digital_revoke_failed',array(),$request->id);return new WP_Error('revoke_failed','Digital access could not be revoked.');}
}

function store_returns_execute_refund( $request_id ) {
	global $wpdb; $r=store_returns_get_request($request_id);
	if($r && 'refunded'===$r->status){$t=store_returns_tables();$done=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['events']} WHERE event_key=%s",'refund.execute:'.$request_id.':v1'));if($done&&'complete'===$done->status)return (int)$done->object_id;}
	if(!$r || !in_array($r->status,array('approved','received'),true)) return new WP_Error('not_approved','The request is not ready for refund.');
	$key='refund.execute:'.$request_id.':v1'; $claim=store_returns_claim_event($key,$request_id,'refund');
	if(true!==$claim) return 'complete'===$claim->status ? (int)$claim->object_id : new WP_Error('refund_processing','Refund processing is already in progress.');
	$order=wc_get_order($r->order_id);$item=$order?$order->get_item($r->item_id):false;$eligible=store_returns_item_eligibility($order,$item,$r->quantity);
	if(is_wp_error($eligible)){store_returns_exception($key.'.eligibility',$r->order_id,$r->item_id,'refund_not_eligible',array('code'=>$eligible->get_error_code()),$r->id);return $eligible;}
	$unit=(float)$item->get_total()/(float)$item->get_quantity();$amount=(float)wc_format_decimal($unit*(float)$r->quantity,wc_get_price_decimals());$max=(float)$order->get_remaining_refund_amount();
	if($amount<=0 || $amount>$max){store_returns_exception($key.'.over',$r->order_id,$r->item_id,'over_refund_rejected',array('amount'=>$amount,'maximum'=>$max),$r->id);return new WP_Error('invalid_refund_amount','The refund exceeds the remaining order amount.');}
	$refund=wc_create_refund(array('amount'=>$amount,'reason'=>'Approved customer return request #'.$r->id,'order_id'=>$r->order_id,'line_items'=>array($r->item_id=>array('qty'=>(float)$r->quantity,'refund_total'=>$amount,'refund_tax'=>array())),'refund_payment'=>false,'restock_items'=>'physical_return'===$r->request_type));
	if(is_wp_error($refund)){store_returns_exception($key.'.failed',$r->order_id,$r->item_id,'refund_failed',array('code'=>$refund->get_error_code()),$r->id);return $refund;}
	$t=store_returns_tables();$now=current_time('mysql',true);$wpdb->update($t['events'],array('status'=>'complete','object_id'=>$refund->get_id(),'updated_at'=>$now),array('event_key'=>$key));$wpdb->update($t['requests'],array('status'=>'refunded','refund_id'=>$refund->get_id(),'updated_at'=>$now),array('id'=>$r->id));
	if('digital_refund'===$r->request_type && 'revoke'===get_option('store_returns_digital_refund_access','retain')) store_returns_revoke_downloads(store_returns_get_request($r->id));
	store_returns_send_email('refunded',store_returns_get_request($r->id)); return $refund->get_id();
}

function store_returns_send_email( $kind, $request ) {
	if ( function_exists( 'store_notifications_send' ) ) { do_action( 'store_return_status_notification', $kind, $request ); return true; }
	$order=$request?wc_get_order($request->order_id):false;if(!$order)return false;
	$labels=array('requested'=>'received','approved'=>'approved','rejected'=>'rejected','received'=>'marked as received','refunded'=>'refunded');$label=$labels[$kind]??$kind;
	$subject=sprintf('[%s] Return request update',wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES));
	$body=sprintf("Your return/refund request for order #%s has been %s.\n\nRequest reference: %d",$order->get_order_number(),$label,$request->id);
	do_action('store_returns_email_generated',$kind,$request,$subject,$body);return wp_mail($order->get_billing_email(),$subject,$body);
}

function store_returns_handle_customer_post() {
	if(empty($_POST['store_return_action']))return;
	$order_id=absint($_POST['order_id']??0); if(!is_user_logged_in()||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'store_return_'.$order_id)){wc_add_notice('Security check failed.','error');return;}
	$result=store_returns_create_request($order_id,absint($_POST['item_id']??0),wc_stock_amount(wp_unslash($_POST['quantity']??0)),sanitize_text_field(wp_unslash($_POST['reason']??'')),sanitize_textarea_field(wp_unslash($_POST['customer_note']??'')),get_current_user_id());
	wc_add_notice(is_wp_error($result)?$result->get_error_message():'Your return/refund request was submitted.',is_wp_error($result)?'error':'success');wp_safe_redirect(wc_get_endpoint_url('view-order',$order_id,wc_get_page_permalink('myaccount')));exit;
}
add_action('template_redirect','store_returns_handle_customer_post');

function store_returns_render_customer( $order_id ) {
	global $wpdb;$order=wc_get_order($order_id);if(!$order||!is_user_logged_in()||(int)$order->get_customer_id()!==get_current_user_id())return;
	$t=store_returns_tables();$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE order_id=%d AND customer_id=%d ORDER BY id DESC",$order_id,get_current_user_id()));
	echo '<section class="woocommerce-order-details store-return-requests"><h2>Returns and refunds</h2>';
	if($rows){echo '<ul>';foreach($rows as $r){$item=$order->get_item($r->item_id);echo '<li>'.esc_html($item?$item->get_name():'Order item').' — '.esc_html(ucwords(str_replace('_',' ',$r->status))).' ('.esc_html(wc_format_localized_decimal($r->quantity)).')</li>';}echo '</ul>';}
	foreach($order->get_items() as $item_id=>$item){$e=store_returns_item_eligibility($order,$item,1);if(is_wp_error($e))continue;echo '<form method="post" class="store-return-form"><h3>'.esc_html($item->get_name()).'</h3><input type="hidden" name="store_return_action" value="create"><input type="hidden" name="order_id" value="'.esc_attr($order_id).'"><input type="hidden" name="item_id" value="'.esc_attr($item_id).'">';wp_nonce_field('store_return_'.$order_id);echo '<p><label>Quantity <input required type="number" min="1" max="'.esc_attr($e['available']).'" name="quantity" value="1"></label></p><p><label>Reason <select required name="reason"><option value="">Choose a reason</option><option>Changed mind</option><option>Item issue</option><option>Other</option></select></label></p><p><label>Additional note <textarea name="customer_note" maxlength="1000"></textarea></label></p><button class="button" type="submit">Request return / refund</button></form>';}
	echo '</section>';
}
add_action('woocommerce_order_details_after_order_table','store_returns_render_customer',30);

function store_returns_admin_menu(){add_submenu_page('woocommerce','Return / Refund Requests','Return / Refund Requests','manage_woocommerce','store-return-requests','store_returns_admin_page');}
add_action('admin_menu','store_returns_admin_menu');
function store_returns_admin_page(){if(!current_user_can('manage_woocommerce'))wp_die('Not allowed.');global $wpdb;$t=store_returns_tables();$rows=$wpdb->get_results("SELECT * FROM {$t['requests']} ORDER BY id DESC LIMIT 200");echo '<div class="wrap"><h1>Return / Refund Requests</h1><table class="widefat striped"><thead><tr><th>ID</th><th>Order</th><th>Item</th><th>Type</th><th>Qty</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>';foreach($rows as $r){$o=wc_get_order($r->order_id);$i=$o?$o->get_item($r->item_id):false;echo '<tr><td>'.esc_html($r->id).'</td><td>#'.esc_html($r->order_id).'</td><td>'.esc_html($i?$i->get_name():'Unavailable').'</td><td>'.esc_html($r->request_type).'</td><td>'.esc_html($r->quantity).'</td><td>'.esc_html($r->reason).'</td><td>'.esc_html($r->status).'</td><td>';foreach(array('approved','rejected','awaiting_return','received','refund','closed') as $a){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="store_return_admin_action"><input type="hidden" name="request_id" value="'.esc_attr($r->id).'"><input type="hidden" name="return_action" value="'.esc_attr($a).'">';wp_nonce_field('store_return_admin_'.$r->id);echo '<button class="button button-small">'.esc_html(ucwords(str_replace('_',' ',$a))).'</button></form> ';}echo '</td></tr>';}echo '</tbody></table></div>';}
function store_returns_admin_action(){if(!current_user_can('manage_woocommerce'))wp_die('Not allowed.',403);$id=absint($_POST['request_id']??0);check_admin_referer('store_return_admin_'.$id);$action=sanitize_key($_POST['return_action']??'');$result='refund'===$action?store_returns_execute_refund($id):store_returns_set_status($id,$action);$url=admin_url('admin.php?page=store-return-requests');wp_safe_redirect(add_query_arg(is_wp_error($result)?array('return_error'=>$result->get_error_code()):array('return_updated'=>1),$url));exit;}
add_action('admin_post_store_return_admin_action','store_returns_admin_action');

function store_returns_settings( $settings, $section ) {
	if('account'!==$section)return $settings;
	$settings[]=array('title'=>'Returns baseline','type'=>'title','id'=>'store_returns_options');
	$settings[]=array('title'=>'Physical return window (days)','id'=>'store_returns_physical_window_days','type'=>'number','default'=>'30','custom_attributes'=>array('min'=>'1'));
	$settings[]=array('title'=>'Digital refund requests','id'=>'store_returns_digital_policy','type'=>'select','default'=>'review','options'=>array('review'=>'Allow for review','disabled'=>'Disabled'));
	$settings[]=array('title'=>'Digital access after refund','id'=>'store_returns_digital_refund_access','type'=>'select','default'=>'retain','options'=>array('retain'=>'Retain access','revoke'=>'Revoke access'));
	$settings[]=array('type'=>'sectionend','id'=>'store_returns_options');return $settings;
}
add_filter('woocommerce_get_settings_account','store_returns_settings',20,2);
