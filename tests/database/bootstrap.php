<?php
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( "Explicit disposable-database opt-in required.\n" );
require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
if ( strpos( DB_NAME, 'gml_regression' ) !== 0 || $wpdb->prefix !== 'test_' ) exit( "Unsafe test database.\n" );

function gml_db_assert( $condition, $label ) {
    if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $label );
    echo 'PASS: ' . $label . "\n";
}

$GLOBALS['gml_test_http_calls'] = 0;
add_filter( 'pre_http_request', static function() {
    $GLOBALS['gml_test_http_calls']++;
    return new WP_Error( 'test_network_blocked', 'No external requests during database tests.' );
} );

$product = realpath( getenv( 'GML_TEST_PRODUCT_DIR' ) ?: '' );
if ( ! $product ) exit( "Product directory required.\n" );
if ( is_file( $product . '/gml-translate.php' ) ) {
    if ( ! class_exists( 'GML_Translate', false ) ) require $product . '/gml-translate.php';
    function gml_test_product_init() { GML_Translate::get_instance()->init_components(); }
} elseif ( is_file( $product . '/gml-seo.php' ) ) {
    if ( ! class_exists( 'GML_SEO', false ) ) require $product . '/gml-seo.php';
    function gml_test_product_init() { GML_SEO_Translate_Bootstrap::init(); }
} else {
    exit( "Unknown product.\n" );
}
