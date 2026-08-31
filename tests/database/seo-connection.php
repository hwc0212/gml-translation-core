<?php
define( 'DOING_AJAX', true );
require __DIR__ . '/bootstrap.php';
if ( ! class_exists( 'GML_SEO' ) ) exit( "SKIP SEO connection controller on standalone host\n" );
wp_set_current_user( 1 );
update_option( 'gml_translation_paused', true );
wp_clear_scheduled_hook( 'gml_process_queue' );
update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'synthetic-controller-key' ] );
$cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
$cache->setAccessible( true );
$cache->setValue( null, null );
$_REQUEST = [ 'nonce' => wp_create_nonce( 'gml_seo_admin' ) ];
$response = [];
$body = [];
$status = 200;
$mock = static function( $pre, $args ) use ( &$response, &$body, &$status ) {
    $body = json_decode( $args['body'], true );
    return [ 'response' => [ 'code' => $status ], 'headers' => [], 'body' => wp_json_encode( $response ) ];
};
add_filter( 'pre_http_request', $mock, 99, 2 );
class GML_Connection_Done extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static function() { return static function() { throw new GML_Connection_Done(); }; } );
foreach ( [ 'empty', 'truncated', 'success', 'rate_limit' ] as $case ) {
    $status = $case === 'rate_limit' ? 429 : 200;
    $response = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'thought' => true, 'text' => 'hidden' ], [ 'text' => $case === 'empty' ? '' : 'OK' ] ] ], 'finishReason' => $case === 'truncated' ? 'MAX_TOKENS' : 'STOP' ] ] ];
    update_option( 'gml_translation_circuit_breaker', [ 'message' => 'test breaker' ] );
    $before = $GLOBALS['gml_test_http_calls'];
    ob_start();
    try { (new GML_SEO_Admin_Ajax())->test_ai_engine(); }
    catch ( GML_Connection_Done $e ) {}
    $result = json_decode( ob_get_clean(), true );
    gml_db_assert( is_array( $result ) && $result['success'] === ( $case === 'success' ), 'SEO AJAX test result: ' . $case );
    gml_db_assert( $GLOBALS['gml_test_http_calls'] === $before + 1 && $body['generationConfig']['maxOutputTokens'] === 1024, 'SEO test uses bounded output and exactly one HTTP request: ' . $case );
    gml_db_assert( GML_Queue_Processor::circuit_is_open() === ( $case !== 'success' ), 'only a usable test answer clears the breaker: ' . $case );
    gml_db_assert( get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( 'gml_process_queue' ), 'SEO connection test does not resume translation: ' . $case );
}
delete_option( 'gml_translation_circuit_breaker' );
remove_filter( 'pre_http_request', $mock, 99 );
$_REQUEST = [];
