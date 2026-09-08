<?php
if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON',true);
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
wp_set_current_user( 1 );
gml_db_assert( true === GML_Installer::activate(), 'redirect schema available' );
$id = wp_insert_post( [ 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'Redirect fixture' ] );
$destination_id = wp_insert_post( [ 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'Destination fixture' ] );
$resource = GML_Resource_Identity::for_post( $id );
$source = $resource->get_source_url();
$target = get_permalink( $destination_id );
$calls = [];
$routes = [];
$http = static function( $pre, $args, $url ) use ( &$calls, &$routes ) {
    $url = remove_query_arg( 'gml_crawl', $url );
    $calls[] = $url;
    gml_db_assert( $args['redirection'] === 0 && $args['sslverify'] && $args['cookies'] === [], 'explicit cookie-free validated hop' );
    if ( ! isset( $routes[$url] ) ) return new WP_Error( 'unmapped', 'Unexpected test URL' );
    list( $status, $location ) = $routes[$url];
    return [ 'response'=>['code'=>$status], 'headers'=>['content-type'=>'text/html', 'location'=>$location], 'body'=>'<html><head><title>Destination title</title></head><body>Destination only text</body></html>' ];
};
add_filter( 'pre_http_request', $http, 99, 3 );
$discovery = new GML_Resource_Manifest_Discovery();
$cases = [
    '200'=>[200,200,'complete'], '301'=>[301,200,'permanent_redirect'],
    '308'=>[308,200,'permanent_redirect'], '302'=>[302,200,'render_error'],
    '307'=>[307,200,'render_error'], '404 destination'=>[301,404,'render_error'],
    '500 destination'=>[301,500,'render_error'],
];
foreach ( $cases as $label=>$case ) {
    $calls=[];
    $routes=[$source=>[$case[0],$target],$target=>[$case[1],'']];
    $cache_key = GML_Page_Cache::key('de', '/redirect-fixture/');
    set_transient($cache_key, '<html>OLD INDEXABLE HTML</html>', 3600);
    $discovery->discover( $resource );
    $row=GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
    gml_db_assert( $row->discovery_state === $case[2], $label . ' has correct terminal state' );
    if ( $case[2] === 'permanent_redirect' ) {
        gml_db_assert( $row->redirect_destination === $target && (int)$row->required_count === 0, 'redirect relationship retained without copying destination manifest' );
        gml_db_assert( GML_Resource_Readiness::get_status( $resource, 'de' ) !== 'complete', 'redirect source cannot be machine-complete' );
        gml_db_assert(!GML_Public_Eligibility::is_eligible($resource, 'en') && GML_Public_Eligibility::get_public_urls($resource) === [], 'redirect source omitted from every language cluster including English');
        gml_db_assert(GML_Page_Cache::key('de', '/redirect-fixture/') !== $cache_key && get_transient(GML_Page_Cache::key('de', '/redirect-fixture/')) === false, 'old cached HTML cannot be read after redirect discovery');
    }
}
$default_port_target = preg_replace('#^(https?://[^/:]+)#', '$1:80', $target);
$routes=[$source=>[301,$default_port_target],$default_port_target=>[200,'']];
gml_db_assert(true === $discovery->discover($resource), 'explicit default port is the same origin');
$routes=[$source=>[301,str_replace(':80', ':81', $default_port_target)]];
gml_db_assert(is_wp_error($discovery->discover($resource)), 'non-default port is not the same origin');
foreach ( ['cross-domain'=>'https://outside.test/path/','self'=>$source,'fragment'=>$source.'#fragment'] as $label=>$location ) {
    $calls=[];$routes=[$source=>[301,$location]];
    $discovery->discover($resource);
    gml_db_assert( GML_Resource_Manifest_Store::get_by_key($resource->get_key())->discovery_state==='render_error' && count($calls)===1, $label.' rejected before follow' );
}
$middle = home_url('/redirect-middle/');
$last = home_url('/redirect-last/');
foreach ( [
    'loop'=>[$source=>[301,$middle],$middle=>[308,$source]],
    'temporary chain'=>[$source=>[301,$middle],$middle=>[302,$target]],
    'missing location'=>[$source=>[301,'']],
    'too many hops'=>[$source=>[301,$middle],$middle=>[301,$last],$last=>[308,$target],$target=>[301,home_url('/fourth/')]],
    'outside subdirectory'=>[$source=>[301,'http://gml-regression.test/outside/']],
] as $label=>$map ) {
    if ($label==='outside subdirectory' && GML_URL_Helper::get_home_path()==='') continue;
    $calls=[];$routes=$map;
    $discovery->discover($resource);
    gml_db_assert(GML_Resource_Manifest_Store::get_by_key($resource->get_key())->discovery_state==='render_error' && count($calls)<=4,$label.' fails closed with bounded requests');
}
$routes=[$source=>[301,$middle],$middle=>[308,$last],$last=>[301,$target],$target=>[200,'']];
gml_db_assert(true===$discovery->discover($resource),'three permanent hops accepted');
$row=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
gml_db_assert(json_decode($row->redirect_chain,true)===[$source,$middle,$last,$target],'full source to final destination relationship retained');
$table=GML_Resource_Manifest_Store::manifest_table();
$relations=GML_Resource_Manifest_Store::relation_table();
gml_db_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $relations WHERE resource_id=%d AND manifest_generation=%d",$row->id,$row->manifest_generation))===0,'old source relations are not current after redirect');
// Restrict corpus assertions to this fixture; no production DB can reach here.
$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id<>%d",$row->id));
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
update_option(GML_Resource_Backfill::OPTION,['status'=>'complete','phase'=>'complete','cursor'=>0,'updated_at'=>time()],false);
GML_Translation_Readiness::clear_cache();
gml_db_assert(GML_Translation_Readiness::current_corpus_is_complete(),'valid redirect is terminal without blocking corpus');
gml_db_assert(GML_Translation_Readiness::current_queue_scope_sql('q')!=='','authority enables current-only failure classification');
foreach([404,500,302,307] as $failure_status) {
    $routes=[$source=>[301,$target],$target=>[200,'']];
    gml_db_assert(true === $discovery->discover($resource), 'establish valid redirect before revalidation');
    $routes[$target]=[$failure_status,home_url('/temporary/')];
    $discovery->discover($resource);
    $failed_row=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
    gml_db_assert($failed_row->discovery_state==='render_error' && $failed_row->redirect_destination===$target, 'failed revalidation preserves exclusion hint: '.$failure_status);
    gml_db_assert(GML_Public_Eligibility::get_public_urls($resource)===[] && !GML_Translation_Readiness::current_corpus_is_complete(), 'failed revalidation cannot publish EN or authorize cleanup: '.$failure_status);
}
$routes=[$source=>[301,$target],$target=>[200,'']];
$discovery->discover($resource);
$routes=[];
$discovery->discover($resource);
gml_db_assert(GML_Public_Eligibility::get_public_urls($resource)===[] && !GML_Translation_Readiness::current_corpus_is_complete(), 'network failure preserves old-source exclusion and blocks cleanup');
$routes=[$source=>[301,$target],$target=>[200,'']];
$discovery->discover($resource);
GML_Resource_Manifest_Manager::invalidate_permanent_redirects();
gml_db_assert(!GML_Translation_Readiness::current_corpus_is_complete(),'changed destination invalidates old terminal proof');
gml_db_assert(GML_Translation_Readiness::current_queue_scope_sql('q')==='','invalidated relation blocks obsolete classification');
$routes=[$source=>[200,'']];
gml_db_assert(true===$discovery->discover($resource),'redirect source can become a regular resource again');
$row=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
gml_db_assert($row->discovery_state==='complete' && $row->redirect_destination===null && (int)$row->required_count>0,'rediscovery removes obsolete destination metadata');
$db_version = get_option('gml_db_version');
update_option('gml_db_version', '3.3.0');
gml_db_assert(is_wp_error($discovery->discover($resource)) && !GML_Public_Eligibility::is_eligible($resource,'en'), 'incomplete additive migration blocks state writes and public eligibility');
update_option('gml_db_version', $db_version);

