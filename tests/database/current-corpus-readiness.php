<?php
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
wp_set_current_user( 1 );

gml_db_assert( true === GML_Installer::activate(), 'current-corpus fixture schema is available' );

global $wpdb;
$manifests = $wpdb->prefix . 'gml_resource_manifests';
$relations = $wpdb->prefix . 'gml_resource_strings';
$readiness = $wpdb->prefix . 'gml_resource_readiness';
$index = $wpdb->prefix . 'gml_index';
$queue = $wpdb->prefix . 'gml_queue';

foreach ( [ $readiness, $relations, $manifests ] as $table ) $wpdb->query( "DELETE FROM $table" );
$wpdb->delete( $index, [ 'target_lang' => 'qc' ] );
$wpdb->delete( $queue, [ 'target_lang' => 'qc' ] );
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
delete_option( GML_Queue_Processor::SAMPLE_OPTION );
delete_option( GML_Queue_Processor::CIRCUIT_OPTION );
update_option( 'gml_resource_manifest_global_generation', 1, false );
update_option( 'gml_resource_backfill_state', [ 'status' => 'complete', 'phase' => 'complete', 'cursor' => 0, 'updated_at' => time() ], false );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'qc', 'enabled' => true, 'paused' => false, 'site_mode' => 'local' ] ] );

$hashes = [];
$nodes = [];
for ( $i = 0; $i < 20; $i++ ) {
    $text = 'current corpus source ' . $i;
    $hash = md5( $text );
    $hashes[] = $hash;
    $nodes[] = [ 'text' => $text, 'hash' => $hash, 'context_type' => $i === 0 ? 'seo_title' : 'text' ];
    if ( $i < 18 ) {
        $wpdb->insert( $index, [
            'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qc',
            'translated_text' => 'QC ' . $text, 'context_type' => $i === 0 ? 'seo_title' : 'text', 'status' => 'auto',
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ] );
    } else {
        $wpdb->insert( $queue, [
            'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qc',
            'context_type' => 'text', 'priority' => 5, 'status' => $i === 18 ? 'failed' : 'pending',
            'attempts' => $i === 18 ? 3 : 0, 'error_message' => $i === 18 ? 'Historical provider error' : null,
            'created_at' => current_time( 'mysql' ), 'processed_at' => $i === 18 ? current_time( 'mysql' ) : null,
        ] );
    }
}
$current_failed_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $queue WHERE source_hash=%s AND target_lang='qc'", $hashes[18] ) );

$historical_ids = [];
for ( $i = 0; $i < 10; $i++ ) {
    $text = 'obsolete redesign source ' . $i;
    $hash = md5( $text );
    $hashes[] = $hash;
    $wpdb->insert( $queue, [
        'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qc',
        'context_type' => 'text', 'priority' => 10, 'status' => 'failed', 'attempts' => 3,
        'error_message' => 'Old redesign error', 'created_at' => date( 'Y-m-d H:i:s', time() - 86400 ),
        'processed_at' => date( 'Y-m-d H:i:s', time() - 86400 ),
    ] );
    $historical_ids[] = (int) $wpdb->insert_id;
}
for ( $i = 0; $i < 10; $i++ ) {
    $text = 'obsolete pending source ' . $i;
    $hash = md5( $text );
    $hashes[] = $hash;
    $wpdb->insert( $queue, [
        'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qc',
        'context_type' => 'text', 'priority' => 10, 'status' => 'pending', 'attempts' => 0,
        'created_at' => date( 'Y-m-d H:i:s', time() - 86400 ),
    ] );
}
for ( $i = 0; $i < 10; $i++ ) {
    $text = 'obsolete translated source ' . $i;
    $hash = md5( $text );
    $hashes[] = $hash;
    $wpdb->insert( $index, [
        'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qc',
        'translated_text' => 'QC ' . $text, 'context_type' => 'text', 'status' => 'auto',
        'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
    ] );
}

$resource = GML_Resource_Identity::from_parts( 'post', 970001, '', 'page', home_url( '/current-corpus/' ), 'r1' );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $nodes ), 'current-corpus manifest is saved' );

