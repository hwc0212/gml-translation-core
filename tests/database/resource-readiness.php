<?php
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

$home = getenv( 'GML_TEST_HOME' ) ?: 'http://gml-regression.test';
update_option( 'home', untrailingslashit( $home ) );
update_option( 'siteurl', untrailingslashit( $home ) );

gml_db_assert( class_exists( 'GML_Resource_Identity' ), 'Phase 2B immutable resource identity is available' );
gml_db_assert( class_exists( 'GML_Resource_Manifest_Store' ), 'Phase 2B manifest store is available' );
gml_db_assert( class_exists( 'GML_Resource_Readiness' ), 'Phase 2B resource readiness service is available' );
gml_db_assert( class_exists( 'GML_Resource_Manifest_Discovery' ), 'Phase 2B authoritative discovery is available' );

$installed = GML_Installer::activate();
gml_db_assert( true === $installed, 'Phase 2B additive schema installs' );

global $wpdb;
$manifest_table = $wpdb->prefix . 'gml_resource_manifests';
$relation_table = $wpdb->prefix . 'gml_resource_strings';
$readiness_table = $wpdb->prefix . 'gml_resource_readiness';
$index_table = $wpdb->prefix . 'gml_index';
$queue_table = $wpdb->prefix . 'gml_queue';

$wpdb->query( "DELETE FROM $index_table WHERE source_text LIKE 'phase2b%'" );
$wpdb->query( "DELETE FROM $queue_table WHERE source_text LIKE 'phase2b%'" );

foreach ( [ $readiness_table, $relation_table, $manifest_table ] as $table ) {
    gml_db_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table, basename( $table ) . ' exists' );
    $wpdb->query( "DELETE FROM $table" );
}

update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [
    [ 'code' => 'qa', 'enabled' => true, 'site_mode' => 'local', 'url_prefix' => '/qa/' ],
] );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );
delete_option( 'gml_api_key_encrypted' );
delete_option( 'gml_deepseek_api_key_encrypted' );
GML_Translation_Readiness::clear_cache();
gml_db_assert( GML_Translation_State::multilingual_enabled(), 'multilingual state is enabled for the resource provider fixture' );

