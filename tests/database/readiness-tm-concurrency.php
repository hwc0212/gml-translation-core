<?php
/** Real connections: TM invalidation must serialize with readiness publication. */
require __DIR__.'/bootstrap.php';
gml_test_product_init();
$mode=$argv[1]??'';
$case=$argv[2]??'existing';
$isolation=$argv[3]??'repeatable';
gml_db_assert(in_array($case,['existing','missing'],true) && in_array($isolation,['repeatable','committed'],true),'bounded readiness race case');
$option='gml_test_readiness_tm_race';
$index=$wpdb->prefix.'gml_index';
if($mode==='prepare'){
    gml_db_assert(GML_Installer::activate()===true,'readiness race schema ready');
    update_option('blog_public',1);
    update_option('gml_multilingual_enabled',true);
    update_option('gml_ai_translation_enabled',false);
    update_option('gml_source_lang','en');
    update_option('gml_languages',[['code'=>'de','enabled'=>true]]);
    $post=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Readiness race '.$case.' '.$isolation]);
    $resource=GML_Resource_Identity::for_post($post);
    $text='Readiness critical title '.$post;
    gml_db_assert(GML_Resource_Manifest_Store::save_complete($resource,[['text'=>$text,'context_type'=>'seo_title']])===true,'readiness race source saved');
    gml_db_assert(GML_Translation_Memory::upsert(['source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','translated_text'=>'Race translation','status'=>'manual'])===true,'readiness race TM saved');
    $manifest=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
    gml_db_assert(GML_Resource_Readiness::recalculate_resources([(int)$manifest->id],['de'])===1,'readiness race starts complete');
    if($case==='missing') $wpdb->delete(GML_Resource_Manifest_Store::readiness_table(),['resource_id'=>(int)$manifest->id,'target_lang'=>'de']);
    $fixture=['resource_id'=>(int)$manifest->id,'key'=>$resource->get_key(),'post'=>$post,'tm_id'=>(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $index WHERE source_hash=%s AND target_lang='de'",md5($text))),'barrier'=>sys_get_temp_dir().'/gml-readiness-'.wp_generate_uuid4()];
    gml_db_assert(mkdir($fixture['barrier'],0700),'private fixture barrier created');
    update_option($option,$fixture,false);
    exit;
}
$f=(array)get_option($option,[]);
gml_db_assert(isset($f['barrier'],$f['resource_id'],$f['tm_id']),'readiness race fixture loaded');
$barrier=$f['barrier'];
$signal=static function($name)use($barrier){file_put_contents($barrier.'/'.$name,'1',LOCK_EX);};
$wait=static function($name,$seconds=2)use($barrier){
    $end=microtime(true)+$seconds;
    do{clearstatcache(true,$barrier.'/'.$name);if(is_file($barrier.'/'.$name))return true;usleep(10000);}while(microtime(true)<$end);
    return false;
};
if($mode==='reader'){
    $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL '.($isolation==='committed'?'READ COMMITTED':'REPEATABLE READ'));
    $started=false;$published=false;
    $hook=static function($sql)use(&$started,&$published,$signal,$wait){
        if(!$started && strpos($sql,'SELECT p.resource_id,p.manifest_generation,p.target_lang,s.source_hash')!==false){
            $started=true;$signal('reader_locked');
            gml_db_assert($wait('writer_started'),'writer reaches normal TM API');
            $wait('writer_invalidated',0.6);
        }
        if(!$published && $started && preg_match('/^REPLACE INTO .*gml_resource_readiness/',$sql)){
            $published=true;$signal('reader_snapshot');
            $wait('writer_done',0.6);
        }
        return $sql;
    };
    add_filter('query',$hook);
    $count=GML_Resource_Readiness::recalculate_resources([(int)$f['resource_id']],['de']);
    remove_filter('query',$hook);
    gml_db_assert($started && $published && $count===1,'reader performs a complete transaction');
    $signal('reader_done');
    exit;
}
if($mode==='writer'){
    $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL '.($isolation==='committed'?'READ COMMITTED':'REPEATABLE READ'));
    gml_db_assert($wait('reader_locked'),'reader reaches snapshot point');
    $signal('writer_started');
    $mutation_reached=false;
    $hook=static function($sql)use($signal,$wait,$index,&$mutation_reached){
        if(strpos(str_replace(chr(96),'',$sql),'DELETE FROM '.$index)!==false){
            $mutation_reached=true;
            $signal('writer_invalidated');
            gml_db_assert($wait('reader_snapshot'),'reader reaches publication point');
        }
        return $sql;
    };
    add_filter('query',$hook);
    $saved=GML_Translation_Memory::delete_by_id((int)$f['tm_id']);
    remove_filter('query',$hook);
    gml_db_assert($saved && $mutation_reached,'normal TM deletion commits through the intended barrier');
    $signal('writer_done');
    exit;
}
if($mode==='verify'){
    gml_db_assert($wait('reader_done') && $wait('writer_done'),'both readiness race workers finished');
    gml_db_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $index WHERE id=%d",(int)$f['tm_id'])),'concurrent TM deletion persisted');
    gml_db_assert(GML_Resource_Readiness::get_status($f['key'],'de')!=='complete','concurrent deletion cannot leave obsolete complete readiness');
    gml_db_assert(!GML_Public_Eligibility::get_status($f['key'],'de')['public_eligible'],'concurrent deletion stays private');
    GML_Resource_Readiness::recalculate_resources([(int)$f['resource_id']],['de']);
    gml_db_assert(GML_Resource_Readiness::get_status($f['key'],'de')==='incomplete','fresh readiness converges after concurrent deletion');
    wp_delete_post($f['post'],true);
    foreach(glob($barrier.'/*') as $file)unlink($file);
    rmdir($barrier);
    delete_option($option);
    exit;
}
throw new RuntimeException('Unknown readiness race mode');
