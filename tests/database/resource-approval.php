<?php
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

$home = getenv( 'GML_TEST_HOME' ) ?: 'http://gml-regression.test';
update_option( 'home', untrailingslashit( $home ) );
update_option( 'siteurl', untrailingslashit( $home ) );
gml_db_assert( GML_Installer::activate() === true, 'Phase 2C additive review schema installs' );
gml_db_assert( class_exists( 'GML_Resource_Approval' ), 'Phase 2C review service is available' );

global $wpdb;
$tables = [
    GML_Resource_Approval::audit_table(),
    GML_Resource_Approval::review_table(),
    GML_Resource_Approval::version_table(),
    GML_Resource_Manifest_Store::readiness_table(),
    GML_Resource_Manifest_Store::relation_table(),
    GML_Resource_Manifest_Store::manifest_table(),
];
foreach ( $tables as $table ) {
    gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table, basename( $table ) . ' exists' );
    $wpdb->query( "DELETE FROM $table" );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}gml_index WHERE source_text LIKE 'phase2c%'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}gml_queue WHERE source_text LIKE 'phase2c%'" );

update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [
    [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ],
    [ 'code' => 'qx', 'enabled' => true, 'site_mode' => 'external', 'external_url' => 'https://external.example/' ],
] );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );

function gml_phase2c_resource( $id, $revision = 'r1' ) {
    return GML_Resource_Identity::from_parts( 'post', $id, '', 'page', home_url( '/phase2c-review-' . $id . '/' ), $revision );
}

function gml_phase2c_nodes( $prefix, $translated ) {
    global $wpdb;
    $nodes = [];
    for ( $i = 0; $i < 3; $i++ ) {
        $text = 'phase2c ' . $prefix . ' source ' . $i;
        $hash = md5( $text );
        $context = $i === 0 ? 'seo_title' : 'text';
        $nodes[] = [ 'text' => $text, 'hash' => $hash, 'context_type' => $context ];
        if ( $i < $translated ) {
            $wpdb->replace( $wpdb->prefix . 'gml_index', [
                'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qa',
                'translated_text' => 'QA ' . $text, 'context_type' => $context, 'status' => 'auto',
                'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
            ] );
        }
    }
    return $nodes;
}

$resource = gml_phase2c_resource( 960001 );
$nodes = gml_phase2c_nodes( 'approved', 3 );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $nodes ), 'complete review fixture manifest is saved' );
$initial = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert( $initial['machine_status'] === 'complete' && $initial['review_status'] === 'unreviewed', 'machine complete remains separate from human approval' );

$http_before = (int) $GLOBALS['gml_test_http_calls'];
$approved = GML_Resource_Approval::approve( $resource, 'qa', 1, 'Reviewed in the Phase 2C fixture.' );
gml_db_assert( ! is_wp_error( $approved ) && $approved['review_status'] === 'approved', 'an explicit reviewer can approve the exact current snapshot' );
gml_db_assert( count( GML_Resource_Approval::get_audit( $resource, 'qa' ) ) === 1, 'approval appends one immutable audit event' );
gml_db_assert( (int) $GLOBALS['gml_test_http_calls'] === $http_before, 'human approval makes no provider or frontend HTTP request' );

$generation_before = $approved['manifest_generation'];
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $nodes ), 'an identical authoritative rescan succeeds' );
$same_snapshot = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert( $same_snapshot['manifest_generation'] === $generation_before && $same_snapshot['review_status'] === 'approved', 'an identical rescan preserves the exact approval snapshot' );

$edited_resource = gml_phase2c_resource( 960001, 'r2' );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $edited_resource, $nodes ), 'a real source revision creates a new manifest generation' );
$source_stale = GML_Resource_Approval::get_status( $edited_resource, 'qa' );
gml_db_assert( $source_stale['manifest_generation'] === $generation_before + 1 && $source_stale['review_status'] === 'stale', 'source revisions stale the previous human approval' );
gml_db_assert( count( GML_Resource_Approval::get_audit( $resource, 'qa' ) ) === 1, 'automatic invalidation does not rewrite approval history' );

$reapproved = GML_Resource_Approval::approve( $edited_resource, 'qa', 1, 'Reviewed after source revision.' );
gml_db_assert( ! is_wp_error( $reapproved ) && $reapproved['review_status'] === 'approved', 'the refreshed manifest can be explicitly approved again' );
$translation_generation = $reapproved['translation_generation'];
$translator = new GML_Translator();
$unchanged_text = (string) $wpdb->get_var( $wpdb->prepare(
    "SELECT translated_text FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND source_lang='en' AND target_lang='qa'",
    $nodes[1]['hash']
) );
gml_db_assert( true === $translator->save_to_index(
    $nodes[1]['hash'], $nodes[1]['text'], $unchanged_text, 'en', 'qa', 'text', 'auto'
), 'an unchanged automatic write succeeds without invalidating approval' );
$unchanged_status = GML_Resource_Approval::get_status( $edited_resource, 'qa' );
gml_db_assert( $unchanged_status['review_status'] === 'approved' && $unchanged_status['translation_generation'] === $translation_generation, 'identical public text preserves its approval generation' );