$public_queries = [];
$query_watcher = static function( $sql ) use ( &$public_queries ) {
    $public_queries[] = $sql;
    return $sql;
};
add_filter( 'query', $query_watcher );
GML_Translation_Readiness::clear_cache();
$public_map = GML_Translation_Readiness::readiness_map();
remove_filter( 'query', $query_watcher );
gml_db_assert( empty( $public_map['qc'] ), 'public readiness withholds incomplete current coverage' );
gml_db_assert( ! array_filter( $public_queries, static function( $sql ) use ( $queue ) {
    return strpos( $sql, $queue ) !== false;
} ), 'public readiness never scans queue history' );

GML_Translation_Readiness::clear_cache();
$stats = GML_Translation_Readiness::language_statistics()['qc'];
gml_db_assert( $stats['required'] === 20 && $stats['translated'] === 18, 'historical translations do not inflate current coverage' );
gml_db_assert( $stats['pending'] === 1 && $stats['failed'] === 1, 'only current unresolved queue rows affect current counts' );
gml_db_assert( $stats['historical_failed'] === 11 && $stats['historical_irrelevant'] === 10, 'obsolete failures remain available as history' );
gml_db_assert( ! GML_Translation_Readiness::language_is_index_ready( 'qc' ), '90 percent current coverage remains withheld' );
gml_db_assert( GML_Queue_Processor::get_actionable_failure_counts()['total'] === 1, 'safety accounting ignores obsolete redesign failures' );

$scope = GML_Translation_Readiness::current_queue_scope_sql( 'q' );
gml_db_assert( $scope !== '', 'completed manifest inventory activates current queue scope' );
$current_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue q WHERE q.status='pending' AND $scope" );
gml_db_assert( $current_pending === 1, 'normal queue scope excludes obsolete pending work' );

$retried = GML_Queue_Processor::retry_failed( 'qc', 1 );
$sample = (array) get_option( GML_Queue_Processor::SAMPLE_OPTION, [] );
gml_db_assert( $retried === 1 && $sample === [ $current_failed_id ], 'limited retry selects the current failure before older obsolete rows' );
$wpdb->update( $queue, [ 'status' => 'failed', 'attempts' => 3, 'error_message' => 'Historical provider error' ], [ 'id' => $current_failed_id ] );
delete_option( GML_Queue_Processor::SAMPLE_OPTION );

$queue_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue WHERE target_lang='qc'" );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, array_slice( $nodes, 0, 18 ) ), 'redesign manifest drops removed source hashes' );
GML_Translation_Readiness::clear_cache();
$stats = GML_Translation_Readiness::language_statistics()['qc'];
gml_db_assert( $stats['required'] === 18 && $stats['translated'] === 18 && $stats['failed'] === 0 && $stats['pending'] === 0, 'removed redesign text no longer occupies current progress' );
gml_db_assert( $stats['historical_failed'] === 11 && $stats['historical_irrelevant'] === 11, 'redesign keeps every historical failure without counting it as current' );
gml_db_assert( GML_Translation_Readiness::language_is_index_ready( 'qc' ), 'fully translated current corpus becomes index-ready' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue WHERE target_lang='qc'" ) === $queue_count_before, 'manifest replacement never deletes queue audit history' );

update_option( 'gml_resource_backfill_state', [ 'status' => 'pending', 'phase' => 'posts', 'cursor' => 1, 'updated_at' => time() ], false );
GML_Translation_Readiness::clear_cache();
gml_db_assert( ! GML_Translation_Readiness::language_is_index_ready( 'qc' ), 'incomplete current-site inventory fails closed' );
gml_db_assert( GML_Translation_Readiness::current_queue_scope_sql( 'q' ) === '', 'queue filtering waits for a complete site inventory' );

$wpdb->query( "DELETE FROM $queue WHERE target_lang='qc'" );
$wpdb->query( "DELETE FROM $index WHERE target_lang='qc'" );
foreach ( [ $readiness, $relations, $manifests ] as $table ) $wpdb->query( "DELETE FROM $table" );
delete_option( GML_Queue_Processor::SAMPLE_OPTION );
delete_option( GML_Queue_Processor::FAILURE_ACK_OPTION );
wp_set_current_user( 0 );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'current-corpus regression makes no provider or HTTP calls' );
echo "OK current-corpus readiness and queue scoping\n";
