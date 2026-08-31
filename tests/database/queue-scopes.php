<?php
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
GML_Gemini_API::save_api_key( 'scope-test-not-a-real-key', 'gemini' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'scope-test-not-a-real-key', 'module_ai_translation_enabled' => 1 ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
$table = $wpdb->prefix . 'gml_queue';
$ids = [];
foreach ( [ 'es', 'de', 'ru', 'fr', 'es', 'es' ] as $i => $lang ) {
    $text = 'Scope test ' . wp_generate_uuid4();
    $wpdb->insert( $table, [ 'source_hash' => md5( $text ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => $lang, 'context_type' => 'text', 'status' => $i === 5 ? 'failed' : 'pending', 'attempts' => $i === 5 ? 3 : 0, 'created_at' => current_time( 'mysql' ) ] );
    $ids[] = (int) $wpdb->insert_id;
}
update_option( 'gml_languages', [ [ 'code'=>'es', 'enabled'=>true ], [ 'code'=>'de', 'enabled'=>true ], [ 'code'=>'ru', 'enabled'=>true ], [ 'code'=>'fr', 'enabled'=>false ] ] );
delete_option( 'gml_translation_normal_queue_enabled' );
delete_option( 'gml_translation_retry_sample_paused' );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_process_lock' );
update_option( 'gml_translation_failure_ack', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='failed'" ) );
update_option( 'gml_translation_retry_sample_ids', [ $ids[4] ] );
update_option( 'gml_translation_paused', false );
gml_db_assert( GML_Translation_Queue_Scope::normal_languages() === [], 'legacy sample upgrade does not silently start ordinary pending work' );
GML_Translation_Controls::pause();
gml_db_assert( GML_Translation_Controls::start( 'de' ) === true, 'single-language start is available while a sample exists' );
gml_db_assert( GML_Translation_Queue_Scope::normal_languages() === [ 'de' ], 'single-language start after global pause does not resume other languages' );
gml_db_assert( GML_Translation_Controls::sample_status()['paused'], 'normal start does not resume a paused failure sample' );
gml_db_assert( GML_Translation_Controls::start( 'ru' ) === true && GML_Translation_Queue_Scope::normal_languages() === [ 'de', 'ru' ], 'another language can start independently without stopping the first' );
GML_Translation_Controls::pause( 'de' );
gml_db_assert( GML_Translation_Queue_Scope::normal_languages() === [ 'ru' ], 'language pause affects only that language' );
gml_db_assert( GML_Translation_Controls::start() === true && GML_Translation_Queue_Scope::normal_languages() === [ 'es', 'de', 'ru' ], 'Start All includes all enabled languages but never a disabled language' );
gml_db_assert( get_option( 'gml_translation_retry_sample_ids' ) === [ $ids[4] ], 'Start All preserves the approved sample IDs' );
gml_db_assert( $wpdb->get_var( "SELECT status FROM $table WHERE id={$ids[5]}" ) === 'failed', 'Start All never resets failed rows' );
$event = wp_get_scheduled_event( 'gml_process_queue' );
gml_db_assert( $event && $event->schedule === 'every_minute', 'Start All installs a recurring worker rather than a single batch' );
GML_Translation_Controls::pause( 'es' );
GML_Translation_Controls::pause( 'de' );
GML_Translation_Controls::pause( 'ru' );
gml_db_assert( GML_Translation_Controls::resume_sample() === true, 'sample can run with normal languages paused' );
GML_Translation_Controls::start( 'ru' );
class GML_Scope_Worker extends GML_Queue_Processor {
    public static $texts = [];
    protected function create_api() {
        return new class {
            public function translate_batch( $texts, $source, $target, $type ) {
                GML_Scope_Worker::$texts = array_merge( GML_Scope_Worker::$texts, $texts );
                return array_map( static function( $text ) { return 'Translated ' . $text; }, $texts );
            }
        };
    }
}
delete_option( 'gml_translation_last_batch' );
$worker = new GML_Scope_Worker();
$worker->process_batch();
gml_db_assert( $wpdb->get_var( "SELECT status FROM $table WHERE id={$ids[4]}" ) === 'completed', 'sample runs independently of ordinary language pauses' );
gml_db_assert( ! get_option( 'gml_translation_paused' ) && GML_Translation_Queue_Scope::normal_languages() === [ 'ru' ], 'sample completion does not stop explicitly started normal work' );
$worker->process_batch();
gml_db_assert( $wpdb->get_var( "SELECT status FROM $table WHERE id={$ids[2]}" ) === 'completed', 'normal work continues after the failure sample finishes' );
gml_db_assert( $wpdb->get_var( "SELECT status FROM $table WHERE id={$ids[0]}" ) === 'pending', 'paused ordinary Spanish work is not included with its failure sample' );
gml_db_assert( $wpdb->get_var( "SELECT status FROM $table WHERE id={$ids[3]}" ) === 'pending', 'disabled language is not processed' );
gml_db_assert( $wpdb->get_var( "SELECT status FROM $table WHERE id={$ids[5]}" ) === 'failed', 'historical failed row stays failed throughout normal work' );
GML_Translation_Controls::pause();
gml_db_assert( ! wp_next_scheduled( 'gml_process_queue' ), 'Pause All removes recurring worker events' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'scope regressions make no paid API requests' );
wp_schedule_single_event( time() + 5, 'gml_process_queue' );
gml_db_assert( GML_Translation_Controls::start( 'ru' ) === true && wp_get_scheduled_event( 'gml_process_queue' )->schedule === 'every_minute', 'explicit start upgrades an existing one-shot event to recurring work' );
GML_Translation_Controls::pause( 'ru' );
gml_db_assert( ! wp_next_scheduled( 'gml_process_queue' ), 'pausing the last normal language removes idle worker polling' );
update_option( 'gml_translation_retry_sample_ids', [ $ids[0] ] );
gml_db_assert( GML_Translation_Controls::resume_sample() === true, 'independent sample can resume while normal scope has no active languages' );
GML_Translation_Controls::pause_sample();
gml_db_assert( ! wp_next_scheduled( 'gml_process_queue' ) && get_option( 'gml_translation_retry_sample_ids' ) === [ $ids[0] ], 'sample pause keeps its IDs but removes idle polling when normal work is paused' );
wp_set_current_user( 0 );
gml_db_assert( is_wp_error( GML_Translation_Controls::pause_sample() ), 'sample pause requires administrator permission' );
wp_set_current_user( 1 );
delete_option( 'gml_translation_retry_sample_ids' );
GML_Translation_Controls::pause();
$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', $ids ) . ')' );
delete_option( 'gml_translation_normal_queue_enabled' );
delete_option( 'gml_translation_retry_sample_paused' );
