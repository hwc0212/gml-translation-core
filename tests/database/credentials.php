<?php
/** Saved credentials must survive a round trip and never send ciphertext. */
require __DIR__ . '/bootstrap.php';

$key = 'local-test-opaque-credential.1234567890';
$old_options = get_option( 'gml_seo', [] );
delete_option( 'gml_api_key_encrypted' );
delete_option( 'gml_deepseek_api_key_encrypted' );
delete_option( 'gml_qwen_api_key_encrypted' );
delete_option( 'gml_openai_api_key_encrypted' );
update_option( 'gml_translation_engine', 'gemini' );
$iv = str_repeat( 'x', 16 );
$foreign = base64_encode( $iv . openssl_encrypt( $key, 'AES-256-CBC', 'different-site-salt', OPENSSL_RAW_DATA, $iv ) );
update_option( 'gml_api_key_encrypted', $foreign );
gml_db_assert( GML_Gemini_API::decrypt_key( $foreign ) === '', 'wrong-salt ciphertext is never returned as a credential' );

gml_db_assert( GML_Gemini_API::save_api_key( $key, 'gemini' ), 'credential can be encrypted and saved' );
$stored = get_option( 'gml_api_key_encrypted' );
gml_db_assert( $stored !== $key && GML_Gemini_API::decrypt_key( $stored ) === $key, 'saved opaque credential reads back exactly without a key-prefix restriction' );
$raw = base64_decode( $stored, true );
gml_db_assert( openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', wp_salt( 'auth' ), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) ) === $key, 'saved format remains readable by older releases' );

$reject_write = static function( $value, $old ) { return $old; };
add_filter( 'pre_update_option_gml_api_key_encrypted', $reject_write, 10, 2 );
gml_db_assert( ! GML_Gemini_API::save_api_key( 'replacement-local-test-key', 'gemini' ), 'failed option update is not reported as a successful save' );
gml_db_assert( get_option( 'gml_api_key_encrypted' ) === $stored, 'failed write preserves the previous credential' );
remove_filter( 'pre_update_option_gml_api_key_encrypted', $reject_write, 10 );

foreach ( [ '********', "key\r\nInjected: value", 'key with spaces', str_repeat( 'x', 513 ) ] as $invalid ) {
    gml_db_assert( ! GML_Gemini_API::save_api_key( $invalid, 'gemini' ), 'invalid credential input is rejected locally' );
}
foreach ( [ $foreign, substr( $stored, 0, -7 ), 'corrupt-record', base64_encode( str_repeat( 'x', 32 ) ) ] as $invalid ) {
    gml_db_assert( GML_Gemini_API::decrypt_key( $invalid ) === '', 'unreadable stored payload fails closed' );
}
foreach ( [ 'AIza' . str_repeat( 'a', 35 ), 'sk-' . str_repeat( 'b', 32 ) ] as $legacy ) {
    gml_db_assert( GML_Gemini_API::decrypt_key( $legacy ) === $legacy, 'historical plaintext provider key remains readable without rewriting it' );
}
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'credential storage and validation make no network requests' );

// The SEO adapter normally uses its own provider settings; exercise its legacy fallback too.
if ( defined( 'GML_SEO_VER' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => '' ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
update_option( 'gml_api_key_encrypted', $foreign );
$test = (new GML_Gemini_API())->test_connection();
gml_db_assert( ! $test['valid'] && strpos( $test['message'], 'decrypt' ) !== false, 'stored connection test explains local decryption failure' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'unreadable key is blocked before HTTP' );

GML_Gemini_API::save_api_key( $key, 'gemini' );
$sent_key = '';
$response = static function( $pre, $args, $url ) use ( &$sent_key ) {
    $sent_key = $args['headers']['x-goog-api-key'] ?? '';
    return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( [
        'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'Hallo' ] ] ] ] ],
    ] ) ];
};
add_filter( 'pre_http_request', $response, 99, 3 );
$test = (new GML_Gemini_API())->test_connection();
gml_db_assert( $test['valid'] && hash_equals( $key, $sent_key ), 'test uses the exact saved and decrypted key with mocked HTTP' );
remove_filter( 'pre_http_request', $response, 99 );

foreach ( [ 400, 200 ] as $status ) {
    $failure = static function() use ( $key, $status ) {
        return [ 'response' => [ 'code' => $status ], 'headers' => [], 'body' => wp_json_encode( [
            'error' => [ 'message' => 'Invalid credential: ' . $key ],
        ] ) ];
    };
    add_filter( 'pre_http_request', $failure, 99 );
    $before_calls = $GLOBALS['gml_test_http_calls'];
    $test = (new GML_Gemini_API())->test_connection();
    remove_filter( 'pre_http_request', $failure, 99 );
    gml_db_assert( ! $test['valid'] && strpos( $test['message'], 'API key is valid' ) === false, 'HTTP ' . $status . ' without usable output cannot report successful verification' );
    gml_db_assert( strpos( $test['message'], $key ) === false, 'provider errors never echo opaque credentials' );
    gml_db_assert( $GLOBALS['gml_test_http_calls'] === $before_calls + 1, 'connection test does not retry a failed response' );
}

if ( ! defined( 'GML_SEO_VER' ) ) {
    GML_Translation_State::set_multilingual_enabled( true );
    GML_Translation_State::set_ai_translation_enabled( true );
    delete_option( 'gml_api_key_encrypted' );
    GML_Gemini_API::save_api_key( 'local-test-deepseek-key', 'deepseek' );
    gml_db_assert( ! GML_Translation_State::has_api_key(), 'DeepSeek key cannot enable Gemini processing' );
    update_option( 'gml_translation_engine', 'deepseek' );
    gml_db_assert( GML_Translation_State::has_api_key(), 'selected provider uses its own readable key' );
    update_option( 'gml_translation_engine', 'gemini' );
    update_option( 'gml_api_key_encrypted', $foreign );
    gml_db_assert( ! GML_Translation_State::ai_available(), 'unreadable key prevents new AI work' );
    gml_db_assert( GML_Translation_State::multilingual_enabled(), 'invalid credentials never turn off existing language pages' );
    foreach ( [ 'qwen', 'openai' ] as $engine ) {
        $provider_key = 'local-test-' . $engine . '-opaque-key';
        gml_db_assert( GML_Gemini_API::save_api_key( $provider_key, $engine ), $engine . ' credential can be encrypted and saved' );
        update_option( 'gml_translation_engine', $engine );
        gml_db_assert( GML_Translation_State::has_api_key(), $engine . ' can be selected without reusing another provider key' );
        gml_db_assert( GML_Translation_Credentials::read( $engine ) === $provider_key, $engine . ' credential round trip is exact' );
    }
}
delete_option( 'gml_api_key_encrypted' );
delete_option( 'gml_deepseek_api_key_encrypted' );
delete_option( 'gml_qwen_api_key_encrypted' );
delete_option( 'gml_openai_api_key_encrypted' );
if ( defined( 'GML_SEO_VER' ) ) update_option( 'gml_seo', $old_options );
echo "OK credential lifecycle (synthetic keys, no external HTTP)\n";
