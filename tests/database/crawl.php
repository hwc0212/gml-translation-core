<?php
define( 'DOING_AJAX', true );
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
GML_Gemini_API::save_api_key( 'disposable-test-key-not-used', 'gemini' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'module_ai_translation_enabled' => 1, 'engine' => 'gemini', 'gemini_key' => 'disposable-test-key-not-used' ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
$case = $argv[1] ?? 'resume';
update_option( 'gml_translation_paused', true );
update_option( 'gml_crawl_running', false );
update_option( 'gml_crawl_offset', 12 );
delete_option( 'gml_translation_retry_sample_ids' );
delete_option( 'gml_translation_circuit_breaker' );
wp_clear_scheduled_hook( 'gml_crawl_content' );
wp_clear_scheduled_hook( 'gml_process_queue' );
update_option( 'gml_languages', [ [ 'code' => 'de', 'enabled' => true, 'paused' => true ] ] );
if ( $case === 'breaker' ) update_option( 'gml_translation_circuit_breaker', [ 'reason' => 'API quota exhausted' ] );
if ( $case === 'sample' ) update_option( 'gml_translation_retry_sample_ids', [ 1, 2 ] );
if ( $case === 'schedule' ) add_filter( 'pre_schedule_event', '__return_false' );
if ( $case === 'queue-schedule' ) add_filter( 'pre_schedule_event', static function( $pre, $event ) {
    return $event->hook === 'gml_process_queue' ? false : $pre;
}, 10, 2 );
if ( $case === 'no-key' ) {
    delete_option( 'gml_api_key_encrypted' );
    if ( isset( $cache ) ) { update_option( 'gml_seo', [] ); $cache->setValue( null, null ); }
}
if ( $case === 'unauthorized' ) wp_set_current_user( 0 );
$_POST['crawl_action'] = 'start';
$_REQUEST['nonce'] = wp_create_nonce( 'gml_editor_nonce' );
if ( $case === 'nonce' ) $_REQUEST['nonce'] = 'invalid';
class GML_Test_Json_Done extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static function() {
    return static function( $message = '' ) { echo $message; throw new GML_Test_Json_Done(); };
} );
ob_start();
try { (new GML_Translation_Editor())->ajax_crawl_action(); }
catch ( GML_Test_Json_Done $done ) {}
$raw = ob_get_clean();
$response = json_decode( $raw, true );
if ( in_array( $case, [ 'resume', 'queue-schedule', 'sample' ], true ) ) {
    gml_db_assert( ! empty( $response['success'] ), 'real AJAX starts an independent scan from an ordinary pause' );
    gml_db_assert( get_option( 'gml_translation_paused' ) && get_option( 'gml_crawl_running' ), 'successful scan start leaves translation paused' );
    gml_db_assert( wp_next_scheduled( 'gml_crawl_content' ) && ! wp_next_scheduled( 'gml_process_queue' ), 'scan schedules only its own task, even when the queue scheduler is unavailable' );
} else {
    gml_db_assert( empty( $response['success'] ), $case . ' blocks full crawl' );
    gml_db_assert( get_option( 'gml_translation_paused' ) && ! get_option( 'gml_crawl_running' ) && (int) get_option( 'gml_crawl_offset' ) === 12, $case . ' preserves pause and progress state' );
    gml_db_assert( ! wp_next_scheduled( 'gml_crawl_content' ), $case . ' does not schedule a crawl' );
    $messages = [ 'schedule' => 'could not schedule', 'queue-schedule' => 'could not schedule the translation worker', 'breaker' => 'safety-paused', 'sample' => 'sample is still running' ];
    if ( isset( $messages[$case] ) ) gml_db_assert( strpos( $response['data'] ?? '', $messages[$case] ) !== false, $case . ' reports the actual blocking reason' );
}
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'start action makes zero paid API requests' );
