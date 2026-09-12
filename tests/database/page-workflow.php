<?php
require __DIR__.'/bootstrap.php';
gml_test_product_init();
wp_set_current_user(1);
gml_db_assert(GML_Installer::activate()===true,'page workflow additive schema installed');
update_option('gml_multilingual_enabled',true);
update_option('gml_ai_translation_enabled',true);
update_option('gml_source_lang','en');
update_option('blog_public','1');
update_option('gml_page_ready_percent',98);
update_option('gml_languages',[['code'=>'de','enabled'=>true,'paused'=>false]]);
update_option('gml_translation_paused',true);
update_option('gml_resource_manifest_global_generation',1);
GML_Gemini_API::save_api_key('page-workflow-mock-only','gemini');
foreach([GML_Queue_Processor::CIRCUIT_OPTION,GML_Queue_Processor::BACKOFF_OPTION,GML_Queue_Processor::LOCK_OPTION,GML_Manual_Translation::JOB] as $option) delete_option($option);
$q=$wpdb->prefix.'gml_queue'; $i=$wpdb->prefix.'gml_index';
$wpdb->query("DELETE FROM $q"); $wpdb->query("DELETE FROM $i");
$wpdb->query('DELETE FROM '.GML_Resource_Manifest_Store::relation_table());
$wpdb->query('DELETE FROM '.GML_Resource_Manifest_Store::readiness_table());
$wpdb->query('DELETE FROM '.GML_Resource_Manifest_Store::manifest_table());
$post=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Workflow fixture','post_content'=>'Current content']);
$resource=GML_Resource_Identity::for_post($post);
$nodes=[]; $ids=[]; $records=[];
for($n=0;$n<50;$n++) {
    $text=sprintf('Current engineering specification number %02d.',$n);
    $nodes[]=['text'=>$text,'context_type'=>'text'];
    $record=['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','context_type'=>'text'];
    $wpdb->insert($q,$record+['status'=>'pending','created_at'=>current_time('mysql'),'attempts'=>0]);
    $ids[]=(int)$wpdb->insert_id;
    if($n<49) $records[]=$record+['translated_text'=>'DE '.$text,'status'=>'auto'];
}
gml_db_assert(GML_Resource_Manifest_Store::save_complete($resource,$nodes)===true,'exact current resource manifest saved');
gml_db_assert(GML_Translation_Memory::insert_missing_batch($records)['inserted']===49,'49 current assets inserted');
$manifest=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
GML_Resource_Readiness::recalculate_resources([$manifest->id],['de']);
$status=GML_Public_Eligibility::get_status($resource,'de');
gml_db_assert($status['public_eligible'] && $status['page_readiness']['percent']==98,'98 percent resource is SEO eligible without global language totals');
$held=$wpdb->get_row("SELECT * FROM $i ORDER BY id LIMIT 1",ARRAY_A);
gml_db_assert(GML_Translation_Memory::hold_auto_by_id($held['id'],$held),'official quality hold applied to fixture');
GML_Resource_Readiness::recalculate_resources([$manifest->id],['de']);
gml_db_assert(!GML_Public_Eligibility::is_eligible($resource,'de'),'quality hold does not pass SEO policy');
$snapshot=GML_Manual_Translation::snapshot($ids[49]);
$token=GML_Manual_Translation::token($snapshot);
$request=GML_Manual_Translation::request($ids[49],$token);
gml_db_assert(!is_wp_error($request),'explicit item accepted while background paused');
class GML_Page_Test_Provider {
    public $calls=0;
    public function translate_batch($texts,$source,$target,$type) { $this->calls++; return array_map(static function($text){return 'DE '.$text;},$texts); }
    public function get_last_error(){return [];}
}
class GML_Page_Test_Worker extends GML_Queue_Processor {
    public static $api;
    protected function create_api(){return self::$api;}
}
GML_Page_Test_Worker::$api=new GML_Page_Test_Provider();
$worker=new GML_Page_Test_Worker();
$worker->process_batch();
gml_db_assert(GML_Page_Test_Worker::$api->calls===1,'single explicit job uses one existing worker provider batch');
gml_db_assert((bool)get_option('gml_translation_paused')===true,'manual request never resumes background queue');
gml_db_assert(GML_Manual_Translation::job()['state']==='saved','manual missing-only generation saved');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='pending'")===49,'other pending work was untouched');
GML_Resource_Readiness::recalculate_resources([$manifest->id],['de']);
delete_option(GML_Manual_Translation::JOB);
$snapshot=GML_Manual_Translation::snapshot($ids[49]);
$existing=$snapshot['tm'];
gml_db_assert(!is_wp_error(GML_Manual_Translation::request($ids[49],GML_Manual_Translation::token($snapshot))),'existing auto can request a candidate');
$worker->process_batch();
gml_db_assert(GML_Manual_Translation::job()['state']==='candidate','existing auto receives candidate instead of overwrite');
gml_db_assert(GML_Translation_Memory::edit_snapshot($existing['id'])===$existing,'candidate generation preserves every existing TM field');
$edit_token=GML_Manual_Translation::token(GML_Manual_Translation::snapshot($ids[49]));
gml_db_assert(GML_Manual_Translation::save_manual($ids[49],$edit_token,'Manually reviewed current specification 49.'),'manual edit uses protected mutation');
gml_db_assert(!GML_Manual_Translation::save_manual($ids[49],$edit_token,'Stale second editor.'),'old form cannot overwrite newer manual text');
gml_db_assert(GML_Translation_Memory::edit_snapshot($existing['id'])['status']==='manual','manual status preserved');
gml_db_assert(count(GML_Page_Cache::pending_clusters())>0,'manual save records durable resource-cluster invalidation');
update_option('gml_page_demand_enabled',true);
$day=gmdate('Y-m-d'); $proof=GML_Page_Demand::token($manifest->id,'en',$day);
gml_db_assert(GML_Page_Demand::record($manifest->id,'en',$day,$proof,'synthetic-client'),'known source view counted');
gml_db_assert(!GML_Page_Demand::record($manifest->id,'en',$day,$proof,'synthetic-client'),'repeated refresh deduplicated');
gml_db_assert(!GML_Page_Demand::record(999999,'en',$day,$proof,'synthetic-client'),'forged arbitrary resource rejected');
gml_db_assert(!GML_Page_Demand::record($manifest->id,'ru',$day,$proof,'synthetic-client'),'disabled language rejected');
$scores=GML_Page_Demand::scores([$manifest->id]);
gml_db_assert($scores[$manifest->id]['en']===1,'source-language demand retained for target priority');
gml_db_assert(GML_Page_Test_Worker::$api->calls===2,'demand recording never calls AI');
update_option('gml_page_demand_enabled',false);
gml_db_assert(GML_Page_Demand::scores([$manifest->id])===[],'disabled demand is excluded from scheduling');
wp_set_current_user(0);
$before_job=GML_Manual_Translation::job();
gml_db_assert(is_wp_error(GML_Manual_Translation::request($ids[1],$token)),'anonymous users cannot request paid AI');
gml_db_assert(!GML_Manual_Translation::save_manual($ids[1],$token,'Unauthorized'),'anonymous users cannot save translations');
gml_db_assert(GML_Manual_Translation::job()===$before_job,'unauthorized request cannot change a job');
wp_set_current_user(1);
delete_option(GML_Manual_Translation::JOB);
$snapshot=GML_Manual_Translation::snapshot($ids[2]);$proof=GML_Manual_Translation::token($snapshot);
update_option('gml_ai_translation_enabled',false);
gml_db_assert(is_wp_error(GML_Manual_Translation::request($ids[2],$proof)),'hard AI-off blocks explicit paid work');
update_option('gml_ai_translation_enabled',true);
gml_db_assert(is_wp_error(GML_Manual_Translation::request($ids[2],str_repeat('0',64))),'stale source snapshot cannot enqueue explicit work');
$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM $q WHERE id=%d",$ids[2]),ARRAY_A);
$fault=static function($sql)use($wpdb){return strpos($sql,"INSERT INTO {$wpdb->options}")!==false && strpos($sql,GML_Manual_Translation::JOB)!==false?'INSERT INTO gml_missing_fixture_table (id) VALUES(1)':$sql;};
$quiet=$wpdb->suppress_errors(true);add_filter('query',$fault);
try{$broken=GML_Manual_Translation::request($ids[2],$proof);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($quiet);}
gml_db_assert(is_wp_error($broken) && $broken->get_error_code()==='job_write','job write fault is reported');
gml_db_assert($wpdb->get_row($wpdb->prepare("SELECT * FROM $q WHERE id=%d",$ids[2]),ARRAY_A)===$before,'job write failure rolls back queue status, priority and attempts');
gml_db_assert(GML_Manual_Translation::job()===[],'failed transaction leaves no active job');
$wpdb->query("ALTER TABLE $q ENGINE=MyISAM");
try {
    $unsupported=GML_Manual_Translation::request($ids[2],$proof);
    gml_db_assert(is_wp_error($unsupported) && $unsupported->get_error_code()==='transaction_unavailable','nontransactional queue is refused without partial writes');
} finally { $wpdb->query("ALTER TABLE $q ENGINE=InnoDB"); }
echo "OK page workflow integration\n";
