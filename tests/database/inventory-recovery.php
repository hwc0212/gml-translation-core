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
// Recreate legacy shapes that used to fall outside both recovery queues.
$manifests = GML_Resource_Manifest_Store::manifest_table();
$readiness = GML_Resource_Manifest_Store::readiness_table();
$manifest = GML_Resource_Manifest_Store::get_by_key( $key );
$wpdb->update( $manifests, [ 'global_generation'=>0, 'discovery_state'=>'stale' ], [ 'id'=>$manifest->id ] );
update_option( GML_Resource_Manifest_Manager::RECOVERY_CURSOR, (int)$manifest->id - 1, false );
GML_Resource_Manifest_Manager::recover_pending();
gml_db_assert( in_array( $key, get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION), true ), 'older global generation requeued' );
GML_Resource_Manifest_Manager::process_dirty();
$wpdb->update( $readiness, [ 'manifest_generation'=>0, 'status'=>'complete' ], [ 'resource_id'=>$manifest->id, 'target_lang'=>'de' ] );
update_option( GML_Resource_Manifest_Manager::RECOVERY_CURSOR, (int)$manifest->id - 1, false );
GML_Resource_Manifest_Manager::recover_pending();
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( GML_Public_Eligibility::get_status($key,'de')['public_eligible'], 'old complete language row refreshed against its actual manifest' );
$draft = wp_insert_post( [ 'post_type'=>'post', 'post_status'=>'draft', 'post_title'=>'Unpublished recovery fixture' ] );
$draft_key = 'post:post:' . $draft;
gml_db_assert( ! in_array( $draft_key, (array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION), true ), 'new unpublished resource has no work' );
GML_Resource_Manifest_Manager::add_dirty( $draft_key );
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( ! in_array( $draft_key, (array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION), true ), 'pre-upgrade unpublished hint retires' );
wp_delete_post( $draft, true );
$redirect_renderer = new class( $id ) {
    private $id;
    private $destination;
    public function __construct( $id ) {
        $this->id = $id;
        $this->destination = home_url( '/destination/' );
    }
    public function render( $resource ) {
        update_post_meta( $this->id, '_seopress_titles_desc', 'A changed source description.' );
        return new WP_Error( 'gml_resource_permanent_redirect', 'Old redirect', [
            'destination' => $this->destination,
            'chain' => [ $resource->get_source_url(), $this->destination ],
        ] );
    }
};
$redirect = ( new GML_Resource_Manifest_Discovery( $redirect_renderer ) )->discover( $key );
gml_db_assert( is_wp_error( $redirect ) && $redirect->get_error_code() === 'gml_resource_changed', 'superseded redirect proof is rejected' );
gml_db_assert( GML_Resource_Manifest_Store::get_by_key($key)->discovery_state === 'stale', 'old redirect cannot overwrite newer stale state' );
update_post_meta( $id, '_seopress_titles_desc', 'A source description.' );
GML_Resource_Manifest_Manager::process_dirty();

// An older render must not invalidate a newer completed discovery.
$inner_snapshot = null;
$during_render = static function() use($id,$key,&$inner_snapshot) {
    update_post_meta($id, '_seopress_titles_desc', 'New source completed by another discovery.');
    wp_update_post(['ID'=>$id, 'post_name'=>'inventory-newer-source-'.$id]);
    gml_db_assert((new GML_Resource_Manifest_Discovery())->discover($key) === true, 'a newer source discovery completes while an older render is outstanding');
    $inner_snapshot = GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($key));
};
$superseded = (new GML_Resource_Manifest_Discovery())->discover($key);
gml_db_assert(is_wp_error($superseded) && $superseded->get_error_code()==='gml_resource_changed', 'an older render reports its superseded source');
gml_db_assert(GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($key)) === $inner_snapshot, 'an older source render preserves the newer complete manifest');

$inner_snapshot = null;
$during_render = static function() use($key,&$inner_snapshot) {
    update_option(GML_Resource_Manifest_Manager::GLOBAL_OPTION, GML_Resource_Manifest_Manager::global_generation()+1, false);
    gml_db_assert((new GML_Resource_Manifest_Discovery())->discover($key) === true, 'a newer global-generation discovery completes');
    $inner_snapshot = GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($key));
};
$superseded = (new GML_Resource_Manifest_Discovery())->discover($key);
gml_db_assert(is_wp_error($superseded) && $superseded->get_error_code()==='gml_resource_changed', 'an older render reports its superseded global generation');
gml_db_assert(GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($key)) === $inner_snapshot, 'an older global render preserves the newer complete manifest');

