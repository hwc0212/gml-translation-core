<?php
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
require __DIR__ . '/bootstrap.php';

gml_test_product_init();
gml_db_assert( class_exists( 'GML_Translation_Uninstaller' ), 'shared translation uninstaller is available in the product build' );

$index = $wpdb->prefix . 'gml_index';
$queue = $wpdb->prefix . 'gml_queue';

update_option( 'gml_source_lang', 'en', false );
update_option( 'gml_languages', [ [ 'code' => 'es' ] ], false );
update_option( 'gml_translate_uninstall_delete_data', 0, false );
update_option( 'gml_seo_uninstall_fixture', 'keep', false );
update_option( 'gml_indexnow_key', 'keep', false );
set_transient( 'gml_page_uninstall_fixture', 'cached', HOUR_IN_SECONDS );
wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'gml_process_queue', [ 'fixture' ] );

$uploads = wp_upload_dir();
$cache   = trailingslashit( $uploads['basedir'] ) . 'gml-cache';
wp_mkdir_p( $cache );
file_put_contents( $cache . '/fixture.html', 'cached' );

GML_Translation_Uninstaller::cleanup_runtime();

gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) === $index, 'runtime cleanup preserves the translation memory table' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) === $queue, 'runtime cleanup preserves the translation queue table' );
gml_db_assert( get_option( 'gml_source_lang' ) === 'en', 'runtime cleanup preserves translation settings' );
gml_db_assert( wp_next_scheduled( 'gml_process_queue', [ 'fixture' ] ) === false, 'runtime cleanup removes scheduled jobs with arguments' );
gml_db_assert( get_transient( 'gml_page_uninstall_fixture' ) === false, 'runtime cleanup removes rendered page cache' );
gml_db_assert( ! file_exists( $cache ), 'runtime cleanup removes the dedicated uploads cache directory' );

update_option( 'gml_translate_uninstall_delete_data', 1, false );
GML_Translation_Uninstaller::delete_site_data();

gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) !== $index, 'complete removal drops the translation memory table' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) !== $queue, 'complete removal drops the translation queue table' );
gml_db_assert( get_option( 'gml_source_lang', false ) === false, 'complete removal deletes translation settings' );
gml_db_assert( get_option( 'gml_translate_uninstall_delete_data', false ) === false, 'complete removal deletes its own retention preference' );
gml_db_assert( get_option( 'gml_seo_uninstall_fixture' ) === 'keep', 'translation cleanup preserves GML SEO options' );
gml_db_assert( get_option( 'gml_indexnow_key' ) === 'keep', 'translation cleanup preserves the SEO IndexNow key' );

delete_option( 'gml_seo_uninstall_fixture' );
delete_option( 'gml_indexnow_key' );
