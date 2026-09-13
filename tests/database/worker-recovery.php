<?php
require __DIR__.'/worker-continuation.php';

function gml_worker_fixture($count=3) {
    global $wpdb;
    foreach (['gml_queue','gml_index'] as $name) $wpdb->query("DELETE FROM {$wpdb->prefix}$name");
    foreach ([GML_Resource_Manifest_Store::relation_table(),GML_Resource_Manifest_Store::manifest_table(),GML_Resource_Manifest_Store::readiness_table()] as $table) $wpdb->query("DELETE FROM $table");
    foreach ([GML_Queue_Processor::CIRCUIT_OPTION,GML_Queue_Processor::BACKOFF_OPTION,GML_Queue_Processor::LOCK_OPTION,GML_Queue_Processor::SAMPLE_OPTION,GML_Manual_Translation::JOB,GML_Page_Work_Scheduler::WINDOW] as $key) delete_option($key);
    update_option('gml_translation_paused',false);
    update_option('gml_ai_translation_enabled',true);
    update_option(GML_Translation_Queue_Scope::NORMAL_OPTION,1);
    GML_Queue_Processor::unschedule_cron();
    $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Recovery fixture']);
    $resource=GML_Resource_Identity::for_post($id);
    $nodes=[];
    for ($n=0;$n<$count;$n++) {
        $text='Recovery engineering specification '.$n;
        $type=$n===0?'seo_title':($n===1?'seo_meta':'text');
        $nodes[]=['text'=>$text,'context_type'=>$type];
        $wpdb->insert($wpdb->prefix.'gml_queue',['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','context_type'=>$type,'status'=>'pending','attempts'=>0,'created_at'=>current_time('mysql')]);
    }
    GML_Resource_Manifest_Store::save_complete($resource,$nodes);
    return $resource;
}
class GML_Recovery_Provider extends GML_Continuation_Provider {
    public $after;
    public function translate_batch($texts,$source,$target,$type) {
        GML_Translation_Budget::reserve_worker_request(strlen(implode('',$texts)),2048);
        $result=parent::translate_batch($texts,$source,$target,$type);
        if ($this->after) call_user_func($this->after);
        return $result;
    }
}
foreach (['pause','hard_stop','budget','lease'] as $case) {
    gml_worker_fixture();
    $api=new GML_Recovery_Provider();
    $api->after=static function()use($case) {
        if($case==='pause') update_option('gml_translation_paused',true);
        if($case==='hard_stop') update_option('gml_ai_translation_enabled',false);
        if($case==='budget') add_filter('gml_translation_worker_can_request','__return_false');
        if($case==='lease') {
            delete_option(GML_Queue_Processor::LOCK_OPTION);
            GML_Atomic_Option_Lock::acquire(GML_Queue_Processor::LOCK_OPTION,60);
        }
    };
    GML_Continuation_Worker::$provider=$api;
    $worker->process_batch();
    gml_db_assert(count($api->groups)===1,$case.' prevents a second provider request');
    gml_db_assert((int)$wpdb->get_var("SELECT SUM(attempts) FROM $q")===0,$case.' does not burn retry attempts');
    remove_filter('gml_translation_worker_can_request','__return_false');
}

gml_worker_fixture();
class GML_Protected_Failure_Provider extends GML_Recovery_Provider {
    public $calls=0;
    public function translate_batch($texts,$source,$target,$type) {
        $this->calls++;
        throw new RuntimeException('Protected term changed in fixture.');
    }
    public function get_last_error(){return ['code'=>'protected_term','item_index'=>0];}
}
$bad=new GML_Protected_Failure_Provider();GML_Continuation_Worker::$provider=$bad;
$worker->process_batch();
gml_db_assert($bad->calls===1,'deterministic protected-term failure has no unchanged single-item fallback');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='failed' AND attempts=1")===1,'only the bad tuple is terminal after one request');
GML_Continuation_Worker::$provider=new GML_Recovery_Provider();
$worker->process_batch();
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='completed'")===2,'other valid contexts continue despite one terminal content failure');

