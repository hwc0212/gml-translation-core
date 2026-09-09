<?php
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
gml_db_assert(true === GML_Installer::activate(), 'cluster fixture schema available');
global $wpdb;
$home = getenv('GML_TEST_HOME') ?: 'http://gml-regression.test';
update_option('home', $home);
update_option('siteurl', $home);
update_option('gml_source_lang', 'en');
update_option('gml_languages', [
    ['code'=>'de','enabled'=>true], ['code'=>'ru','enabled'=>true],
    ['code'=>'zh','enabled'=>true,'site_mode'=>'external','external_url'=>'https://external.example/'],
]);
update_option('gml_translation_paused', true);
$user = get_users(['role'=>'administrator','number'=>1]);
wp_set_current_user($user[0]->ID);
$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_name'=>'cluster-fixture','post_title'=>'Cluster fixture']);
$resource = GML_Resource_Identity::for_post($id);
$source = 'Cluster exact source ' . $id;
GML_Resource_Manifest_Store::save_complete($resource, [['text'=>$source,'context_type'=>'seo_title']]);
$manifest = GML_Resource_Manifest_Store::get_by_key($resource->get_key());
$rid = (int)$manifest->id;
function cluster_plan($rid) {
    foreach (GML_Page_Cache::pending_clusters(100) as $row) if ($row['resource_id']===$rid) return $row;
    return null;
}
// Keep this fixture independent of earlier tests' deliberately unresolved resources.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(GML_Page_Cache::CLUSTER_PREFIX).'%'));
GML_Page_Cache::invalidate_resources([$rid]);
$old_key = GML_Page_Cache::key('en', '/cluster-fixture/');
set_transient($old_key, '<head>en,x-default</head>', 300);
$now = current_time('mysql');
$wpdb->replace($wpdb->prefix.'gml_index', ['source_hash'=>md5($source),'source_text'=>$source,'source_lang'=>'en','target_lang'=>'de',
    'translated_text'=>'Exakter Inhalt','status'=>'auto','created_at'=>$now,'updated_at'=>$now]);
// This was missing: a readiness-only transition did not rotate source HTML keys.
GML_Resource_Readiness::recalculate_resources([$rid], ['de']);
gml_db_assert(GML_Resource_Readiness::get_status($resource,'de')==='complete', 'DE becomes complete');
gml_db_assert(GML_Page_Cache::key('en','/cluster-fixture/')!==$old_key, 'source cannot reuse incomplete hreflang HTML');
$plan = cluster_plan($rid);
$expected = GML_Page_Cache::cluster_urls($resource);
gml_db_assert($plan && !$plan['blocked'] && count($plan['urls'])===3 && $plan['urls']===$expected, 'durable plan includes source and all local alternates only');
gml_db_assert(!str_contains(implode(' ', $plan['urls']), '/ygnaglul/de/ygnaglul/'), 'cluster does not duplicate subdirectory');
$generation = GML_Page_Cache::generation();
wp_cache_set(GML_Page_Cache::GENERATION_OPTION, 1, 'options');
gml_db_assert(GML_Page_Cache::generation()===$generation, 'stale Redis cannot roll back cluster authority');
$wpdb->query('START TRANSACTION');
GML_Page_Cache::invalidate_resources([$rid]);
$wpdb->query('ROLLBACK');
gml_db_assert(cluster_plan($rid)['token']===$plan['token'] && GML_Page_Cache::generation()===$generation, 'rolled-back mutation leaves no committed invalidation');
GML_Page_Cache::invalidate_resources([$rid]);
gml_db_assert(!GML_Page_Cache::acknowledge_cluster($plan['name'],$plan['token']), 'old purge acknowledgement cannot consume concurrent change');
$plan = cluster_plan($rid);
gml_db_assert(GML_Page_Cache::acknowledge_cluster($plan['name'],$plan['token']) && cluster_plan($rid)===null, 'exact successful maintenance acknowledgement consumes only its token');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND target_lang='de'",md5($source)),ARRAY_A);
gml_db_assert(GML_Translation_Memory::hold_auto_by_id($row['id'],$row), 'official quality hold succeeds');
gml_db_assert(cluster_plan($rid)!==null && GML_Resource_Readiness::get_status($resource,'de')!=='complete', 'quality hold durably invalidates full cluster');
gml_db_assert($GLOBALS['gml_test_http_calls']===0 && get_option('gml_translation_paused'), 'no external requests or queue resume during state changes');
wp_set_current_user(0);
gml_db_assert(GML_Page_Cache::pending_clusters()===[] && !GML_Page_Cache::acknowledge_cluster($plan['name'],$plan['token']), 'maintenance is administrator-only');
echo "OK resource cluster cache regression\n";
