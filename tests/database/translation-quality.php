<?php
require __DIR__.'/bootstrap.php';
gml_test_product_init();
gml_db_assert(method_exists('GML_Translation_Text','obvious_contamination'),'obvious contamination diagnostic exists');
$source='DC 3.7v to 9v Step Up Flyback Transformer';
gml_db_assert(GML_Translation_Text::obvious_contamination($source,'Valid translation GML, WordPress, WooCommerce, Gemini'),'unsupported provider/plugin cluster detected');
gml_db_assert(GML_Translation_Text::obvious_contamination('website, which provides the SERVICE.','Final check: Plain text only, no quotes, no markdown. translated website'),'output instructions detected');
gml_db_assert(!GML_Translation_Text::obvious_contamination('WordPress with WooCommerce','WordPress mit WooCommerce'),'legitimate source terms preserved');
gml_db_assert(!GML_Translation_Text::obvious_contamination('The Gemini spacecraft','Gemini spacecraft'),'legitimate ambiguous model word preserved');
gml_db_assert(!GML_Translation_Text::obvious_contamination('90*45*30mm','90*45*30mm'),'technical dimensions untouched');
gml_db_assert(!GML_Translation_Text::obvious_contamination('Please output plain text only','Please output plain text only'),'source instructions are content, not false positive');
gml_db_assert(!GML_Translation_Text::obvious_contamination('仅限纯文本','Plain text only'),'a legitimate translated single phrase is diagnostic-only');
gml_db_assert(!GML_Translation_Text::obvious_contamination('不要使用 Markdown','No markdown'),'a legitimate translated formatting phrase is not blocked');
wp_set_current_user(1);
gml_db_assert(GML_Installer::activate()===true,'quality hold schema ready');
$table=$wpdb->prefix.'gml_index';
$text='Snapshot hold fixture';
GML_Translation_Memory::upsert(['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','translated_text'=>'Fixture GML, WordPress, WooCommerce, Gemini','status'=>'auto']);
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE source_hash=%s AND target_lang='de'",md5($text)),ARRAY_A);
$before=$row;
$resource_ids=[];
foreach(['Quality source one','Quality source two'] as $title) {
    $post_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$title]);
    $resource_ids[]=$post_id;
    GML_Resource_Manifest_Store::save_complete(GML_Resource_Identity::for_post($post_id), [['text'=>$text,'hash'=>md5($text),'context_type'=>'text']]);
}
GML_Resource_Readiness::run_rebuild_batch('quality-hold-test');
wp_set_current_user(0);
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$row),'anonymous hold refused');
wp_set_current_user(1);
$wrong=$row;$wrong['translated_text']='Old snapshot';
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$wrong),'stale snapshot hold refused');
$wrong=$row;$wrong['source_hash']='invalid';
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$wrong),'invalid hash cannot enter an untransactional mutation callback');
$wrong=$row;$wrong['target_lang']='!';
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$wrong),'invalid language cannot enter an untransactional mutation callback');
$wpdb->query("ALTER TABLE $table ENGINE=MyISAM");
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$row),'legacy nontransactional storage refuses the hold without writes');
$wpdb->query("ALTER TABLE $table ENGINE=InnoDB");
$break_cache=static function($sql) use ($wpdb) {
    return strpos($sql, "UPDATE {$wpdb->options} SET option_value=GREATEST") === 0 ? "UPDATE {$wpdb->prefix}missing_cache_generation SET value=1" : $sql;
};
$suppress=$wpdb->suppress_errors(true);
add_filter('query',$break_cache);
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$row),'cache invalidation error rolls back the hold');
remove_filter('query',$break_cache);
$wpdb->suppress_errors($suppress);
$unchanged=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$row['id']),ARRAY_A);
gml_db_assert($unchanged===$row,'failed hold preserves exact old tuple');
$generation=GML_Page_Cache::generation();
gml_db_assert(GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$row),'explicit exact-snapshot hold succeeds');
$after=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$row['id']),ARRAY_A);
gml_db_assert($after['status']==='pending' && $after['translated_text']===$before['translated_text'],'hold retains all translated text for recovery');
gml_db_assert(GML_Page_Cache::generation()>$generation,'hold rotates rendered cache namespace');
foreach($resource_ids as $post_id) {
    gml_db_assert(!GML_Public_Eligibility::is_eligible(GML_Resource_Identity::for_post($post_id),'de'), 'held shared translation revokes each related resource');
    wp_delete_post($post_id,true);
}
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$before),'repeated stale hold refused');
GML_Translation_Memory::update_by_id((int)$row['id'],'Manually corrected','manual');
$manual=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$row['id']),ARRAY_A);
gml_db_assert(!GML_Translation_Memory::hold_auto_by_id((int)$row['id'],$manual),'manual translation protected');
$wpdb->delete($table,['id'=>(int)$row['id']]);
$reply='Valid translation GML, WordPress, WooCommerce, Gemini';
$mock=static function($pre,$args,$url) use (&$reply) { return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>$reply]]]]]])]; };
add_filter('pre_http_request',$mock,99,3);
$api=new GML_Gemini_API(['engine'=>'gemini','api_key'=>'local-test-key-not-sent']);
$caught=false;
try {$api->translate($source,'en','de');} catch(RuntimeException $e) {$caught=true;}
gml_db_assert($caught && $api->get_last_error()['code']==='translation_contamination','single provider response rejected with actionable error');
gml_db_assert(GML_Translation_Error::classify($api->get_last_error())['category']==='content','contamination is a non-transient content failure');
$reply="[1] Valid translation\n[2] Final check: Plain text only, no quotes, no markdown.";
$caught=false;
try {$api->translate_batch(['Plain source','Second source'],'en','de');} catch(RuntimeException $e) {$caught=true;}
gml_db_assert($caught && $api->get_last_error()['code']==='translation_contamination','batch checks each source and target separately');
remove_filter('pre_http_request',$mock,99);
wp_set_current_user(0);
echo "OK translation quality diagnostic\n";
