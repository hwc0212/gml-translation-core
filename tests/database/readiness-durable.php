<?php
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

$installed = GML_Installer::activate();
gml_db_assert( true === $installed, 'Phase 2B.1 readiness schema is available' );

global $wpdb;
$manifest_table  = $wpdb->prefix . 'gml_resource_manifests';
$relation_table  = $wpdb->prefix . 'gml_resource_strings';
$readiness_table = $wpdb->prefix . 'gml_resource_readiness';
$index_table     = $wpdb->prefix . 'gml_index';
$queue_table     = $wpdb->prefix . 'gml_queue';
$global          = max( 1, (int) get_option( 'gml_resource_manifest_global_generation', 1 ) );
$now             = current_time( 'mysql' );

update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [
    [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ],
] );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );
update_option( 'gml_translation_paused', true );

$wpdb->query( "DELETE r FROM $readiness_table r INNER JOIN $manifest_table m ON m.id=r.resource_id WHERE m.resource_key LIKE 'post:page:phase2b1-%'" );
$wpdb->query( "DELETE s FROM $relation_table s INNER JOIN $manifest_table m ON m.id=s.resource_id WHERE m.resource_key LIKE 'post:page:phase2b1-%'" );
$wpdb->query( "DELETE FROM $manifest_table WHERE resource_key LIKE 'post:page:phase2b1-%'" );
delete_option( 'gml_resource_readiness_reverse_state' );
delete_option( GML_Resource_Readiness::REBUILD_LOCK );
wp_clear_scheduled_hook( 'gml_resource_readiness_reverse' );
wp_clear_scheduled_hook( GML_Resource_Readiness::REBUILD_HOOK );

function gml_phase2b1_insert_translation( $hash, $text = '' ) {
    global $wpdb, $index_table, $now;
    $text = $text !== '' ? $text : 'phase2b1 ' . $hash;
    return false !== $wpdb->replace( $index_table, [
        'source_hash' => $hash,
        'source_text' => $text,
        'source_lang' => 'en',
        'target_lang' => 'qa',
        'translated_text' => 'QA ' . $text,
        'context_type' => 'text',
        'status' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ] );
}

function gml_phase2b1_insert_fanout( $label, $count, array $hashes ) {
    global $wpdb, $manifest_table, $relation_table, $readiness_table, $global, $now;
    $keys = [];
    for ( $offset = 0; $offset < $count; $offset += 200 ) {
        $manifest_values = [];
        $manifest_args   = [];
        $limit           = min( $count, $offset + 200 );
        for ( $i = $offset; $i < $limit; $i++ ) {
            $key = 'post:page:phase2b1-' . $label . '-' . $i;
            $keys[] = $key;
            $manifest_values[] = '(%s,%s,%d,%s,%s,%s,%s,%d,%s,%d,%d,%d,%s,%s,%s,%s)';
            array_push(
                $manifest_args,
                $key,
                'post',
                0,
                '',
                'page',
                hash( 'sha256', home_url( '/' . $key . '/' ) ),
                'phase2b1-r1',
                1,
                hash( 'sha256', $key ),
                $global,
                count( $hashes ),
                0,
                'complete',
                $now,
                $now,
                $now
            );
        }
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $manifest_table (resource_key,resource_type,object_id,taxonomy,variant,source_url_hash,source_revision,manifest_generation,manifest_fingerprint,global_generation,required_count,critical_count,discovery_state,created_at,updated_at,discovered_at) VALUES " . implode( ',', $manifest_values ),
            $manifest_args
        ) );
    }

    foreach ( array_chunk( $keys, 200 ) as $key_chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $key_chunk ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,resource_key FROM $manifest_table WHERE resource_key IN ($placeholders)",
            $key_chunk
        ) );
        $relation_values  = [];
        $relation_args    = [];
        $readiness_values = [];
        $readiness_args   = [];
        foreach ( $rows as $row ) {
            foreach ( $hashes as $hash ) {
                $relation_values[] = '(%d,%d,%s,%s,%s,%d,%s)';
                array_push( $relation_args, (int) $row->id, 1, $hash, 'text', '', 0, $now );
            }
            $readiness_values[] = '(%d,%s,%d,%d,%d,%d,%d,%s,%s)';
            array_push( $readiness_args, (int) $row->id, 'qa', 1, $global, count( $hashes ), count( $hashes ), 0, 'complete', $now );
        }
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $relation_table (resource_id,manifest_generation,source_hash,context_type,context_key,critical,created_at) VALUES " . implode( ',', $relation_values ),
            $relation_args
        ) );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $readiness_table (resource_id,target_lang,manifest_generation,global_generation,required_count,translated_count,critical_missing_count,status,calculated_at) VALUES " . implode( ',', $readiness_values ),
            $readiness_args
        ) );
    }
    return $keys;
}

