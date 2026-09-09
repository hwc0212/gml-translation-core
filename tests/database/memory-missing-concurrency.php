<?php
require __DIR__.'/bootstrap.php';
gml_test_product_init();
$mode=$argv[1]??'';$slot=$argv[2]??'';
$text='Missing-only genuine two-process fixture';
$record=['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','translated_text'=>'Writer '.$slot,'context_type'=>'text','status'=>'auto'];
$table=$wpdb->prefix.'gml_index';
if($mode==='prepare') {
 GML_Installer::activate();
 $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE source_hash=%s AND source_lang='en' AND target_lang='de'",md5($text)));
 foreach($ids as $id)GML_Translation_Memory::delete_by_id($id);
 foreach(['one','two'] as $s){delete_option('gml_missing_ready_'.$s);delete_option('gml_missing_result_'.$s);}
 update_option('gml_missing_generation_before',GML_Page_Cache::generation(),false);
 exit;
}
if($mode==='worker' && in_array($slot,['one','two'],true)) {
 $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_hash=%s AND source_lang='en' AND target_lang='de'",md5($text)));
 gml_db_assert($existing===null,'both processes preflight missing');
 update_option('gml_missing_ready_'.$slot,'1',false);
 $until=microtime(true)+10;
 do {
  $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('gml_missing_ready_one','gml_missing_ready_two') AND option_value='1'");
  if($count===2)break;
  usleep(10000);
 } while(microtime(true)<$until);
 gml_db_assert($count===2,'two-process barrier reached');
 $result=GML_Translation_Memory::insert_missing_batch([$record]);
 gml_db_assert(is_array($result) && $result['committed'],'concurrent writer has explicit committed ledger');
 update_option('gml_missing_result_'.$slot,$result,false);
 echo wp_json_encode($result)."\n";exit;
}
if($mode==='verify') {
 $rows=$wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('gml_missing_result_one','gml_missing_result_two')");
 $results=array_map('maybe_unserialize',$rows);
 gml_db_assert(count($results)===2 && array_sum(array_column($results,'inserted'))===1 && array_sum(array_column($results,'skipped'))===1,'two processes produce one inserted and one existing');
 $rows=$wpdb->get_results($wpdb->prepare("SELECT translated_text FROM $table WHERE source_hash=%s AND source_lang='en' AND target_lang='de'",md5($text)));
 gml_db_assert(count($rows)===1 && in_array($rows[0]->translated_text,['Writer one','Writer two'],true),'exactly one intact winner');
 gml_db_assert(GML_Page_Cache::generation()===(int)get_option('gml_missing_generation_before')+1,'concurrent loser does not advance cache generation');
 echo "OK real two-process missing-only\n";exit;
}
throw new RuntimeException('Invalid concurrency mode');
