<?php
require __DIR__ . '/bootstrap.php';
$parser = new GML_HTML_Parser();
$client = (new ReflectionClass( 'GML_Gemini_API' ))->newInstanceWithoutConstructor();
$clean = new ReflectionMethod( 'GML_Translation_AI_Client', 'clean_output' );
$clean->setAccessible( true );
foreach ( [ '90*45*30mm', '90 * 45 * 30 mm', '10**3', 'A__B__C', 'SKU_A*B', '45.5*30.2*10mm' ] as $specification ) {
    $source = 'Dimensions ' . $specification;
    $target = 'Abmessungen ' . $specification;
    gml_db_assert( $clean->invoke( $client, $target ) === $target, 'provider output preserves ' . $specification . ' before storage' );
    $html = '<html><head><title>' . $source . '</title><meta name="description" content="' . $source . '"></head><body><p>' . $source . '</p><img alt="' . $source . '" data-size="' . $specification . '"></body></html>';
    $parsed = $parser->parse( $html );
    $parsed['replacements'] = [ $source => $target ];
    $result = $parser->rebuild( $parsed );
    gml_db_assert( strpos( $result, '<p>' . $target . '</p>' ) !== false, 'visible technical text preserves ' . $specification );
    gml_db_assert( strpos( $result, '<title>' . $target . '</title>' ) !== false, 'title preserves ' . $specification );
    gml_db_assert( strpos( $result, 'content="' . $target . '"' ) !== false && strpos( $result, 'alt="' . $target . '"' ) !== false, 'metadata and alt preserve ' . $specification );
    gml_db_assert( strpos( $result, 'data-size="' . $specification . '"' ) !== false, 'technical attribute is untouched' );
}
$parsed = $parser->parse( '<html><body><p>Dimensions</p></body></html>' );
$parsed['replacements'] = [ 'Dimensions' => '**Abmessungen 90*45*30mm**' ];
$result = $parser->rebuild( $parsed );
gml_db_assert( strpos( $result, '<p>Abmessungen 90*45*30mm</p>' ) !== false, 'Markdown bold wrapper can be removed without damaging the dimension' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'technical text rendering needs no AI request' );
