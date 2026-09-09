<?php
/** Offline reproduction of a reasoning-heavy five-item translation truncation. */
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
gml_db_assert( GML_Installer::activate() === true, 'provider regression schema is available' );

$requests = [];
$mock = static function( $pre, $args, $url ) use ( &$requests ) {
    $body = json_decode( $args['body'], true );
    $requests[] = $body;
    $config = $body['generationConfig'] ?? [];
    $complete = ( $config['maxOutputTokens'] ?? 0 ) >= 2048
        && ( $config['thinkingConfig']['thinkingLevel'] ?? '' ) === 'minimal';
    return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( [
        'candidates' => [ [ 'finishReason' => $complete ? 'STOP' : 'MAX_TOKENS',
            'content' => [ 'parts' => [ [ 'text' => $complete ? "[1] Pumpe\n[2] Ventil\n[3] Rohr\n[4] Platte\n[5] Kit" : '[1] Pum' ] ] ] ] ],
        'usageMetadata' => [ 'promptTokenCount' => 206, 'thoughtsTokenCount' => 983, 'candidatesTokenCount' => 37, 'totalTokenCount' => 1226 ],
    ] ) ];
};
add_filter( 'pre_http_request', $mock, 99, 3 );
$client = new GML_Gemini_API( [ 'engine' => 'gemini', 'model' => 'gemini-3.6-flash', 'api_key' => 'local-budget-fixture' ] );
$result = [];
try { $result = $client->translate_batch( [ 'Pump', 'Valve', 'Tube', 'Plate', 'Kit' ], 'en', 'de', 'seo_title' ); }
catch ( Throwable $e ) { echo 'DIAGNOSTIC ' . $e->getMessage() . "\n"; }
remove_filter( 'pre_http_request', $mock, 99 );
echo 'REQUEST_BUDGET ' . ( $requests[0]['generationConfig']['maxOutputTokens'] ?? 0 ) . "\n";
gml_db_assert( count( $result ) === 5, 'five-item reasoning-heavy batch returns all five validated items' );
echo "OK provider budget\n";

function budget_fixture( $engine, $mode, array $texts, $type = 'text' ) {
    $requests = [];
    $mock = static function( $pre, $args, $url ) use ( &$requests, $engine, $mode ) {
        $body = json_decode( $args['body'], true );
        $requests[] = $body;
        $prompt = $engine === 'gemini' ? $body['contents'][0]['parts'][0]['text'] : end( $body['messages'] )['content'];
        preg_match_all( '/^\[\d+\] /m', $prompt, $matches );
        $count = max( 1, count( $matches[0] ) );
        $limit = $mode === 'always_limit' || ( in_array( $mode, [ 'raise', 'limit_partial', 'limit_timeout' ], true ) && count( $requests ) === 1 )
            || ( $mode === 'split' && $count > 3 ) || ( $mode === 'single' && $count > 1 );
        $output = $count === 1 ? 'Pumpe' : implode( "\n", array_map( static function( $i ) { return '[' . $i . '] Pumpe'; }, range( 1, $count ) ) );
        if ( $mode === 'partial' ) $output = '[1] Pumpe';
        if ( $mode === 'limit_partial' && count( $requests ) > 1 ) $output = '[1] Pumpe';
        if ( $mode === 'duplicate' ) $output = "[1] Pumpe\n[1] Rohr\n[2] Ventil";
        if ( $mode === 'pollution' ) $output = 'WordPress Gemini OpenAI';
        if ( $mode === 'preserve' ) $output = $prompt;
        if ( $mode === 'format_order' ) $output = 'Anzahl %d Name %s';
        if ( $mode === 'format_precision' ) $output = 'Wert %0.2f';
        if ( $mode === 'format_numbered' ) $output = 'Anzahl %2$d Name %1$s %%';
        if ( $mode === 'limit_timeout' && count( $requests ) > 1 ) return new WP_Error( 'timeout', 'fixture timeout' );
        if ( $mode === 'timeout' ) return new WP_Error( 'timeout', 'fixture timeout' );
        if ( $mode === 'malformed' ) return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{' ];
        $data = $engine === 'gemini'
            ? [ 'candidates' => [ [ 'finishReason' => $limit ? 'MAX_TOKENS' : 'STOP', 'content' => [ 'parts' => [ [ 'text' => $output ] ] ] ] ],
                'usageMetadata' => [ 'promptTokenCount' => 206, 'thoughtsTokenCount' => 983, 'candidatesTokenCount' => 37, 'totalTokenCount' => 1226 ] ]
            : [ 'choices' => [ [ 'finish_reason' => $limit ? 'length' : 'stop', 'message' => [ 'content' => $output ] ] ],
                'usage' => [ 'prompt_tokens' => 100, 'completion_tokens' => 50, 'completion_tokens_details' => [ 'reasoning_tokens' => 0 ], 'total_tokens' => 150 ] ];
        return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( $data ) ];
    };
    add_filter( 'pre_http_request', $mock, 99, 3 );
    $client = new GML_Gemini_API( [ 'engine' => $engine, 'model' => $engine === 'gemini' ? 'gemini-3.6-flash' : 'deepseek-v4-flash', 'api_key' => 'fixture-not-a-real-key' ] );
    $result = null;
    try { $result = $client->translate_batch( $texts, 'en', 'ru', $type ); } catch ( Throwable $e ) {}
    remove_filter( 'pre_http_request', $mock, 99 );
    return [ $result, $requests, $client->get_last_error(), $client->get_request_metrics() ];
}

