<?php
define( 'DOING_CRON', true );
require __DIR__ . '/bootstrap.php';
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_api_key_encrypted', 'test-only-never-sent' );
update_option( 'gml_translation_paused', true );
update_option( 'gml_crawl_running', true );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'module_ai_translation_enabled' => 1, 'engine' => 'gemini', 'gemini_key' => 'test-only-never-sent' ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
gml_test_product_init();
gml_db_assert( has_action( 'gml_crawl_content' ) !== false, 'paused AI still registers the independent scan Cron handler' );
gml_db_assert( ! has_action( 'gml_process_queue' ), 'paused AI does not register a paid queue worker in Cron' );
gml_db_assert( get_option( 'gml_translation_paused' ), 'Cron bootstrap preserves AI pause' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'Cron registration makes no provider request' );
update_option( 'gml_crawl_running', false );