// Resource identity covers object, role, taxonomy and explicit archive types.
register_post_type( 'phase2b_product', [ 'public' => true, 'has_archive' => true ] );
register_post_type( 'phase2b_cpt', [ 'public' => true, 'has_archive' => true ] );
register_taxonomy( 'phase2b_product_cat', 'phase2b_product', [ 'public' => true ] );
register_taxonomy( 'phase2b_product_tag', 'phase2b_product', [ 'public' => true ] );
register_taxonomy( 'phase2b_tax', 'phase2b_cpt', [ 'public' => true ] );
function gml_phase2b_term( $name, $taxonomy, $slug ) {
    $existing = term_exists( $slug, $taxonomy );
    if ( $existing ) return is_array( $existing ) ? $existing : [ 'term_id' => (int) $existing ];
    return wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
}
$page_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Phase 2B page', 'post_content' => 'Phase 2B page body' ] );
$product_id = wp_insert_post( [ 'post_type' => 'phase2b_product', 'post_status' => 'publish', 'post_title' => 'Phase 2B product' ] );
$cpt_id = wp_insert_post( [ 'post_type' => 'phase2b_cpt', 'post_status' => 'publish', 'post_title' => 'Phase 2B CPT' ] );
$category = gml_phase2b_term( 'Phase 2B category', 'category', 'phase2b-category' );
$tag = gml_phase2b_term( 'Phase 2B tag', 'post_tag', 'phase2b-tag' );
$product_category = gml_phase2b_term( 'Phase 2B product category', 'phase2b_product_cat', 'phase2b-product-category' );
$product_tag = gml_phase2b_term( 'Phase 2B product tag', 'phase2b_product_tag', 'phase2b-product-tag' );
$custom_term = gml_phase2b_term( 'Phase 2B custom term', 'phase2b_tax', 'phase2b-custom-term' );
gml_db_assert( strpos( GML_Resource_Identity::for_post( $page_id )->get_key(), 'post:page:' ) === 0, 'page has a typed immutable resource key' );
gml_db_assert( strpos( GML_Resource_Identity::for_post( $product_id )->get_key(), 'post:phase2b_product:' ) === 0, 'product has a typed immutable resource key' );
gml_db_assert( strpos( GML_Resource_Identity::for_post( $cpt_id )->get_key(), 'post:phase2b_cpt:' ) === 0, 'public CPT has a typed immutable resource key' );
gml_db_assert( GML_Resource_Identity::front_page()->get_type() === 'role' && GML_Resource_Identity::posts_page()->get_type() === 'role', 'homepage and posts page use role identities' );
gml_db_assert( GML_Resource_Identity::for_term( $category['term_id'], 'category' )->get_taxonomy() === 'category', 'category resource resolves' );
gml_db_assert( GML_Resource_Identity::for_term( $tag['term_id'], 'post_tag' )->get_taxonomy() === 'post_tag', 'tag resource resolves' );
gml_db_assert( GML_Resource_Identity::for_term( $product_category['term_id'], 'phase2b_product_cat' )->get_taxonomy() === 'phase2b_product_cat', 'product category resource resolves' );
gml_db_assert( GML_Resource_Identity::for_term( $product_tag['term_id'], 'phase2b_product_tag' )->get_taxonomy() === 'phase2b_product_tag', 'product tag resource resolves' );
gml_db_assert( GML_Resource_Identity::for_term( $custom_term['term_id'], 'phase2b_tax' )->get_taxonomy() === 'phase2b_tax', 'custom taxonomy resource resolves' );
gml_db_assert( GML_Resource_Identity::for_archive( 'phase2b_cpt' )->get_type() === 'archive', 'explicit public CPT archive resolves' );
gml_db_assert( ! GML_Resource_Identity::excluded( 'search' )->is_eligible(), 'non-indexable request classes can be represented only as excluded resources' );
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
wp_clear_scheduled_hook( GML_Resource_Manifest_Manager::DIRTY_HOOK );

function gml_phase2b_nodes( $prefix, $count, $translated = 0 ) {
    global $wpdb;
    $nodes = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $text = $prefix . ' source ' . $i;
        $hash = md5( $text );
        $nodes[] = [
            'text'         => $text,
            'hash'         => $hash,
            'context_type' => $i === 0 ? 'seo_title' : 'text',
        ];
        if ( $i < $translated ) {
            $wpdb->replace( $wpdb->prefix . 'gml_index', [
                'source_hash'     => $hash,
                'source_text'     => $text,
                'source_lang'     => 'en',
                'target_lang'     => 'qa',
                'translated_text' => 'QA ' . $text,
                'context_type'    => $i === 0 ? 'seo_title' : 'text',
                'status'          => 'auto',
                'created_at'      => current_time( 'mysql' ),
                'updated_at'      => current_time( 'mysql' ),
            ] );
        }
    }
    return $nodes;
}

function gml_phase2b_resource( $id, $slug, $revision = 'r1' ) {
    return GML_Resource_Identity::from_parts(
        'post',
        $id,
        '',
        'page',
        home_url( '/' . trim( $slug, '/' ) . '/' ),
        $revision
    );
}

// Historical table totals cannot certify a language before current manifests exist.
for ( $i = 0; $i < 97; $i++ ) {
    $text = 'phase2b global translated ' . $i;
    $wpdb->replace( $index_table, [
        'source_hash' => md5( $text ), 'source_text' => $text,
        'source_lang' => 'en', 'target_lang' => 'qa', 'translated_text' => 'QA ' . $text,
        'context_type' => 'text', 'status' => 'auto',
        'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
    ] );
}
for ( $i = 0; $i < 3; $i++ ) {
    $text = 'phase2b global pending ' . $i;
    $wpdb->replace( $queue_table, [
        'source_hash' => md5( $text ), 'source_text' => $text,
        'source_lang' => 'en', 'target_lang' => 'qa', 'context_type' => 'text',
        'priority' => 5, 'status' => 'pending', 'attempts' => 0,
        'created_at' => current_time( 'mysql' ),
    ] );
}
GML_Translation_Readiness::clear_cache();
gml_db_assert( ! GML_Translation_Readiness::language_is_index_ready( 'qa' ), 'historical 97 percent coverage cannot certify an undiscovered current corpus' );