$rolled_back = GML_Resource_Readiness::apply_translation_change( $nodes[1]['hash'], 'qa', static function () use ( $wpdb, $nodes ) {
    $wpdb->update( $wpdb->prefix . 'gml_index', [ 'translated_text' => 'phase2c must roll back' ], [
        'source_hash' => $nodes[1]['hash'], 'source_lang' => 'en', 'target_lang' => 'qa',
    ] );
    return false;
} );
gml_db_assert( $rolled_back === false, 'a failed translation mutation reports failure' );
$rollback_status = GML_Resource_Approval::get_status( $edited_resource, 'qa' );
$rollback_text = (string) $wpdb->get_var( $wpdb->prepare(
    "SELECT translated_text FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND source_lang='en' AND target_lang='qa'",
    $nodes[1]['hash']
) );
gml_db_assert( $rollback_text === $unchanged_text, 'a failed mutation rolls back the translation row' );
gml_db_assert( $rollback_status['review_status'] === 'approved' && $rollback_status['translation_generation'] === $translation_generation, 'a failed mutation also rolls back readiness and approval invalidation' );

gml_db_assert( true === $translator->save_to_index(
    $nodes[1]['hash'], $nodes[1]['text'], 'QA revised translation', 'en', 'qa', 'text', 'manual'
), 'a reviewed translation can still be manually corrected' );
$translation_stale = GML_Resource_Approval::get_status( $edited_resource, 'qa' );
gml_db_assert( $translation_stale['machine_status'] === 'stale' && $translation_stale['review_status'] === 'stale', 'translation changes fail closed before readiness rebuild' );
GML_Resource_Readiness::run_rebuild_batch( 'phase2c-test' );
$rebuilt = GML_Resource_Approval::get_status( $edited_resource, 'qa' );
gml_db_assert( $rebuilt['machine_status'] === 'complete' && $rebuilt['review_status'] === 'stale', 'machine rebuild cannot silently restore an old human approval' );
gml_db_assert( $rebuilt['translation_generation'] === $translation_generation + 1, 'reviewed resource translation generation advances exactly once' );

$rejected = GML_Resource_Approval::reject( $edited_resource, 'qa', 1, 'Terminology needs correction.' );
gml_db_assert( ! is_wp_error( $rejected ) && $rejected['review_status'] === 'rejected', 'a reviewer can reject the current complete snapshot with a reason' );
gml_db_assert( count( GML_Resource_Approval::get_audit( $resource, 'qa' ) ) === 3, 're-approval and rejection append audit events without deleting history' );
$missing_note = GML_Resource_Approval::reject( $edited_resource, 'qa', 1, '' );
gml_db_assert( is_wp_error( $missing_note ) && $missing_note->get_error_code() === 'gml_review_note', 'rejection requires an actionable review note' );

$incomplete_resource = gml_phase2c_resource( 960002 );
$incomplete_nodes = gml_phase2c_nodes( 'incomplete', 0 );
GML_Resource_Manifest_Store::save_complete( $incomplete_resource, $incomplete_nodes );
$blocked = GML_Resource_Approval::approve( $incomplete_resource, 'qa', 1, '' );
gml_db_assert( is_wp_error( $blocked ) && $blocked->get_error_code() === 'gml_review_machine', 'incomplete translations cannot be approved' );
$external = GML_Resource_Approval::approve( $edited_resource, 'qx', 1, '' );
gml_db_assert( is_wp_error( $external ) && $external->get_error_code() === 'gml_review_language', 'external unverified sites cannot receive a local approval' );

$query_before = (int) $wpdb->num_queries;
$listing = GML_Resource_Approval::list_resources( [ 'languages' => [ 'qa' ], 'page' => 1, 'per_page' => 25 ] );
$list_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( $list_queries === 2 && $listing['total'] === 2 && count( $listing['rows'] ) === 2, 'review queue uses two bounded indexed queries' );
$payload = GML_Resource_Approval::get_review_payload( $edited_resource, 'qa', 1, 2 );
gml_db_assert( ! is_wp_error( $payload ) && count( $payload['strings'] ) === 2 && $payload['pages'] === 2, 'review detail paginates current manifest strings' );
$audit_columns = array_map( static function( $row ) { return $row->Field; }, $wpdb->get_results( 'SHOW COLUMNS FROM ' . GML_Resource_Approval::audit_table() ) );
gml_db_assert( ! in_array( 'source_text', $audit_columns, true ) && ! in_array( 'translated_text', $audit_columns, true ), 'audit schema stores decisions and fingerprints, not page content' );

echo 'METRIC phase2c_review_list_queries=' . $list_queries . "\n";
echo 'OK Phase 2C human review shadow workflow for ' . $home . "\n";
