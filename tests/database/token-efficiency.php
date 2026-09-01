<?php
/** Repeated text and irrelevant terminology must not consume provider tokens. */
require __DIR__ . '/bootstrap.php';

$old_terms    = get_option( 'gml_protected_terms', [] );
$old_glossary = get_option( 'gml_glossary_rules', [] );
update_option( 'gml_protected_terms', [ 'GML', 'WordPress' ] );
GML_Glossary::save_rules( [
    [ 'source' => 'Pump', 'target' => 'Pumpe', 'lang' => 'de', 'enabled' => true ],
    [ 'source' => 'Valve', 'target' => 'Ventil', 'lang' => 'de', 'enabled' => true ],
] );

$captured = [];
$mock = static function( $pre, $args, $url ) use ( &$captured ) {
    $captured = [ 'url' => $url, 'body' => json_decode( $args['body'] ?? '', true ) ];
    return [
        'response' => [ 'code' => 200 ],
        'headers'  => [],
        'body'     => wp_json_encode( [
            'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => "[1] GML Pumpe\n[2] Einzigartig" ] ] ] ] ],
        ] ),
    ];
};
add_filter( 'pre_http_request', $mock, 99, 3 );
$before = $GLOBALS['gml_test_http_calls'];
$client = new GML_Gemini_API( [ 'engine' => 'gemini', 'api_key' => 'local-test-key-not-sent' ] );
$result = $client->translate_batch( [ 'GML Pump', 'GML Pump', 'Unique' ], 'en', 'de' );
remove_filter( 'pre_http_request', $mock, 99 );

gml_db_assert( $result === [ 'GML Pumpe', 'GML Pumpe', 'Einzigartig' ], 'duplicate batch input is translated once and expanded in original order' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === $before + 1, 'deduplicated batch uses one provider request' );
$prompt = $captured['body']['contents'][0]['parts'][0]['text'] ?? '';
$system = $captured['body']['systemInstruction']['parts'][0]['text'] ?? '';
gml_db_assert( substr_count( $prompt, 'GML Pump' ) === 1 && strpos( $prompt, '[3]' ) === false, 'provider prompt omits duplicate numbered input' );
gml_db_assert( strpos( $system, 'GML' ) !== false && strpos( $system, 'WordPress' ) === false, 'only protected terms present in this batch enter the prompt' );
gml_db_assert( strpos( $system, 'Pump' ) !== false && strpos( $system, 'Valve' ) === false, 'only glossary rules present in this batch enter the prompt' );
gml_db_assert( (int) ( $captured['body']['generationConfig']['maxOutputTokens'] ?? 0 ) < 4096, 'short translations use a bounded output allowance' );

update_option( 'gml_protected_terms', $old_terms );
update_option( 'gml_glossary_rules', $old_glossary );
echo "OK translation token efficiency\n";
