<?php
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
if ( strpos( DB_NAME, 'gml_regression' ) !== 0 || $wpdb->prefix !== 'test_' ) exit( 2 );
$product = getenv( 'GML_TEST_PRODUCT_DIR' );
if ( basename( $product ) === 'gml-seo' ) {
    define( 'GML_TRANSLATION_HOST', 'standalone' );
    require $product . '/gml-seo.php';
    $loaded = new ReflectionProperty( GML_SEO_Translate_Bootstrap::class, 'loaded' );
    $loaded->setAccessible( true );
    if ( $loaded->getValue() ) throw new RuntimeException( 'A second embedded runtime loaded after standalone in the same request.' );
    echo "PASS: SEO does not load a second runtime when standalone was already loaded in the same request\n";
    exit( 0 );
}
if ( basename( $product ) !== 'gml-translate' ) exit( 2 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// Emulate a host already loaded before WordPress activation sandboxing.
define( 'GML_TRANSLATION_HOST', 'gml-seo' );
define( 'GML_VERSION', 'embedded-test' );
define( 'GML_PLUGIN_DIR', '/embedded-host-must-not-be-overwritten/' );
define( 'GML_PLUGIN_URL', 'http://example.test/embedded/' );
define( 'GML_PLUGIN_FILE', '/embedded-host.php' );
$before = $wpdb->get_results( "CHECKSUM TABLE {$wpdb->prefix}gml_queue, {$wpdb->prefix}gml_index", ARRAY_A );
class GML_Test_Activation_Blocked extends RuntimeException {}
add_filter( 'wp_die_handler', static function() {
    return static function( $message ) { throw new GML_Test_Activation_Blocked( $message ); };
} );
set_error_handler( static function( $code, $message ) { throw new RuntimeException( $message ); } );
$blocked = false;
try { activate_plugin( 'gml-translate/gml-translate.php' ); }
catch ( GML_Test_Activation_Blocked $error ) { $blocked = strpos( $error->getMessage(), 'already providing multilingual translation' ) !== false; }
finally { restore_error_handler(); if ( ob_get_level() ) ob_end_clean(); }
if ( ! $blocked || is_plugin_active( 'gml-translate/gml-translate.php' ) ) throw new RuntimeException( 'Conflicting activation was not safely blocked.' );
if ( GML_PLUGIN_DIR !== '/embedded-host-must-not-be-overwritten/' || $before !== $wpdb->get_results( "CHECKSUM TABLE {$wpdb->prefix}gml_queue, {$wpdb->prefix}gml_index", ARRAY_A ) ) throw new RuntimeException( 'Conflicting activation modified host or translation data.' );
echo "PASS: embedded-host activation conflict returns a clear message without constant redeclaration, runtime duplication or data changes\n";