$resource_a = gml_phase2b_resource( 910001, 'phase2b-page-a' );
$resource_b = gml_phase2b_resource( 910002, 'phase2b-page-b' );
$nodes_a = gml_phase2b_nodes( 'phase2b-a', 100, 100 );
$nodes_b = gml_phase2b_nodes( 'phase2b-b', 100, 60 );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource_a, $nodes_a ), 'Page A manifest saved' );
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource_b, $nodes_b ), 'Page B manifest saved' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_a, 'qa' ) === 'complete', 'unrelated Page A remains complete' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_b, 'qa' ) === 'incomplete', 'Page B stays incomplete at 60 percent despite language readiness' );

// A source edit invalidates only the edited resource before rediscovery.
GML_Resource_Manifest_Store::mark_stale( $resource_b, 'r2' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_b, 'qa' ) === 'stale', 'source edit immediately makes Page B stale' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_a, 'qa' ) === 'complete', 'source edit does not dirty Page A' );

// The actual save hook dirties one object and schedules only background work.
$managed_page = GML_Resource_Identity::for_post( $page_id );
GML_Resource_Manifest_Store::save_complete( $managed_page, gml_phase2b_nodes( 'phase2b-managed', 2, 2 ) );
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
$http_before_edit = (int) $GLOBALS['gml_test_http_calls'];
GML_Resource_Manifest_Manager::post_changed( $page_id, get_post( $page_id ), true );
$dirty_after_edit = (array) get_option( GML_Resource_Manifest_Manager::DIRTY_OPTION, [] );
gml_db_assert( $dirty_after_edit === [ $managed_page->get_key() ], 'one source edit synchronously dirties only that resource' );
gml_db_assert( GML_Resource_Readiness::get_status( $managed_page, 'qa' ) === 'stale', 'save hook immediately invalidates the edited resource snapshot' );
gml_db_assert( (int) $GLOBALS['gml_test_http_calls'] === $http_before_edit, 'source edit performs no synchronous authoritative render' );
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
wp_clear_scheduled_hook( GML_Resource_Manifest_Manager::DIRTY_HOOK );
GML_Resource_Manifest_Store::save_complete( $managed_page, gml_phase2b_nodes( 'phase2b-managed-publish', 2, 2 ) );
wp_update_post( [ 'ID' => $page_id, 'post_status' => 'draft' ] );
GML_Resource_Manifest_Manager::post_changed( $page_id, get_post( $page_id ), true );
gml_db_assert( GML_Resource_Readiness::get_status( $managed_page->get_key(), 'qa' ) === 'stale', 'unpublishing content invalidates its previously complete resource manifest' );
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
wp_clear_scheduled_hook( GML_Resource_Manifest_Manager::DIRTY_HOOK );

// Existing/manual Translation Memory satisfies hashes without AI work.
for ( $i = 60; $i < 94; $i++ ) {
    $node = $nodes_b[ $i ];
    $wpdb->replace( $index_table, [
        'source_hash' => $node['hash'], 'source_text' => $node['text'],
        'source_lang' => 'en', 'target_lang' => 'qa', 'translated_text' => 'QA ' . $node['text'],
        'context_type' => 'text', 'status' => 'auto',
        'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
    ] );
}
gml_db_assert( true === GML_Resource_Manifest_Store::save_complete( $resource_b, $nodes_b ), 'Page B authoritative manifest refreshed' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_b, 'qa' ) === 'incomplete', '94 percent remains incomplete' );
$translator = new GML_Translator();
$last = $nodes_b[94];
gml_db_assert( true === $translator->save_to_index( $last['hash'], $last['text'], 'Manual QA text', 'en', 'qa', 'text', 'manual' ), 'manual Translation Memory row saved' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_b, 'qa' ) === 'stale', 'manual Translation Memory save fails closed before asynchronous readiness rebuild' );
GML_Resource_Readiness::run_rebuild_batch( 'test' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_b, 'qa' ) === 'complete', 'manual Translation Memory reaches 95 percent without AI' );

