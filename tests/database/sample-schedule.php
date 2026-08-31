<?php
define( 'DOING_AJAX', true );
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_translation_engine', 'gemini' );
GML_Gemini_API::save_api_key( 'synthetic-sample-schedule-only', 'gemini' );
update_option( 'gml_translation_paused', true );
update_option( 'gml_languages', [ [ 'code' => 'es', 'enabled' => true, 'paused' => true ] ] );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_retry_sample_ids' );
delete_option( 'gml_translation_process_lock' );
wp_clear_scheduled_hook( 'gml_process_queue' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'synthetic-sample-schedule-only', 'module_ai_translation_enabled' => 1 ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
$table = $wpdb->prefix . 'gml_queue';
$text = 'Sample schedule fixture ' . wp_generate_uuid4();
$wpdb->insert( $table, [ 'source_hash' => md5( $text ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'es', 'context_type' => 'text', 'status' => 'failed', 'attempts' => 3, 'priority' => 99, 'created_at' => current_time( 'mysql' ) ] );
$id = (int) $wpdb->insert_id;
update_option( 'gml_translation_failure_ack', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='failed'" ) );
class GML_Sample_Ajax_Exit extends RuntimeException {}
$die = static function() { return static function() { throw new GML_Sample_Ajax_Exit(); }; };
$reject = static function() { return false; };
add_filter( 'wp_die_ajax_handler', $die );
add_filter( 'pre_schedule_event', $reject );
$_POST = [ 'lang' => 'es' ];
$_REQUEST = [ 'nonce' => wp_create_nonce( 'gml_editor_nonce' ) ];
ob_start();
try { (new GML_Translation_Editor())->ajax_retry_failed(); }
catch ( GML_Sample_Ajax_Exit $error ) {}
$result = json_decode( ob_get_clean(), true );
remove_filter( 'pre_schedule_event', $reject );
remove_filter( 'wp_die_ajax_handler', $die );
gml_db_assert( is_array( $result ) && $result['success'] === false, 'initial AJAX sample reports scheduling failure instead of success' );
gml_db_assert( get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( 'gml_process_queue' ), 'failed initial scheduling cannot unpause the AI queue' );
gml_db_assert( in_array( $id, get_option( 'gml_translation_retry_sample_ids', [] ), true ), 'initial scheduling failure retains an explicitly resumable sample' );
gml_db_assert( GML_Translation_Controls::resume_sample() === true, 'the retained sample resumes after scheduling recovers' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'sample scheduling and resume never make an immediate paid request' );
GML_Translation_Controls::pause();
delete_option( 'gml_translation_retry_sample_ids' );
$wpdb->delete( $table, [ 'id' => $id ] );
$_POST = $_REQUEST = [];