function gml_phase2b1_status_count( $label, $status ) {
    global $wpdb, $manifest_table, $readiness_table;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $readiness_table r INNER JOIN $manifest_table m ON m.id=r.resource_id WHERE m.resource_key LIKE %s AND r.target_lang='qa' AND r.status=%s",
        'post:page:phase2b1-' . $label . '-%',
        $status
    ) );
}

function gml_phase2b1_complete_for_hash( $hash ) {
    global $wpdb, $manifest_table, $relation_table, $readiness_table;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT r.resource_id) FROM $readiness_table r INNER JOIN $manifest_table m ON m.id=r.resource_id INNER JOIN $relation_table s ON s.resource_id=m.id AND s.manifest_generation=m.manifest_generation WHERE s.source_hash=%s AND r.target_lang='qa' AND r.status='complete'",
        $hash
    ) );
}

function gml_phase2b1_drain( $label, $limit = 30 ) {
    for ( $i = 0; $i < $limit; $i++ ) {
        $pending = gml_phase2b1_status_count( $label, 'stale' ) + gml_phase2b1_status_count( $label, 'rebuilding' );
        if ( $pending === 0 ) return $i;
        GML_Resource_Readiness::run_rebuild_batch( 'test' );
    }
    throw new RuntimeException( 'FAIL: durable readiness worker did not converge for ' . $label );
}

