<?php
require __DIR__ . '/bootstrap.php';
class GML_Test_Translator extends GML_Translator {
    protected function ai_translation_available() { return true; }
}
$text = 'Concurrent enqueue regression';
$hash = md5( $text );
$queue = $wpdb->prefix . 'gml_queue';
$mode = $argv[1] ?? '';
if ( $mode === 'prepare' ) {
    $wpdb->delete( $queue, [ 'source_hash' => $hash ] );
    delete_option( 'gml_translation_circuit_breaker' );
    update_option( 'gml_translation_paused', false );
    exit;
}
$parsed = [ 'nodes' => [ [ 'hash' => $hash, 'text' => $text ] ] ];
$translator = new GML_Test_Translator();
if ( $mode === 'worker' ) {
    $translator->translate( $parsed, 'de' );
    exit;
}
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE source_hash = %s", $hash ) ) === 1, 'parallel enqueues create exactly one row in a legacy queue without unique key' );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock = GML_Translator::enqueue_lock_name( 'en', 'de' );
$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
$start = microtime( true );
$parsed['nodes'][0] = [ 'hash' => md5( 'Blocked enqueue' ), 'text' => 'Blocked enqueue' ];
$translator->translate( $parsed, 'de' );
gml_db_assert( microtime( true ) - $start < 1, 'contended enqueue never waits on another request' );
$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $queue WHERE source_hash = %s", md5( 'Blocked enqueue' ) ) ) === 0, 'contended enqueue is skipped safely' );
$cache_text = 'Readiness cache invalidation ' . wp_generate_uuid4();
$cache_hash = md5( $cache_text );
$parsed['nodes'][0] = [ 'hash' => $cache_hash, 'text' => $cache_text ];
$generation = GML_Page_Cache::generation();
$translator->translate( $parsed, 'de' );
gml_db_assert( GML_Page_Cache::generation() > $generation, 'new queue work invalidates rendered pages and readiness state' );
$generation = GML_Page_Cache::generation();
$translator->translate( $parsed, 'de' );
gml_db_assert( GML_Page_Cache::generation() === $generation, 'an already queued segment does not churn the page-cache generation' );
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue" );
update_option( 'gml_translation_paused', true );
$translator->translate( $parsed, 'de' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue" ) === $count, 'ordinary pause prevents new enqueue' );
update_option( 'gml_translation_paused', false );
update_option( 'gml_translation_circuit_breaker', [ 'reason' => 'test' ] );
$translator->translate( $parsed, 'de' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue" ) === $count, 'API circuit breaker prevents new enqueue' );
update_option( 'gml_ai_translation_enabled', false );
delete_option( 'gml_api_key_encrypted' );
$parsed['nodes'][0] = [ 'hash' => md5( 'memory-1' ), 'text' => 'Memory 1' ];
$translated = (new GML_Translator())->translate( $parsed, 'de' );
gml_db_assert( ( $translated['replacements']['Memory 1'] ?? '' ) === 'Saved 1', 'manual translation is readable with AI disabled and no key' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue WHERE source_text LIKE 'Legacy %'" ) === 130000, 'concurrent enqueue never deletes historical duplicates' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'translation lookup and enqueue make zero external API requests' );
$wpdb->delete( $queue, [ 'source_hash' => $cache_hash ] );