// Removing a source relation does not delete historical Translation Memory.
$history_resource = gml_phase2b_resource( 910003, 'phase2b-history' );
$history_nodes = gml_phase2b_nodes( 'phase2b-history', 2, 2 );
GML_Resource_Manifest_Store::save_complete( $history_resource, $history_nodes );
GML_Resource_Manifest_Store::save_complete( $history_resource, [ $history_nodes[0] ] );
$history_manifest = GML_Resource_Manifest_Store::get_by_key( $history_resource->get_key() );
$history_relations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $relation_table WHERE resource_id=%d", $history_manifest->id ) );
$historical_tm = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $index_table WHERE source_hash=%s", $history_nodes[1]['hash'] ) );
gml_db_assert( $history_relations === 1 && $historical_tm === 1, 'removed source leaves the next manifest but remains in historical Translation Memory' );

// A shared global generation invalidates old snapshots without rendering all resources.
$before_global = GML_Resource_Manifest_Manager::global_generation();
GML_Resource_Manifest_Manager::bump_global_generation( 'test_menu_change' );
gml_db_assert( GML_Resource_Manifest_Manager::global_generation() === $before_global + 1, 'global generation bumps once' );
GML_Resource_Manifest_Manager::bump_global_generation( 'same_request_duplicate' );
gml_db_assert( GML_Resource_Manifest_Manager::global_generation() === $before_global + 1, 'global generation does not bump twice in one request' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_a, 'qa' ) === 'stale', 'global content change makes Page A manifest stale' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_b, 'qa' ) === 'stale', 'global content change makes Page B manifest stale' );

// One translation asset can satisfy 100 resource relationships.
$shared_text = 'phase2b shared source hash';
$shared_node = [ [ 'text' => $shared_text, 'hash' => md5( $shared_text ), 'context_type' => 'text' ] ];
$shared_resources = [];
for ( $i = 1; $i <= 100; $i++ ) {
    $resource = gml_phase2b_resource( 920000 + $i, 'phase2b-shared-' . $i );
    $shared_resources[] = $resource;
    GML_Resource_Manifest_Store::save_complete( $resource, $shared_node );
}
$relation_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $relation_table WHERE source_hash=%s", md5( $shared_text ) ) );
gml_db_assert( $relation_count === 100, 'one shared hash creates 100 resource relationships' );
gml_db_assert( true === $translator->save_to_index( md5( $shared_text ), $shared_text, 'QA shared text', 'en', 'qa', 'text', 'auto' ), 'shared translation asset saved once' );
$asset_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $index_table WHERE source_hash=%s AND source_lang='en' AND target_lang='qa'", md5( $shared_text ) ) );
gml_db_assert( $asset_count === 1, 'shared hash remains one Translation Memory asset' );
$shared_stale = GML_Resource_Readiness::get_bulk_statuses( $shared_resources, [ 'qa' ] );
gml_db_assert( count( array_filter( $shared_stale, static function( $row ) { return ( $row['qa'] ?? '' ) === 'stale'; } ) ) === 100, 'one shared Translation Memory save fails closed for all 100 related resources' );
GML_Resource_Readiness::run_rebuild_batch( 'test' );
$shared_statuses = GML_Resource_Readiness::get_bulk_statuses( $shared_resources, [ 'qa' ] );
gml_db_assert( count( array_filter( $shared_statuses, static function( $row ) { return ( $row['qa'] ?? '' ) === 'complete'; } ) ) === 100, 'one translation recalculates all 100 related resources' );
$wpdb->delete( $index_table, [ 'source_hash' => md5( $shared_text ), 'source_lang' => 'en', 'target_lang' => 'qa' ] );
GML_Resource_Readiness::translation_changed( md5( $shared_text ), 'qa' );
$shared_stale_after_delete = GML_Resource_Readiness::get_bulk_statuses( $shared_resources, [ 'qa' ] );
gml_db_assert( count( array_filter( $shared_stale_after_delete, static function( $row ) { return ( $row['qa'] ?? '' ) === 'stale'; } ) ) === 100, 'deleting one Translation Memory asset fails closed before asynchronous rebuild' );
GML_Resource_Readiness::run_rebuild_batch( 'test' );
$shared_after_delete = GML_Resource_Readiness::get_bulk_statuses( $shared_resources, [ 'qa' ] );
gml_db_assert( count( array_filter( $shared_after_delete, static function( $row ) { return ( $row['qa'] ?? '' ) === 'incomplete'; } ) ) === 100, 'deleting one Translation Memory asset recalculates all related resources without duplicate queue jobs' );