// Upgrade from the Phase 2B table adds only the bounded-worker index.
$rows_before_upgrade = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $readiness_table" );
$index_before = $wpdb->get_var( "SHOW INDEX FROM $readiness_table WHERE Key_name='status_id'", 2 );
if ( $index_before !== null ) $wpdb->query( "ALTER TABLE $readiness_table DROP INDEX status_id" );
update_option( 'gml_db_version', '3.0.0', false );
$upgraded = GML_Installer::activate();
$index_after = $wpdb->get_var( "SHOW INDEX FROM $readiness_table WHERE Key_name='status_id'", 2 );
gml_db_assert( true === $upgraded && $index_after === 'status_id', 'Phase 2B table upgrades in place with status_id index' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $readiness_table" ) === $rows_before_upgrade, 'readiness index migration preserves existing rows' );

// Clear unrelated stale work left by earlier shadow-readiness fixtures.
for ( $i = 0; $i < 30 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM $readiness_table WHERE status='stale'" ) > 0; $i++ ) {
    GML_Resource_Readiness::run_rebuild_batch( 'test-prime' );
}

// This exact sequence left 100 rows falsely complete in rc.13: the second
// high-fanout hash overwrote the first hash's single continuation option.
$hash_a = md5( 'phase2b1 high fanout A' );
$hash_b = md5( 'phase2b1 high fanout B' );
gml_phase2b1_insert_translation( $hash_a );
gml_phase2b1_insert_translation( $hash_b );
gml_phase2b1_insert_fanout( 'legacy-a', 600, [ $hash_a ] );
gml_phase2b1_insert_fanout( 'legacy-b', 600, [ $hash_b ] );

$queue_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" );
$http_before  = (int) $GLOBALS['gml_test_http_calls'];
GML_Resource_Readiness::translation_changed( $hash_a, 'qa' );
$continuation_a = (array) get_option( 'gml_resource_readiness_reverse_state', [] );
GML_Resource_Readiness::translation_changed( $hash_b, 'qa' );
$continuation_b = (array) get_option( 'gml_resource_readiness_reverse_state', [] );
$lost_complete  = gml_phase2b1_complete_for_hash( $hash_a );
if (
    ( $continuation_a['source_hash'] ?? '' ) === $hash_a
    && ( $continuation_b['source_hash'] ?? '' ) === $hash_b
    && $lost_complete > 0
) {
    echo 'REPRO phase2b1_single_continuation_overwrite=' . $lost_complete . "\n";
}
gml_db_assert( $lost_complete === 0, 'a second high-fanout hash cannot leave the first hash falsely complete' );
gml_db_assert( gml_phase2b1_status_count( 'legacy-a', 'stale' ) === 600 && gml_phase2b1_status_count( 'legacy-b', 'stale' ) === 600, 'all rows from both competing hashes fail closed' );
gml_db_assert( false === get_option( 'gml_resource_readiness_reverse_state', false ), 'new invalidation stores no per-hash continuation cursor' );
gml_phase2b1_drain( 'legacy-a' );
gml_phase2b1_drain( 'legacy-b' );

// A pre-upgrade continuation is consumed once and expanded into durable rows.
$wpdb->query( "UPDATE $readiness_table r INNER JOIN $manifest_table m ON m.id=r.resource_id SET r.status='complete' WHERE m.resource_key LIKE 'post:page:phase2b1-legacy-a-%'" );
update_option( 'gml_resource_readiness_reverse_state', [ 'source_hash' => $hash_a, 'target_lang' => 'qa', 'after_id' => 500 ], false );
GML_Resource_Readiness::migrate_legacy_continuation();
gml_db_assert( gml_phase2b1_status_count( 'legacy-a', 'stale' ) === 600, 'legacy continuation migrates the whole hash fanout into durable stale rows' );
gml_db_assert( false === get_option( 'gml_resource_readiness_reverse_state', false ), 'legacy continuation option is removed after durable migration' );
gml_phase2b1_drain( 'legacy-a' );

// Query cost for atomic invalidation is constant across 100 and 1,500 rows.
$hash_100 = md5( 'phase2b1 fanout 100' );
gml_phase2b1_insert_translation( $hash_100 );
gml_phase2b1_insert_fanout( 'fanout-100', 100, [ $hash_100 ] );
GML_Resource_Readiness::ensure_recovery_schedule();
$query_before = (int) $wpdb->num_queries;
GML_Resource_Readiness::translation_changed( $hash_100, 'qa' );
$mark_100_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( gml_phase2b1_status_count( 'fanout-100', 'stale' ) === 100, 'one hash atomically marks 100 resources stale' );
gml_phase2b1_drain( 'fanout-100' );
gml_db_assert( gml_phase2b1_status_count( 'fanout-100', 'complete' ) === 100, '100-resource fanout fully converges' );

$hash_1500 = md5( 'phase2b1 fanout 1500' );
gml_phase2b1_insert_translation( $hash_1500 );
gml_phase2b1_insert_fanout( 'fanout-1500', 1500, [ $hash_1500 ] );
$query_before = (int) $wpdb->num_queries;
GML_Resource_Readiness::translation_changed( $hash_1500, 'qa' );
$mark_1500_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( gml_phase2b1_status_count( 'fanout-1500', 'stale' ) === 1500, 'one hash atomically marks 1,500 resources stale' );
$query_before = (int) $wpdb->num_queries;
$rebuilt_first = GML_Resource_Readiness::run_rebuild_batch( 'metric' );
$rebuild_500_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( $rebuilt_first === 500 && gml_phase2b1_status_count( 'fanout-1500', 'complete' ) === 500, 'one bounded worker rebuilds exactly 500 readiness rows' );
gml_phase2b1_drain( 'fanout-1500' );
gml_db_assert( gml_phase2b1_status_count( 'fanout-1500', 'complete' ) === 1500, '1,500-resource fanout fully converges' );

// Three hashes can change before a worker runs. The same 1,501 resources are
// represented by one unique readiness row each and are recalculated once.
$overlap_hashes = [
    md5( 'phase2b1 overlap one' ),
    md5( 'phase2b1 overlap two' ),
    md5( 'phase2b1 overlap three' ),
];
foreach ( $overlap_hashes as $hash ) gml_phase2b1_insert_translation( $hash );
gml_phase2b1_insert_fanout( 'overlap', 1501, $overlap_hashes );
foreach ( $overlap_hashes as $hash ) GML_Resource_Readiness::translation_changed( $hash, 'qa' );
gml_db_assert( gml_phase2b1_status_count( 'overlap', 'stale' ) === 1501, 'three simultaneous hashes preserve all overlapping stale rows above 1,500 fanout' );
$claimed_overlap = 0;
$claim_counter = static function( $count ) use ( &$claimed_overlap ) { $claimed_overlap += (int) $count; };
add_action( 'gml_resource_readiness_batch_claimed', $claim_counter );
gml_phase2b1_drain( 'overlap' );
remove_action( 'gml_resource_readiness_batch_claimed', $claim_counter );
$overlap_rows = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM $readiness_table r INNER JOIN $manifest_table m ON m.id=r.resource_id WHERE m.resource_key LIKE 'post:page:phase2b1-overlap-%' AND r.target_lang='qa'"
);
gml_db_assert( $overlap_rows === 1501 && $claimed_overlap === 1501, 'overlapping hashes deduplicate to one final row and one rebuild per resource-language snapshot' );
gml_db_assert( gml_phase2b1_status_count( 'overlap', 'complete' ) === 1501, 'three simultaneous high-fanout hashes fully converge' );

