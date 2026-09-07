<?php
/** Two-process Human Review CAS regression. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

$mode = sanitize_key( $argv[1] ?? '' );
$case = sanitize_key( $argv[2] ?? 'approve_reject' );
$cases = [
    'approve_approve' => [ 'approved', [ 'approve', 'approve' ] ],
    'approve_reject' => [ '', [ 'approve', 'reject' ] ],
    'reject_reject' => [ 'rejected', [ 'reject', 'reject' ] ],
];
if ( ! isset( $cases[ $case ] ) ) throw new RuntimeException( 'Unknown Phase 2C.1 concurrency case.' );
$case_number = array_search( $case, array_keys( $cases ), true ) + 1;
$resource = GML_Resource_Identity::from_parts( 'post', 970010 + $case_number, '', 'page', home_url( '/phase2c1-concurrency-' . $case . '/' ), 'r1' );
$snapshot_option = 'gml_phase2c1_concurrent_snapshot_' . $case;
$result_prefix = 'gml_phase2c1_concurrent_result_' . $case . '_';

if ( $mode === 'prepare' ) {
    GML_Installer::activate();
    update_option( 'gml_source_lang', 'en' );
    update_option( 'gml_languages', [ [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ] ] );
    $text = 'phase2c1 concurrent source ' . $case;
    $hash = md5( $text );
    GML_Translation_Memory::upsert( [
        'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qa',
        'translated_text' => 'QA concurrent translation', 'context_type' => 'seo_title', 'status' => 'auto',
    ], false );
    GML_Resource_Manifest_Store::save_complete( $resource, [ [ 'text' => $text, 'hash' => $hash, 'context_type' => 'seo_title' ] ] );
    global $wpdb;
    $manifest = GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
    $wpdb->delete( GML_Resource_Approval::review_table(), [ 'resource_id' => (int) $manifest->id, 'target_lang' => 'qa' ] );
    $wpdb->delete( GML_Resource_Approval::audit_table(), [ 'resource_id' => (int) $manifest->id, 'target_lang' => 'qa' ] );
    delete_option( $result_prefix . 'one' );
    delete_option( $result_prefix . 'two' );
    update_option( $snapshot_option, GML_Resource_Approval::expected_snapshot( GML_Resource_Approval::get_status( $resource, 'qa' ) ), false );
    echo "OK Phase 2C.1 concurrent review prepared for {$case}\n";
    exit( 0 );
}

if ( $mode === 'worker' ) {
    $slot = sanitize_key( $argv[3] ?? '' );
    $decision = sanitize_key( $argv[4] ?? '' );
    if ( ! in_array( $slot, [ 'one', 'two' ], true ) || ! in_array( $decision, [ 'approve', 'reject' ], true ) ) {
        throw new RuntimeException( 'Invalid Phase 2C.1 concurrent worker.' );
    }
    $snapshot = (array) get_option( $snapshot_option, [] );
    usleep( 150000 );
    $result = $decision === 'approve'
        ? GML_Resource_Approval::approve( $resource, 'qa', 11, 'Concurrent approval.', $snapshot )
        : GML_Resource_Approval::reject( $resource, 'qa', 12, 'Concurrent rejection.', $snapshot );
    update_option( $result_prefix . $slot, is_wp_error( $result ) ? $result->get_error_code() : 'success', false );
    echo "OK Phase 2C.1 concurrent worker {$slot}/{$decision} completed for {$case}\n";
    exit( 0 );
}

if ( $mode === 'verify' ) {
    global $wpdb;
    $result_options = [ $result_prefix . 'one', $result_prefix . 'two' ];
    $results = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
        $result_options
    ) );
    sort( $results );
    gml_db_assert( $results === [ 'gml_review_conflict', 'success' ], $case . ' produces one success and one explicit conflict' );
    $audit = GML_Resource_Approval::get_audit( $resource, 'qa' );
    $status = GML_Resource_Approval::get_status( $resource, 'qa' );
    $expected_status = $cases[ $case ][0];
    $status_ok = $expected_status !== '' ? $status['review_status'] === $expected_status : in_array( $status['review_status'], [ 'approved', 'rejected' ], true );
    gml_db_assert( count( $audit ) === 1 && $status_ok, $case . ' leaves one deterministic current row and one append-only audit event' );
    echo "OK Phase 2C.1 concurrent Human Review CAS for {$case}\n";
    exit( 0 );
}

throw new RuntimeException( 'Unknown Phase 2C.1 concurrency mode.' );
