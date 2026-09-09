<?php
/** Missing-only contract; --legacy asserts no-overwrite against the old upsert. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
gml_db_assert(GML_Installer::activate() === true, 'missing-only schema ready');
wp_set_current_user(1);
update_option('gml_source_lang','en');
update_option('gml_translation_paused',true);
$legacy=in_array('--legacy',$argv,true);
$run=bin2hex(random_bytes(8));
function missing_record($label) {
 global $run;
 $text='Missing-only '.$run.' '.$label;
 return ['source_hash'=>md5($text),'source_text'=>$text,'source_lang'=>'en','target_lang'=>'de','context_type'=>'text','translated_text'=>'Candidate '.$label,'status'=>'auto'];
}
function missing_write($records) {
 global $legacy;
 return $legacy ? GML_Translation_Memory::upsert_batch($records,true) : GML_Translation_Memory::insert_missing_batch($records);
}
function missing_row($record) {
 global $wpdb;
 return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND source_lang=%s AND target_lang=%s",$record['source_hash'],$record['source_lang'],$record['target_lang']),ARRAY_A);
}
$bad=[];
foreach(['auto','manual','pending'] as $status) {
 $record=missing_record('interleaving-'.$status); $injected=false;
 gml_db_assert(missing_row($record)===null,'preflight missing '.$status);
 $inject=static function($sql) use(&$injected,$record,$status){
  if(!$injected && strtoupper(trim($sql))==='START TRANSACTION') {
   $injected=true; $old=$record; $old['translated_text']='Existing';$old['status']=$status==='manual'?'manual':'auto';
   if(!GML_Translation_Memory::upsert($old))throw new RuntimeException('Fixture seed failed');
   if($status==='pending') { $row=missing_row($record);if(!GML_Translation_Memory::hold_auto_by_id($row['id'],$row))throw new RuntimeException('Fixture hold failed'); }
  }
  return $sql;
 };
 add_filter('query',$inject);
 try {$result=missing_write([$record]);} finally {remove_filter('query',$inject);}
 $actual=missing_row($record);
 $preserved=$injected && $actual['translated_text']==='Existing' && $actual['status']===$status;
 echo ($preserved?'PASS':'FAIL').': preflight/write interleaving preserves '.$status."\n";
 if(!$preserved)$bad[]=$status;
 GML_Translation_Memory::delete_by_id($actual['id']);
}
gml_db_assert(!$bad,'all preflight/write interleavings preserve existing tuples');
if($legacy)exit;

$fresh=missing_record('fresh');
$generation=GML_Page_Cache::generation();
$dict=new GML_Translation_Translator(); $dict->get_dictionary('de');
wp_cache_set('gml_dict_en_de',['old'=>'cached'],'gml_translate');
$result=missing_write([$fresh,$fresh]);
gml_db_assert($result['committed']===true && $result['inserted']===1 && $result['skipped']===1,'same-batch duplicate has one insert and one explicit duplicate');
gml_db_assert(array_column($result['items'],'outcome')===['inserted','duplicate_input'],'ordered ledger includes every input');
gml_db_assert(GML_Page_Cache::generation()===$generation+1,'page generation increments once on actual insert');
gml_db_assert(wp_cache_get('gml_dict_en_de','gml_translate')===false,'persistent dictionary cache invalidated');
gml_db_assert(($dict->get_dictionary('de')[$fresh['source_hash']]??'')===$fresh['translated_text'],'in-process known missing dictionary refreshed');
$generation=GML_Page_Cache::generation();
$prior=missing_row($fresh);
$result=missing_write([$fresh]);
gml_db_assert($result['inserted']===0 && $result['items'][0]['outcome']==='existing' && $result['items'][0]['existing_status']==='auto','existing auto conflict ledger');
gml_db_assert(missing_row($fresh)===$prior && GML_Page_Cache::generation()===$generation,'skipped tuple unchanged including timestamps and page generation');

foreach(['manual','pending','held'] as $status) {
 $r=missing_record($status);$seed=$r;$seed['status']='manual';GML_Translation_Memory::upsert($seed);
 $stored_status=$status==='held'?'pending':$status;
 if($status==='held') {
  GML_Translation_Memory::update_by_id(missing_row($r)['id'],$r['translated_text'],'auto');
  $row=missing_row($r);
  gml_db_assert(GML_Translation_Memory::hold_auto_by_id($row['id'],$row),'operator hold API stores pending');
 } elseif($status==='pending')$wpdb->update($wpdb->prefix.'gml_index',['status'=>'pending'],['id'=>missing_row($r)['id']]);
 $before=missing_row($r);$result=missing_write([$r]);
 gml_db_assert($result['items'][0]['existing_status']===$stored_status && missing_row($r)===$before,'missing-only protects '.$status);
}
$other=missing_record('mixed-missing');
$result=missing_write([$fresh,$other]);
gml_db_assert($result['inserted']===1 && $result['skipped']===1 && array_column($result['items'],'outcome')===['existing','inserted'],'policy B inserts missing sibling and reports existing conflict');

update_option('gml_languages', [['code'=>'de','enabled'=>true,'site_mode'=>'local','url_prefix'=>'/de/']]);
$resource=GML_Resource_Identity::from_parts('post',900000000+random_int(1,999999),'','page',home_url('/missing-memory-'.$run.'/'),'r1');
$a=missing_record('generation-a');$b=missing_record('generation-b');
GML_Translation_Memory::upsert_batch([$a,$b]);
$nodes=array_map(static function($r){return ['text'=>$r['source_text'],'hash'=>$r['source_hash'],'context_type'=>'seo_title'];},[$a,$b]);
gml_db_assert(GML_Resource_Manifest_Store::save_complete($resource,$nodes)===true,'generation fixture manifest');
$approval=GML_Resource_Approval::approve($resource,'de',1,'Missing-only test.',GML_Resource_Approval::expected_snapshot(GML_Resource_Approval::get_status($resource,'de')));
gml_db_assert(!is_wp_error($approval),'generation fixture approved');
$initial=GML_Resource_Approval::get_status($resource,'de');
missing_write([$a,$b]);
gml_db_assert(GML_Resource_Approval::get_status($resource,'de')===$initial,'all existing skips preserve readiness and review generation exactly');
GML_Translation_Memory::delete_by_id(missing_row($b)['id']);
$before=GML_Resource_Approval::get_status($resource,'de');
missing_write([$a,$b]);
$after=GML_Resource_Approval::get_status($resource,'de');
gml_db_assert($after['translation_generation']===$before['translation_generation']+1,'one actual insert advances resource generation once');
GML_Resource_Readiness::run_rebuild_batch('missing-only-test');
$after=GML_Resource_Approval::get_status($resource,'de');
gml_db_assert($after['machine_status']==='complete' && $after['review_status']!=='approved','rebuild sees complete TM but does not auto-approve');

$invalid=missing_record('invalid');$invalid['source_hash']=md5('other source');
gml_db_assert(missing_write([$invalid])===false,'source hash mismatch fails before mutation');
$different=$fresh;$different['translated_text']='Conflicting input';
gml_db_assert(missing_write([$fresh,$different])===false,'conflicting duplicate input is rejected');
$wpdb->query('START TRANSACTION');
try {gml_db_assert(missing_write([missing_record('nested')])===false,'nested transaction rejected without implicit commit');}
finally {$wpdb->query('ROLLBACK');}

foreach(['insert','invalidation','commit'] as $failure) {
 $a=missing_record($failure.'-one');$b=missing_record($failure.'-two');$generation=GML_Page_Cache::generation();$seen=0;
 $fail=static function($sql) use($failure,&$seen){
  $is_insert=strpos($sql,'INSERT INTO test_gml_index')!==false;
  if(($failure==='insert' && $is_insert && ++$seen===2)
    || ($failure==='invalidation' && strpos($sql,'SET r.status=')!==false)
    || ($failure==='commit' && trim($sql)==='COMMIT')) return 'SELECT gml_deliberate_missing_function()';
  return $sql;
 };
 $suppressed=$wpdb->suppress_errors(true);add_filter('query',$fail);
 try {$result=missing_write([$a,$b]);} finally {remove_filter('query',$fail);$wpdb->suppress_errors($suppressed);}
 gml_db_assert($result===false && missing_row($a)===null && missing_row($b)===null,'DB '.$failure.' failure rolls back all tentative inserts');
 gml_db_assert(GML_Page_Cache::generation()===$generation,'DB '.$failure.' rollback leaves generation unchanged');
}
gml_db_assert($GLOBALS['gml_test_http_calls']===0,'missing-only makes no provider calls');
echo "OK missing-only memory contract\n";
