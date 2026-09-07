<?php
/** Phase 2D derived publication eligibility against a real WordPress database. */
require __DIR__ . '/bootstrap.php';

$source_path = trim( (string) getenv( 'GML_TEST_CORE_SRC' ) );
$source_core = $source_path !== '' ? realpath( $source_path ) : false;
if ( $source_core ) {
    foreach ( [
        'class-installer.php',
        'class-resource-identity.php',
        'class-resource-manifest-store.php',
        'class-resource-approval.php',
        'class-public-eligibility.php',
    ] as $file ) {
        require_once $source_core . '/' . $file;
    }
}
gml_test_product_init();

$home = getenv( 'GML_TEST_HOME' ) ?: 'http://gml-regression.test';
update_option( 'home', untrailingslashit( $home ) );
update_option( 'siteurl', untrailingslashit( $home ) );
update_option( 'blog_public', '1' );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [
    [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ],
    [ 'code' => 'qb', 'enabled' => false, 'site_mode' => 'local', 'url_prefix' => '/qb/' ],
    [ 'code' => 'qx', 'enabled' => true, 'site_mode' => 'external', 'external_url' => 'https://external.example/' ],
] );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );

gml_db_assert( GML_Installer::activate() === true, 'Phase 2D additive publication schema installs' );
gml_db_assert( GML_Installer::DB_VERSION === '3.3.0', 'Phase 2D database version is active' );
gml_db_assert( class_exists( 'GML_Public_Eligibility' ), 'derived publication service is available' );

global $wpdb;
$url_index = $wpdb->get_var( 'SHOW INDEX FROM ' . GML_Resource_Manifest_Store::manifest_table() . " WHERE Key_name='source_url_hash'", 2 );
gml_db_assert( $url_index !== null, 'manifest source URL hash has an indexed lookup' );

foreach ( [
    GML_Resource_Approval::audit_table(),
    GML_Resource_Approval::review_table(),
    GML_Resource_Approval::version_table(),
    GML_Resource_Manifest_Store::readiness_table(),
    GML_Resource_Manifest_Store::relation_table(),
    GML_Resource_Manifest_Store::manifest_table(),
] as $table ) {
    $wpdb->query( "DELETE FROM $table" );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}gml_index WHERE source_text LIKE 'phase2d%'" );

function gml_phase2d_fixture( $slug ) {
    $id = wp_insert_post( [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Phase 2D ' . $slug,
        'post_name' => $slug,
        'post_content' => 'Phase 2D fixture content.',
    ] );
    if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
    clean_post_cache( $id );
    return GML_Resource_Identity::for_post( $id );
}