// Authoritative anonymous rendering works without AI and never enqueues work.
$render_resource = gml_phase2b_resource( 930001, 'phase2b-authoritative' );
$render_text = 'phase2b authoritative source';
$translator->save_to_index( md5( $render_text ), $render_text, 'QA authoritative text', 'en', 'qa', 'text', 'manual' );
$GLOBALS['gml_phase2b_http_mode'] = 'success';
$GLOBALS['gml_phase2b_http_request'] = [];
add_filter( 'pre_http_request', static function( $preempt, $args, $url ) use ( $render_text ) {
    if ( strpos( (string) $url, 'gml_crawl=1' ) === false ) return $preempt;
    $GLOBALS['gml_phase2b_http_request'] = [ 'args' => $args, 'url' => $url ];
    if ( $GLOBALS['gml_phase2b_http_mode'] === 'error' ) return new WP_Error( 'phase2b_render_failed', 'render failed' );
    return [
        'headers'  => [ 'content-type' => 'text/html; charset=UTF-8' ],
        'body'     => '<!doctype html><html><head><title>' . esc_html( $render_text ) . '</title></head><body><p>' . esc_html( $render_text ) . '</p></body></html>',
        'response' => [ 'code' => 200, 'message' => 'OK' ],
        'cookies'  => [],
        'filename' => null,
    ];
}, 999, 3 );
$queue_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" );
$paused_before = get_option( 'gml_translation_paused', false );
update_option( 'gml_translation_paused', true );
$discovery = new GML_Resource_Manifest_Discovery();
gml_db_assert( true === $discovery->discover( $render_resource ), 'authoritative manifest discovery succeeds with AI disabled' );
update_option( 'gml_translation_paused', $paused_before );
$queue_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" );
gml_db_assert( $queue_before === $queue_after, 'AI-disabled discovery does not enqueue paid work' );
gml_db_assert( empty( $GLOBALS['gml_phase2b_http_request']['args']['cookies'] ), 'authoritative request is cookie-free' );
gml_db_assert( (int) $GLOBALS['gml_phase2b_http_request']['args']['redirection'] === 0, 'authoritative request refuses redirects' );
gml_db_assert( ! empty( $GLOBALS['gml_phase2b_http_request']['args']['headers']['X-GML-Crawl'] ), 'authoritative request is signed' );
$expected_path = rtrim( wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/phase2b-authoritative/';
echo 'METRIC phase2b_authoritative_path=' . wp_parse_url( $GLOBALS['gml_phase2b_http_request']['url'], PHP_URL_PATH ) . "\n";
gml_db_assert( untrailingslashit( wp_parse_url( $GLOBALS['gml_phase2b_http_request']['url'], PHP_URL_PATH ) ) === untrailingslashit( $expected_path ), 'authoritative request preserves the install path exactly once' );

$failed_resource = gml_phase2b_resource( 930002, 'phase2b-render-error' );
$GLOBALS['gml_phase2b_http_mode'] = 'error';
$failed = $discovery->discover( $failed_resource );
gml_db_assert( is_wp_error( $failed ), 'failed authoritative render returns an error' );
gml_db_assert( GML_Resource_Readiness::get_status( $failed_resource, 'qa' ) === 'render_error', 'failed authoritative render never certifies a complete manifest' );

// Bulk status reads are bounded: one query for one resource, two chunks for 1000.
$bulk_resources = [];
$global_generation = GML_Resource_Manifest_Manager::global_generation();
$now = current_time( 'mysql' );
for ( $chunk = 0; $chunk < 5; $chunk++ ) {
    $manifest_values = [];
    $manifest_args = [];
    for ( $i = 1; $i <= 200; $i++ ) {
        $n = $chunk * 200 + $i;
        $key = 'post:page:' . ( 940000 + $n );
        $bulk_resources[] = $key;
        $manifest_values[] = '(%s,%s,%d,%s,%s,%s,%s,%d,%s,%d,%d,%d,%s,%s,%s,%s)';
        array_push( $manifest_args, $key, 'post', 940000 + $n, '', 'page', hash( 'sha256', home_url( '/phase2b-bulk-' . $n . '/' ) ), 'bulk-r1', 1, hash( 'sha256', $key ), $global_generation, 1, 0, 'complete', $now, $now, $now );
    }
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $manifest_table (resource_key,resource_type,object_id,taxonomy,variant,source_url_hash,source_revision,manifest_generation,manifest_fingerprint,global_generation,required_count,critical_count,discovery_state,created_at,updated_at,discovered_at) VALUES " . implode( ',', $manifest_values ) .
        ' ON DUPLICATE KEY UPDATE global_generation=VALUES(global_generation), discovery_state=VALUES(discovery_state), updated_at=VALUES(updated_at)',
        $manifest_args
    ) );
}
$bulk_ids = $wpdb->get_results( "SELECT id,resource_key FROM $manifest_table WHERE resource_key LIKE 'post:page:94%'" );
for ( $chunk = 0; $chunk < count( $bulk_ids ); $chunk += 200 ) {
    $values = [];
    $args = [];
    foreach ( array_slice( $bulk_ids, $chunk, 200 ) as $row ) {
        foreach ( [ 'qa', 'qb', 'qc' ] as $lang ) {
            $values[] = '(%d,%s,%d,%d,%d,%d,%d,%s,%s)';
            array_push( $args, (int) $row->id, $lang, 1, $global_generation, 1, 1, 0, 'complete', $now );
        }
    }
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $readiness_table (resource_id,target_lang,manifest_generation,global_generation,required_count,translated_count,critical_missing_count,status,calculated_at) VALUES " . implode( ',', $values ) .
        ' ON DUPLICATE KEY UPDATE status=VALUES(status),global_generation=VALUES(global_generation)',
        $args
    ) );
}