// A stale persistent-cache value is advisory; DB invalidation remains visible.
wp_cache_set( 'resource_status:' . md5( 'post:page:phase2b1-overlap-0' ), [ 'qa' => 'complete' ], 'gml_translate', 600 );
GML_Resource_Readiness::translation_changed( $overlap_hashes[0], 'qa' );
gml_db_assert( GML_Resource_Readiness::get_status( 'post:page:phase2b1-overlap-0', 'qa' ) === 'stale', 'stale Redis complete cannot override authoritative DB stale state' );
gml_phase2b1_drain( 'overlap' );

// A newer invalidation during calculation fences an older claimed result.
$race_hash = md5( 'phase2b1 repeated hash race' );
gml_phase2b1_insert_translation( $race_hash );
gml_phase2b1_insert_fanout( 'race', 100, [ $race_hash ] );
GML_Resource_Readiness::translation_changed( $race_hash, 'qa' );
$reinvalidated = false;
$race_callback = static function() use ( $race_hash, &$reinvalidated ) {
    if ( $reinvalidated ) return;
    $reinvalidated = true;
    GML_Resource_Readiness::translation_changed( $race_hash, 'qa' );
};
add_action( 'gml_resource_readiness_batch_claimed', $race_callback );
GML_Resource_Readiness::run_rebuild_batch( 'race-owner' );
remove_action( 'gml_resource_readiness_batch_claimed', $race_callback );
gml_db_assert( $reinvalidated && gml_phase2b1_status_count( 'race', 'stale' ) === 100, 'newer invalidation fences an older worker result for the same hash' );
gml_phase2b1_drain( 'race' );
gml_db_assert( gml_phase2b1_status_count( 'race', 'complete' ) === 100, 'repeated hash change converges on the subsequent durable rebuild' );

