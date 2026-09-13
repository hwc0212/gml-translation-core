<?php
/** Regression entrypoint for bounded worker continuation. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
wp_set_current_user(1);
GML_Installer::activate();
update_option('gml_multilingual_enabled',true);
update_option('gml_ai_translation_enabled',true);
update_option('gml_source_lang','en');
update_option('gml_languages',[['code'=>'de','enabled'=>true]]);
GML_Translation_Credentials::save('worker-fixture-not-a-secret','gemini');
update_option('gml_translation_engine','gemini');
update_option('gml_translation_paused',false);
update_option(GML_Translation_Queue_Scope::NORMAL_OPTION,1);
foreach ([GML_Queue_Processor::CIRCUIT_OPTION,GML_Queue_Processor::BACKOFF_OPTION,
    GML_Queue_Processor::SAMPLE_OPTION,GML_Queue_Processor::LOCK_OPTION,
    GML_Manual_Translation::JOB,GML_Page_Work_Scheduler::WINDOW] as $key) delete_option($key);
global $wpdb;
$q=$wpdb->prefix.'gml_queue';
foreach ([$q,$wpdb->prefix.'gml_index',GML_Resource_Manifest_Store::relation_table(),
    GML_Resource_Manifest_Store::manifest_table(),GML_Resource_Manifest_Store::readiness_table()] as $table) $wpdb->query("DELETE FROM $table");
$post=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Worker fixture']);
$resource=GML_Resource_Identity::for_post($post);
$nodes=[];
for ($i=0;$i<30;$i++) {
    $text='Synthetic worker text '.$i;
    $type=$i===0?'seo_title':($i===1?'seo_meta':'text');
    $nodes[]=['text'=>$text,'hash'=>md5($text),'context_type'=>$type];
    $wpdb->insert($q,['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de',
        'context_type'=>$type,'status'=>'pending','priority'=>10,'attempts'=>0,'created_at'=>current_time('mysql')]);
}
GML_Resource_Manifest_Store::save_complete($resource,$nodes);
class GML_Continuation_Provider {
    public $groups=[];
    public function translate_batch($texts,$source,$target,$type) {
        $this->groups[]=['type'=>$type,'items'=>count($texts)];
        return array_map(static function($text){return 'Uebersetzung '.$text;},$texts);
    }
}
class GML_Continuation_Worker extends GML_Queue_Processor {
    public static $provider;
    protected function create_api() {return self::$provider;}
}
GML_Continuation_Worker::$provider=new GML_Continuation_Provider();
$worker=new GML_Continuation_Worker();
$worker->process_batch();
echo wp_json_encode(['completed'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='completed'"),'groups'=>GML_Continuation_Worker::$provider->groups])."\n";
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='completed'")===30,
    'one wake saves all 30 short texts across three contexts');
gml_db_assert(count(GML_Continuation_Worker::$provider->groups)===3,'three context contracts, no generic prompt merging');
gml_db_assert((get_option('gml_translation_last_run')['saved']??0)===30,'worker log counts committed inserts, not requested items');
gml_db_assert($GLOBALS['gml_test_http_calls']===0,'no paid provider request');
echo "OK bounded worker continuation\n";
