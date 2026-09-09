<?php
require __DIR__.'/bootstrap.php';
gml_test_product_init();GML_Installer::activate();
require_once dirname((new ReflectionClass('GML_Translation_Memory'))->getFileName()).'/class-translator.php';
update_option('gml_source_lang','en');update_option('gml_multilingual_enabled',true);update_option('gml_ai_translation_enabled',true);
update_option('gml_translation_engine','gemini');GML_Translation_Credentials::save('disposable-not-real','gemini');
update_option('gml_translation_paused',true);delete_option('gml_translation_circuit_breaker');
update_option('gml_languages',[['code'=>'de','enabled'=>true],['code'=>'ru','enabled'=>true]]);
$run=bin2hex(random_bytes(5));
$text='Showing 1–12 of 16 results '.$run;
$html='<!doctype html><html><body><p>'.$text.'</p></body></html>';
$renderer=new class($html) {private $html;function __construct($html){$this->html=$html;}function render($resource){return $this->html;}};
$term=wp_insert_term('Synthetic category '.$run,'category');
$resource=GML_Resource_Identity::for_term($term['term_id'],'category');
$discovery=new GML_Resource_Manifest_Discovery($renderer);
$count=static function($table)use($text,$wpdb){return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}$table WHERE source_hash=%s AND source_lang='en' AND target_lang='de'",md5($text)));};
gml_db_assert($discovery->discover($resource)===true,'ordinary authoritative manifest discovery succeeds');
gml_db_assert($count('gml_queue')===0 && $count('gml_index')===0,'old discovery gap fixture has required string without queue/TM');
gml_db_assert($discovery->discover($resource,'de')===true && $count('gml_queue')===1,'explicit resource-language discovery queues required archive text while paused');
gml_db_assert($discovery->discover($resource,'de')===true && $count('gml_queue')===1,'repeated discovery does not duplicate queue asset');
$term2=wp_insert_term('Other category '.$run,'category');
$other=GML_Resource_Identity::for_term($term2['term_id'],'category');
gml_db_assert($discovery->discover($other,'de')===true && $count('gml_queue')===1,'same text across resources reuses one queue asset');
gml_db_assert((bool)get_option('gml_translation_paused') && $GLOBALS['gml_test_http_calls']===0,'explicit discovery never starts AI work');
foreach(['manual','held'] as $status) {
 $s='Protected archive text '.$status.' '.$run;$h=md5($s);
 $r=['source_text'=>$s,'source_hash'=>$h,'source_lang'=>'en','target_lang'=>'de','translated_text'=>'Saved '.$status,'status'=>$status==='manual'?'manual':'auto'];
 GML_Translation_Memory::upsert($r);wp_set_current_user(1);
 if($status==='held'){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gml_index WHERE source_hash=%s",$h),ARRAY_A);GML_Translation_Memory::hold_auto_by_id($row['id'],$row);}
 $parser=(new GML_HTML_Parser())->parse('<html><body><p>'.$s.'</p></body></html>');
 $translator=new GML_Translation_Translator();$translator->discover($parser,'de');$translator->discover($parser,'de');
 gml_db_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}gml_queue WHERE source_hash=%s AND target_lang='de'",$h))===0,'repeated enqueue protects '.$status.' TM');
}
gml_db_assert(is_wp_error($discovery->discover($resource,'en')),'source language cannot become a queue target');
update_option('gml_ai_translation_enabled',false);
gml_db_assert(is_wp_error($discovery->discover($resource,'ru')),'AI-disabled explicit discovery fails closed without queue');
echo "OK explicit manifest-to-queue bridge\n";
