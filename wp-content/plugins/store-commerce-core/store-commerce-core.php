<?php
/**
 * Plugin Name: Store Commerce Core
 * Description: Environment, security, logging, migrations, provider contracts, and readiness checks for project-owned commerce workflows.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
define('STORE_COMMERCE_CORE_SCHEMA_VERSION','1.1.0');

function store_commerce_environment(){return wp_get_environment_type();}
function store_commerce_is_production(){return 'production'===store_commerce_environment();}
function store_commerce_allows_mocks(){
	if(defined('STORE_ALLOW_MOCK_PROVIDERS'))return true===STORE_ALLOW_MOCK_PROVIDERS;
	return in_array(store_commerce_environment(),array('local','development'),true);
}
function store_commerce_config($name,$default=''){
	$constant='STORE_'.strtoupper(preg_replace('/[^A-Z0-9_]/i','_',$name));
	if(defined($constant))return constant($constant);
	$value=getenv($constant);return false!==$value&&''!==$value?$value:$default;
}
function store_commerce_secret($name,$local_option=''){
	$value=store_commerce_config($name,'');
	if(''!==$value)return (string)$value;
	return !store_commerce_is_production()&&$local_option?(string)get_option($local_option,''):'';
}
function store_commerce_redact($value){
	if(is_array($value)){foreach($value as $k=>$v){$value[$k]=preg_match('/secret|token|password|authorization|card|payload|download_url/i',(string)$k)?'[redacted]':store_commerce_redact($v);}return $value;}
	if(is_string($value)){return preg_replace(array('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i','/(?:sk|pk)_(?:live|test)_[A-Za-z0-9]+/i'),'[redacted]',$value);}
	return $value;
}
function store_commerce_log($event_type,$order_id,$result,$context=array()){
	if(!function_exists('wc_get_logger'))return;
	$safe=array_intersect_key(store_commerce_redact($context),array_flip(array('operation_id','reference','attempt','status')));
	wc_get_logger()->info(wp_json_encode(array('event_type'=>sanitize_key($event_type),'order_id'=>absint($order_id),'result'=>sanitize_key($result),'timestamp'=>gmdate('c'),'context'=>$safe)),array('source'=>'store-commerce-core'));
}
function store_commerce_rate_limit(WP_REST_Request $request,$scope,$limit=60,$window=60){
	$ip=sanitize_text_field($_SERVER['REMOTE_ADDR']??'unknown');$key='store_rate_'.md5($scope.'|'.$ip);$count=(int)get_transient($key);if($count>=$limit)return false;set_transient($key,$count+1,$window);return true;
}

interface Store_Payment_Provider_Interface{
	public function create(WC_Order $order,array $context=array());
	public function confirm(WC_Order $order,array $context=array());
	public function query(WC_Order $order);
	public function reconcile(WC_Order $order);
	public function refund(WC_Order $order,$amount,$idempotency_key);
}
interface Store_Supplier_Provider_Interface{
	public function availability(array $items);
	public function submit(WC_Order $order,array $items,$idempotency_key);
	public function query($provider_order_reference);
	public function cancel($provider_order_reference);
	public function tracking($provider_order_reference);
}
interface Store_Email_Transport_Interface{public function send($recipient,$subject,$body,array $headers=array());}
final class Store_WordPress_Email_Transport implements Store_Email_Transport_Interface{public function send($recipient,$subject,$body,array $headers=array()){return wp_mail($recipient,$subject,$body,$headers);}}

function store_commerce_migrate(){
	if(STORE_COMMERCE_CORE_SCHEMA_VERSION===get_option('store_commerce_core_schema_version'))return;
	global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$charset=$wpdb->get_charset_collate();$table=$wpdb->prefix.'store_commerce_events';
	dbDelta("CREATE TABLE $table (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		event_key varchar(191) NOT NULL,
		event_type varchar(80) NOT NULL,
		order_id bigint unsigned NOT NULL,
		result varchar(30) NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY order_id (order_id),
		KEY event_type (event_type),
		KEY result (result)
	) $charset;");
	foreach(array('store_fulfillment_install','store_returns_install','store_notifications_install') as $installer)if(function_exists($installer))$installer();
	update_option('store_commerce_core_schema_version',STORE_COMMERCE_CORE_SCHEMA_VERSION,false);
}
register_activation_hook(__FILE__,'store_commerce_migrate');add_action('plugins_loaded','store_commerce_migrate',30);
function store_commerce_claim_event($scope,$external_id,$event_type,$order_id){global $wpdb;$table=$wpdb->prefix.'store_commerce_events';$key=sanitize_key($scope).':'.hash('sha256',(string)$external_id);$now=current_time('mysql',true);$inserted=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table(event_key,event_type,order_id,result,created_at,updated_at)VALUES(%s,%s,%d,'processing',%s,%s)",$key,sanitize_key($event_type),$order_id,$now,$now));return array('claimed'=>1===$inserted,'key'=>$key);}
function store_commerce_finish_event($key,$result){global $wpdb;return $wpdb->update($wpdb->prefix.'store_commerce_events',array('result'=>sanitize_key($result),'updated_at'=>current_time('mysql',true)),array('event_key'=>$key));}

add_action('send_headers',function(){if(function_exists('is_cart')&&(is_cart()||is_checkout()||is_account_page())){nocache_headers();header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0',true);}},20);
add_action('template_redirect',function(){if(is_admin())return;$uri=$_SERVER['REQUEST_URI']??'';$needle='/index.php/';if(false!==strpos($uri,$needle)){wp_safe_redirect(str_replace($needle,'/',$uri),301);exit;}},1);

function store_commerce_health(){
	global $wpdb;$tables=array_merge(array($wpdb->prefix.'store_commerce_events'),function_exists('store_fulfillment_tables')?array_values(store_fulfillment_tables()):array(),function_exists('store_returns_tables')?array_values(store_returns_tables()):array(),function_exists('store_notifications_table')?array(store_notifications_table()):array());$missing=array();foreach($tables as $table)if($table!==$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)))$missing[]=$table;
	$pages=array();foreach(array('shop','cart','checkout','myaccount') as $key){$id='shop'===$key?wc_get_page_id('shop'):wc_get_page_id($key);$pages[$key]=$id>0&&'publish'===get_post_status($id);}
	$pending_migrations=STORE_COMMERCE_CORE_SCHEMA_VERSION!==get_option('store_commerce_core_schema_version');
	$as_failed=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status=%s AND group_id IN (SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug=%s)",'failed','store-fulfillment-baseline'));
	$debug_display=defined('WP_DEBUG_DISPLAY')?(bool)WP_DEBUG_DISPLAY:(bool)ini_get('display_errors');
	return array('wordpress'=>did_action('init')||function_exists('wp'),'woocommerce'=>class_exists('WooCommerce'),'blocksy'=>'blocksy'===wp_get_theme()->get_stylesheet(),'hpos'=>class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')&&Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),'pages'=>$pages,'action_scheduler_failed'=>(int)$as_failed,'missing_tables'=>$missing,'pending_migrations'=>$pending_migrations,'mocks_allowed'=>store_commerce_allows_mocks(),'mock_policy_ok'=>!store_commerce_is_production()||!store_commerce_allows_mocks(),'debug_display'=>$debug_display,'permalink_structure'=>get_option('permalink_structure'),'environment'=>store_commerce_environment());
}
