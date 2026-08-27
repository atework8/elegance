<?php
/**
 * Plugin Name: Store Transactional Notifications
 * Description: Normalized, deduplicated transactional notifications for store-owned commerce workflows.
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;

function store_notifications_table() { global $wpdb; return $wpdb->prefix . 'store_notification_log'; }
function store_notifications_install() {
	global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $table=store_notifications_table(); $charset=$wpdb->get_charset_collate();
	dbDelta("CREATE TABLE $table (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		event_key varchar(191) NOT NULL,
		event_type varchar(80) NOT NULL,
		order_id bigint unsigned NOT NULL,
		status varchar(20) NOT NULL,
		recipient_type varchar(20) NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY order_id (order_id),
		KEY event_type (event_type),
		KEY status (status)
	) $charset;");
	if(!function_exists('store_commerce_is_production')||!store_commerce_is_production()){
		update_option('woocommerce_email_from_name','Elegance');
		update_option('woocommerce_email_from_address','elegance@ecommerce.local');
	}
	update_option('woocommerce_email_base_color','#1f7a4d');
	update_option('woocommerce_email_background_color','#f5f5f5');
	update_option('woocommerce_email_body_background_color','#ffffff');
	update_option('woocommerce_email_text_color','#111111');
	update_option('store_notifications_schema_version','1.0.0',false);
}
register_activation_hook(__FILE__,'store_notifications_install');
add_filter('wp_mail_from_name',function($current){$configured=function_exists('store_commerce_config')?store_commerce_config('EMAIL_FROM_NAME',''):'';if($configured)return sanitize_text_field($configured);return function_exists('store_commerce_is_production')&&store_commerce_is_production()?$current:'Elegance';});
add_filter('wp_mail_from',function($current){$configured=function_exists('store_commerce_config')?store_commerce_config('EMAIL_FROM_ADDRESS',''):'';if($configured&&is_email($configured))return $configured;return function_exists('store_commerce_is_production')&&store_commerce_is_production()?$current:'elegance@ecommerce.local';});

function store_notifications_supported_events(){return array('order.processing','order.completed','order.failed','order.refunded','fulfillment.shipped','fulfillment.delivered','fulfillment.exception','digital.ready','digital.failed','return.requested','return.approved','return.rejected','return.received','refund.completed');}
function store_notifications_claim($event_key,$event_type,$order_id,$recipient_type){
	global $wpdb;$table=store_notifications_table();$now=current_time('mysql',true);
	return 1===$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table(event_key,event_type,order_id,status,recipient_type,created_at,updated_at) VALUES(%s,%s,%d,'processing',%s,%s,%s)",$event_key,$event_type,$order_id,$recipient_type,$now,$now));
}
function store_notifications_finish($event_key,$status){global $wpdb;$wpdb->update(store_notifications_table(),array('status'=>$status,'updated_at'=>current_time('mysql',true)),array('event_key'=>$event_key),array('%s','%s'),array('%s'));}
function store_notifications_safe_url($url){$url=esc_url_raw($url,array('http','https'));return $url&&wp_http_validate_url($url)?$url:'';}
function store_notifications_send($event_type,$event_key,$order_id,$context=array()){
	if(!in_array($event_type,store_notifications_supported_events(),true))return new WP_Error('unknown_notification','Unknown notification event.');
	$order=wc_get_order($order_id);if(!$order)return new WP_Error('missing_order','Order not found.');
	$admin=!empty($context['admin']);$recipient=$admin?get_option('admin_email'):$order->get_billing_email();
	if(!$recipient)return new WP_Error('missing_recipient','Notification recipient is unavailable.');
	if(!store_notifications_claim(sanitize_key($event_key),$event_type,$order_id,$admin?'admin':'customer'))return array('duplicate'=>true);
	$item_name=sanitize_text_field($context['item_name']??'your item');$number=$order->get_order_number();$account=wc_get_endpoint_url('orders','',wc_get_page_permalink('myaccount'));
	$messages=array(
		'fulfillment.shipped'=>array("Part of order #$number has shipped","Good news — $item_name has shipped."),
		'fulfillment.delivered'=>array("Part of order #$number was delivered","$item_name has been marked as delivered."),
		'fulfillment.exception'=>array("Fulfillment review needed for order #$number","A fulfillment issue requires staff review for order #$number."),
		'digital.ready'=>array("Your download for order #$number is ready","$item_name is ready. Use My Account to access your protected download."),
		'digital.failed'=>array("Digital delivery review needed for order #$number","Payment was received, but digital delivery needs staff review for order #$number."),
		'return.requested'=>array("Return request received for order #$number","We received your return/refund request and will review it."),
		'return.approved'=>array("Return request approved for order #$number","Your return/refund request has been approved."),
		'return.rejected'=>array("Return request update for order #$number","Your return/refund request was not approved. Please contact us if you need help."),
		'return.received'=>array("Returned item received for order #$number","We have received your returned item."),
		'refund.completed'=>array("Refund completed for order #$number","Your approved refund has been recorded for order #$number."),
	);
	if(str_starts_with($event_type,'order.')){$messages[$event_type]=array("Order #$number update","There is an update to your order. View the order details in My Account.");}
	list($subject,$intro)=$messages[$event_type];$lines=array('<p>'.esc_html(sprintf('Hello %s,',$order->get_billing_first_name()?:'there')).'</p>','<p>'.esc_html($intro).'</p>');
	if('fulfillment.shipped'===$event_type){$carrier=sanitize_text_field($context['carrier']??'');$tracking=sanitize_text_field($context['tracking_number']??'');$url=store_notifications_safe_url($context['tracking_url']??'');if($carrier)$lines[]='<p><strong>Carrier:</strong> '.esc_html($carrier).'</p>';if($tracking)$lines[]='<p><strong>Tracking number:</strong> '.esc_html($tracking).'</p>';if($url)$lines[]='<p><a href="'.esc_url($url).'">Track shipment</a></p>';}
	if(!$admin)$lines[]='<p><a href="'.esc_url($account).'">View in My Account</a></p>';
	$body=WC()->mailer()->wrap_message(esc_html($subject),implode("\n",$lines));$headers=array('Content-Type: text/html; charset=UTF-8');
	do_action('store_notification_generated',$event_type,$event_key,$order_id,$recipient,$subject,$body);
	$sent=wp_mail($recipient,$subject,$body,$headers);store_notifications_finish(sanitize_key($event_key),$sent?'sent':'failed');if(function_exists('store_commerce_log'))store_commerce_log($event_type,$order_id,$sent?'success':'failure');return $sent;
}

add_action('store_fulfillment_item_state_changed',function($order_id,$item_id,$type,$state){$order=wc_get_order($order_id);$item=$order?$order->get_item($item_id):false;if(!$item)return;$context=array('item_name'=>$item->get_name());if('digital'===$type&&'ready'===$state)store_notifications_send('digital.ready',"digital.ready.$order_id.$item_id",$order_id,$context);if('digital'===$type&&'failed'===$state){store_notifications_send('digital.failed',"digital.failed.customer.$order_id.$item_id",$order_id,$context);store_notifications_send('digital.failed',"digital.failed.admin.$order_id.$item_id",$order_id,array_merge($context,array('admin'=>true)));}if('physical'===$type&&in_array($state,array('failed','exception'),true)){store_notifications_send('fulfillment.exception',"fulfillment.exception.customer.$order_id.$item_id",$order_id,$context);store_notifications_send('fulfillment.exception',"fulfillment.exception.admin.$order_id.$item_id",$order_id,array_merge($context,array('admin'=>true)));}},10,4);
add_action('store_fulfillment_tracking_applied',function($order_id,$item_id,$status,$safe){$order=wc_get_order($order_id);$item=$order?$order->get_item($item_id):false;if(!$item)return;$context=array_merge(array('item_name'=>$item->get_name()),$safe);store_notifications_send('fulfillment.'.$status,"fulfillment.$status.$order_id.$item_id",$order_id,$context);},10,4);
add_action('store_return_status_notification',function($kind,$request){$map=array('requested'=>'return.requested','approved'=>'return.approved','rejected'=>'return.rejected','received'=>'return.received','refunded'=>'refund.completed');if(isset($map[$kind])){$event=$map[$kind];store_notifications_send($event,$event.'.customer.'.$request->id,$request->order_id,array());if('requested'===$kind)store_notifications_send($event,$event.'.admin.'.$request->id,$request->order_id,array('admin'=>true));}},10,2);
