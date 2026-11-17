<?php
/** A metadata-only edit must recover publication without using a provider. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
gml_db_assert( GML_Installer::activate() === true, 'inventory fixture schema is ready' );
update_option( 'blog_public', 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', false );
update_option( 'gml_translation_paused', true );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', array_map( static function( $code ) { return [ 'code'=>$code, 'enabled'=>true ]; }, [ 'de','es','ru' ] ) );
update_option( GML_Resource_Backfill::OPTION, [ 'status'=>'complete', 'phase'=>'complete', 'cursor'=>0, 'updated_at'=>time() ], false );
$id = wp_insert_post( [ 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'Inventory recovery fixture', 'post_content'=>'A source description.' ] );
update_post_meta( $id, '_seopress_titles_desc', 'A source description. ' );
$resource = GML_Resource_Identity::for_post( $id );
$key = $resource->get_key();
$mode = 'success'; $during_render = null; $requests = [];
add_filter( 'pre_http_request', static function( $pre, $args, $url ) use ( &$mode, &$during_render, &$requests, $id ) {
    $requests[] = $url;
    gml_db_assert( strpos( $url, 'gml_crawl=1' ) !== false, 'HTTP is restricted to source rendering' );
    if ( $mode === 'failure' ) return new WP_Error( 'gml_test_transient', 'Synthetic timeout' );
    if ( $during_render ) { $callback=$during_render; $during_render=null; $callback(); }
    return [ 'response'=>[ 'code'=>200 ], 'headers'=>[ 'content-type'=>'text/html' ],
        'body'=>'<html><head><title>Inventory recovery fixture</title><meta name="description" content="'.esc_attr(get_post_meta($id,'_seopress_titles_desc',true)).'"></head><body><p>A source description.</p></body></html>' ];
}, 999, 3 );
$discovery = new GML_Resource_Manifest_Discovery();
gml_db_assert( $discovery->discover( $key ) === true, 'initial authoritative inventory saved' );
$index=$wpdb->prefix.'gml_index'; $queue=$wpdb->prefix.'gml_queue';
$nodes=(new GML_HTML_Parser())->parse('<html><head><title>Inventory recovery fixture</title><meta name="description" content="A source description."></head><body><p>A source description.</p></body></html>')['nodes'];
foreach ( [ 'de','es','ru' ] as $lang ) foreach ( $nodes as $node ) {
    $wpdb->replace( $index, [ 'source_hash'=>$node['hash'], 'source_text'=>$node['text'], 'source_lang'=>'en', 'target_lang'=>$lang, 'translated_text'=>'Translated '.$node['text'], 'context_type'=>$node['context_type'], 'status'=>'manual', 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql') ] );
}
GML_Resource_Readiness::recalculate_resources( [ GML_Resource_Manifest_Store::get_by_key($key)->id ] );
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
$fixture_hashes=array_values(array_unique(array_column($nodes,'hash')));
$fixture_sql=$wpdb->prepare("SELECT * FROM $index WHERE source_hash IN (".implode(',',array_fill(0,count($fixture_hashes),'%s')).") ORDER BY id",$fixture_hashes);
$before=$wpdb->get_results($fixture_sql,ARRAY_A);
$queue_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue");
foreach ( ['de','es','ru'] as $lang ) gml_db_assert( GML_Public_Eligibility::get_status($key,$lang)['public_eligible'], "$lang complete unreviewed fixture is public" );
update_post_meta( $id, '_seopress_titles_desc', 'A source description.' );
gml_db_assert( GML_Resource_Readiness::get_status($key,'de')==='stale', 'metadata edit invalidates the resource' );
gml_db_assert( !GML_Public_Eligibility::get_status($key,'de')['public_eligible'], 'stale snapshot stays private' );
$mode='failure';
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( in_array($key,(array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),true), 'failed discovery remains durable pending work' );
gml_db_assert( wp_next_scheduled(GML_Resource_Manifest_Manager::DIRTY_HOOK)!==false, 'failed discovery has a recovery event' );
$mode='success';
GML_Resource_Manifest_Manager::process_dirty();
foreach ( ['de','es','ru'] as $lang ) {
    gml_db_assert( GML_Resource_Readiness::get_status($key,$lang)==='complete', "$lang machine readiness recovers" );
    gml_db_assert( GML_Public_Eligibility::get_status($key,$lang)['public_eligible'], "$lang publication recovers without approval" );
}
gml_db_assert( $wpdb->get_results($fixture_sql,ARRAY_A)===$before, 'every translation byte and status is preserved' );
gml_db_assert( (int)$wpdb->get_var("SELECT COUNT(*) FROM $queue")===$queue_before, 'inventory recovery never changes the paid translation queue' );
GML_Resource_Manifest_Store::mark_stale($key);
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( GML_Resource_Readiness::get_status($key,'de')==='complete', 'orphaned stale manifest recovers with an empty dirty hint' );
$second=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Another changed source']);
$other=GML_Resource_Identity::for_post($second)->get_key();
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
GML_Resource_Manifest_Manager::add_dirty($key);
$during_render=static function() use($other) { GML_Resource_Manifest_Manager::add_dirty($other); };
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(in_array($other,(array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),true),'a new dirty key arriving during rendering is retained');
GML_Resource_Manifest_Manager::process_dirty();
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
GML_Resource_Manifest_Manager::add_dirty($key);
$during_render=static function() use($key) { GML_Resource_Manifest_Manager::add_dirty($key); };
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(in_array($key,(array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),true),'same resource dirtied during rendering is retained');
gml_db_assert(GML_Resource_Readiness::get_status($key,'de')==='stale','concurrent edit cannot publish the superseded discovery');
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(GML_Resource_Readiness::get_status($key,'de')==='complete','concurrently dirtied resource completes on its next pass');
GML_Resource_Backfill::reset_pending('pause_during_render');
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
$during_render=static function() { GML_Resource_Backfill::pause(); };
GML_Resource_Backfill::run_batch();
gml_db_assert(GML_Resource_Backfill::state()['status']==='paused','concurrent explicit pause survives a backfill checkpoint');
gml_db_assert(!wp_next_scheduled(GML_Resource_Backfill::HOOK),'paused backfill does not resurrect its event');
GML_Resource_Backfill::reset_pending('reset_during_render');
$during_render=static function() { GML_Resource_Backfill::reset_pending('newer_reset'); };
GML_Resource_Backfill::run_batch();
gml_db_assert(GML_Resource_Backfill::state()['reason']==='newer_reset' && GML_Resource_Backfill::state()['phase']==='roles','newer reset and cursor survive an old backfill worker');
$mode='failure';
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
GML_Resource_Backfill::run_batch();
gml_db_assert((array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),'failed backfill resources become retryable dirty work');
$diag=GML_Resource_Manifest_Manager::diagnostics(GML_Resource_Identity::front_page()->get_key());
gml_db_assert(isset($diag['backfill']['phase'],$diag['lock_active'],$diag['dirty_count']),'inventory diagnostics expose the phase, cursor, lock and pending work');
$failure=(array)get_option(GML_Resource_Manifest_Manager::FAILURE_OPTION,[]);
gml_db_assert(($failure['error_code']??'')==='gml_test_transient','diagnostics persist the concrete error code');
gml_db_assert(wp_next_scheduled(GML_Resource_Backfill::HOOK)!==false,'backfill has a persisted recovery wakeup');
GML_Resource_Backfill::pause();
$mode='success';
wp_delete_post($id,true);wp_delete_post($second,true);
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
echo "OK inventory recovery, preserved TM, no provider requests\n";
