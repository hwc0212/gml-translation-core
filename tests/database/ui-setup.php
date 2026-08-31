<?php
// Optional loopback-only browser fixture; never included in release ZIPs.
if ( PHP_SAPI !== 'cli' || getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
if ( DB_NAME !== 'gml_regression' || $wpdb->prefix !== 'test_' || WP_HOME !== 'http://127.0.0.1:8941' ) exit( 2 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$slug = $argv[1] ?? '';
if ( ! in_array( $slug, [ 'gml-seo', 'gml-translate' ], true ) ) exit( 2 );
deactivate_plugins( get_option( 'active_plugins', [] ) );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_api_key_encrypted', 'test-only-never-sent' );
update_option( 'gml_seo', [ 'module_ai_translation_enabled' => 1, 'engine' => 'gemini', 'gemini_key' => 'test-only-never-sent' ] );
update_option( 'gml_languages', [ [ 'code' => 'de', 'native_name' => 'Deutsch', 'country' => 'de', 'enabled' => true, 'paused' => true ] ] );
update_option( 'gml_translation_paused', true );
update_option( 'gml_crawl_running', false );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_retry_sample_ids' );
wp_clear_scheduled_hook( 'gml_process_queue' );
wp_clear_scheduled_hook( 'gml_crawl_content' );
wp_set_password( 'Gml-local-test-only-2026', 1 );
update_user_meta( 1, 'locale', 'en_US' );
$result = activate_plugin( $slug . '/' . $slug . '.php' );
if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
echo "Local UI ready: $slug\n";
