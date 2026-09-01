<?php
/** Both products must use the same four provider transports. */
require __DIR__ . '/bootstrap.php';

$expected = [
    'gemini'  => [ 'host' => 'generativelanguage.googleapis.com', 'path' => ':generateContent' ],
    'deepseek'=> [ 'host' => 'api.deepseek.com', 'path' => '/v1/chat/completions' ],
    'qwen'    => [ 'host' => 'dashscope.aliyuncs.com', 'path' => '/compatible-mode/v1/chat/completions' ],
    'openai'  => [ 'host' => 'api.openai.com', 'path' => '/v1/chat/completions' ],
];

foreach ( $expected as $engine => $endpoint ) {
    $captured = [];
    $mock = static function( $pre, $args, $url ) use ( &$captured, $engine ) {
        $captured = [ 'url' => $url, 'headers' => $args['headers'] ?? [], 'body' => json_decode( $args['body'] ?? '', true ) ];
        $body = $engine === 'gemini'
            ? [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'OK' ] ] ] ] ] ]
            : [ 'choices' => [ [ 'message' => [ 'content' => 'OK' ] ] ] ];
        return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( $body ) ];
    };
    add_filter( 'pre_http_request', $mock, 99, 3 );
    $client = new GML_Gemini_API( [ 'engine' => $engine, 'api_key' => 'provider-adapter-test-key' ] );
    $result = $client->test_connection();
    remove_filter( 'pre_http_request', $mock, 99 );

    gml_db_assert( ! empty( $result['valid'] ), $engine . ' adapter accepts a valid mocked response' );
    gml_db_assert( wp_parse_url( $captured['url'], PHP_URL_HOST ) === $endpoint['host'] && strpos( $captured['url'], $endpoint['path'] ) !== false, $engine . ' adapter uses the expected official endpoint' );
    if ( $engine === 'gemini' ) {
        gml_db_assert( ( $captured['headers']['x-goog-api-key'] ?? '' ) === 'provider-adapter-test-key', 'Gemini uses the dedicated API key header' );
    } else {
        gml_db_assert( ( $captured['headers']['Authorization'] ?? '' ) === 'Bearer provider-adapter-test-key', $engine . ' uses Bearer authentication' );
    }
}
echo "OK synchronized translation provider adapters\n";