$resource=gml_worker_fixture(1);
// Legacy imports can lack the current unique index; reproduce that schema only here.
$wpdb->query("ALTER TABLE $q DROP INDEX queue_hash_lang");
$row=$wpdb->get_row("SELECT * FROM $q LIMIT 1",ARRAY_A);
$wpdb->update($q,['status'=>'failed','attempts'=>3,'error_message'=>'[bad_request] old fixture failure'],['id'=>$row['id']]);
unset($row['id']);
$row['status']='failed';$row['attempts']=3;$row['error_message']='[bad_request] old fixture failure';
for($n=0;$n<6;$n++) $wpdb->insert($q,$row);
$ids=$wpdb->get_col("SELECT id FROM $q ORDER BY id");
if(class_exists('GML_Page_Workflow_Admin')) {
    $_GET=['language'=>'de','scope'=>'current'];
    ob_start();(new GML_Page_Workflow_Admin())->render('failures');$html=ob_get_clean();
    gml_db_assert(substr_count($html,'AI Translate This Item')===1,'seven failures produce one current AI action');
    gml_db_assert(strpos($html,'7 stored records')!==false,'duplicate history remains visible');
}
$id=(int)end($ids);
$snapshot=GML_Manual_Translation::snapshot($id);
gml_db_assert(!is_wp_error(GML_Manual_Translation::request($id,GML_Manual_Translation::token($snapshot))),'one explicit recovery accepted');
$api=new GML_Recovery_Provider();GML_Continuation_Worker::$provider=$api;
$worker->process_batch();
gml_db_assert(count($api->groups)===1,'seven old rows generate only once');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='completed'")===7,'successful asset resolves all exact duplicate failures');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE error_message LIKE '%old fixture failure%'")===6,'old failure history is preserved');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gml_index")===1,'one translation asset inserted');

$resource=gml_worker_fixture(0);
$wpdb->query("ALTER TABLE $q ADD UNIQUE KEY queue_hash_lang (source_hash,source_lang,target_lang)");
update_option('gml_translation_paused',true);
class GML_Recovery_Renderer { public function render($resource) {return '<html><head><title>Engineering recovery title</title></head><body><p>Current engineering recovery paragraph.</p></body></html>';}}
$discovery=new GML_Resource_Manifest_Discovery(new GML_Recovery_Renderer());
gml_db_assert($discovery->discover($resource)===true,'inventory-only manifest discovery succeeds');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q")===0,'discovery without target does not enqueue');
gml_db_assert($discovery->discover($resource,'de')===true,'explicit page language discovers and queues missing text');
$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $q");
gml_db_assert($count>=2,'both title and body missing assets queued');
gml_db_assert($discovery->discover($resource,'de')===true && (int)$wpdb->get_var("SELECT COUNT(*) FROM $q")===$count,'repeated explicit request does not duplicate assets');
gml_db_assert((bool)get_option('gml_translation_paused')===true,'page enqueue never resumes user pause');
gml_db_assert(!wp_next_scheduled(GML_Queue_Processor::CONTINUE_HOOK),'paused page request does not register continuation');
gml_db_assert($GLOBALS['gml_test_http_calls']===0,'all recovery cases used local mock providers only');
$client=new GML_Gemini_API();
$prompt=new ReflectionMethod(GML_Translation_AI_Client::class,'build_system_instruction');
$prompt->setAccessible(true);
$technical=$prompt->invoke($client,'en','de','text','Describe your application, voltage and equipment requirements.');
$employment=$prompt->invoke($client,'en','de','text','Submit your job application.');
gml_db_assert(strpos($technical,'intended use')!==false,'technical application receives intended-use context');
gml_db_assert(strpos($employment,'intended use')===false,'employment application is not globally rewritten');
GML_Translation_Budget::begin_worker(45,static function(){return '';});
for($n=0;$n<8;$n++) GML_Translation_Budget::reserve_worker_request(20,1024);
$yield='';
try {GML_Translation_Budget::reserve_worker_request(20,1024);} catch(GML_Translation_Worker_Yield $e) {$yield=$e->getMessage();}
gml_db_assert($yield==='request_budget','ninth request refused before transport');
GML_Translation_Budget::begin_worker(6,static function(){return '';});
$yield='';
try {GML_Translation_Budget::reserve_worker_request(20,1024);} catch(GML_Translation_Worker_Yield $e) {$yield=$e->getMessage();}
gml_db_assert($yield==='time_budget' && GML_Translation_Budget::worker_timeout(60)<=4,'insufficient response time prevents HTTP start');
GML_Translation_Budget::end_worker();
echo "OK worker recovery and page enqueue\n";
