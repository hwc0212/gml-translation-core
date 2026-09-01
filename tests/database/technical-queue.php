<?php
/** Pure specifications already in the queue must complete without provider use. */
require __DIR__ . '/bootstrap.php';

update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'it', 'enabled' => true, 'paused' => false ] ] );
GML_Gemini_API::save_api_key( 'technical-queue-key-not-sent', 'gemini' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'module_ai_translation_enabled' => 1, 'engine' => 'gemini', 'gemini_key' => 'technical-queue-key-not-sent' ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
GML_Queue_Processor::clear_circuit_breaker();
update_option( 'gml_translation_paused', false );
delete_option( 'gml_translation_retry_sample_ids' );

$queue = $wpdb->prefix . 'gml_queue';
$index = $wpdb->prefix . 'gml_index';
$source = '<40°C';
$hash = md5( $source );
$wpdb->delete( $queue, [ 'source_hash' => $hash, 'target_lang' => 'it' ] );
$wpdb->delete( $index, [ 'source_hash' => $hash, 'target_lang' => 'it' ] );
$wpdb->insert( $queue, [
    'source_hash' => $hash, 'source_text' => $source, 'source_lang' => 'en', 'target_lang' => 'it',
    'context_type' => 'text', 'priority' => 5, 'status' => 'pending', 'attempts' => 0, 'created_at' => current_time( 'mysql' ),
] );
$before = $GLOBALS['gml_test_http_calls'];
( new GML_Queue_Processor() )->process_batch();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, error_message FROM $queue WHERE source_hash = %s AND target_lang = 'it'", $hash ) );
$saved = $wpdb->get_var( $wpdb->prepare( "SELECT translated_text FROM $index WHERE source_hash = %s AND target_lang = 'it'", $hash ) );
gml_db_assert( $row && $row->status === 'completed' && empty( $row->error_message ), 'technical-only queue row completes locally' );
gml_db_assert( $saved === $source, 'technical-only value is preserved byte-for-byte in translation memory' );
GML_Translator::invalidate_cache( 'en', 'it' );
$dictionary = ( new GML_Translator() )->get_dictionary( 'it' );
gml_db_assert( ( $dictionary[ $hash ] ?? '' ) === $source, 'technical comparison survives translation-memory readback' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === $before, 'technical-only queue row uses zero provider requests' );
$wpdb->delete( $queue, [ 'source_hash' => $hash, 'target_lang' => 'it' ] );
$wpdb->delete( $index, [ 'source_hash' => $hash, 'target_lang' => 'it' ] );
echo "OK local technical queue bypass\n";