function gml_phase2d_complete( GML_Resource_Identity $resource, $prefix ) {
    global $wpdb;
    $nodes = [];
    foreach ( [ 'seo_title', 'seo_meta', 'text' ] as $i => $context ) {
        $text = 'phase2d ' . $prefix . ' source ' . $i;
        $hash = md5( $text );
        $nodes[] = [ 'text' => $text, 'hash' => $hash, 'context_type' => $context ];
        $wpdb->replace( $wpdb->prefix . 'gml_index', [
            'source_hash' => $hash,
            'source_text' => $text,
            'source_lang' => 'en',
            'target_lang' => 'qa',
            'translated_text' => 'QA ' . $text,
            'context_type' => $context,
            'status' => 'auto',
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );
    }
    gml_db_assert( GML_Resource_Manifest_Store::save_complete( $resource, $nodes ) === true, $prefix . ' manifest is complete' );
    return $nodes;
}

function gml_phase2d_approve( GML_Resource_Identity $resource ) {
    $status = GML_Resource_Approval::get_status( $resource, 'qa' );
    return GML_Resource_Approval::approve( $resource, 'qa', 1, 'Phase 2D fixture review.', GML_Resource_Approval::expected_snapshot( $status ) );
}

$approved_resource = gml_phase2d_fixture( 'phase2d-approved' );
$noindex_resource = gml_phase2d_fixture( 'phase2d-noindex' );
$nodes = gml_phase2d_complete( $approved_resource, 'approved' );
gml_phase2d_complete( $noindex_resource, 'noindex' );

$unreviewed = GML_Public_Eligibility::get_status( $approved_resource, 'qa' );
gml_db_assert( ! $unreviewed['public_eligible'] && $unreviewed['reason'] === 'unreviewed', 'machine-complete but unreviewed target fails closed' );

$approved = gml_phase2d_approve( $approved_resource );
gml_db_assert( ! is_wp_error( $approved ), 'current translation snapshot can be approved' );
$eligible = GML_Public_Eligibility::get_status( $approved_resource, 'qa' );
gml_db_assert( $eligible['public_eligible'] && $eligible['reason'] === 'eligible', 'exact approved current snapshot becomes public eligible' );
gml_db_assert( strpos( $eligible['url'], '/qa/phase2d-approved/' ) !== false, 'eligible route contains one language prefix under root or subdirectory' );

$source_status = GML_Public_Eligibility::get_status( $approved_resource, 'en' );
gml_db_assert( $source_status['public_eligible'] && $source_status['reason'] === 'source', 'indexable source resource remains a public cluster member' );
$disabled = GML_Public_Eligibility::get_status( $approved_resource, 'qb' );
gml_db_assert( ! $disabled['public_eligible'] && $disabled['reason'] === 'language_disabled', 'disabled local target is not published' );
$external = GML_Public_Eligibility::get_status( $approved_resource, 'qx' );
gml_db_assert( ! $external['public_eligible'] && $external['reason'] === 'external_unverified', 'external unverified target is not published' );

$rejected = GML_Resource_Approval::reject(
    $approved_resource,
    'qa',
    1,
    'Needs terminology correction.',
    GML_Resource_Approval::expected_snapshot( GML_Resource_Approval::get_status( $approved_resource, 'qa' ) )
);
gml_db_assert( ! is_wp_error( $rejected ), 'approved current snapshot can be explicitly rejected' );
$rejected_status = GML_Public_Eligibility::get_status( $approved_resource, 'qa' );
gml_db_assert( ! $rejected_status['public_eligible'] && $rejected_status['reason'] === 'rejected', 'rejected target fails closed' );
gml_db_assert( ! is_wp_error( gml_phase2d_approve( $approved_resource ) ), 'rejected snapshot can be re-approved after review' );

$same_nodes = GML_Resource_Manifest_Store::save_complete( $approved_resource, $nodes );
gml_db_assert( $same_nodes === true && GML_Public_Eligibility::is_eligible( $approved_resource, 'qa' ), 'identical rescan preserves approval and publication eligibility' );

$query_before = (int) $wpdb->num_queries;
$bulk_review = GML_Resource_Approval::get_statuses_bulk( [ $approved_resource, $noindex_resource ], [ 'qa' ] );
$review_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( $review_queries === 1 && count( $bulk_review ) === 2, 'bulk Human Review status uses one joined query for a resource chunk' );

$noindex_filter = static function( $indexable, $resource ) use ( $noindex_resource ) {
    return $resource->get_key() === $noindex_resource->get_key() ? false : $indexable;
};
add_filter( 'gml_translation_resource_indexable', $noindex_filter, 10, 2 );
$query_before = (int) $wpdb->num_queries;
$clusters = GML_Public_Eligibility::get_clusters_bulk( [ $approved_resource, $noindex_resource ], [ 'entrypoint' => 'test' ] );
$cluster_queries = (int) $wpdb->num_queries - $query_before;
remove_filter( 'gml_translation_resource_indexable', $noindex_filter, 10 );
gml_db_assert( $cluster_queries === 1, 'bulk public clusters avoid URL by language database reads' );
gml_db_assert( $clusters[ $approved_resource->get_key() ]['languages']['qa']['public_eligible'], 'bulk cluster includes eligible approved target' );
gml_db_assert( ! $clusters[ $noindex_resource->get_key() ]['languages']['en']['public_eligible'], 'SEO noindex resource is excluded from every language cluster' );

$hashes = [
    $approved_resource->get_source_url_hash(),
    $noindex_resource->get_source_url_hash(),
];
$query_before = (int) $wpdb->num_queries;
$keys = GML_Resource_Manifest_Store::get_resource_keys_by_url_hashes( $hashes );
$hash_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( $hash_queries === 1 && count( $keys ) === 2, 'sitemap URL hashes resolve in one indexed query' );
$resolved = GML_Resource_Identity::resolve_urls( [ $eligible['url'], $approved_resource->get_source_url() ] );
gml_db_assert( count( $resolved ) === 2 && $resolved[ $eligible['url'] ]->get_key() === $approved_resource->get_key(), 'source and translated URLs resolve to the same resource identity' );

$changed = ( new GML_Translator() )->save_to_index(
    $nodes[2]['hash'], $nodes[2]['text'], 'QA changed Phase 2D text', 'en', 'qa', 'text', 'manual'
);
gml_db_assert( $changed === true, 'translation mutation succeeds through the Core contract' );
$translation_stale = GML_Public_Eligibility::get_status( $approved_resource, 'qa' );
gml_db_assert( ! $translation_stale['public_eligible'] && $translation_stale['reason'] === 'stale', 'translation change immediately revokes publication eligibility' );
GML_Resource_Readiness::run_rebuild_batch( 'phase2d-test' );
gml_db_assert( ! GML_Public_Eligibility::is_eligible( $approved_resource, 'qa' ), 'readiness rebuild cannot resurrect stale Human Approval' );

update_option( 'blog_public', '0' );
gml_db_assert( ! GML_Public_Eligibility::is_eligible( $approved_resource, 'en' ), 'site-wide discourage-search setting excludes the source cluster' );
update_option( 'blog_public', '1' );

gml_db_assert( (int) $GLOBALS['gml_test_http_calls'] === 0, 'publication eligibility performs no external HTTP request' );
wp_delete_post( $approved_resource->get_object_id(), true );
wp_delete_post( $noindex_resource->get_object_id(), true );

echo 'METRIC phase2d_review_bulk_queries=' . $review_queries . "\n";
echo 'METRIC phase2d_cluster_bulk_queries=' . $cluster_queries . "\n";
echo 'OK Phase 2D derived publication eligibility for ' . $home . "\n";