function budget_tm_digest() {
    global $wpdb;
    $hash = hash_init( 'sha256' );
    $id = 0;
    do {
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gml_index WHERE id>%d ORDER BY id LIMIT 500", $id ), ARRAY_A );
        if ( $wpdb->last_error ) throw new RuntimeException( 'TM snapshot query failed.' );
        foreach ( $rows as $row ) { hash_update( $hash, wp_json_encode( $row ) . "\n" ); $id = (int) $row['id']; }
    } while ( $rows );
    return hash_final( $hash );
}
$tm_before = budget_tm_digest();
foreach ( [ 'gemini', 'deepseek' ] as $engine ) {
    foreach ( [ 'normal', 'raise', 'split' ] as $mode ) {
        list( $result, $requests, $error, $metrics ) = budget_fixture( $engine, $mode, [ 'Pump', 'Valve', 'Tube', 'Plate', 'Kit' ] );
        gml_db_assert( count( $result ?? [] ) === 5, "$engine $mode returns exactly five items" );
        $budgets = array_map( static function( $body ) { return $body['generationConfig']['maxOutputTokens'] ?? $body['max_tokens']; }, $requests );
        if ( $mode !== 'normal' ) gml_db_assert( $budgets[1] > $budgets[0], "$engine increases budget on first truncation" );
        if ( $mode === 'split' ) gml_db_assert( count( $requests ) === 4, "$engine splits after second truncation" );
        gml_db_assert( count( $requests ) <= 12 && array_sum( $budgets ) <= 32768, "$engine recovery has call and requested-token caps" );
        gml_db_assert( count( $metrics ) === count( $requests ) && $metrics[0]['total_tokens'] > 0, "$engine reports usage for failed and successful attempts" );
        if ( $engine === 'deepseek' ) gml_db_assert( $requests[0]['thinking']['type'] === 'disabled' && $metrics[0]['reasoning_tokens'] === 0, 'DeepSeek explicit non-thinking path and usage accounting' );
    }
    list( $result, $requests ) = budget_fixture( $engine, 'single', [ 'Pump', 'Valve' ] );
    gml_db_assert( count( $result ?? [] ) === 2 && count( $requests ) === 4, "$engine final single-item fallback is bounded" );
    foreach ( [ 'always_limit', 'malformed', 'partial', 'duplicate', 'timeout', 'limit_partial', 'limit_timeout' ] as $mode ) {
        list( $result, $requests, $error ) = budget_fixture( $engine, $mode, [ 'Pump', 'Valve' ] );
        gml_db_assert( $result === null, "$engine $mode never returns partial items" );
        if ( $mode === 'always_limit' ) gml_db_assert( $error['code'] === 'output_limit' && count( $requests ) <= 4, "$engine exhausted output is explicit, not repeated forever" );
        if ( $mode === 'timeout' ) gml_db_assert( count( $requests ) === 1 && $error['retryable'], "$engine timeout delegated to queue backoff, no immediate retry" );
        if ( strpos( $mode, 'limit_' ) === 0 ) gml_db_assert( count( $requests ) === 2 && $error['code'] === 'output_limit' && ! $error['retryable'], "$engine recovery terminal status survives changed error category" );
    }
    list( $result ) = budget_fixture( $engine, 'preserve', [ 'Dimensions 90*45*30mm, {{name}}, %1$s and https://example.test/a' ] );
    gml_db_assert( count( $result ?? [] ) === 1, "$engine preserves dimensions, placeholders and links" );
    list( $result, , $error ) = budget_fixture( $engine, 'normal', [ 'Dimensions 90*45*30mm {{name}}' ] );
    gml_db_assert( $result === null && $error['code'] === 'protected_term', "$engine changed structural tokens rejected" );
    list( $result, , $error ) = budget_fixture( $engine, 'pollution', [ 'Pump' ] );
    gml_db_assert( $result === null && $error['code'] === 'translation_contamination', "$engine prompt leakage rejected" );
    list( $result, $requests ) = budget_fixture( $engine, 'preserve', [ str_repeat( 'Long source ', 900 ) ] );
    $budget = $requests[0]['generationConfig']['maxOutputTokens'] ?? $requests[0]['max_tokens'];
    gml_db_assert( count( $result ?? [] ) === 1 && $budget === 8192, "$engine long source uses capped dynamic budget" );
    foreach ( [ 'format_order' => 'Name %s count %d', 'format_precision' => 'Value %0.3f' ] as $mode => $source ) {
        list( $result, , $error ) = budget_fixture( $engine, $mode, [ $source ] );
        gml_db_assert( $result === null && $error['code'] === 'protected_term', "$engine rejects changed format order or precision" );
    }
    list( $result ) = budget_fixture( $engine, 'format_numbered', [ 'Name %1$s count %2$d %%' ] );
    gml_db_assert( count( $result ?? [] ) === 1, "$engine allows numbered argument reordering and literal percent" );
}
gml_db_assert( budget_tm_digest() === $tm_before, 'all provider trials, including truncation, leave TM byte-for-byte unchanged' );
gml_db_assert( GML_Translation_Budget::gemini_thinking( 'gemini-unknown' ) === [], 'unknown Gemini model gets no invented thinking setting' );
gml_db_assert( GML_Translation_Budget::gemini_thinking( 'gemini-3.8-flash' ) === [ 'thinkingLevel' => 'low' ], 'Gemini model without minimal uses documented low' );
$before = $GLOBALS['gml_test_http_calls'];
$client = new GML_Gemini_API( [ 'api_key' => '' ] );
try { $client->translate( 'Pump', 'en', 'de' ); } catch ( Throwable $e ) {}
gml_db_assert( $GLOBALS['gml_test_http_calls'] === $before, 'missing key generates no HTTP request' );
echo "OK provider budget regressions\n";