$query_before = (int) $wpdb->num_queries;
$single = GML_Resource_Readiness::get_all_statuses( $bulk_resources[0] );
$single_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( $single_queries === 1 && ( $single['qa'] ?? '' ) === 'complete', 'one resource all-language status uses one DB query' );
$query_before = (int) $wpdb->num_queries;
$bulk = GML_Resource_Readiness::get_bulk_statuses( $bulk_resources, [ 'qa', 'qb', 'qc' ] );
$bulk_queries = (int) $wpdb->num_queries - $query_before;
gml_db_assert( $bulk_queries <= 2 && count( $bulk ) === 1000, '1000 resources and multiple languages use two bounded bulk reads' );

// Persistent-cache data is advisory only; newer DB generations win.
$cache_key = 'resource_status:' . md5( $resource_a->get_key() );
wp_cache_set( $cache_key, [ 'qa' => 'complete' ], 'gml_translate', 600 );
GML_Resource_Manifest_Store::mark_stale( $resource_a, 'redis-newer-db' );
gml_db_assert( GML_Resource_Readiness::get_status( $resource_a, 'qa' ) === 'stale', 'Redis complete cannot override newer DB stale generation' );

// Provider object mode never falls back to the global language metric.
$provider = new GML_Translation_Provider();
$provider_languages = $provider->get_languages();
echo 'METRIC phase2b_provider_state=' . ( GML_Translation_State::multilingual_enabled() ? 'enabled' : 'disabled' ) . ';utils=' . ( class_exists( 'GML_Language_Utils' ) ? 'yes' : 'no' ) . ';languages=' . implode( ',', $provider_languages ) . "\n";
$unknown_status = $provider->get_translation_status( 999999999, 'qa' );
echo 'METRIC phase2b_unknown_object_status=' . $unknown_status . "\n";
gml_db_assert( $unknown_status === 'unknown', 'unknown object status does not fall back to language readiness' );
gml_db_assert( method_exists( $provider, 'get_resource_statuses_bulk' ) && method_exists( $provider, 'get_resource_alternate_candidates_bulk' ), 'object-aware bulk provider API is available' );