$keys = [];
for ($i=0; $i<200; $i++) $keys[] = 'post:page:' . (900000+$i);
update_option(GML_Resource_Manifest_Manager::DIRTY_OPTION, $keys, false);
GML_Resource_Manifest_Manager::add_dirty($resource->get_key());
gml_db_assert(get_option(GML_Resource_Backfill::OPTION)['status'] !== 'complete', 'final 201-key dirty list schedules a new complete inventory');

wp_update_post(['ID'=>$id, 'post_status'=>'draft']);
gml_db_assert(true === $discovery->discover($resource->get_key()), 'unpublished known source reaches excluded terminal state');
gml_db_assert(GML_Resource_Manifest_Store::get_by_key($resource->get_key())->discovery_state === 'excluded', 'unpublished source history retained');
wp_delete_post($id,true);
gml_db_assert(true === $discovery->discover($resource->get_key()), 'deleted known source remains excluded');
gml_db_assert(is_wp_error($discovery->discover('post:unknown_type:123456')), 'unresolvable unknown identity remains fail closed');
remove_filter( 'pre_http_request', $http, 99 );
wp_delete_post($id,true);
wp_delete_post($destination_id,true);
$wpdb->query("DELETE FROM $table");
$wpdb->query("DELETE FROM $relations");
delete_option(GML_Resource_Manifest_Manager::DIRTY_OPTION);
echo "OK permanent redirect regression\n";
