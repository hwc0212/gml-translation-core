<?php
require __DIR__.'/bootstrap.php';
gml_test_product_init();
global $wpdb;
$q=$wpdb->prefix.'gml_queue';
if(($argv[1]??'')==='prepare') {
    wp_set_current_user(1);
    GML_Installer::activate();
    update_option('gml_multilingual_enabled',true);
    update_option('gml_ai_translation_enabled',true);
    update_option('gml_source_lang','en');
    update_option('gml_languages',[['code'=>'de','enabled'=>true]]);
    GML_Translation_Credentials::save('cron-fixture-not-a-secret','gemini');
    update_option('gml_translation_engine','gemini');
    update_option('gml_translation_paused',false);
    update_option(GML_Translation_Queue_Scope::NORMAL_OPTION,1);
    foreach([GML_Queue_Processor::CIRCUIT_OPTION,GML_Queue_Processor::BACKOFF_OPTION,GML_Queue_Processor::SAMPLE_OPTION,GML_Queue_Processor::LOCK_OPTION,GML_Manual_Translation::JOB,GML_Page_Work_Scheduler::WINDOW] as $key) delete_option($key);
    foreach([$q,$wpdb->prefix.'gml_index',GML_Resource_Manifest_Store::relation_table(),GML_Resource_Manifest_Store::manifest_table(),GML_Resource_Manifest_Store::readiness_table()] as $table) $wpdb->query("DELETE FROM $table");
    $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'HTTP Cron fixture']);
    $resource=GML_Resource_Identity::for_post($id);$nodes=[];
    for($n=0;$n<100;$n++) {
        $text='HTTP scheduler engineering text '.$n;
        $nodes[]=['text'=>$text,'context_type'=>'text'];
        $wpdb->insert($q,['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','context_type'=>'text','status'=>'pending','attempts'=>0,'created_at'=>current_time('mysql')]);
    }
    GML_Resource_Manifest_Store::save_complete($resource,$nodes);
    update_option('gml_test_cron_fixture',1,false);
    update_option('gml_test_cron_runs',[],false);
    update_option('gml_test_provider_calls',0,false);
    GML_Queue_Processor::unschedule_cron();
    // Remove other synthetic scenarios' discovery events from this disposable DB.
    update_option('cron',[],false);
    wp_schedule_event(time()-1,'every_minute',GML_Queue_Processor::CRON_HOOK);
    echo "Prepared 100 synthetic assets; invoke local wp-cron.php, not process_batch.\n";
} else {
    $runs=(array)get_option('gml_test_cron_runs',[]);
    echo wp_json_encode($runs,JSON_PRETTY_PRINT)."\n";
    gml_db_assert(count($runs)>=3,'at least three actual WP-Cron HTTP execution windows');
    gml_db_assert(array_sum(array_column($runs,'saved'))===100,'100 actual TM inserts recorded across windows');
    gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status='completed'")===100,'all 100 queued assets completed');
    gml_db_assert((int)get_option('gml_test_provider_calls')===5,'five correctly bounded context batches');
    foreach($runs as $run) {
        gml_db_assert($run['scheduled_at']>0 && $run['scheduled_at']<=$run['started_at'],'due timestamp precedes actual start');
        gml_db_assert($run['batches']<=2 && $run['saved']<=40,'fixture two-batch window limit honored');
    }
    $continuations=0;
    foreach(_get_cron_array() as $events) if(isset($events[GML_Queue_Processor::CONTINUE_HOOK])) $continuations+=count($events[GML_Queue_Processor::CONTINUE_HOOK]);
    gml_db_assert($continuations<=1,'fixed hook and args never accumulate continuation events');
    update_option('gml_test_cron_fixture',0,false);
    update_option('gml_translation_paused',true);
    GML_Queue_Processor::unschedule_cron();
    echo "OK actual local HTTP Cron continuation\n";
}
