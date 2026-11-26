<?php
/** Disposable synthetic WooCommerce aggregate; never loaded by a public request. */
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';
WC_Install::create_tables();
WC_Post_Types::register_taxonomies();WC_Post_Types::register_post_types();
WC()->init();WC_Shortcodes::init();
require_once WC_ABSPATH.'includes/wc-template-functions.php';
gml_test_product_init();
wp_set_current_user(1);
add_filter('pre_wp_mail', static function(){throw new RuntimeException('Mail forbidden');});
update_option('home','http://gml-inventory.test');update_option('siteurl','http://gml-inventory.test');
update_option('blog_public',1);update_option('permalink_structure','/%postname%/');$wp_rewrite->init();
update_option('gml_source_lang','en');update_option('gml_multilingual_enabled',true);
update_option('gml_languages',array_map(static function($code){return ['code'=>$code,'enabled'=>true,'paused'=>false];},['de','es','fr','ru']));
update_option('gml_ai_translation_enabled',true);update_option('gml_translation_paused',false);
GML_Gemini_API::save_api_key('synthetic-offline-only','gemini');
gml_db_assert(GML_Installer::activate()===true,'isolated additive schema installed');
$tables=[GML_Resource_Manifest_Store::readiness_table(),GML_Resource_Manifest_Store::relation_table(),GML_Resource_Manifest_Store::manifest_table(),$wpdb->prefix.'gml_index',$wpdb->prefix.'gml_queue'];
foreach($tables as $table)$wpdb->query("DELETE FROM $table");
update_option(GML_Resource_Backfill::OPTION,['status'=>'complete','phase'=>'complete','cursor'=>0,'updated_at'=>time()]);
$product=new WC_Product_Simple();$product->set_name('Synthetic power module');$product->set_status('publish');$product->set_regular_price('123.45');$product->set_short_description('<p>Old synthetic summary.</p>');$product_id=$product->save();
$aggregate_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Synthetic aggregate','post_name'=>'synthetic-aggregate','post_content'=>'[product_page id="'.$product_id.'"]']);
$other=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Unrelated synthetic resource','post_name'=>'unrelated-inventory','post_content'=>'<p>Unrelated text.</p>']);
function gml_inventory_html($id){$p=get_post($id);return '<!doctype html><html><head><title>'.esc_html($p->post_title).'</title></head><body>'.do_shortcode($p->post_content).'</body></html>';}
$GLOBALS['inventory_render_failure']=false;$GLOBALS['inventory_render_callback']=null;$GLOBALS['inventory_renders']=0;$GLOBALS['inventory_forbidden_http']=0;
add_filter('pre_http_request',static function($pre,$args,$url) use($aggregate_id,$other){
    if(parse_url($url,PHP_URL_HOST)!=='gml-inventory.test'||strpos($url,'gml_crawl=1')===false){$GLOBALS['inventory_forbidden_http']++;return new WP_Error('inventory_forbidden_http');}
    $GLOBALS['inventory_renders']++;
    if($GLOBALS['inventory_render_callback']){$cb=$GLOBALS['inventory_render_callback'];$GLOBALS['inventory_render_callback']=null;$cb();}
    if($GLOBALS['inventory_render_failure'])return new WP_Error('inventory_render_failed','Synthetic render failure');
    $id=strpos($url,'synthetic-aggregate')!==false?$aggregate_id:$other;
    return ['response'=>['code'=>200],'headers'=>['content-type'=>'text/html'],'body'=>gml_inventory_html($id)];
},999,3);
$discovery=new GML_Resource_Manifest_Discovery();$resource=GML_Resource_Identity::for_post($aggregate_id);$other_resource=GML_Resource_Identity::for_post($other);
$parsed=(new GML_HTML_Parser())->parse(gml_inventory_html($aggregate_id));
gml_db_assert(in_array('Old synthetic summary.',array_column($parsed['nodes'],'text'),true),'native WooCommerce product_page renders short description');
$index=$wpdb->prefix.'gml_index';$queue=$wpdb->prefix.'gml_queue';
foreach([$aggregate_id,$other] as $fixture_post_id){
    foreach((new GML_HTML_Parser())->parse(gml_inventory_html($fixture_post_id))['nodes'] as $n)foreach(['de','es','fr','ru'] as $lang)$wpdb->replace($index,['source_hash'=>$n['hash'],'source_text'=>$n['text'],'source_lang'=>'en','target_lang'=>$lang,'translated_text'=>strtoupper($lang).' '.$n['text'],'context_type'=>$n['context_type'],'status'=>$lang==='fr'?'manual':'auto']);
    gml_db_assert($discovery->discover(GML_Resource_Identity::for_post($fixture_post_id))===true,'baseline manifest discovered with existing TM');
}
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
wp_clear_scheduled_hook(GML_Resource_Manifest_Manager::DIRTY_HOOK);
$before=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
$start=microtime(true);
$product=wc_get_product($product_id);$product->set_short_description('<p>New neutral synthetic summary.</p>');$product->save();
$after=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
$diagnostics=GML_Resource_Manifest_Manager::diagnostics($resource->get_key());
gml_db_assert(GML_Resource_Manifest_Store::snapshot_version($before)===GML_Resource_Manifest_Store::snapshot_version($after),'product save does not change aggregate manifest version');
gml_db_assert(!$diagnostics['resource_pending'],'product save does not add aggregate to dirty hints');
$new_html=gml_inventory_html($aggregate_id);
echo 'RENDER_CHECK '.wp_json_encode(['db_excerpt'=>get_post_field('post_excerpt',$product_id),'old_present'=>strpos($new_html,'Old synthetic summary.')!==false,'new_present'=>strpos($new_html,'New neutral synthetic summary.')!==false,'global_post'=>$GLOBALS['post']->ID??null,'global_product'=>isset($GLOBALS['product'])&&is_object($GLOBALS['product'])?$GLOBALS['product']->get_id():null])."\n";
file_put_contents('/tmp/inventory-render.html',$new_html);
gml_db_assert(strpos($new_html,'New neutral synthetic summary.')!==false && strpos($new_html,'Old synthetic summary.')===false,'authoritative fixture renders current product excerpt');
$relations=GML_Resource_Manifest_Store::relation_table();$ready=GML_Resource_Manifest_Store::readiness_table();
function gml_inventory_members($id){global $wpdb;return $wpdb->get_col($wpdb->prepare('SELECT source_hash FROM '.GML_Resource_Manifest_Store::relation_table().' WHERE resource_id=%d ORDER BY source_hash',$id));}
gml_db_assert(in_array(md5('Old synthetic summary.'),gml_inventory_members($before->id),true),'aggregate manifest retains removed summary before an explicit scan');
echo 'OBSERVATION '.wp_json_encode(['event'=>'product_excerpt_saved','elapsed_ms'=>round((microtime(true)-$start)*1000),'aggregate_key'=>$resource->get_key(),'aggregate_id'=>$before->id,'diagnostics'=>$diagnostics])."\n";
if(($argv[1]??'')==='baseline'){echo "OK inventory baseline\n";exit;}
// Candidate acceptance starts after synthetic setup and notification side effects.
foreach(['de','es','fr','ru'] as $lang)$wpdb->replace($index,['source_hash'=>md5('New neutral synthetic summary.'),'source_text'=>'New neutral synthetic summary.','source_lang'=>'en','target_lang'=>$lang,'translated_text'=>strtoupper($lang).' confirmed neutral summary.','context_type'=>'text','status'=>$lang==='fr'?'manual':'auto']);
$product=wc_get_product($product_id);$product->set_short_description('<p>New neutral synthetic summary.</p><p>New untranslated safety detail.</p>');$product->save();
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);wp_clear_scheduled_hook(GML_Resource_Manifest_Manager::DIRTY_HOOK);
function gml_inventory_job_state(){global $wpdb;return [
    'queue'=>$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'gml_queue ORDER BY id',ARRAY_A),
    'cron'=>get_option('cron'),'dirty'=>get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION),
    'backfill'=>get_option(GML_Resource_Backfill::OPTION),
    'controls'=>array_map('get_option',['gml_translation_paused','gml_ai_translation_enabled','gml_translation_circuit_breaker','gml_translation_backoff_until','gml_translation_worker_lock']),
    'retries'=>$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '%gml_manifest_retry_%' ORDER BY option_name",ARRAY_A),
];}
function gml_inventory_tm(){global $wpdb;return $wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'gml_index ORDER BY id',ARRAY_A);}
function gml_inventory_refresh($discovery,$resource){$m=GML_Resource_Manifest_Store::get_by_key($resource->get_key());return $discovery->refresh_inventory($resource,GML_Resource_Manifest_Store::snapshot_version($m),$resource->get_source_revision());}
$other_m=GML_Resource_Manifest_Store::get_by_key($other_resource->get_key());
$other_before=[(array)$other_m,$wpdb->get_results($wpdb->prepare("SELECT * FROM $ready WHERE resource_id=%d ORDER BY target_lang",$other_m->id),ARRAY_A),gml_inventory_members($other_m->id)];
$wpdb->insert($queue,['source_hash'=>md5('Unrelated pending sentinel'),'source_text'=>'Unrelated pending sentinel','source_lang'=>'en','target_lang'=>'de','context_type'=>'text','status'=>'pending','priority'=>7,'attempts'=>2,'created_at'=>current_time('mysql')]);
$tm_before=gml_inventory_tm();$jobs_before=gml_inventory_job_state();$cache_before=GML_Page_Cache::generation();
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(GML_Page_Cache::CLUSTER_PREFIX).'%'));
$started=microtime(true);$result=gml_inventory_refresh($discovery,$resource);
gml_db_assert($result===true,'candidate single-resource inventory refresh succeeds');
$members=gml_inventory_members($before->id);
gml_db_assert(!in_array(md5('Old synthetic summary.'),$members,true) && in_array(md5('New neutral synthetic summary.'),$members,true),'old summary exits and current summary enters exact manifest');
gml_db_assert(in_array(md5('New untranslated safety detail.'),$members,true),'genuinely missing current text enters inventory');
gml_db_assert(gml_inventory_tm()===$tm_before,'all manual and auto translation rows remain byte-for-byte unchanged');
gml_db_assert(gml_inventory_job_state()===$jobs_before,'no queue priority retries scheduling controls or backfill changes');
foreach(['de','es','fr','ru'] as $lang){$state=GML_Public_Eligibility::get_status($resource,$lang);$policy=$state['page_readiness'];gml_db_assert((int)$policy['unresolved_count']===1 && !$state['public_eligible'],'existing translations reused and true missing text stays behind SEO gate: '.$lang);}
gml_db_assert($other_before===[(array)GML_Resource_Manifest_Store::get_by_key($other_resource->get_key()),$wpdb->get_results($wpdb->prepare("SELECT * FROM $ready WHERE resource_id=%d ORDER BY target_lang",$other_m->id),ARRAY_A),gml_inventory_members($other_m->id)],'unrelated manifest membership and all readiness rows unchanged');
$plans=GML_Page_Cache::pending_clusters(100);
gml_db_assert(count($plans)===1 && $plans[0]['resource_id']===(int)$before->id && count($plans[0]['urls'])===5,'outbox contains only this resource source plus four local target URLs');
$cache_after=GML_Page_Cache::generation();gml_db_assert($cache_after>$cache_before,'global internal page cache generation really advances');
echo 'CACHE_EFFECT '.wp_json_encode(['before'=>$cache_before,'after'=>$cache_after,'outbox'=>$plans,'elapsed_ms'=>round((microtime(true)-$started)*1000)])."\n";
$repeat_m=GML_Resource_Manifest_Store::get_by_key($resource->get_key());$repeat_generation=GML_Page_Cache::generation();
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'repeated explicit refresh succeeds');
gml_db_assert(GML_Resource_Manifest_Store::snapshot_version($repeat_m)===GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($resource->get_key())) && GML_Page_Cache::generation()===$repeat_generation,'identical refresh preserves manifest version and cache generation');
$old_expected=GML_Resource_Manifest_Store::snapshot_version($before);$render_count=$GLOBALS['inventory_renders'];
$error=$discovery->refresh_inventory($resource,$old_expected,$resource->get_source_revision());
gml_db_assert(is_wp_error($error)&&$error->get_error_code()==='gml_resource_changed' && $GLOBALS['inventory_renders']===$render_count,'stale starting snapshot rejected before rendering');
$before_race=gml_inventory_members($before->id);
$GLOBALS['inventory_render_callback']=static function()use($resource){GML_Resource_Manifest_Store::mark_stale($resource);};
$error=gml_inventory_refresh($discovery,$resource);
gml_db_assert(is_wp_error($error)&&$error->get_error_code()==='gml_resource_changed' && gml_inventory_members($before->id)===$before_race,'concurrent manifest invalidation cannot commit old members');
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'manual repeat recovers from conflict without starting a retry');
$GLOBALS['inventory_render_callback']=static function(){GML_Page_Cache::force_invalidate();};
$error=gml_inventory_refresh($discovery,$resource);
gml_db_assert(is_wp_error($error)&&$error->get_error_code()==='gml_resource_changed','cache epoch change during render rejects potentially changed embedded content');
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'manual repeat recovers after embedded-change fence');
class GML_Inventory_Conflict_Parser {
    private $resource;
    public function __construct($resource){$this->resource=$resource;}
    public function parse($html){GML_Resource_Manifest_Store::mark_stale($this->resource);return (new GML_HTML_Parser())->parse($html);}
}
$conflicting=new GML_Resource_Manifest_Discovery(null,new GML_Inventory_Conflict_Parser($resource));
$error=gml_inventory_refresh($conflicting,$resource);
gml_db_assert(is_wp_error($error)&&$error->get_error_code()==='gml_resource_changed','late parser invalidation cannot commit a superseded inventory');
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'explicit retry recovers a late parser conflict');
$GLOBALS['inventory_render_failure']=true;$jobs=gml_inventory_job_state();$members_before=gml_inventory_members($before->id);
$error=gml_inventory_refresh($discovery,$resource);$GLOBALS['inventory_render_failure']=false;
gml_db_assert(is_wp_error($error)&&$error->get_error_code()==='inventory_render_failed','render failure is surfaced');
gml_db_assert(gml_inventory_members($before->id)===$members_before && !GML_Public_Eligibility::is_eligible($resource,'fr'),'render failure retains historical members but fails closed');
gml_db_assert(gml_inventory_job_state()===$jobs,'render failure starts no new task or retry');
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'manual retry repairs render failure');
// Fault injection uses the existing wpdb query filter, not a replacement database.
$product=wc_get_product($product_id);$product->set_short_description('<p>New neutral synthetic summary.</p><p>New untranslated safety detail.</p><p>A third synthetic detail.</p>');$product->save();
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);wp_clear_scheduled_hook(GML_Resource_Manifest_Manager::DIRTY_HOOK);
$target_ready=$ready;$fault_count=0;
$fault=static function($sql)use($target_ready,&$fault_count){if($fault_count===0 && strpos($sql,'REPLACE INTO `'.$target_ready.'`')===0 && strpos($sql,"'fr'")!==false){$fault_count++;return 'GML_SYNTHETIC_READINESS_WRITE_FAILURE';}return $sql;};
$jobs=gml_inventory_job_state();add_filter('query',$fault,999);$error=gml_inventory_refresh($discovery,$resource);remove_filter('query',$fault,999);
gml_db_assert($fault_count===1 && is_wp_error($error)&&$error->get_error_code()==='gml_readiness_write','third language write failure is explicitly returned after earlier language commits');
$m=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
gml_db_assert($m->discovery_state==='stale' && !GML_Public_Eligibility::is_eligible($resource,'de') && !GML_Public_Eligibility::is_eligible($resource,'ru'),'partial readiness failure leaves whole resource fail closed');
gml_db_assert(gml_inventory_job_state()===$jobs && gml_inventory_tm()===$tm_before,'partial readiness failure preserves translations and creates no retries');
echo 'PARTIAL_FAILURE '.wp_json_encode(['manifest'=>(array)$m,'rows'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM $ready WHERE resource_id=%d ORDER BY target_lang",$m->id),ARRAY_A)])."\n";
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'explicit repeat rebuilds all language rows after partial failure');
// Relation INSERT fails after delete inside transaction: old membership must survive rollback.
$product=wc_get_product($product_id);$product->set_short_description('<p>A replacement requiring another manifest.</p>');$product->save();
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);wp_clear_scheduled_hook(GML_Resource_Manifest_Manager::DIRTY_HOOK);
$members_before=gml_inventory_members($before->id);$jobs=gml_inventory_job_state();$fault_count=0;
$fault=static function($sql)use($relations,&$fault_count){if($fault_count===0 && strpos($sql,'INSERT INTO '.$relations.' ')===0){$fault_count++;return 'GML_SYNTHETIC_RELATION_WRITE_FAILURE';}return $sql;};
add_filter('query',$fault,999);$error=gml_inventory_refresh($discovery,$resource);remove_filter('query',$fault,999);
gml_db_assert($fault_count===1 && is_wp_error($error) && $error->get_error_code()==='gml_manifest_write' && gml_inventory_members($before->id)===$members_before,'failed relation replacement rolls back old membership');
gml_db_assert(gml_inventory_job_state()===$jobs && gml_inventory_tm()===$tm_before,'manifest write failure starts no retry or AI work');
gml_db_assert(gml_inventory_refresh($discovery,$resource)===true,'manual repeat recovers failed relation write');
// AJAX endpoint: same native nonce/capability checks as all page workflow actions.
require_once GML_PLUGIN_DIR.'admin/class-page-workflow-admin.php';
if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
add_filter('wp_die_ajax_handler',static function(){return static function($message=''){throw new RuntimeException('inventory_ajax_die:'.(string)$message);};});
$admin=new GML_Page_Workflow_Admin();
$method=new ReflectionMethod($admin,'inventory_token');$method->setAccessible(true);
function gml_inventory_ajax($admin,$input){$_POST=$_REQUEST=$input;ob_start();try{$admin->action();}catch(RuntimeException $e){$message=$e->getMessage();}$body=ob_get_clean();return [$body,$message??''];}
$m=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
$input=['operation'=>'inventory','resource'=>$resource->get_key(),'snapshot'=>$method->invoke($admin,$resource,$m),'cache_effects'=>'1','nonce'=>wp_create_nonce('gml_page_workflow')];
$jobs=gml_inventory_job_state();$old_renders=$GLOBALS['inventory_renders'];
[$json,$die]=gml_inventory_ajax($admin,array_merge($input,['nonce'=>'invalid']));gml_db_assert($die==='inventory_ajax_die:-1' && $GLOBALS['inventory_renders']===$old_renders,'invalid nonce prevents rendering or writes');
wp_set_current_user(0);[$json]=gml_inventory_ajax($admin,array_merge($input,['nonce'=>wp_create_nonce('gml_page_workflow')]));
gml_db_assert(json_decode($json,true)['success']===false && strpos($json,'Permission denied')!==false,'valid nonce never bypasses anonymous capability denial');
$subscriber=wp_insert_user(['user_login'=>'inventory-reader-'.wp_generate_password(6,false),'role'=>'subscriber','user_pass'=>wp_generate_password(20)]);wp_set_current_user($subscriber);
[$json]=gml_inventory_ajax($admin,array_merge($input,['nonce'=>wp_create_nonce('gml_page_workflow')]));gml_db_assert(strpos($json,'Permission denied')!==false,'ordinary logged-in account cannot refresh inventory');
wp_set_current_user(1);[$json]=gml_inventory_ajax($admin,array_merge($input,['cache_effects'=>'0']));gml_db_assert(strpos($json,'Acknowledge')!==false && $GLOBALS['inventory_renders']===$old_renders,'cache acknowledgment required before any render');
[$json]=gml_inventory_ajax($admin,array_merge($input,['snapshot'=>'tampered']));gml_db_assert(strpos($json,'Resource changed')!==false && $GLOBALS['inventory_renders']===$old_renders,'tampered snapshot rejected before render');
[$json]=gml_inventory_ajax($admin,$input);gml_db_assert(json_decode($json,true)['success']===true,'authorized nonce-protected administrator endpoint succeeds');
gml_db_assert(gml_inventory_job_state()===$jobs && gml_inventory_tm()===$tm_before,'AJAX entry preserves all queues controls schedules and TM');
gml_db_assert($GLOBALS['inventory_forbidden_http']===0,'no model provider or other outbound request attempted');
update_option('gml_inventory_synthetic_fixture',['aggregate_id'=>$aggregate_id,'product_id'=>$product_id,'key'=>$resource->get_key(),'other_key'=>$other_resource->get_key()]);
echo "OK inventory refresh candidate\n";
