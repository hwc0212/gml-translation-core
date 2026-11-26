<?php
/** Full native HTTP probe, restricted to the disposable internal NAS lab. */
require __DIR__.'/bootstrap.php';
$http_home=untrailingslashit(getenv('GML_INVENTORY_HTTP_HOME')?:'');
$http_host=wp_parse_url($http_home,PHP_URL_HOST);
gml_db_assert(getenv('GML_INVENTORY_HTTP_TEST')==='1' && $http_host && home_url()===$http_home,'explicit isolated HTTP target only');
wp_set_current_user(1);
$fixture=get_option('gml_inventory_synthetic_fixture');
gml_db_assert(is_array($fixture),'synthetic HTTP mapping exists');
remove_all_filters('pre_http_request');$blocked=0;
add_filter('pre_http_request',static function($pre,$args,$url)use(&$blocked,$http_host){if(parse_url($url,PHP_URL_HOST)!==$http_host){$blocked++;return new WP_Error('inventory_network_blocked');}return $pre;},999,3);
$mode=$argv[1]??'';
if($mode==='prepare'){
    update_option('active_plugins',['woocommerce/woocommerce.php','gml-translate/gml-translate.php']);
    // Normal native requests are warmed before the operation measurement.
    echo "OK HTTP configured\n";exit;
}
$resource=GML_Resource_Identity::resolve($fixture['key']);
function gml_inventory_http_option($key){global $wpdb;return maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));}
function gml_inventory_http_state(){global $wpdb;return ['queue'=>$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'gml_queue ORDER BY id',ARRAY_A),'tm'=>$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'gml_index ORDER BY id',ARRAY_A),'cron'=>gml_inventory_http_option('cron'),'dirty'=>gml_inventory_http_option(GML_Resource_Manifest_Manager::DIRTY_OPTION),'backfill'=>gml_inventory_http_option(GML_Resource_Backfill::OPTION),'paused'=>gml_inventory_http_option('gml_translation_paused')];}
if($mode==='source'){
    wp_clear_scheduled_hook('gml_process_queue');wp_clear_scheduled_hook('gml_resource_cache_consume');wp_clear_scheduled_hook('gml_resource_manifest_backfill');
    wp_clear_scheduled_hook('gml_resource_readiness_rebuild',['recovery']);
    $before=gml_inventory_http_state();
    $result=(new GML_Authoritative_Renderer())->render($resource,true);
    gml_db_assert(!is_wp_error($result)&&strpos($result,'A replacement requiring another manifest.')!==false,'real authoritative HTTP renders native aggregate with current excerpt');
    gml_db_assert(gml_inventory_http_state()===$before,'real source render preserves queue TM cron dirty and backfill state after warm bootstrap');
    echo "OK inventory source HTTP\n";exit;
}
if($mode==='unsigned'){
    wp_clear_scheduled_hook('gml_process_queue');
    $result=wp_remote_get(add_query_arg('gml_crawl','1',$resource->get_source_url()),['headers'=>['X-GML-Inventory-Only'=>'1','X-GML-Crawl'=>'invalid']]);
    $cron=gml_inventory_http_option('cron');$scheduled=false;foreach($cron as $events)if(is_array($events)&&isset($events['gml_process_queue']))$scheduled=true;
    gml_db_assert(!is_wp_error($result)&&$scheduled,'unsigned inventory flag cannot bypass normal GML bootstrap');
    echo "OK unsigned inventory flag\n";exit;
}
$admin=new GML_Page_Workflow_Admin();$method=new ReflectionMethod($admin,'inventory_token');$method->setAccessible(true);
$manifest=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
$expires=time()+3600;$session=WP_Session_Tokens::get_instance(1)->create($expires);
$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie(1,$expires,'logged_in',$session);
$cookies=[new WP_Http_Cookie(['name'=>LOGGED_IN_COOKIE,'value'=>$_COOKIE[LOGGED_IN_COOKIE]]),new WP_Http_Cookie(['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie(1,$expires,'auth',$session)])];
if($mode==='ajax-cold'){
    wp_clear_scheduled_hook('gml_process_queue');wp_clear_scheduled_hook('gml_resource_cache_consume');wp_clear_scheduled_hook('gml_resource_manifest_backfill');
    wp_clear_scheduled_hook('gml_resource_readiness_rebuild',['recovery']);
}
$before=gml_inventory_http_state();
$response=wp_remote_post(admin_url('admin-ajax.php'),['timeout'=>30,'cookies'=>$cookies,'body'=>['action'=>'gml_page_workflow','operation'=>'inventory','nonce'=>wp_create_nonce('gml_page_workflow'),'resource'=>$resource->get_key(),'snapshot'=>$method->invoke($admin,$resource,$manifest),'cache_effects'=>'1']]);
if(is_wp_error($response))throw new RuntimeException($response->get_error_message());
$json=json_decode(wp_remote_retrieve_body($response),true);
gml_db_assert(!empty($json['success']),'native authenticated HTTP AJAX refresh succeeds');
$after=gml_inventory_http_state();$changed=[];foreach($before as $key=>$value)if($value!==$after[$key])$changed[]=$key;
echo 'HTTP_EFFECTS '.wp_json_encode(['changed'=>$changed,'before_cron'=>$before['cron'],'after_cron'=>$after['cron'],'response_code'=>wp_remote_retrieve_response_code($response),'response'=>$json,'blocked_external_calls'=>$blocked])."\n";
gml_db_assert($before===$after,'native HTTP preserves pending queue TM cron dirty backfill and pause state');
echo "OK inventory AJAX HTTP probe\n";
