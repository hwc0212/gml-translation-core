<?php
require __DIR__ . '/bootstrap.php';
$body = [];
$reply = [];
$mock = static function( $pre, $args ) use ( &$body, &$reply ) {
    $body = json_decode( $args['body'], true );
    return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( $reply ) ];
};
add_filter( 'pre_http_request', $mock, 99, 2 );
$client = new GML_Gemini_API( [ 'engine' => 'gemini', 'api_key' => 'synthetic-gemini-response-key' ] );
$reply = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'thought' => true, 'text' => 'Private reasoning must never become a translation.' ], [ 'text' => 'Hel' ], [ 'text' => 'lo' ] ] ], 'finishReason' => 'STOP' ] ] ];
$reply['promptFeedback'] = [ 'blockReason' => 'BLOCK_REASON_UNSPECIFIED' ];
$test = $client->test_connection();
gml_db_assert( $test['valid'] && $GLOBALS['gml_test_http_calls'] === 1, 'connection test accepts a final answer in later parts with one request' );
gml_db_assert( $body['generationConfig']['maxOutputTokens'] >= 1024 && $body['generationConfig']['maxOutputTokens'] <= 4096, 'connection test has a bounded budget beyond the old 20-token limit' );
$result = $client->generate( [ 'prompt' => 'Translate hello.' ] );
gml_db_assert( $result['ok'] && $result['text'] === 'Hello', 'Gemini parser concatenates final text parts and excludes thought text' );

$cases = [
    [ [ 'candidates' => [ [ 'finishReason' => 'MAX_TOKENS', 'content' => [ 'parts' => [ [ 'text' => 'incomplete' ] ] ] ] ], 'usageMetadata' => [ 'thoughtsTokenCount' => 20, 'candidatesTokenCount' => 0 ] ], 'output_limit', 'MAX_TOKENS' ],
    [ [ 'candidates' => [ [ 'finishReason' => 'SAFETY', 'content' => [ 'parts' => [ [ 'text' => 'blocked partial' ] ] ] ] ] ], 'content_blocked', 'SAFETY' ],
    [ [ 'promptFeedback' => [ 'blockReason' => 'PROHIBITED_CONTENT' ] ], 'content_blocked', 'PROHIBITED_CONTENT' ],
    [ [ 'candidates' => [ [ 'finishReason' => 'STOP', 'content' => [ 'parts' => [ [ 'thought' => true, 'text' => 'not an answer' ] ] ] ] ] ], 'empty_response', 'STOP' ],
    [ [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => '   ' ] ] ] ] ] ], 'empty_response', 'UNKNOWN' ],
    [ [ 'error' => [ 'message' => 'opaque secret must never be displayed' ] ], 'empty_response', 'UNKNOWN' ],
];
foreach ( $cases as [ $reply, $code, $reason ] ) {
    $result = $client->generate( [ 'prompt' => 'test', 'retries' => 0 ] );
    gml_db_assert( ! $result['ok'] && $result['error']['code'] === $code, 'response classification: ' . $code . '/' . $reason );
    gml_db_assert( strpos( $result['error']['message'], $reason ) !== false && strpos( $result['error']['message'], 'opaque secret' ) === false, 'diagnostics contain safe reason codes, not response text' );
}
$reply = $cases[0][0];
$before = $GLOBALS['gml_test_http_calls'];
$test = $client->test_connection();
gml_db_assert( ! $test['valid'] && $GLOBALS['gml_test_http_calls'] === $before + 1, 'truncated test is not accepted or automatically retried' );
gml_db_assert( strpos( $test['message'], 'thoughts=20' ) !== false, 'empty-response diagnostics explain consumed thinking tokens when reported' );
gml_db_assert( ! GML_Queue_Processor::is_provider_wide_failure( 'Prompt blocked: SAFETY', $client ), 'content-specific refusal is not an authentication failure' );

if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'synthetic-gemini-response-key' ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
    $seo = new GML_SEO_AI_Client();
    $reply = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'thought' => true, 'text' => 'reasoning' ], [ 'text' => 'Final' ], [ 'text' => ' answer' ] ] ], 'finishReason' => 'STOP' ] ] ];
    gml_db_assert( $seo->call( 'test' ) === 'Final answer', 'SEO and translation use the same Gemini final-answer parser' );
    $reply = $cases[0][0];
    $error = $seo->call( 'test' );
    gml_db_assert( is_wp_error( $error ) && $error->get_error_code() === 'output_limit', 'SEO rejects truncated output rather than storing partial metadata' );
}
remove_filter( 'pre_http_request', $mock, 99 );