// A tick with only delayed work should leave the queue untouched and sleep
// until retry is due. A subsequent edit must wake that worker earlier.
$retry_after = time() + 600;
delete_option( GML_Resource_Manifest_Manager::DIRTY_OPTION );
GML_Resource_Manifest_Manager::add_dirty( $key );
set_transient( 'gml_manifest_retry_' . md5( $key ), [ 'attempt'=>4, 'after'=>$retry_after ], DAY_IN_SECONDS );
wp_clear_scheduled_hook( GML_Resource_Manifest_Manager::DIRTY_HOOK );
$request_count = count($requests);
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( count($requests) === $request_count && wp_next_scheduled(GML_Resource_Manifest_Manager::DIRTY_HOOK) === $retry_after, 'a cooldown-only queue sleeps until the earliest retry' );
$wake = wp_insert_post( ['post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'Ready behind cooldown'] );
$wake_key = GML_Resource_Identity::for_post($wake)->get_key();
gml_db_assert( wp_next_scheduled(GML_Resource_Manifest_Manager::DIRTY_HOOK) < $retry_after, 'new work advances the worker wakeup' );
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( GML_Resource_Manifest_Store::get_by_key($wake_key)->discovery_state === 'complete', 'ready work passes a delayed resource' );
update_post_meta( $id, '_seopress_titles_desc', 'A revised source during cooldown.' );
$request_count = count($requests);
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( count($requests) > $request_count && GML_Resource_Manifest_Store::get_by_key($key)->discovery_state === 'complete', 'a same-resource source edit bypasses its old retry cooldown' );
update_post_meta( $id, '_seopress_titles_desc', 'A source description.' );
GML_Resource_Manifest_Manager::process_dirty();
// Global layout edits invalidate the failed render as well.
$retry_generation = GML_Resource_Manifest_Manager::global_generation();
GML_Resource_Manifest_Manager::add_dirty( $key );
set_transient( 'gml_manifest_retry_' . md5( $key ), [ 'attempt'=>5, 'after'=>time()+600, 'global_generation'=>$retry_generation ], DAY_IN_SECONDS );
wp_clear_scheduled_hook(GML_Resource_Manifest_Manager::DIRTY_HOOK);
wp_schedule_single_event(time()+600,GML_Resource_Manifest_Manager::DIRTY_HOOK);
GML_Resource_Manifest_Manager::bump_global_generation('review_global_change');
gml_db_assert(wp_next_scheduled(GML_Resource_Manifest_Manager::DIRTY_HOOK)<time()+300, 'a global source change advances the delayed inventory worker');
$request_count = count($requests);
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert( count($requests) > $request_count && GML_Resource_Manifest_Store::get_by_key($key)->discovery_state === 'complete', 'a new global generation bypasses the old retry cooldown' );

// Cooldown records created before generation metadata was added also recover.
GML_Resource_Manifest_Manager::add_dirty($key);
set_transient('gml_manifest_retry_' . md5($key), ['attempt'=>5,'after'=>time()+600], DAY_IN_SECONDS);
update_option(GML_Resource_Manifest_Manager::GLOBAL_OPTION, GML_Resource_Manifest_Manager::global_generation()+1, false);
$request_count=count($requests);
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(count($requests)>$request_count, 'a legacy cooldown is invalidated when its manifest global generation is obsolete');

// A source change inside a failed attempt must not restore its old delay.
GML_Resource_Manifest_Manager::add_dirty( $key );
set_transient( 'gml_manifest_retry_' . md5( $key ), [ 'attempt'=>7, 'after'=>time()-1 ], DAY_IN_SECONDS );
$during_render = static function() use($id) { update_post_meta($id, '_seopress_titles_desc', 'Another source revision.'); };
GML_Resource_Manifest_Manager::process_dirty();
$changed_retry = (array)get_transient('gml_manifest_retry_' . md5($key));
gml_db_assert( (int)($changed_retry['after']??0) <= time() && (int)($changed_retry['attempt']??0) === 1, 'an edit during rendering resets the superseded retry attempt' );
GML_Resource_Manifest_Manager::process_dirty();
update_post_meta($id, '_seopress_titles_desc', 'A source description.');
GML_Resource_Manifest_Manager::process_dirty();
delete_transient( 'gml_manifest_retry_' . md5( $key ) );
wp_delete_post( $wake, true );
GML_Resource_Manifest_Manager::process_dirty();
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
GML_Resource_Manifest_Manager::add_dirty($key);
$during_render=static function() use($id) { wp_update_post(['ID'=>$id,'post_name'=>'inventory-recovery-'.wp_generate_uuid4()]); };
GML_Resource_Manifest_Manager::process_dirty();
$changed_failure=(array)get_option(GML_Resource_Manifest_Manager::FAILURE_OPTION,[]);
gml_db_assert(GML_Resource_Readiness::get_status($key,'de')==='stale','source change during rendering leaves a stale snapshot private');
gml_db_assert(($changed_failure['error_code']??'')==='gml_resource_changed','stale discovery reports the exact changed-source error');
gml_db_assert(in_array($key,(array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),true),'changed-source discovery remains pending after its failed pass');
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(GML_Resource_Readiness::get_status($key,'de')==='complete','changed-source stale snapshot recovers on a fresh render');
$reject_readiness=true;
$write_failure=static function($sql) use(&$reject_readiness){
 if($reject_readiness && preg_match('/^REPLACE INTO .?test_gml_resource_readiness/i',$sql)){
  $reject_readiness=false;return 'INSERT INTO test_gml_intentionally_missing_table VALUES (1)';
 }
 return $sql;
};
add_filter('query',$write_failure);
$old_suppression=$wpdb->suppress_errors(true);
GML_Resource_Manifest_Manager::add_dirty($key);
GML_Resource_Manifest_Manager::process_dirty();
$wpdb->suppress_errors($old_suppression);
remove_filter('query',$write_failure);
gml_db_assert(!$reject_readiness,'test injected a real readiness write failure');
gml_db_assert(GML_Resource_Readiness::get_status($key,'de')==='stale','partial readiness save keeps the manifest private');
$partial_failure=(array)get_option(GML_Resource_Manifest_Manager::FAILURE_OPTION,[]);
gml_db_assert(($partial_failure['error_code']??'')==='gml_readiness_write','partial snapshot exposes its exact retry reason');
gml_db_assert(in_array($key,(array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),true),'partial readiness save is not acknowledged as completed work');
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(GML_Resource_Readiness::get_status($key,'de')==='complete','partial readiness save recovers with existing TM');
// An older readiness calculation may still be holding pre-render manifest data.
$ready_transactions=0;
$newer_between_languages=false;
$readiness_interleave=static function($sql)use($id,$key,&$ready_transactions,&$newer_between_languages){
    if(!$newer_between_languages && $sql==='START TRANSACTION' && ++$ready_transactions===3){
        $newer_between_languages=true;
        wp_update_post(['ID'=>$id,'post_name'=>'newer-during-readiness-'.$id]);
        gml_db_assert((new GML_Resource_Manifest_Discovery())->discover($key)===true,'a newer discovery completes between old language snapshot writes');
    }
    return $sql;
};
add_filter('query',$readiness_interleave);
$older_readiness=(new GML_Resource_Manifest_Discovery())->discover($key);
remove_filter('query',$readiness_interleave);
gml_db_assert($newer_between_languages,'readiness writes were interleaved after the first language');
$newest_manifest=GML_Resource_Manifest_Store::get_by_key($key);
$readiness_table=GML_Resource_Manifest_Store::readiness_table();
$old_snapshot_rows=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $readiness_table WHERE resource_id=%d AND manifest_generation<>%d",$newest_manifest->id,$newest_manifest->manifest_generation));
gml_db_assert($old_snapshot_rows===0,'an older readiness calculation cannot overwrite newer language snapshots');
gml_db_assert(is_wp_error($older_readiness) && $older_readiness->get_error_code()==='gml_resource_changed','superseded readiness work reports a changed resource');

// A readiness failure must not invalidate a newer manifest completed meanwhile.
$readiness_failed = false;
$newer_after_readiness = false;
$newer_ready_snapshot = null;
$readiness_fallback = static function($sql) use($id,$key,&$readiness_failed,&$newer_after_readiness,&$newer_ready_snapshot) {
    if (!$readiness_failed && preg_match('/^REPLACE INTO .?test_gml_resource_readiness/i',$sql)) {
        $readiness_failed = true;
        return 'INSERT INTO test_gml_intentionally_missing_table VALUES (1)';
    }
    if ($readiness_failed && !$newer_after_readiness
        && strpos($sql,'SELECT * FROM test_gml_resource_manifests WHERE resource_key=')===0) {
        $newer_after_readiness = true;
        wp_update_post(['ID'=>$id,'post_name'=>'newer-after-readiness-'.$id]);
        gml_db_assert((new GML_Resource_Manifest_Discovery())->discover($key)===true,'a newer discovery completes before the old readiness failure fallback');
        $newer_ready_snapshot=GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($key));
    }
    return $sql;
};
add_filter('query',$readiness_fallback);
$old_suppression=$wpdb->suppress_errors(true);
$old_readiness_result=(new GML_Resource_Manifest_Discovery())->discover($key);
$wpdb->suppress_errors($old_suppression);
remove_filter('query',$readiness_fallback);
gml_db_assert($readiness_failed && $newer_after_readiness,'readiness failure and newer completion were interleaved');
gml_db_assert(is_wp_error($old_readiness_result),'the original partial readiness operation reports failure');
gml_db_assert(GML_Resource_Manifest_Store::snapshot_version(GML_Resource_Manifest_Store::get_by_key($key))===$newer_ready_snapshot,'a late readiness failure preserves the newer complete manifest');

// Failure to invalidate the manifest must still retain the direct caller's work.
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
$reject_snapshot=false;$reject_invalidation=false;
$invalidate_failure=static function($sql)use(&$reject_snapshot,&$reject_invalidation){
    if(!$reject_snapshot && preg_match('/^REPLACE INTO .?test_gml_resource_readiness/i',$sql)){
        $reject_snapshot=true;return 'INSERT INTO test_gml_intentionally_missing_table VALUES (1)';
    }
    if($reject_snapshot && !$reject_invalidation && preg_match('/^UPDATE .*test_gml_resource_manifests/i',$sql)){
        $reject_invalidation=true;return 'INSERT INTO test_gml_intentionally_missing_table VALUES (1)';
    }
    return $sql;
};
add_filter('query',$invalidate_failure);
$old_suppression=$wpdb->suppress_errors(true);
$failed_invalidation=(new GML_Resource_Manifest_Discovery())->discover($key);
$wpdb->suppress_errors($old_suppression);remove_filter('query',$invalidate_failure);
gml_db_assert($reject_snapshot && $reject_invalidation && is_wp_error($failed_invalidation),'readiness and fallback write failures are reported');
gml_db_assert(in_array($key,(array)get_option(GML_Resource_Manifest_Manager::DIRTY_OPTION,[]),true),'a failed readiness invalidation remains durable pending work');
GML_Resource_Manifest_Manager::process_dirty();

// A source edit can also arrive between failure handling and the transient write.
$retry_key='gml_manifest_retry_'.md5($key);
GML_Resource_Manifest_Manager::add_dirty($key);
set_transient($retry_key,['attempt'=>7,'after'=>time()-1,'global_generation'=>GML_Resource_Manifest_Manager::global_generation()],DAY_IN_SECONDS);
$late_edit=false;
$retry_write=static function($value)use($id,&$late_edit){
    if(!$late_edit && is_array($value) && ($value['after']??0)>time()){
        $late_edit=true;
        update_post_meta($id,'_seopress_titles_desc','Edited while the failed attempt records its cooldown.');
    }
    return $value;
};
add_filter('pre_set_transient_'.$retry_key,$retry_write);
$mode='failure';
GML_Resource_Manifest_Manager::process_dirty();
remove_filter('pre_set_transient_'.$retry_key,$retry_write);
gml_db_assert($late_edit,'a source edit was injected before the old retry transient write');
$mode='success';$request_count=count($requests);
GML_Resource_Manifest_Manager::process_dirty();
gml_db_assert(count($requests)>$request_count && GML_Resource_Manifest_Store::get_by_key($key)->discovery_state==='complete','a late retry write cannot restore cooldown after a new source edit');
update_post_meta($id,'_seopress_titles_desc','A source description.');
GML_Resource_Manifest_Manager::process_dirty();

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
gml_db_assert($wpdb->get_results($fixture_sql,ARRAY_A)===$before, 'review regressions preserve every stored fixture translation');
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $queue")===$queue_before, 'review regressions preserve the paid queue');
echo "OK inventory recovery, preserved TM, no provider requests\n";
