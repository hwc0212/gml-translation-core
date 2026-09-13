<?php
/** Install only as an MU symlink inside the disposable Docker regression site. */
if (getenv('GML_DATABASE_TESTS')!=='1' || !defined('DB_NAME') || strpos(DB_NAME,'gml_regression')!==0) return;
add_filter('pre_http_request',static function(){return new WP_Error('mock_only','External HTTP disabled.');});
add_action('plugins_loaded',static function() {
    if(!get_option('gml_test_cron_fixture',false)) return;
    if(!class_exists('GML_Translate',false)) require getenv('GML_TEST_PRODUCT_DIR').'/gml-translate.php';
    GML_Translate::get_instance()->init_components();
    class GML_Cron_HTTP_Provider {
        public function translate_batch($texts,$source,$target,$type) {
            GML_Translation_Budget::reserve_worker_request(strlen(implode('',$texts)),2048);
            usleep(20000);
            update_option('gml_test_provider_calls',(int)get_option('gml_test_provider_calls',0)+1,false);
            return array_map(static function($text){return 'DE '.$text;},$texts);
        }
    }
    class GML_Cron_HTTP_Worker extends GML_Queue_Processor {
        const RUN_BATCHES=2;
        const BATCH_SIZE=20;
        protected function create_api(){return new GML_Cron_HTTP_Provider();}
    }
    foreach([GML_Queue_Processor::CRON_HOOK,GML_Queue_Processor::CONTINUE_HOOK] as $hook) remove_all_actions($hook);
    new GML_Cron_HTTP_Worker();
    $record=static function() {
        $runs=(array)get_option('gml_test_cron_runs',[]);
        $runs[]=get_option('gml_translation_last_run',[]);
        update_option('gml_test_cron_runs',array_slice($runs,-10),false);
    };
    add_action(GML_Queue_Processor::CRON_HOOK,$record,100);
    add_action(GML_Queue_Processor::CONTINUE_HOOK,$record,100);
},100);
