<?php
/** Saving a source page must queue only its changed text without starting AI. */
define( 'DOING_CRON', true );
require __DIR__ . '/bootstrap.php';

update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_translation_paused', true );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'de', 'enabled' => true, 'paused' => true ] ] );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_dirty_posts' );
wp_clear_scheduled_hook( 'gml_discover_changed_content' );
wp_clear_scheduled_hook( 'gml_process_queue' );
update_option( 'gml_translation_engine', 'gemini' );
GML_Gemini_API::save_api_key( 'incremental-test-key-not-sent', 'gemini' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'module_ai_translation_enabled' => 1, 'engine' => 'gemini', 'gemini_key' => 'incremental-test-key-not-sent' ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}

$requested_urls = [];
add_filter( 'pre_http_request', static function( $pre, $args, $url ) use ( &$requested_urls ) {
    $requested_urls[] = $url;
    return $pre;
}, 1, 3 );
gml_test_product_init();

$token = wp_generate_uuid4();
$title_one = 'Incremental source title ' . $token;
$body_one  = 'Incremental source body ' . $token;
$post_id = wp_insert_post( [
    'post_type' => 'page', 'post_status' => 'publish',
    'post_title' => $title_one, 'post_content' => '<p>' . $body_one . '</p>',
] );
gml_db_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'source page fixture is published' );
$dirty = (array) get_option( 'gml_translation_dirty_posts', [] );
gml_db_assert( array_key_exists( $post_id, $dirty ), 'published source save records a bounded incremental scan' );
gml_db_assert( wp_next_scheduled( 'gml_discover_changed_content' ) !== false, 'source save schedules incremental discovery' );

$queue = $wpdb->prefix . 'gml_queue';
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE source_hash = %s", md5( $body_one ) ) ) === 0, 'save request itself performs no translation discovery' );
do_action( 'gml_discover_changed_content' );
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE source_hash = %s AND target_lang = 'de' AND status = 'pending'", md5( $body_one ) ) ) === 1, 'incremental Cron queues the saved page body' );
gml_db_assert( get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( 'gml_process_queue' ), 'incremental discovery never resumes the paid worker' );
gml_db_assert( ! get_option( 'gml_translation_dirty_posts', [] ), 'completed incremental discovery clears its dirty marker' );

wp_clear_scheduled_hook( 'gml_discover_changed_content' );
$title_two = 'Updated source title ' . $token;
$body_two  = 'Updated source body ' . $token;
wp_update_post( [ 'ID' => $post_id, 'post_title' => $title_two, 'post_content' => '<p>' . $body_two . '</p>' ] );
gml_db_assert( array_key_exists( $post_id, (array) get_option( 'gml_translation_dirty_posts', [] ) ), 'editing an existing page records another incremental scan' );
do_action( 'gml_discover_changed_content' );
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE source_hash = %s AND target_lang = 'de' AND status = 'pending'", md5( $body_two ) ) ) === 1, 'updated source text receives a new hash and queue item' );
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE source_hash = %s AND target_lang = 'de'", md5( $body_one ) ) ) === 1, 'existing translation history is retained for rollback and memory reuse' );

foreach ( $requested_urls as $url ) {
    gml_db_assert( strpos( $url, 'gml_crawl=1' ) !== false, 'incremental HTTP is limited to the internal source-page renderer' );
}
gml_db_assert( count( $requested_urls ) === 2, 'two source saves perform two internal renders and zero provider requests' );

wp_delete_post( $post_id, true );
wp_clear_scheduled_hook( 'gml_discover_changed_content' );
delete_option( 'gml_translation_dirty_posts' );
echo "OK source-page incremental translation discovery\n";
