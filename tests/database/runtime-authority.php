<?php
/** Final rendered source, not a translated sprintf template, owns GML memory. */
require __DIR__.'/bootstrap.php';
gml_db_assert(GML_Installer::activate()===true,'runtime schema installed');
update_option('gml_source_lang','en');
update_option('gml_languages', [['code'=>'de','enabled'=>true]]);
update_option('gml_multilingual_enabled',true);
update_option('gml_ai_translation_enabled',false);
update_option('gml_translation_paused',true);
update_option('blog_public','1');
gml_test_product_init();
$records=[
 'Runtime catalogue'=>'Laufzeit-Katalog',
 'Shop order'=>'Shop-Bestellung',
 'Shop-Bestellung'=>'Shop-Bestellung (GML, WordPress, WooCommerce, Gemini)',
 'Showing %1$d&ndash;%2$d of %3$d results'=>'Zeige %1$d&ndash;%2$d von %3$d Ergebnissen',
 'Showing 1–12 of 16 results'=>'Ergebnisse 1–12 von 16',
 'Select options for &ldquo;%s&rdquo;'=>'Optionen für „%s“ auswählen',
 'Select options for “New 3g/15g/30g/h Kit”'=>'Optionen für „Neues Kit 3g/15g/30g/h“',
 '© 2026 Example. All rights reserved.'=>'© 2026 Example. Alle Rechte vorbehalten.',
];
$dict=[];
foreach($records as $source=>$target){
 $wpdb->replace($wpdb->prefix.'gml_index',['source_hash'=>md5($source),'source_text'=>$source,'source_lang'=>'en','target_lang'=>'de','translated_text'=>$target,'context_type'=>'text','status'=>'auto','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
 $dict[md5($source)]=$target;
}
GML_Translator::invalidate_cache('en','de');
$filter=new GML_Gettext_Filter();
foreach(['target_lang'=>'de','source_lang'=>'en','dict'=>$dict] as $name=>$value){$p=new ReflectionProperty($filter,$name);$p->setAccessible(true);$p->setValue($filter,$value);}
$count='Showing %1$d&ndash;%2$d of %3$d results';
$aria='Select options for &ldquo;%s&rdquo;';
$sort=$filter->filter_gettext('Shop order','Shop order','woocommerce');
$formatted=sprintf($filter->filter_ngettext($count,$count,$count,16,'woocommerce'),1,12,16);
$label=sprintf($filter->filter_gettext($aria,$aria,'woocommerce'),'New 3g/15g/30g/h Kit');
$html='<html><head><title>Runtime catalogue</title></head><body><select aria-label="'.esc_attr($sort).'"></select><p>'.$formatted.'</p><a aria-label="'.esc_attr(html_entity_decode($label,ENT_QUOTES|ENT_HTML5,'UTF-8')).'">Runtime catalogue</a><footer><span>&copy; 2026 Example. All rights reserved.</span></footer></body></html>';
$parser=new GML_HTML_Parser();
$translated=(new GML_Translator())->translate($parser->parse($html),'de');
$output=$parser->rebuild($translated);
class RuntimeAuthorityProbe extends GML_Output_Buffer {
 public function __construct(){$this->target_lang='de';}
 public function ready($parsed){return $this->translation_is_index_ready($parsed);}
}
$results=[
 'dynamic gettext template remains source until sprintf'=>$filter->filter_gettext($aria,$aria,'woocommerce')===$aria,
 'render readiness does not reject formatted GML-owned strings'=>(new RuntimeAuthorityProbe())->ready($translated),
 'pretranslated static label is not translated twice'=>strpos($output,'WordPress')===false&&strpos($output,'Shop-Bestellung')!==false,
 'exact full aria tuple owns output'=>strpos(html_entity_decode($output,ENT_QUOTES|ENT_HTML5,'UTF-8'),$records['Select options for “New 3g/15g/30g/h Kit”'])!==false,
 'result count uses exact rendered source tuple'=>strpos($output,'Ergebnisse 1–12 von 16')!==false,
 'named entity footer is translated'=>strpos($output,'Alle Rechte vorbehalten.')!==false&&strpos($output,'All rights reserved.')===false,
];
foreach($results as $label=>$pass)echo ($pass?'PASS: ':'FAIL: ').$label."\n";
if(in_array(false,$results,true))throw new RuntimeException('Runtime authority regression failed');
$html='<html><body><p>  &copy; 2026 Example. All rights reserved.  </p><p>&#169; 2026 Example. All rights reserved.</p><p>Alpha</p><p>Beta</p><p>Alpha more</p><p>Safety text</p><script>Alpha</script><a href="/Alpha/" data-value="Alpha">Alpha</a></body></html>';
$parsed=$parser->parse($html);
$parsed['replacements']=[
 '© 2026 Example. All rights reserved.'=>'© 2026 Example. Alle Rechte vorbehalten.',
 'Alpha'=>'Beta', 'Beta'=>'Gamma', 'Safety text'=>'Safe & <strong>sound</strong>',
];
$output=$parser->rebuild($parsed);
gml_db_assert(substr_count($output,'Alle Rechte vorbehalten.')===2,'named and numeric entities use the same exact source');
gml_db_assert(strpos($output,'<p>  © 2026 Example. Alle Rechte vorbehalten.  </p>')!==false,'whole-node replacement preserves surrounding whitespace');
gml_db_assert(strpos($output,'<p>Beta</p><p>Gamma</p><p>Alpha more</p>')!==false,'translations do not cascade or replace unrelated substrings');
gml_db_assert(strpos($output,'Safe &amp; sound')!==false && strpos($output,'<strong>')===false,'translated text cannot introduce HTML markup');
gml_db_assert(strpos($output,'<script>Alpha</script>')!==false && strpos($output,'href="/Alpha/" data-value="Alpha"')!==false,'script URL and technical attributes retain their bytes');
$native='Ergebnisse %1$d bis %2$d von %3$d';
gml_db_assert($filter->filter_ngettext($native,$count,$count,16,'woocommerce')===$native,'an upstream native localized format is not overwritten');
gml_db_assert(!GML_Output_Buffer::is_pretranslated_text('Shop-Bestellung','ru'),'pretranslated output identity is isolated by language');
gml_db_assert($GLOBALS['gml_test_http_calls']===0,'runtime fixture has no provider calls');
