<?php
/** Phase 2C.1 resource lifecycle and approval invalidation regressions. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

gml_db_assert( GML_Installer::activate() === true, 'Phase 2C.1 invalidation schema installs' );
GML_Resource_Manifest_Manager::register_hooks();
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ] ] );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );

global $wpdb;
$run_token = substr( hash( 'sha256', uniqid( 'phase2c1-invalidation-', true ) ), 0, 12 );
$resource_base = 120000000 + ( hexdec( substr( $run_token, 0, 8 ) ) % 1000000000 );

function gml_phase2c1_invalidation_resource( $id, $label ) {
    return GML_Resource_Identity::from_parts(
        'post', $id, '', 'page', home_url( '/phase2c1-invalidation-' . $label . '/' ), 'r1'
    );
}

function gml_phase2c1_approve_fixture( GML_Resource_Identity $resource, $label, $status = 'auto' ) {
    global $run_token;
    $text = 'phase2c1 invalidation ' . $run_token . ' ' . $label;
    $hash = md5( $text );
    $record = [
        'source_hash' => $hash,
        'source_text' => $text,
        'source_lang' => 'en',
        'target_lang' => 'qa',
        'translated_text' => 'QA ' . $text,
        'context_type' => 'seo_title',
        'status' => $status,
    ];
    gml_db_assert( GML_Translation_Memory::upsert( $record, false ), $label . ' translation is saved through the mutation contract' );
    $nodes = [ [ 'text' => $text, 'hash' => $hash, 'context_type' => 'seo_title' ] ];
    gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $nodes ), $label . ' manifest is complete' );
    $status_row = GML_Resource_Approval::get_status( $resource, 'qa' );
    $decision = GML_Resource_Approval::approve(
        $resource,
        'qa',
        1,
        'Lifecycle regression.',
        GML_Resource_Approval::expected_snapshot( $status_row )
    );
    gml_db_assert( ! is_wp_error( $decision ) && $decision['review_status'] === 'approved', $label . ' exact snapshot is approved' );
    return [ $nodes, $record, $decision ];
}

function gml_phase2c1_assert_noncurrent( $resource, $label ) {
    $status = GML_Resource_Approval::get_status( $resource, 'qa' );
    gml_db_assert( $status['review_status'] !== 'approved', $label . ' makes the old approval non-current' );
    return $status;
}

// A source post edit must invalidate the reviewed resource through the real hook.
$post_id = wp_insert_post( [
    'post_title' => 'Phase 2C.1 post edit ' . $run_token,
    'post_content' => 'Before edit',
    'post_status' => 'publish',
    'post_type' => 'post',
] );
gml_db_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'source-post fixture is created' );
$post_resource = GML_Resource_Identity::for_post( $post_id );
gml_phase2c1_approve_fixture( $post_resource, 'source-post-edit' );
wp_update_post( [ 'ID' => $post_id, 'post_content' => 'After edit ' . $run_token ] );
gml_phase2c1_assert_noncurrent( $post_resource, 'source post edit' );

// A source term edit must invalidate the reviewed resource through the real hook.
$term_result = wp_insert_term( 'Phase 2C.1 term ' . $run_token, 'category' );
gml_db_assert( ! is_wp_error( $term_result ), 'source-term fixture is created' );
$term_id = (int) $term_result['term_id'];
$term_resource = GML_Resource_Identity::for_term( get_term( $term_id, 'category' ) );
gml_phase2c1_approve_fixture( $term_resource, 'source-term-edit' );
wp_update_term( $term_id, 'category', [ 'description' => 'Updated ' . $run_token ] );
gml_phase2c1_assert_noncurrent( $term_resource, 'source term edit' );

// Unpublishing and deleting use separate resources so both real hooks are covered.
$unpublish_id = wp_insert_post( [
    'post_title' => 'Phase 2C.1 unpublish ' . $run_token,
    'post_status' => 'publish',
    'post_type' => 'post',
] );
$unpublish_resource = GML_Resource_Identity::for_post( $unpublish_id );
gml_phase2c1_approve_fixture( $unpublish_resource, 'resource-unpublish' );
wp_update_post( [ 'ID' => $unpublish_id, 'post_status' => 'draft' ] );
gml_phase2c1_assert_noncurrent( $unpublish_resource, 'resource unpublish' );

$delete_id = wp_insert_post( [
    'post_title' => 'Phase 2C.1 delete ' . $run_token,
    'post_status' => 'publish',
    'post_type' => 'post',
] );
$delete_resource = GML_Resource_Identity::for_post( $delete_id );
gml_phase2c1_approve_fixture( $delete_resource, 'resource-delete' );
wp_delete_post( $delete_id, true );
gml_phase2c1_assert_noncurrent( $delete_resource, 'resource delete' );

// Deleting an effective manual translation changes both generation and snapshot.
$manual_resource = gml_phase2c1_invalidation_resource( $resource_base + 1, 'manual-delete' );
list( , $manual_record, $manual_before ) = gml_phase2c1_approve_fixture( $manual_resource, 'manual-delete', 'manual' );
$translation_id = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND source_lang='en' AND target_lang='qa'",
    $manual_record['source_hash']
) );
gml_db_assert( $translation_id > 0 && GML_Translation_Memory::delete_by_id( $translation_id ), 'manual translation delete uses the mutation contract' );
GML_Resource_Readiness::run_rebuild_batch( 'phase2c1-manual-delete' );
$manual_after = gml_phase2c1_assert_noncurrent( $manual_resource, 'manual translation delete' );
gml_db_assert(
    $manual_after['translation_generation'] === $manual_before['translation_generation'] + 1
        && ! hash_equals( $manual_after['translation_fingerprint'], $manual_before['translation_fingerprint'] ),
    'manual delete advances generation and changes the effective translation fingerprint'
);

// Authoritative render failure is a machine state and never leaves approval current.
$render_resource = gml_phase2c1_invalidation_resource( $resource_base + 2, 'render-error' );
gml_phase2c1_approve_fixture( $render_resource, 'render-error' );
gml_db_assert( GML_Resource_Manifest_Store::record_state( $render_resource, 'render_error' ), 'authoritative render error is recorded' );
$render_status = gml_phase2c1_assert_noncurrent( $render_resource, 'authoritative render error' );
gml_db_assert( $render_status['machine_status'] === 'render_error', 'render_error remains a separate machine state' );

// Ordinary rebuild, migration, and backfill work must never manufacture decisions.
$audit_resource = gml_phase2c1_invalidation_resource( $resource_base + 3, 'audit-history' );
list( $audit_nodes, $audit_record, $audit_status ) = gml_phase2c1_approve_fixture( $audit_resource, 'audit-history' );
$audit_table = GML_Resource_Approval::audit_table();
$review_table = GML_Resource_Approval::review_table();
$audit_manifest = GML_Resource_Manifest_Store::get_by_key( $audit_resource->get_key() );
$audit_before = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $audit_table WHERE resource_id=%d ORDER BY id ASC", $audit_manifest->id ), ARRAY_A );
$reviews_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $review_table" );
gml_db_assert( GML_Translation_Memory::upsert( $audit_record, false ), 'logically identical translation write is accepted as unchanged' );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $audit_resource, $audit_nodes ), 'identical authoritative rescan succeeds' );
GML_Resource_Readiness::recalculate_resources( [ (int) $audit_manifest->id ] );
update_option( 'gml_db_version', '3.1.0', false );
gml_db_assert( GML_Installer::activate() === true, 'additive migration re-entry succeeds' );
$fast_http_failure = static function() { return new WP_Error( 'phase2c1_no_http', 'No public render needed in this regression.' ); };
add_filter( 'pre_http_request', $fast_http_failure );
GML_Resource_Backfill::reset_pending( 'phase2c1-review-proof' );
GML_Resource_Backfill::run_batch();
GML_Resource_Backfill::run_batch();
remove_filter( 'pre_http_request', $fast_http_failure );
$audit_after = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $audit_table WHERE resource_id=%d ORDER BY id ASC", $audit_manifest->id ), ARRAY_A );
gml_db_assert( $audit_after === $audit_before, 'ordinary writes, rebuild, migration, and backfill never update or delete historical audit rows' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $review_table" ) === $reviews_before, 'migration and backfill never create Human Review decisions' );
$identical_status = GML_Resource_Approval::get_status( $audit_resource, 'qa' );
gml_db_assert( $identical_status['review_status'] === 'approved', 'logically identical translation and authoritative rescan preserve current approval' );

// A shared generation change makes every old snapshot non-current.
$global_resource = gml_phase2c1_invalidation_resource( $resource_base + 4, 'global-generation' );
gml_phase2c1_approve_fixture( $global_resource, 'global-generation' );
$global_before = GML_Resource_Manifest_Manager::global_generation();
update_option( GML_Resource_Manifest_Manager::GLOBAL_OPTION, $global_before + 1, false );
$global_status = gml_phase2c1_assert_noncurrent( $global_resource, 'global generation change' );
gml_db_assert( $global_status['machine_status'] === 'stale', 'global generation drift is visible as machine stale' );

wp_delete_post( $post_id, true );
wp_delete_post( $unpublish_id, true );
wp_delete_term( $term_id, 'category' );

echo "OK Phase 2C.1 lifecycle invalidation and audit immutability for {$run_token}\n";
