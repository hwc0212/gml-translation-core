<?php
/** Current rendered source must replace stale page structure without deleting history. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

$home = getenv( 'GML_TEST_HOME' ) ?: 'http://gml-regression.test';
update_option( 'home', untrailingslashit( $home ) );
update_option( 'siteurl', untrailingslashit( $home ) );
update_option( 'blog_public', '1' );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_translation_paused', false );
update_option( 'gml_languages', [ [
    'code' => 'qr',
    'enabled' => true,
    'paused' => false,
    'site_mode' => 'local',
    'url_prefix' => '/qr/',
] ] );
delete_option( GML_Queue_Processor::CIRCUIT_OPTION );

gml_db_assert( true === GML_Installer::activate(), 'redesign synchronization schema is available' );

global $wpdb;
$index = $wpdb->prefix . 'gml_index';
$queue = $wpdb->prefix . 'gml_queue';
$manifests = GML_Resource_Manifest_Store::manifest_table();
$relations = GML_Resource_Manifest_Store::relation_table();
$readiness = GML_Resource_Manifest_Store::readiness_table();
foreach ( [ $readiness, $relations, $manifests ] as $table ) $wpdb->query( "DELETE FROM $table" );
$wpdb->delete( $index, [ 'target_lang' => 'qr' ] );
$wpdb->delete( $queue, [ 'target_lang' => 'qr' ] );
update_option( 'gml_resource_manifest_global_generation', 1, false );
update_option( 'gml_resource_backfill_state', [
    'status' => 'complete',
    'phase' => 'complete',
    'cursor' => 0,
    'updated_at' => time(),
], false );

$post_id = wp_insert_post( [
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Redesign synchronization fixture',
    'post_name' => 'redesign-synchronization',
    'post_content' => 'Authoritative HTML is supplied by the regression fixture.',
] );
gml_db_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'redesign source page is published' );
$resource = GML_Resource_Identity::for_post( $post_id );
gml_db_assert( $resource instanceof GML_Resource_Identity, 'redesign source has a stable resource identity' );

$old_html = '<!doctype html><html><head>'
    . '<title>Legacy Product Page</title>'
    . '<meta name="description" content="Legacy product description.">'
    . '</head><body>'
    . '<h1>Product Overview</h1>'
    . '<p>Shared engineering specification.</p>'
    . '<p>Legacy section alpha.</p>'
    . '<p>Legacy section beta.</p>'
    . '<a href="/legacy-datasheet/">View Technical Details</a>'
    . '<footer>Global company footer.</footer>'
    . '</body></html>';
$new_html = '<!doctype html><html><head>'
    . '<title>Current Product Page</title>'
    . '<meta name="description" content="Current product description.">'
    . '</head><body>'
    . '<h1>Product Overview</h1>'
    . '<p>Shared engineering specification.</p>'
    . '<p>Current section gamma.</p>'
    . '<p>Current section delta.</p>'
    . '<a href="/current-datasheet/">View Technical Details</a>'
    . '<footer>Global company footer.</footer>'
    . '</body></html>';

$parser = new GML_HTML_Parser();
$old = $parser->parse( $old_html );
$current = $parser->parse( $new_html );
$old_by_hash = [];
foreach ( $old['nodes'] as $node ) $old_by_hash[ $node['hash'] ] = $node;
$current_by_hash = [];
foreach ( $current['nodes'] as $node ) $current_by_hash[ $node['hash'] ] = $node;
$removed = array_diff_key( $old_by_hash, $current_by_hash );
$added = array_diff_key( $current_by_hash, $old_by_hash );
$shared = array_intersect_key( $old_by_hash, $current_by_hash );
gml_db_assert( count( $removed ) === 4 && count( $added ) === 4 && count( $shared ) === 4, 'fixture separates removed, added, and unchanged current strings' );

$translator = new GML_Translator();
foreach ( $old_by_hash as $hash => $node ) {
    $status = $node['text'] === 'Shared engineering specification.' ? 'manual' : 'auto';
    gml_db_assert(
        true === $translator->save_to_index( $hash, $node['text'], 'QR ' . $node['text'], 'en', 'qr', $node['context_type'], $status ),
        'initial translation is stored'
    );
}
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $old['nodes'] ), 'legacy rendered manifest is stored' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource, 'qr' ) === 'complete', 'legacy page begins fully translated' );

$legacy_manifest = GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $current['nodes'] ), 'current rendered manifest atomically replaces legacy relations' );
$current_manifest = GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
gml_db_assert( (int) $current_manifest->manifest_generation === (int) $legacy_manifest->manifest_generation + 1, 'redesign advances the resource manifest generation' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource, 'qr' ) === 'incomplete', 'new current strings make the translated route incomplete' );
gml_db_assert( ! GML_Public_Eligibility::is_eligible( $resource, 'qr' ), 'redesigned page below page-local threshold stays out of SEO discovery while source fallback remains accessible' );
$partial = $translator->translate( $current, 'qr' );
foreach ( $added as $node ) gml_db_assert( !isset($partial['replacements'][$node['text']]), 'new source content is preserved instead of substituting obsolete translation' );

$current_relation_hashes = $wpdb->get_col( $wpdb->prepare(
    "SELECT source_hash FROM $relations WHERE resource_id=%d",
    $current_manifest->id
) );
gml_db_assert( ! array_intersect( array_keys( $removed ), $current_relation_hashes ), 'removed source strings no longer belong to the current page' );
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $index WHERE target_lang='qr' AND source_hash IN (" . implode( ',', array_fill( 0, count( $removed ), '%s' ) ) . ')',
    array_keys( $removed )
) ) === count( $removed ), 'removed translations remain available as historical Translation Memory' );
$manual_hash = md5( 'Shared engineering specification.' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare(
    "SELECT status FROM $index WHERE target_lang='qr' AND source_hash=%s",
    $manual_hash
) ) === 'manual', 'unchanged manual translation remains authoritative' );

class GML_Redesign_Discovery_Translator extends GML_Translator {
    protected function ai_translation_available() { return true; }
}
$discovery = new GML_Redesign_Discovery_Translator();
$discovery->discover( $current, 'qr' );
$queued_hashes = $wpdb->get_col( "SELECT source_hash FROM $queue WHERE target_lang='qr' AND status='pending' ORDER BY source_hash" );
$expected_added = array_keys( $added );
sort( $expected_added );
gml_db_assert( $queued_hashes === $expected_added, 'incremental discovery queues only newly added current strings' );
gml_db_assert( (int) $GLOBALS['gml_test_http_calls'] === 0, 'redesign synchronization performs no provider or external HTTP request' );

foreach ( $added as $hash => $node ) {
    gml_db_assert(
        true === $translator->save_to_index( $hash, $node['text'], 'QR ' . $node['text'], 'en', 'qr', $node['context_type'], 'auto' ),
        'new current translation is stored'
    );
}
GML_Resource_Readiness::run_rebuild_batch( 'redesign-sync' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource, 'qr' ) === 'complete', 'completeness reaches 100 percent after every current string is translated' );
gml_db_assert( GML_Public_Eligibility::is_eligible( $resource, 'qr' ), 'fully translated current page is anonymously publishable without mandatory review' );

$translated = $translator->translate( $current, 'qr' );
$rebuilt = $parser->rebuild( $translated );
gml_db_assert( strpos( $rebuilt, 'QR Current Product Page' ) !== false, 'current SEO title is translated' );
gml_db_assert( strpos( $rebuilt, 'QR Current product description.' ) !== false, 'current SEO description is translated' );
gml_db_assert( strpos( $rebuilt, 'QR Shared engineering specification.' ) !== false, 'unchanged manual translation is reused' );
gml_db_assert( strpos( $rebuilt, '/current-datasheet/' ) !== false && strpos( $rebuilt, '/legacy-datasheet/' ) === false, 'current non-translatable URL structure is preserved' );
gml_db_assert( strpos( $rebuilt, 'Legacy section alpha.' ) === false && strpos( $rebuilt, 'QR Legacy section alpha.' ) === false, 'removed source and translated legacy sections cannot leak into current output' );

wp_delete_post( $post_id, true );
$wpdb->delete( $queue, [ 'target_lang' => 'qr' ] );
$wpdb->delete( $index, [ 'target_lang' => 'qr' ] );
foreach ( [ $readiness, $relations, $manifests ] as $table ) $wpdb->query( "DELETE FROM $table" );
echo 'OK redesign current-source synchronization for ' . $home . "\n";
