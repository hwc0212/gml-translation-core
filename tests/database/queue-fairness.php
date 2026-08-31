<?php
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_translation_paused', false );
update_option( 'gml_translation_engine', 'gemini' );
GML_Gemini_API::save_api_key( 'synthetic-fairness-test-only', 'gemini' );
update_option( 'gml_languages', [ [ 'code' => 'es', 'enabled' => true ], [ 'code' => 'ru', 'enabled' => true ] ] );
delete_option( 'gml_translation_last_batch' );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_process_lock' );
delete_option( 'gml_translation_retry_sample_ids' );
update_option( 'gml_translation_failure_ack', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gml_queue WHERE status = 'failed'" ) );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'synthetic-fairness-test-only', 'module_ai_translation_enabled' => 1 ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
$table = $wpdb->prefix . 'gml_queue';
// Isolate pending work using the existing bounded sample mechanism, without deleting old rows.
$ids = [];
foreach ( [ 'es', 'ru' ] as $lang ) {
    for ( $i = 0; $i < 35; $i++ ) {
        $text = 'Fairness fixture ' . $lang . ' ' . $i;
        $wpdb->insert( $table, [ 'source_hash' => md5( $text ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => $lang, 'context_type' => 'text', 'status' => 'pending', 'priority' => 5, 'attempts' => 0, 'created_at' => current_time( 'mysql' ) ] );
        $ids[] = (int) $wpdb->insert_id;
    }
}
update_option( 'gml_translation_retry_sample_ids', $ids );
class GML_Fairness_Worker extends GML_Queue_Processor {
    public static $languages = [];
    protected function create_api() {
        return new class {
            public function translate_batch( $texts, $source, $target, $type ) {
                GML_Fairness_Worker::$languages[] = $target;
                return array_map( static function( $text ) { return 'Translated ' . $text; }, $texts );
            }
        };
    }
}
$worker = new GML_Fairness_Worker();
$worker->process_batch();
$worker->process_batch();
gml_db_assert( GML_Fairness_Worker::$languages === [ 'es', 'ru' ], 'Russian gets the second batch while Spanish still has pending segments' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='pending' AND target_lang='es' AND id IN (" . implode( ',', $ids ) . ')' ) === 5, 'Spanish backlog is not exhausted before Russian starts' );
gml_db_assert( count( GML_Fairness_Worker::$languages ) === 2, 'fairness keeps exactly one provider batch per worker run' );
update_option( 'gml_languages', [ [ 'code' => 'es', 'enabled' => true, 'paused' => true ], [ 'code' => 'ru', 'enabled' => true ] ] );
$worker->process_batch();
gml_db_assert( GML_Fairness_Worker::$languages === [ 'es', 'ru', 'ru' ], 'rotation still respects language pauses and wraps to available work' );
update_option( 'gml_translation_paused', true );
$worker->process_batch();
gml_db_assert( count( GML_Fairness_Worker::$languages ) === 3, 'global pause prevents further batches' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'fairness tests use no real AI requests' );
$wpdb->query( "DELETE FROM $table WHERE id IN (" . implode( ',', $ids ) . ')' );
delete_option( 'gml_translation_retry_sample_ids' );