$languages_before_external = get_option( 'gml_languages', [] );
update_option( 'gml_languages', array_merge( $languages_before_external, [
    [ 'code' => 'qx', 'enabled' => true, 'site_mode' => 'external', 'external_url' => 'https://external.example/' ],
] ) );
$external_resource = gml_phase2b_resource( 950001, 'phase2b-external' );
GML_Resource_Manifest_Store::save_complete( $external_resource, [ [ 'text' => 'phase2b external source', 'hash' => md5( 'phase2b external source' ), 'context_type' => 'text' ] ] );
gml_db_assert( GML_Resource_Readiness::get_status( $external_resource, 'qx' ) === 'external_unverified', 'external language resources remain explicitly unverified in machine readiness' );
update_option( 'gml_languages', $languages_before_external );

// Backfill lifecycle is explicit, pausable and independent from AI work.
$queue_before_backfill = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" );
GML_Resource_Backfill::pause();
gml_db_assert( GML_Resource_Backfill::state()['status'] === 'paused' && ! wp_next_scheduled( GML_Resource_Backfill::HOOK ), 'shadow backfill can be paused explicitly' );
GML_Resource_Backfill::resume();
gml_db_assert( GML_Resource_Backfill::state()['status'] === 'pending' && wp_next_scheduled( GML_Resource_Backfill::HOOK ), 'shadow backfill can resume from persisted state' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" ) === $queue_before_backfill, 'backfill lifecycle never starts AI or changes the translation queue' );
GML_Resource_Backfill::run_batch();
$backfill_after_batch = GML_Resource_Backfill::state();
gml_db_assert( $backfill_after_batch['phase'] === 'posts' && $backfill_after_batch['status'] === 'pending', 'bounded backfill persists its next phase after one role batch' );
gml_db_assert( ! GML_Atomic_Option_Lock::is_active( GML_Resource_Backfill::LOCK ), 'backfill releases its owner-safe lease after the batch' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" ) === $queue_before_backfill, 'executing a shadow backfill batch still creates no AI queue work' );
$http_before_post_batch = (int) $GLOBALS['gml_test_http_calls'];
GML_Resource_Backfill::run_batch();
$backfill_post_batch = GML_Resource_Backfill::state();
$post_batch_http = (int) $GLOBALS['gml_test_http_calls'] - $http_before_post_batch;
gml_db_assert( $backfill_post_batch['phase'] !== 'roles' && $post_batch_http <= GML_Resource_Backfill::BATCH, 'post backfill advances by stable ID in a bounded five-resource batch' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table" ) === $queue_before_backfill, 'post backfill remains provider-free with AI disabled' );
GML_Resource_Backfill::pause();
wp_clear_scheduled_hook( GML_Resource_Backfill::HOOK );

echo 'METRIC phase2b_single_status_queries=' . $single_queries . "\n";
echo 'METRIC phase2b_1000_status_queries=' . $bulk_queries . "\n";
echo 'METRIC phase2b_backfill_post_batch_renders=' . $post_batch_http . "\n";
echo 'OK Phase 2B shadow resource readiness for ' . $home . "\n";
