<?php
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
GML_Gemini_API::save_api_key( 'test-only-never-sent', 'gemini' );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'de', 'enabled' => true, 'paused' => true ] ] );
update_option( 'gml_translation_paused', true );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_retry_sample_ids' );
delete_option( 'gml_translation_normal_queue_enabled' );
delete_option( 'gml_translation_retry_sample_paused' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'test-only-never-sent', 'module_ai_translation_enabled' => 1 ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
$queue = $wpdb->prefix . 'gml_queue';
$index = $wpdb->prefix . 'gml_index';
$before = $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A );
$admin = (new ReflectionClass( 'GML_Admin_Settings' ))->newInstanceWithoutConstructor();
$render = new ReflectionMethod( $admin, 'render_translations_tab' );
$render->setAccessible( true );
$generation = GML_Page_Cache::generation();
foreach ( [ 'clear_all_cache', 'clear_lang_cache' ] as $action ) {
    $_POST = [ 'gml_cache_action' => $action, 'lang_code' => 'de' ];
    $_REQUEST = [ 'gml_cache_nonce' => wp_create_nonce( 'gml_cache_action' ) ];
    ob_start();
    $render->invoke( $admin );
    ob_end_clean();
    gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A ), $action . ' preserves manual translations and every queue row' );
}
$_POST = $_REQUEST = [];
gml_db_assert( GML_Page_Cache::generation() > $generation, 'cache refresh invalidates rendered pages without touching translation storage' );
$_REQUEST = [ 'gml_cache_nonce' => wp_create_nonce( 'gml_cache_action' ) ];
gml_db_assert( is_wp_error( GML_Translation_Controls::handle_request( [ 'gml_cache_action' => 'clear_lang_queue', 'lang_code' => 'de' ] ) ), 'retired queue deletion command is rejected' );
gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A ), 'retired command does not delete queue items' );
gml_db_assert( class_exists( 'GML_Translation_Controls' ), 'shared translation controls are available' );
gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'paused', 'paused queue is not reported as running' );
wp_clear_scheduled_hook( 'gml_process_queue' );
$reject = static function() { return false; };
add_filter( 'pre_schedule_event', $reject );
$start = GML_Translation_Controls::start();
remove_filter( 'pre_schedule_event', $reject );
gml_db_assert( is_wp_error( $start ) && get_option( 'gml_translation_paused' ), 'queue schedule failure preserves ordinary pause' );
update_option( 'gml_translation_retry_sample_ids', [ 1 ] );
gml_db_assert( GML_Translation_Controls::start() === true && get_option( 'gml_translation_retry_sample_ids' ) === [ 1 ], 'full pending start preserves isolated sample scope' );
delete_option( 'gml_translation_retry_sample_ids' );
gml_db_assert( GML_Translation_Controls::start() === true, 'explicit queue start succeeds' );
gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'scheduled', 'scheduled queue is not reported as actively processing' );
GML_Translation_Controls::pause( 'de' );
gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'paused', 'all languages paused is not reported as scheduled' );
GML_Translation_Controls::start( 'de' );
wp_clear_scheduled_hook( 'gml_process_queue' );
gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'not_scheduled', 'missing cron event is reported' );
update_option( 'gml_translation_process_lock', [ 'token' => 'test', 'expires' => time() - 1 ], false );
gml_db_assert( GML_Translation_Controls::queue_status()['state'] !== 'processing', 'expired worker lock is not reported as processing' );
delete_option( 'gml_translation_process_lock' );
GML_Translation_Controls::pause();
$languages = get_option( 'gml_languages' );
wp_clear_scheduled_hook( 'gml_crawl_content' );
gml_db_assert( GML_Content_Crawler::start_crawl() === true, 'scan starts independently while translation is paused' );
gml_db_assert( get_option( 'gml_translation_paused' ) && get_option( 'gml_languages' ) === $languages, 'scan preserves global and per-language pause settings' );
gml_db_assert( ! wp_next_scheduled( 'gml_process_queue' ), 'scan does not wake the AI queue' );
$id = wp_insert_post( [ 'post_title' => 'Independent scan fixture', 'post_content' => '<p>Discover this unique sentence without translating it.</p>', 'post_type' => 'page', 'post_status' => 'publish' ] );
class GML_Test_Offline_Crawler extends GML_Content_Crawler {
    protected function fetch_rendered_html( $post ) { return $this->build_post_html( $post ); }
}
$old_calls = $GLOBALS['gml_test_http_calls'];
(new GML_Test_Offline_Crawler())->crawl_batch();
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue WHERE source_text = 'Independent scan fixture'" ) > 0, 'paused scan queues discovered text without translating it' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === $old_calls, 'scan never invokes a paid provider' );
gml_db_assert( get_option( 'gml_translation_paused' ), 'scan batch never resumes translation' );
GML_Content_Crawler::stop_crawl();
wp_delete_post( $id, true );
gml_db_assert( GML_Translation_Controls::start() === true, 'queue can start after a scan' );
class GML_Test_Observed_Worker extends GML_Queue_Processor {
    protected function create_api() {
        return new class {
            public function translate_batch( $texts, $source, $target, $type ) {
                gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'processing', 'processing requires a live worker lock and matching batch activity' );
                gml_db_assert( GML_Translation_Controls::queue_status( $target )['state'] === 'processing', 'only the active batch language reports processing' );
                GML_Translation_Controls::pause();
                gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'pausing', 'pause during a live batch is displayed honestly' );
                return array_map( static function( $text ) { return 'Offline ' . $text; }, $texts );
            }
        };
    }
}
(new GML_Test_Observed_Worker())->process_batch();
gml_db_assert( GML_Translation_Controls::queue_status()['state'] === 'paused', 'finished batch is no longer shown as processing' );
gml_db_assert( GML_Translation_Controls::queue_status()['last_activity'] > 0, 'worker completion time is recorded' );
wp_set_current_user( 0 );
gml_db_assert( is_wp_error( GML_Translation_Controls::start() ), 'unauthorized user cannot start translation' );
$generation = GML_Page_Cache::generation();
gml_db_assert( is_wp_error( GML_Translation_Controls::refresh_cache() ), 'unauthorized user cannot refresh the page cache' );
gml_db_assert( GML_Page_Cache::generation() === $generation, 'unauthorized cache request makes no change' );
wp_set_current_user( 1 );
class GML_Test_Invalid_Nonce extends RuntimeException {}
$die = static function() { return static function() { throw new GML_Test_Invalid_Nonce(); }; };
add_filter( 'wp_die_handler', $die );
$_REQUEST = [ 'gml_cache_nonce' => 'invalid' ];
$rejected = false;
try { GML_Translation_Controls::handle_request( [ 'gml_cache_action' => 'refresh_page_cache' ] ); }
catch ( GML_Test_Invalid_Nonce $error ) { $rejected = true; }
remove_filter( 'wp_die_handler', $die );
gml_db_assert( $rejected && GML_Page_Cache::generation() === $generation, 'invalid cache nonce is rejected before any state change' );
$_REQUEST = [];
