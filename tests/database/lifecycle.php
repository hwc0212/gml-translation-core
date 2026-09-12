<?php
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
if ( ! defined( 'SAVEQUERIES' ) ) define( 'SAVEQUERIES', true );
require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
if ( strpos( DB_NAME, 'gml_regression' ) !== 0 || $wpdb->prefix !== 'test_' ) exit( 2 );
if ( ! is_blog_installed() ) throw new RuntimeException( 'WordPress test schema is not installed.' );
echo "MARKER wordpress_bootstrap_loaded\n";
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$slug = basename( realpath( getenv( 'GML_TEST_PRODUCT_DIR' ) ) );
if ( ! in_array( $slug, [ 'gml-seo', 'gml-translate' ], true ) ) exit( 2 );
$file = $slug . '/' . $slug . '.php';
$mode = $argv[1] ?? '';
function gml_lifecycle_assert( $value, $message ) {
    if ( ! $value ) throw new RuntimeException( $message );
    echo 'PASS: ' . $message . "\n";
}
if ( $mode === 'activate' ) {
    update_option( 'gml_multilingual_enabled', false );
    update_option( 'gml_translation_paused', true );
    // WordPress treats printed activation output as an error.
    $result = activate_plugin( $file );
    gml_lifecycle_assert( ! is_wp_error( $result ) && is_plugin_active( $file ), 'real WordPress plugin activation completes without output or fatal error' );
    echo "MARKER product_activated\n";
    update_option( 'gml_db_version', '2.4.0' );
} elseif ( $mode === 'frontend' ) {
    gml_lifecycle_assert( is_plugin_active( $file ) && class_exists( 'GML_Installer' ), 'plugin loaded through WordPress active_plugins lifecycle' );
    gml_lifecycle_assert( get_option( 'gml_db_version' ) === '2.4.0', 'active plugin visitor bootstrap leaves legacy database version untouched' );
    gml_lifecycle_assert( defined('SAVEQUERIES') && SAVEQUERIES, 'visitor SQL tracing remains enabled' );
    // A persistent-cache hit can execute no SQL, leaving wpdb::queries null.
    gml_lifecycle_assert( ! preg_grep( '/(?:ALTER |CREATE |DELETE .*gml_(?:queue|index))/i', array_column( (array)$wpdb->queries, 0 ) ), 'real visitor lifecycle contains no translation migration DDL or cleanup' );
} elseif ( $mode === 'deactivate' ) {
    deactivate_plugins( $file );
    gml_lifecycle_assert( ! is_plugin_active( $file ), 'plugin deactivates without deleting translations' );
}
