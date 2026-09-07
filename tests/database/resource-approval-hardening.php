<?php
/** Phase 2C.1 adversarial snapshot, mutation, and transaction regressions. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

$home = getenv( 'GML_TEST_HOME' ) ?: 'http://gml-regression.test';
update_option( 'home', untrailingslashit( $home ) );
update_option( 'siteurl', untrailingslashit( $home ) );
gml_db_assert( GML_Installer::activate() === true, 'Phase 2C.1 additive schema installs' );
gml_db_assert( method_exists( 'GML_Resource_Approval', 'expected_snapshot' ), 'review API exposes an exact expected snapshot tuple' );
gml_db_assert( method_exists( 'GML_Translation_Memory', 'upsert_batch' ), 'Translation Memory exposes one Core mutation contract' );

global $wpdb;
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ] ] );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );
$gml_phase2c1_run_token = substr( hash( 'sha256', uniqid( 'phase2c1-', true ) ), 0, 12 );
$gml_phase2c1_resource_base = 100000000 + ( hexdec( substr( $gml_phase2c1_run_token, 0, 8 ) ) % 1000000000 );

function gml_phase2c1_resource( $id, $revision = 'r1', $type = 'post' ) {
    return GML_Resource_Identity::from_parts( $type, $id, $type === 'term' ? 'category' : '', 'page', home_url( '/phase2c1-' . $id . '/' ), $revision );
}

function gml_phase2c1_seed( $prefix, $status = 'auto' ) {
    global $gml_phase2c1_run_token;
    $nodes = [];
    $records = [];
    for ( $i = 0; $i < 3; $i++ ) {
        $text = 'phase2c1 ' . $gml_phase2c1_run_token . ' ' . $prefix . ' source ' . $i;
        $hash = md5( $text );
        $context = $i === 0 ? 'seo_title' : 'text';
        $nodes[] = [ 'text' => $text, 'hash' => $hash, 'context_type' => $context ];
        $records[] = [
            'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'qa',
            'translated_text' => 'QA ' . $text, 'context_type' => $context, 'status' => $status,
        ];
    }
    $result = GML_Translation_Memory::upsert_batch( $records, false );
    gml_db_assert( is_array( $result ) && $result['written'] === 3, $prefix . ' fixture translations use the Core mutation service' );
    return [ $nodes, $records ];
}

function gml_phase2c1_decide( $resource, $decision, $note = '' ) {
    $status = GML_Resource_Approval::get_status( $resource, 'qa' );
    $expected = GML_Resource_Approval::expected_snapshot( $status );
    return $decision === 'approved'
        ? GML_Resource_Approval::approve( $resource, 'qa', 1, $note, $expected )
        : GML_Resource_Approval::reject( $resource, 'qa', 1, $note, $expected );
}

list( $nodes, $records ) = gml_phase2c1_seed( 'cas' );
$resource = gml_phase2c1_resource( $gml_phase2c1_resource_base + 1 );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource, $nodes ), 'CAS fixture manifest is machine complete' );
$opened = GML_Resource_Approval::get_status( $resource, 'qa' );
$old_form = GML_Resource_Approval::expected_snapshot( $opened );
$approved = GML_Resource_Approval::approve( $resource, 'qa', 1, 'First reviewer.', $old_form );
gml_db_assert( ! is_wp_error( $approved ) && $approved['review_status'] === 'approved', 'first reviewer approves the displayed snapshot' );
$audit_before = count( GML_Resource_Approval::get_audit( $resource, 'qa' ) );
$stale_decision = GML_Resource_Approval::reject( $resource, 'qa', 2, 'Second reviewer saw the old state.', $old_form );
gml_db_assert( is_wp_error( $stale_decision ) && $stale_decision->get_error_code() === 'gml_review_conflict', 'a concurrent stale decision cannot overwrite a newer human decision' );
gml_db_assert( count( GML_Resource_Approval::get_audit( $resource, 'qa' ) ) === $audit_before, 'a decision conflict appends no audit event' );

$manifest_table = GML_Resource_Manifest_Store::manifest_table();
$readiness_table = GML_Resource_Manifest_Store::readiness_table();
$version_table = GML_Resource_Approval::version_table();
$manifest = GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
$resource_id = (int) $manifest->id;
$baseline = GML_Resource_Approval::get_status( $resource, 'qa' );
$expected = GML_Resource_Approval::expected_snapshot( $baseline );
$audit_before = count( GML_Resource_Approval::get_audit( $resource, 'qa' ) );

$cases = [
    'manifest fingerprint' => [ "UPDATE $manifest_table SET manifest_fingerprint='" . str_repeat( 'a', 64 ) . "' WHERE id=$resource_id", "UPDATE $manifest_table SET manifest_fingerprint='" . esc_sql( $baseline['manifest_fingerprint'] ) . "' WHERE id=$resource_id" ],
    'manifest generation' => [ "UPDATE $manifest_table SET manifest_generation=manifest_generation+1 WHERE id=$resource_id", "UPDATE $manifest_table SET manifest_generation=" . (int) $baseline['manifest_generation'] . " WHERE id=$resource_id" ],
    'global generation' => [ "UPDATE $manifest_table SET global_generation=global_generation+1 WHERE id=$resource_id", "UPDATE $manifest_table SET global_generation=" . (int) $baseline['global_generation'] . " WHERE id=$resource_id" ],
    'translation generation' => [ "UPDATE $version_table SET generation=generation+1 WHERE resource_id=$resource_id AND target_lang='qa'", "UPDATE $version_table SET generation=" . (int) $baseline['translation_generation'] . " WHERE resource_id=$resource_id AND target_lang='qa'" ],
    'translation fingerprint' => [ "UPDATE $readiness_table SET translation_fingerprint='" . str_repeat( 'b', 64 ) . "' WHERE resource_id=$resource_id AND target_lang='qa'", "UPDATE $readiness_table SET translation_fingerprint='" . esc_sql( $baseline['translation_fingerprint'] ) . "' WHERE resource_id=$resource_id AND target_lang='qa'" ],
    'machine incomplete' => [ "UPDATE $readiness_table SET status='incomplete' WHERE resource_id=$resource_id AND target_lang='qa'", "UPDATE $readiness_table SET status='complete' WHERE resource_id=$resource_id AND target_lang='qa'" ],
    'machine stale' => [ "UPDATE $readiness_table SET status='stale' WHERE resource_id=$resource_id AND target_lang='qa'", "UPDATE $readiness_table SET status='complete' WHERE resource_id=$resource_id AND target_lang='qa'" ],
    'render error' => [ "UPDATE $manifest_table SET discovery_state='render_error' WHERE id=$resource_id", "UPDATE $manifest_table SET discovery_state='complete' WHERE id=$resource_id" ],
];
foreach ( $cases as $label => $queries ) {
    gml_db_assert( false !== $wpdb->query( $queries[0] ), $label . ' test mutation applies' );
    $conflict = GML_Resource_Approval::approve( $resource, 'qa', 1, 'Old form.', $expected );
    gml_db_assert( is_wp_error( $conflict ) && $conflict->get_error_code() === 'gml_review_conflict', $label . ' mismatch rejects the old Review form' );
    gml_db_assert( false !== $wpdb->query( $queries[1] ), $label . ' test mutation is restored' );
}
gml_db_assert( count( GML_Resource_Approval::get_audit( $resource, 'qa' ) ) === $audit_before, 'all stale snapshot conflicts preserve the audit log' );

$before_import = GML_Resource_Approval::get_status( $resource, 'qa' );
$records[1]['translated_text'] = 'QA imported replacement';
$imported = GML_Translation_Memory::upsert_batch( [ $records[1] ], true );
gml_db_assert( is_array( $imported ) && $imported['written'] === 1, 'import-style overwrite uses one Core batch mutation' );
$import_stale = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert( $import_stale['machine_status'] === 'stale' && $import_stale['review_status'] === 'stale', 'import overwrite immediately invalidates readiness and human approval' );
GML_Resource_Readiness::run_rebuild_batch( 'phase2c1-import' );
$import_rebuilt = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert( $import_rebuilt['translation_generation'] === $before_import['translation_generation'] + 1, 'batch import increments an affected reviewed resource once' );
gml_db_assert( ! hash_equals( $before_import['translation_fingerprint'], $import_rebuilt['translation_fingerprint'] ) && $import_rebuilt['review_status'] === 'stale', 'rebuilt import fingerprint cannot resurrect the old approval' );

$reapproved = gml_phase2c1_decide( $resource, 'approved', 'Reviewed imported replacement.' );
$import_noop = GML_Translation_Memory::upsert_batch( [ $records[1] ], true );
gml_db_assert(
    is_array( $import_noop ) && $import_noop['written'] === 0 && $import_noop['unchanged'] === 1,
    'identical importer write is an explicit no-op'
);
$import_noop_status = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert(
    $import_noop_status['review_status'] === 'approved'
        && $import_noop_status['translation_generation'] === $reapproved['translation_generation']
        && hash_equals( $import_noop_status['translation_fingerprint'], $reapproved['translation_fingerprint'] ),
    'importer no-op preserves the approved snapshot'
);

list( $protected_nodes, $protected_records ) = gml_phase2c1_seed( 'manual-protection', 'manual' );
$protected_resource = gml_phase2c1_resource( $gml_phase2c1_resource_base + 20 );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $protected_resource, $protected_nodes ), 'manual-protection fixture is machine complete' );
$protected_approved = gml_phase2c1_decide( $protected_resource, 'approved', 'Reviewed manual translation.' );
$protected_auto = $protected_records[0];
$protected_auto['status'] = 'auto';
$protected_auto['translated_text'] = 'Automatic result must not replace manual work';
$protected_result = GML_Translation_Memory::upsert_batch( [ $protected_auto ], true );
$protected_after = GML_Resource_Approval::get_status( $protected_resource, 'qa' );
$protected_row = $wpdb->get_row( $wpdb->prepare(
    "SELECT translated_text,status FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND source_lang='en' AND target_lang='qa'",
    $protected_auto['source_hash']
) );
gml_db_assert(
    is_array( $protected_result ) && $protected_result['written'] === 0 && $protected_result['skipped_manual'] === 1,
    'protected automatic write is reported as skipped'
);
gml_db_assert(
    $protected_row && $protected_row->status === 'manual'
        && hash_equals( $protected_records[0]['translated_text'], (string) $protected_row->translated_text ),
    'automatic translation cannot overwrite protected manual Translation Memory'
);
gml_db_assert(
    $protected_after['review_status'] === 'approved'
        && $protected_after['translation_generation'] === $protected_approved['translation_generation']
        && hash_equals( $protected_after['translation_fingerprint'], $protected_approved['translation_fingerprint'] ),
    'skipped automatic replacement preserves the approved snapshot'
);

$auto_before = $import_noop_status;
$auto_record = $records[2];
$auto_record['translated_text'] = 'QA automatic replacement';
$auto_replaced = GML_Translation_Memory::upsert_batch( [ $auto_record ], true );
gml_db_assert(
    is_array( $auto_replaced ) && $auto_replaced['written'] === 1,
    'changed automatic translation uses the Core mutation contract'
);
$auto_stale = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert(
    $auto_stale['machine_status'] === 'stale' && $auto_stale['review_status'] === 'stale',
    'changed automatic translation immediately invalidates readiness and human approval'
);
GML_Resource_Readiness::run_rebuild_batch( 'phase2c1-auto-replacement' );
$auto_rebuilt = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert(
    $auto_rebuilt['translation_generation'] === $auto_before['translation_generation'] + 1
        && ! hash_equals( $auto_before['translation_fingerprint'], $auto_rebuilt['translation_fingerprint'] )
        && $auto_rebuilt['review_status'] === 'stale',
    'automatic replacement advances one generation and cannot resurrect the old approval'
);

$reapproved = gml_phase2c1_decide( $resource, 'approved', 'Reviewed automatic replacement.' );
gml_db_assert( ! is_wp_error( $reapproved ) && $reapproved['review_status'] === 'approved', 'automatic replacement can be explicitly reviewed again' );
$legacy_before = $reapproved;
gml_db_assert( false !== $wpdb->update( $wpdb->prefix . 'gml_index', [ 'translated_text' => 'QA legacy writer replacement' ], [
    'source_hash' => $nodes[2]['hash'], 'source_lang' => 'en', 'target_lang' => 'qa',
] ), 'legacy writer changes an effective translation without the modern generation protocol' );
gml_db_assert( false !== $wpdb->update( $readiness_table, [ 'status' => 'stale' ], [ 'resource_id' => $resource_id, 'target_lang' => 'qa' ] ), 'legacy writer leaves durable readiness stale for re-entry' );
GML_Resource_Readiness::run_rebuild_batch( 'phase2c1-legacy-reentry' );
$legacy_after = GML_Resource_Approval::get_status( $resource, 'qa' );
gml_db_assert( $legacy_after['translation_generation'] === $legacy_before['translation_generation'], 'legacy writer simulation does not know the modern translation generation' );
gml_db_assert( ! hash_equals( $legacy_before['translation_fingerprint'], $legacy_after['translation_fingerprint'] ) && $legacy_after['review_status'] === 'stale', 're-entry fingerprint keeps a legacy-writer approval stale' );

list( $import_nodes ) = gml_phase2c1_seed( 'imported-unreviewed' );
$import_resource = gml_phase2c1_resource( $gml_phase2c1_resource_base + 2 );
GML_Resource_Manifest_Store::save_complete( $import_resource, $import_nodes );
$import_status = GML_Resource_Approval::get_status( $import_resource, 'qa' );
gml_db_assert( $import_status['machine_status'] === 'complete' && $import_status['review_status'] === 'unreviewed', 'imported complete resource remains human-unreviewed' );

list( $manual_nodes ) = gml_phase2c1_seed( 'manual-unreviewed', 'manual' );
$manual_resource = gml_phase2c1_resource( $gml_phase2c1_resource_base + 3 );
GML_Resource_Manifest_Store::save_complete( $manual_resource, $manual_nodes );
$manual_status = GML_Resource_Approval::get_status( $manual_resource, 'qa' );
gml_db_assert( $manual_status['machine_status'] === 'complete' && $manual_status['review_status'] === 'unreviewed', 'manual Translation Memory also remains human-unreviewed' );

$start_audit = count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) );
$fail_start = static function( $allowed, $command ) { return $command === 'START TRANSACTION' ? false : $allowed; };
add_filter( 'gml_resource_approval_transaction_command', $fail_start, 10, 2 );
$start_result = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $manual_status ) );
remove_filter( 'gml_resource_approval_transaction_command', $fail_start, 10 );
gml_db_assert( is_wp_error( $start_result ) && $start_result->get_error_code() === 'gml_review_transaction', 'START TRANSACTION failure is reported and fails closed' );
gml_db_assert( count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) ) === $start_audit, 'START failure writes no review audit event' );

$fail_commit = static function( $allowed, $command ) { return $command === 'COMMIT' ? false : $allowed; };
add_filter( 'gml_resource_approval_transaction_command', $fail_commit, 10, 2 );
$commit_result = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $manual_status ) );
remove_filter( 'gml_resource_approval_transaction_command', $fail_commit, 10 );
$commit_status = GML_Resource_Approval::get_status( $manual_resource, 'qa' );
gml_db_assert( is_wp_error( $commit_result ) && $commit_result->get_error_code() === 'gml_review_transaction', 'COMMIT failure never reports approval success' );
gml_db_assert( $commit_status['review_status'] === 'unreviewed' && count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) ) === $start_audit, 'COMMIT failure rolls back current decision and audit insert' );

$audit_table = GML_Resource_Approval::audit_table();
gml_db_assert( false !== $wpdb->query( "ALTER TABLE $audit_table ENGINE=MyISAM" ), 'storage-engine test creates a non-transactional audit table' );
$engine_health = GML_Resource_Approval::transaction_health( true );
$engine_result = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $manual_status ) );
gml_db_assert( ! $engine_health['ready'] && is_wp_error( $engine_result ) && $engine_result->get_error_code() === 'gml_review_storage_engine', 'Human Review refuses non-transactional table engines' );
gml_db_assert( false !== $wpdb->query( "ALTER TABLE $audit_table ENGINE=InnoDB" ), 'storage-engine test restores InnoDB' );
gml_db_assert( GML_Resource_Approval::transaction_health( true )['ready'], 'all Human Review transaction tables are InnoDB' );

// Fail each critical Human Review write in a real MariaDB transaction.
$review_table = GML_Resource_Approval::review_table();
$version_trigger = 'test_gml_phase2c1_fail_version';
$review_insert_trigger = 'test_gml_phase2c1_fail_review_insert';
$audit_trigger = 'test_gml_phase2c1_fail_audit';
$review_update_trigger = 'test_gml_phase2c1_fail_review_update';
foreach ( [ $version_trigger, $review_insert_trigger, $audit_trigger, $review_update_trigger ] as $trigger ) {
    $wpdb->query( "DROP TRIGGER IF EXISTS $trigger" );
}

gml_db_assert( false !== $wpdb->query(
    "CREATE TRIGGER $version_trigger BEFORE INSERT ON $version_table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='phase2c1 version failure'"
), 'translation-version failure trigger is installed' );
$version_failure = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $manual_status ) );
$wpdb->query( "DROP TRIGGER IF EXISTS $version_trigger" );
$version_failure_status = GML_Resource_Approval::get_status( $manual_resource, 'qa' );
gml_db_assert( is_wp_error( $version_failure ) && $version_failure->get_error_code() === 'gml_review_write', 'translation-version write failure fails closed' );
gml_db_assert( $version_failure_status['review_status'] === 'unreviewed' && count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) ) === $start_audit, 'translation-version failure keeps decision and audit unchanged' );

gml_db_assert( false !== $wpdb->query(
    "CREATE TRIGGER $review_insert_trigger BEFORE INSERT ON $review_table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='phase2c1 review insert failure'"
), 'current-review insert failure trigger is installed' );
$review_insert_failure = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $manual_status ) );
$wpdb->query( "DROP TRIGGER IF EXISTS $review_insert_trigger" );
$review_insert_status = GML_Resource_Approval::get_status( $manual_resource, 'qa' );
gml_db_assert( is_wp_error( $review_insert_failure ) && $review_insert_failure->get_error_code() === 'gml_review_write', 'current-review insert failure fails closed' );
gml_db_assert( $review_insert_status['review_status'] === 'unreviewed' && count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) ) === $start_audit, 'current-review insert failure rolls back version and audit state' );

gml_db_assert( false !== $wpdb->query(
    "CREATE TRIGGER $audit_trigger BEFORE INSERT ON $audit_table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='phase2c1 audit failure'"
), 'audit insert failure trigger is installed' );
$audit_failure = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $manual_status ) );
$wpdb->query( "DROP TRIGGER IF EXISTS $audit_trigger" );
$audit_failure_status = GML_Resource_Approval::get_status( $manual_resource, 'qa' );
gml_db_assert( is_wp_error( $audit_failure ) && $audit_failure->get_error_code() === 'gml_review_write', 'audit insert failure fails closed' );
gml_db_assert( $audit_failure_status['review_status'] === 'unreviewed' && count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) ) === $start_audit, 'audit insert failure rolls back the current-review insert' );

$query_before = (int) $wpdb->num_queries;
$first_page = GML_Resource_Approval::list_resources( [ 'page' => 1, 'per_page' => 2 ] );
$first_page_queries = (int) $wpdb->num_queries - $query_before;
$query_before = (int) $wpdb->num_queries;
$next_page = GML_Resource_Approval::list_resources( [ 'page' => 2, 'per_page' => 2 ] );
$next_page_queries = (int) $wpdb->num_queries - $query_before;
$query_before = (int) $wpdb->num_queries;
$language_filter = GML_Resource_Approval::list_resources( [ 'languages' => [ 'qa' ], 'page' => 1, 'per_page' => 25 ] );
$language_filter_queries = (int) $wpdb->num_queries - $query_before;
$query_before = (int) $wpdb->num_queries;
$state_filter = GML_Resource_Approval::list_resources( [ 'review_state' => 'unreviewed', 'page' => 1, 'per_page' => 25 ] );
$state_filter_queries = (int) $wpdb->num_queries - $query_before;
$query_before = (int) $wpdb->num_queries;
$filtered = GML_Resource_Approval::list_resources( [ 'languages' => [ 'qa' ], 'review_state' => 'unreviewed', 'page' => 1, 'per_page' => 25 ] );
$filtered_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert(
    $first_page_queries === 2 && $next_page_queries === 2 && $language_filter_queries === 2
        && $state_filter_queries === 2 && $filtered_queries === 2 && $filtered['total'] >= 2,
    'Review pagination, language, state, and combined filters each use two bounded SQL queries'
);
$query_before = (int) $wpdb->num_queries;
$detail = GML_Resource_Approval::get_review_payload( $manual_resource, 'qa', 1, 2 );
$detail_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( ! is_wp_error( $detail ) && $detail_queries <= 5, 'one Review detail page uses bounded queries' );

$query_before = (int) $wpdb->num_queries;
$approved_manual = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, 'Measured approval.', GML_Resource_Approval::expected_snapshot( $manual_status ) );
$approve_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( ! is_wp_error( $approved_manual ), 'measured approval succeeds after transactional engine recovery' );

$approved_audit_count = count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) );
gml_db_assert( false !== $wpdb->query(
    "CREATE TRIGGER $review_update_trigger BEFORE UPDATE ON $review_table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='phase2c1 review update failure'"
), 'current-review update failure trigger is installed' );
$review_update_failure = GML_Resource_Approval::reject( $manual_resource, 'qa', 1, 'Injected update failure.', GML_Resource_Approval::expected_snapshot( $approved_manual ) );
$wpdb->query( "DROP TRIGGER IF EXISTS $review_update_trigger" );
$review_update_status = GML_Resource_Approval::get_status( $manual_resource, 'qa' );
gml_db_assert( is_wp_error( $review_update_failure ) && $review_update_failure->get_error_code() === 'gml_review_write', 'current-review update failure fails closed' );
gml_db_assert( $review_update_status['review_status'] === 'approved' && count( GML_Resource_Approval::get_audit( $manual_resource, 'qa' ) ) === $approved_audit_count, 'current-review update failure preserves the approved decision and audit history' );

$query_before = (int) $wpdb->num_queries;
$reject_manual = GML_Resource_Approval::reject( $manual_resource, 'qa', 1, 'Measured rejection.', GML_Resource_Approval::expected_snapshot( $approved_manual ) );
$reject_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( ! is_wp_error( $reject_manual ), 'measured rejection succeeds for the refreshed decision revision' );
$query_before = (int) $wpdb->num_queries;
$conflict_manual = GML_Resource_Approval::approve( $manual_resource, 'qa', 1, '', GML_Resource_Approval::expected_snapshot( $approved_manual ) );
$conflict_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( is_wp_error( $conflict_manual ) && $conflict_manual->get_error_code() === 'gml_review_conflict', 'measured stale decision conflicts' );

echo 'METRIC phase2c1_first_page_queries=' . $first_page_queries . "\n";
echo 'METRIC phase2c1_next_page_queries=' . $next_page_queries . "\n";
echo 'METRIC phase2c1_language_filter_queries=' . $language_filter_queries . "\n";
echo 'METRIC phase2c1_state_filter_queries=' . $state_filter_queries . "\n";
echo 'METRIC phase2c1_filtered_list_queries=' . $filtered_queries . "\n";
echo 'METRIC phase2c1_detail_queries=' . $detail_queries . "\n";
echo 'METRIC phase2c1_approve_queries=' . $approve_queries . "\n";
echo 'METRIC phase2c1_reject_queries=' . $reject_queries . "\n";
echo 'METRIC phase2c1_conflict_queries=' . $conflict_queries . "\n";
echo 'OK Phase 2C.1 snapshot-safe Human Review hardening for ' . $home . "\n";
