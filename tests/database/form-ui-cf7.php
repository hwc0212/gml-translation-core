<?php
/** Optional real CF7 rendering integration. No submission or mail is invoked. */
require __DIR__ . '/bootstrap.php';
gml_test_product_init();
gml_db_assert(GML_Installer::activate()===true,'CF7 fixture schema is available');
$cf7=getenv('GML_TEST_CF7_FILE') ?: ABSPATH.'wp-content/plugins/contact-form-7/wp-contact-form-7.php';
if(!is_file($cf7))throw new RuntimeException('Install a separately verified CF7 package in the disposable lab first.');
require_once $cf7;
wpcf7();wpcf7_init();
update_option('gml_multilingual_enabled',true);
update_option('gml_ai_translation_enabled',false);
update_option('gml_translation_paused',true);
update_option('gml_source_lang','en');
$locales=json_decode(file_get_contents(getenv('GML_TEST_PRODUCT_DIR').'/tests/fixtures/rfq-ui-translations.json'),true);
update_option('gml_languages',array_map(static function($code){return ['code'=>$code,'enabled'=>true];},array_keys($locales)));
$mail_calls=0;
add_filter('pre_wp_mail',static function()use(&$mail_calls){$mail_calls++;return false;});
$form=WPCF7_ContactForm::get_template(['title'=>'GML form rendering fixture']);
$source='Describe the equipment, intended use, project stage and what you need help with.';
$form->set_properties(['form'=>'<label>Message [textarea* your-message placeholder "'.$source.'"]</label>[submit "Send RFQ"]','additional_settings'=>'skip_mail: on']);
$form_id=$form->save();
gml_db_assert($form_id>0,'native CF7 form was created in the disposable database');
$html=$form->form_html();
$parser=new GML_HTML_Parser();$parsed=$parser->parse($html);
$texts=array_column($parsed['nodes'],'text');
gml_db_assert(in_array($source,$texts,true) && in_array('Send RFQ',$texts,true),'both native CF7 UI attributes are discovered');
$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'GML CF7 fixture page']);
$resource=GML_Resource_Identity::for_post($id);
gml_db_assert(GML_Resource_Manifest_Store::save_complete($resource,$parsed['nodes'])===true,'native CF7 discovery is saved into a resource manifest');
$row=GML_Resource_Manifest_Store::get_by_key($resource->get_key());
$hashes=$wpdb->get_col($wpdb->prepare('SELECT source_hash FROM '.GML_Resource_Manifest_Store::relation_table().' WHERE resource_id=%d AND manifest_generation=%d',$row->id,$row->manifest_generation));
gml_db_assert(in_array(md5($source),$hashes,true) && in_array(md5('Send RFQ'),$hashes,true),'manifest contains both formerly missing UI hashes');
$queue=$wpdb->prefix.'gml_queue';$queue_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue");
$translator=new GML_Translator();
foreach($locales as $lang=>$copy){
 foreach([$source=>$copy[0],'Send RFQ'=>$copy[1]] as $original=>$translated){
  gml_db_assert($translator->save_to_index(md5($original),$original,$translated,'en',$lang,'attribute','manual')===true,"$lang manual UI translation stored");
 }
 $translated=$translator->translate($parsed,$lang);
 $out=$parser->rebuild($translated);
 $expected=str_replace(['placeholder="'.$source.'"','value="Send RFQ"'],['placeholder="'.htmlspecialchars($copy[0],ENT_QUOTES|ENT_HTML5,'UTF-8').'"','value="'.htmlspecialchars($copy[1],ENT_QUOTES|ENT_HTML5,'UTF-8').'"'],$html,$count);
 gml_db_assert($count===2 && $out===$expected,"$lang real CF7 HTML changes only the two approved UI attributes");
}
gml_db_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $queue")===$queue_before,'manual CF7 rendering creates no translation queue work');
gml_db_assert($GLOBALS['gml_test_http_calls']===0 && $mail_calls===0,'CF7 render test makes no HTTP request and sends no mail');
wp_delete_post($id,true);wp_delete_post($form_id,true);
echo 'OK native WordPress / CF7 '.WPCF7_VERSION." form rendering; no submission\n";
