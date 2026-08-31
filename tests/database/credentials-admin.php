<?php
/** Exercise the real standalone settings handlers without external requests. */
require __DIR__ . '/bootstrap.php';
if ( defined( 'GML_SEO_VER' ) ) exit( "SKIP standalone credential settings on SEO host\n" );
require_once ABSPATH . 'wp-admin/includes/template.php';
wp_set_current_user( 1 );
$admin = (new ReflectionClass( 'GML_Admin_Settings' ))->newInstanceWithoutConstructor();
$render = new ReflectionMethod( $admin, 'render_settings_tab' );
$render->setAccessible( true );
update_option( 'gml_languages', [] );
update_option( 'gml_translation_engine', 'gemini' );
update_option( 'gml_translation_paused', true );
update_option( 'gml_translation_circuit_breaker', [ 'opened_at' => time(), 'reason' => 'fixture' ] );
delete_option( 'gml_api_key_encrypted' );
GML_Translation_State::set_multilingual_enabled( true );
GML_Translation_State::set_ai_translation_enabled( false );
$base = [ 'gml_save_settings' => '1', 'gml_translation_engine' => 'gemini', 'gml_source_lang' => 'en', 'gml_multilingual_enabled' => '1', 'gml_ai_translation_enabled' => '1' ];
$key = 'local-ui-test-opaque.key-123456789';
$_POST = $base + [ 'gml_api_key' => wp_slash( $key ) ];
$_REQUEST = [ 'gml_settings_nonce' => wp_create_nonce( 'gml_main_settings' ) ];
$GLOBALS['wp_settings_errors'] = [];
ob_start();
$render->invoke( $admin );
$html = ob_get_clean();
gml_db_assert( GML_Gemini_API::decrypt_key( get_option( 'gml_api_key_encrypted' ) ) === $key, 'admin save persists the entered key exactly' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'Save Changes does not perform a hidden AI request' );
gml_db_assert( strpos( $html, 'read back successfully' ) !== false, 'save success describes local readback, not provider verification' );
gml_db_assert( strpos( $html, $key ) === false && strpos( $html, 'value="********************************"' ) === false, 'saved key and synthetic password masks are not reflected into HTML' );
gml_db_assert( get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( GML_Queue_Processor::CRON_HOOK ), 'saving and enabling AI never resumes the queue' );
gml_db_assert( get_option( 'gml_translation_circuit_breaker' ) !== false, 'local save cannot clear a provider safety breaker' );

$sent = '';
$mock = static function( $pre, $args ) use ( &$sent ) {
    $sent = $args['headers']['x-goog-api-key'] ?? '';
    return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{"candidates":[{"content":{"parts":[{"text":"Hallo"}]}}]}' ];
};
add_filter( 'pre_http_request', $mock, 99, 2 );
$_POST = [ 'gml_test_connection' => '1' ];
$_REQUEST = [ 'gml_test_connection_nonce' => wp_create_nonce( 'gml_test_connection' ) ];
ob_start();
$render->invoke( $admin );
$html = ob_get_clean();
remove_filter( 'pre_http_request', $mock, 99 );
gml_db_assert( $sent === $key && $GLOBALS['gml_test_http_calls'] === 1, 'subsequent Test Saved AI Connection uses the saved key in exactly one mocked request' );
gml_db_assert( strpos( $html, 'API key is valid' ) !== false, 'successful provider test has a distinct result' );
gml_db_assert( get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( GML_Queue_Processor::CRON_HOOK ), 'successful test cannot start queued translations' );

$old = get_option( 'gml_api_key_encrypted' );
$reject = static function( $value, $old ) { return $old; };
add_filter( 'pre_update_option_gml_api_key_encrypted', $reject, 10, 2 );
$_POST = $base + [ 'gml_api_key' => 'replacement-test-key' ];
$_REQUEST = [ 'gml_settings_nonce' => wp_create_nonce( 'gml_main_settings' ) ];
$GLOBALS['wp_settings_errors'] = [];
ob_start();
$render->invoke( $admin );
$html = ob_get_clean();
remove_filter( 'pre_update_option_gml_api_key_encrypted', $reject, 10 );
gml_db_assert( strpos( $html, 'API key save could not be verified' ) !== false, 'admin displays a failed persistence result' );
gml_db_assert( strpos( $html, 'notice-success' ) === false, 'failed key save cannot be followed by a generic success notice' );
gml_db_assert( get_option( 'gml_api_key_encrypted' ) === $old, 'failed admin save keeps old credentials' );

$_POST = $base + [ 'gml_api_key' => '' ];
$GLOBALS['wp_settings_errors'] = [];
ob_start();
$render->invoke( $admin );
ob_end_clean();
gml_db_assert( get_option( 'gml_api_key_encrypted' ) === $old, 'blank password field leaves stored credentials unchanged' );

update_option( 'gml_api_key_encrypted', 'corrupt-record' );
$_POST = [ 'gml_test_connection' => '1' ];
$_REQUEST = [ 'gml_test_connection_nonce' => wp_create_nonce( 'gml_test_connection' ) ];
ob_start();
$render->invoke( $admin );
$html = ob_get_clean();
gml_db_assert( strpos( $html, 'cannot be decrypted' ) !== false && strpos( $html, 'Saved key is unreadable' ) !== false, 'test result and system status agree on a local credential failure' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 1 && GML_Translation_State::multilingual_enabled(), 'bad key causes no network call and preserves the multilingual site' );

$die_handler = static function() { return static function() { throw new RuntimeException( 'expected-wp-die' ); }; };
add_filter( 'wp_die_handler', $die_handler );
$_POST = $base + [ 'gml_api_key' => 'must-not-be-saved' ];
$_REQUEST = [ 'gml_settings_nonce' => 'invalid-nonce' ];
foreach ( [ 'nonce', 'capability' ] as $scenario ) {
    if ( $scenario === 'capability' ) wp_set_current_user( 0 );
    ob_start();
    try {
        $admin->render_page();
        throw new RuntimeException( 'Expected permission rejection' );
    } catch ( RuntimeException $error ) {
        ob_end_clean();
        gml_db_assert( $error->getMessage() === 'expected-wp-die', $scenario . ' protects saved credentials' );
    }
}
remove_filter( 'wp_die_handler', $die_handler );
gml_db_assert( get_option( 'gml_api_key_encrypted' ) === 'corrupt-record', 'rejected admin requests cannot mutate credentials' );
$_POST = $_REQUEST = [];
delete_option( 'gml_api_key_encrypted' );
delete_option( 'gml_translation_circuit_breaker' );
echo "OK real admin Save / Test flow\n";
