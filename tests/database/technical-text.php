<?php
require __DIR__ . '/bootstrap.php';
$parser = new GML_HTML_Parser();
$client = (new ReflectionClass( 'GML_Gemini_API' ))->newInstanceWithoutConstructor();
$clean = new ReflectionMethod( 'GML_Translation_AI_Client', 'clean_output' );
$clean->setAccessible( true );
foreach ( [ '<40°C', '&lt;40°C', '<70%', '90*45*30mm', '90 × 45 × 30 mm', '100kPa' ] as $specification ) {
    gml_db_assert( GML_Translation_Text::is_technical_only( $specification ), 'technical-only value bypasses AI: ' . $specification );
}
foreach ( [ 'Operating temperature <40°C', 'Dimensions 90*45*30mm', 'Save 20% today' ] as $sentence ) {
    gml_db_assert( ! GML_Translation_Text::is_technical_only( $sentence ), 'human-readable sentence still requires translation: ' . $sentence );
}
$technical = $parser->parse( '<html><body><p>&lt;40°C</p><p>90*45*30mm</p><p>Operating temperature &lt;40°C</p></body></html>' );
$technical_texts = array_column( $technical['nodes'], 'text' );
gml_db_assert( ! in_array( '<40°C', $technical_texts, true ) && ! in_array( '90*45*30mm', $technical_texts, true ), 'parser excludes pure specifications from the AI queue' );
gml_db_assert( in_array( 'Operating temperature <40°C', $technical_texts, true ), 'parser keeps explanatory specification text translatable' );
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
gml_db_assert( $clean->invoke( $client, 'Betriebstemperatur <40°C' ) === 'Betriebstemperatur <40°C', 'plain-text cleanup preserves a comparison specification inside a translated sentence' );
gml_db_assert( $clean->invoke( $client, '<strong>Safe</strong> <40°C' ) === 'Safe <40°C', 'plain-text cleanup removes markup while preserving a comparison specification' );
$parsed = $parser->parse( '<html><body><p>Dimensions</p></body></html>' );
$parsed['replacements'] = [ 'Dimensions' => '**Abmessungen 90*45*30mm**' ];
$result = $parser->rebuild( $parsed );
gml_db_assert( strpos( $result, '<p>Abmessungen 90*45*30mm</p>' ) !== false, 'Markdown bold wrapper can be removed without damaging the dimension' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'technical text rendering needs no AI request' );

// Raw comparison signs must survive the complete extraction/rebuild path.
foreach ( ['<500','< 500','&lt;500','&#60;500'] as $comparison ) {
    $raw='Water condition '.$comparison.' µS/cm at 15°C, ambient 20°C';
    $source=html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $target=str_replace('Water condition','Wasserbedingung',$source);
    $parsed=$parser->parse('<p>'.$raw.'</p><a href="/next/">Next</a>');
    gml_db_assert(in_array($source,array_column($parsed['nodes'],'text'),true),'raw comparison sentence extracted: '.$comparison);
    $parsed['replacements']=[$source=>$target];
    gml_db_assert(strpos($parser->rebuild($parsed),htmlspecialchars($target,ENT_QUOTES|ENT_HTML5,'UTF-8'))!==false,'raw comparison sentence rendered: '.$comparison);
}
$schema=['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'item'=>['name'=>'Synthetic equipment &amp;#038; parts','@id'=>'https://example.test/category/']]]];
$raw=json_encode($schema);
$parsed=$parser->parse('<script type="application/ld+json">'.$raw.'</script>');
gml_db_assert(in_array('Synthetic equipment & parts',array_column($parsed['nodes'],'text'),true),'breadcrumb name joins the existing text pipeline');
$parsed['replacements']=['Synthetic equipment & parts'=>'Synthetische Ausrüstung & Teile'];
$out=$parser->rebuild($parsed);
gml_db_assert(strpos($out,'Synthetische Ausrüstung')!==false,'breadcrumb label reuses supplied translation');
gml_db_assert(strpos($out,'https:\\/\\/example.test\\/category\\/')!==false,'parser does not change breadcrumb URLs or emit competing schema');