// Crash after claim: the lock and 500 rebuilding rows survive process exit.
$crash_hash = md5( 'phase2b1 crash recovery' );
gml_phase2b1_insert_translation( $crash_hash );
gml_phase2b1_insert_fanout( 'crash', 600, [ $crash_hash ] );
GML_Resource_Readiness::translation_changed( $crash_hash, 'qa' );
$output = [];
$exit_code = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/readiness-crash-worker.php' ), $output, $exit_code );
gml_db_assert( $exit_code === 86, 'readiness worker process terminates immediately after claiming its batch' );
gml_db_assert( gml_phase2b1_status_count( 'crash', 'rebuilding' ) === 500 && gml_phase2b1_status_count( 'crash', 'stale' ) === 100, 'crash leaves bounded durable DB work without a continuation token' );
$crashed_lock = GML_Atomic_Option_Lock::get( GML_Resource_Readiness::REBUILD_LOCK );
gml_db_assert( $crashed_lock['token'] !== '', 'crashed worker lease remains owner-fenced until expiry' );
$wpdb->update(
    $wpdb->options,
    [ 'option_value' => serialize( [ 'version' => 1, 'token' => $crashed_lock['token'], 'expires' => time() - 1 ] ) ],
    [ 'option_name' => GML_Resource_Readiness::REBUILD_LOCK ]
);
wp_cache_delete( GML_Resource_Readiness::REBUILD_LOCK, 'options' );
wp_cache_delete( 'notoptions', 'options' );
$expired_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - GML_Resource_Readiness::CLAIM_TTL - 10 );
$wpdb->query( $wpdb->prepare(
    "UPDATE $readiness_table r INNER JOIN $manifest_table m ON m.id=r.resource_id SET r.calculated_at=%s WHERE m.resource_key LIKE 'post:page:phase2b1-crash-%' AND r.status='rebuilding'",
    $expired_at
) );
gml_phase2b1_drain( 'crash' );
gml_db_assert( gml_phase2b1_status_count( 'crash', 'complete' ) === 600, 'subsequent worker recovers all claimed and unclaimed rows from DB state alone' );

// Deterministic two-worker ownership check using the product-neutral lock.
$lock_hash = md5( 'phase2b1 lock ownership' );
gml_phase2b1_insert_translation( $lock_hash );
gml_phase2b1_insert_fanout( 'lock', 600, [ $lock_hash ] );
GML_Resource_Readiness::translation_changed( $lock_hash, 'qa' );
$owner = GML_Atomic_Option_Lock::acquire( GML_Resource_Readiness::REBUILD_LOCK, GML_Resource_Readiness::CLAIM_TTL );
gml_db_assert( $owner !== '', 'first readiness worker owns the bounded lease' );
$second_written = GML_Resource_Readiness::run_rebuild_batch( 'second-worker' );
gml_db_assert( $second_written === 0 && gml_phase2b1_status_count( 'lock', 'rebuilding' ) === 0, 'second readiness worker cannot double-own or claim the first owner work' );
gml_db_assert( GML_Atomic_Option_Lock::release( GML_Resource_Readiness::REBUILD_LOCK, $owner ), 'first readiness worker releases only its own lease' );
gml_phase2b1_drain( 'lock' );
gml_db_assert( gml_phase2b1_status_count( 'lock', 'complete' ) === 600, 'work converges after the valid owner releases the lease' );

gml_db_assert( $mark_100_queries <= 12, 'marking 100 affected resources uses bounded query count' );
gml_db_assert( $mark_1500_queries <= 12, 'marking 1,500 affected resources uses bounded query count' );
gml_db_assert( $rebuild_500_queries <= 25, 'rebuilding 500 resource-language rows avoids per-resource queries' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" ) === $queue_before, 'all readiness invalidation and recovery leaves translation queue counts unchanged' );
gml_db_assert( (int) $GLOBALS['gml_test_http_calls'] === $http_before, 'readiness invalidation and recovery makes no external HTTP or AI request' );
gml_db_assert( get_option( 'gml_translation_paused' ) == true && get_option( 'gml_ai_translation_enabled' ) == false, 'readiness recovery works while translation generation is paused and AI is disabled' );

echo 'METRIC phase2b1_mark_100_queries=' . $mark_100_queries . "\n";
echo 'METRIC phase2b1_mark_1500_queries=' . $mark_1500_queries . "\n";
echo 'METRIC phase2b1_rebuild_500_queries=' . $rebuild_500_queries . "\n";
echo "OK Phase 2B.1 durable readiness invalidation and recovery\n";
