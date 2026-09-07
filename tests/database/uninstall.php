<?php
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
require __DIR__ . '/bootstrap.php';

gml_test_product_init();
gml_db_assert( class_exists( 'GML_Translation_Uninstaller' ), 'shared translation uninstaller is available in the product build' );

$index = $wpdb->prefix . 'gml_index';
$queue = $wpdb->prefix . 'gml_queue';
$manifest = $wpdb->prefix . 'gml_resource_manifests';
$relations = $wpdb->prefix . 'gml_resource_strings';
$readiness = $wpdb->prefix . 'gml_resource_readiness';
$review_versions = $wpdb->prefix . 'gml_resource_translation_versions';
$reviews = $wpdb->prefix . 'gml_resource_reviews';
$review_audit = $wpdb->prefix . 'gml_resource_review_audit';

update_option( 'gml_source_lang', 'en', false );
update_option( 'gml_languages', [ [ 'code' => 'es' ] ], false );
update_option( 'gml_translate_uninstall_delete_data', 0, false );
update_option( 'gml_seo_uninstall_fixture', 'keep', false );
update_option( 'gml_indexnow_key', 'keep', false );
$page_cache_generation = GML_Page_Cache::generation();
$page_cache_key = GML_Page_Cache::key( 'es', '/es/uninstall-fixture/' );
set_transient( $page_cache_key, 'cached', HOUR_IN_SECONDS );
wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'gml_process_queue', [ 'fixture' ] );

$uploads = wp_upload_dir();
$cache   = trailingslashit( $uploads['basedir'] ) . 'gml-cache';
wp_mkdir_p( $cache );
file_put_contents( $cache . '/fixture.html', 'cached' );

GML_Translation_Uninstaller::uninstall( 'gml_translate_uninstall_delete_data', false );

gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) === $index, 'default uninstall preserves the translation memory table' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) === $queue, 'default uninstall preserves the translation queue table' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $manifest ) ) === $manifest, 'default uninstall preserves shadow resource manifests' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations ) ) === $relations, 'default uninstall preserves resource-string relationships' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $readiness ) ) === $readiness, 'default uninstall preserves machine-readiness snapshots' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $review_versions ) ) === $review_versions, 'default uninstall preserves review translation generations' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reviews ) ) === $reviews, 'default uninstall preserves current review decisions' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $review_audit ) ) === $review_audit, 'default uninstall preserves review audit history' );
gml_db_assert( get_option( 'gml_source_lang' ) === 'en', 'default uninstall preserves translation settings' );
gml_db_assert( wp_next_scheduled( 'gml_process_queue', [ 'fixture' ] ) === false, 'default uninstall removes scheduled jobs with arguments' );
gml_db_assert( GML_Page_Cache::generation() > $page_cache_generation, 'default uninstall rotates the rendered-page cache namespace' );
$new_page_cache_key = GML_Page_Cache::key( 'es', '/es/uninstall-fixture/' );
gml_db_assert( $new_page_cache_key !== $page_cache_key && get_transient( $new_page_cache_key ) === false, 'default uninstall makes persistent rendered-page cache inaccessible' );
gml_db_assert( ! file_exists( $cache ), 'default uninstall removes the dedicated uploads cache directory' );

update_option( 'gml_translate_uninstall_delete_data', 1, false );
set_transient( 'gml_page_companion_fixture', 'protected', HOUR_IN_SECONDS );
wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'gml_process_queue', [ 'companion' ] );
GML_Translation_Uninstaller::uninstall( 'gml_translate_uninstall_delete_data', true );

gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) === $index, 'installed companion protects the translation memory table' );
gml_db_assert( get_option( 'gml_source_lang' ) === 'en', 'installed companion protects translation settings' );
gml_db_assert( wp_next_scheduled( 'gml_process_queue', [ 'companion' ] ) !== false, 'installed companion keeps the shared runtime scheduled' );
gml_db_assert( get_transient( 'gml_page_companion_fixture' ) === 'protected', 'installed companion keeps the shared rendered cache' );

GML_Translation_Uninstaller::uninstall( 'gml_translate_uninstall_delete_data', false );

gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) !== $index, 'complete removal drops the translation memory table' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) !== $queue, 'complete removal drops the translation queue table' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $manifest ) ) !== $manifest, 'complete removal drops shadow resource manifests' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations ) ) !== $relations, 'complete removal drops resource-string relationships' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $readiness ) ) !== $readiness, 'complete removal drops machine-readiness snapshots' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $review_versions ) ) !== $review_versions, 'complete removal drops review translation generations' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reviews ) ) !== $reviews, 'complete removal drops current review decisions' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $review_audit ) ) !== $review_audit, 'complete removal drops review audit history' );
gml_db_assert( get_option( 'gml_source_lang', false ) === false, 'complete removal deletes translation settings' );
gml_db_assert( get_option( 'gml_translate_uninstall_delete_data', false ) === false, 'complete removal deletes its own retention preference' );
gml_db_assert( get_option( 'gml_seo_uninstall_fixture' ) === 'keep', 'translation cleanup preserves GML SEO options' );
gml_db_assert( get_option( 'gml_indexnow_key' ) === 'keep', 'translation cleanup preserves the SEO IndexNow key' );

delete_option( 'gml_seo_uninstall_fixture' );
delete_option( 'gml_indexnow_key' );
