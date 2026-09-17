<?php
/** Synthetic fixtures only. The bootstrap blocks all external HTTP. */
require __DIR__.'/bootstrap.php';
gml_test_product_init();
wp_set_current_user(1);
gml_db_assert(GML_Installer::activate()===true,'protected diagnostics schema ready');
$probe_calls=0; $mode='dedup';
$probe=static function($pre,$args,$url)use(&$probe_calls,&$mode) {
    $probe_calls++;
    $body=json_decode($args['body'],true); $prompt=$body['contents'][0]['parts'][0]['text'];
    $limited=$mode==='split' && $probe_calls<=2;
    $reply=str_replace('%s','%d',$prompt);
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['finishReason'=>$limited?'MAX_TOKENS':'STOP','content'=>['parts'=>[['text'=>$reply]]]]]])];
};
add_filter('pre_http_request',$probe,99,3);
$api=new GML_Gemini_API(['engine'=>'gemini','api_key'=>'protected-mock-only']);
try {$api->translate_batch(['Safe fixture','Safe fixture','Value %s'],'en','de');} catch(RuntimeException $e) {}
$error=$api->get_last_error();
gml_db_assert(($error['item_index']??-1)===2 && ($error['diagnostic']['source_hash']??'')===md5('Value %s'),'deduplicated batch diagnostic maps to original input index');
$mode='split'; $probe_calls=0;
try {$api->translate_batch(['Safe fixture A','Safe fixture B','Value %s'],'en','de');} catch(RuntimeException $e) {}
$error=$api->get_last_error();
gml_db_assert($probe_calls===4 && ($error['code']??'')==='protected_term' && ($error['item_index']??-1)===2,'split recovery preserves protected failure and exact original index');
remove_filter('pre_http_request',$probe,99);
update_option('gml_protected_terms',['AB-123']);
$checker=new ReflectionMethod('GML_Translation_AI_Client','check_translation_quality'); $checker->setAccessible(true);
$rejected=false;
try {$checker->invoke($api,'Model AB-123','Modell AB-124');} catch(RuntimeException $e) {$rejected=true;}
gml_db_assert($rejected,'configured model identifier remains protected');
delete_option('gml_protected_terms');
update_option('gml_multilingual_enabled',true);
update_option('gml_ai_translation_enabled',true);
update_option('gml_source_lang','en');
update_option('gml_languages',[['code'=>'de','enabled'=>true,'paused'=>false]]);
update_option('gml_translation_paused',false);
GML_Gemini_API::save_api_key('protected-mock-only','gemini');
foreach([GML_Queue_Processor::CIRCUIT_OPTION,GML_Queue_Processor::BACKOFF_OPTION,GML_Queue_Processor::LOCK_OPTION,GML_Manual_Translation::JOB] as $key) delete_option($key);
$q=$wpdb->prefix.'gml_queue'; $tm=$wpdb->prefix.'gml_index';
$wpdb->query("DELETE FROM $q"); $wpdb->query("DELETE FROM $tm");
$bad='Fixture %1$s: 10*20mm, 20% increase.';
$good='A useful plain fixture.';
$post=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Protected diagnostics fixture']);
$identity=GML_Resource_Identity::for_post($post);
GML_Resource_Manifest_Store::save_complete($identity,[['text'=>$bad,'context_type'=>'text'],['text'=>$good,'context_type'=>'text']]);
$ids=[];
foreach([$bad,$good] as $text) {
    $wpdb->insert($q,['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','context_type'=>'text','status'=>'pending','attempts'=>0,'priority'=>100,'created_at'=>current_time('mysql')]);
    $ids[]=(int)$wpdb->insert_id;
}
$requests=0; $bad_requests=0;
$mock=static function($pre,$args,$url)use(&$requests,&$bad_requests,$bad){
    $requests++;
    $body=json_decode($args['body'],true);
    $prompt=$body['contents'][0]['parts'][0]['text'];
    if(strpos($prompt,$bad)!==false) $bad_requests++;
    $reply=str_replace([$bad,'A useful plain fixture.'],['Fixture %1$d: 10 x 20 mm, 20 % increase.','Eine hilfreiche Testzeile.'],$prompt);
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>$reply]]]]]])];
};
add_filter('pre_http_request',$mock,99,3);
$worker=new GML_Queue_Processor();
for($n=0;$n<3;$n++) $worker->process_batch();
remove_filter('pre_http_request',$mock,99);
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $q WHERE id=%d",$ids[0]));
gml_db_assert($row->status==='failed' && (int)$row->attempts===1 && $bad_requests===1,'deterministic failure consumes one mock attempt, never three');
gml_db_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM $q WHERE id=%d",$ids[1]))==='completed','other queue items continue after protected failure');
gml_db_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $tm WHERE source_hash=%s",md5($bad))),'rejected candidate never enters TM');
$detail=GML_Translation_Error::diagnostic($row);
gml_db_assert(($detail['rule']??'')==='format_numbered' && $detail['source_token']==='%1$s' && $detail['candidate_token']==='%1$d','private structured mismatch retains exact protected tokens');
gml_db_assert(strpos($detail['candidate'],'%1$d')!==false && $detail['source_hash']===md5($bad),'rejected candidate is bound to source identity');
gml_db_assert($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",'gml_translation_diagnostic_'.$row->id))==='off' || $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",'gml_translation_diagnostic_'.$row->id))==='no','diagnostic is not autoloaded');
foreach(GML_Translation_Activity::recent(100) as $event) gml_db_assert(!isset($event['diagnostic']) && !isset($event['candidate']) && strpos(wp_json_encode($event),'%1$d')===false,'operational logs remain candidate-free');
wp_set_current_user(0);
gml_db_assert(GML_Translation_Error::diagnostic($row)===[],'anonymous diagnostic read refused');
wp_set_current_user(1);
$stale=clone $row; $stale->source_hash=md5('changed source');
gml_db_assert(GML_Translation_Error::diagnostic($stale)===[],'changed source cannot show another snapshot candidate');
$failure=['code'=>'protected_term','diagnostic'=>['source_hash'=>md5('wrong'),'candidate'=>'wrong']];
gml_db_assert(!GML_Translation_Error::store_diagnostic($row,$failure),'wrong-context diagnostic is never attached to a queue row');
$failure['diagnostic']=['source_hash'=>$row->source_hash,'rule'=>'link','source_token'=>'https://example.test/p?token=secret-one','candidate_token'=>'https://example.test/p?token=secret-two','candidate'=>'<script>bad()</script> api_key=topsecret https://user:password@example.test/p?token=hidden'];
GML_Translation_Error::store_diagnostic($row,$failure);
$safe=GML_Translation_Error::diagnostic($row);
gml_db_assert(strpos(wp_json_encode($safe),'secret')===false && strpos(wp_json_encode($safe),'password')===false && strpos(wp_json_encode($safe),'hidden')===false,'diagnostic credentials and URL secrets redacted');
gml_db_assert($safe['source_token']!==$safe['candidate_token'],'redacted URL differences remain distinguishable by digest');
if(!class_exists('GML_Page_Workflow_Admin')) require getenv('GML_TEST_PRODUCT_DIR').'/admin/class-page-workflow-admin.php';
$_GET=['reason'=>'protected_term'];
$render=new ReflectionMethod('GML_Page_Workflow_Admin','failures'); $render->setAccessible(true);
ob_start(); $render->invoke(new GML_Page_Workflow_Admin()); $html=ob_get_clean();
gml_db_assert(strpos($html,'gml-protected-diagnostic')!==false && strpos($html,'Source token')!==false && strpos($html,'Candidate token')!==false,'Needs Attention renders structured diagnostic');
gml_db_assert(strpos($html,'<script>bad()')===false && strpos($html,'&lt;script&gt;bad()')!==false,'candidate markup is escaped, not executable');
gml_db_assert(strpos($html,'Save Manual Translation')!==false && strpos($html,'Keep source text')!==false && strpos($html,'AI Translate This Item')!==false,'all rc33 manual exits remain available');
for($n=0;$n<102;$n++) { $fixture=clone $row; $fixture->id=900000+$n; GML_Translation_Error::store_diagnostic($fixture,$failure); }
$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('gml_translation_diagnostic_').'%'));
gml_db_assert($count===100,'private diagnostic retention bounded at 100 records');
update_option('gml_translation_paused',true);
echo "OK protected diagnostics\n";
