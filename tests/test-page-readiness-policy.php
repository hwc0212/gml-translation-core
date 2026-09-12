<?php
define('ABSPATH', __DIR__);
$threshold = 98;
function get_option($name, $default = null) { global $threshold; return $name === 'gml_page_ready_percent' ? $threshold : $default; }
require dirname(__DIR__) . '/src/class-page-readiness-policy.php';
function check($condition, $name) { if (!$condition) throw new RuntimeException($name); echo "PASS $name\n"; }
$base = ['machine_status'=>'incomplete','required_count'=>1000,'translated_count'=>980,'critical_missing_count'=>0,'translation_fingerprint'=>str_repeat('a',64)];
$coverage = ['source_bytes'=>10000,'translated_bytes'=>9800,'unknown_count'=>0,'held_count'=>0];
check(GML_Page_Readiness_Policy::evaluate($base,$coverage)['ready'], '98 exact page');
$below=$base; $below['translated_count']=979;
check(!GML_Page_Readiness_Policy::evaluate($below,$coverage)['ready'], '97.9 is not rounded up');
$complete=$base; $complete['translated_count']=1000; $complete['machine_status']='complete';
check(GML_Page_Readiness_Policy::evaluate($complete)['ready'], '100 complete');
$short=$coverage; $short['translated_bytes']=500;
check(!GML_Page_Readiness_Policy::evaluate($base,$short)['ready'], 'navigation cannot hide missing body');
$critical=$base; $critical['critical_missing_count']=1;
check(!GML_Page_Readiness_Policy::evaluate($critical,$coverage)['ready'], 'critical missing');
$held=$coverage; $held['held_count']=1;
check(!GML_Page_Readiness_Policy::evaluate($base,$held)['ready'], 'quality held');
$stale=$base; $stale['machine_status']='stale';
check(!GML_Page_Readiness_Policy::evaluate($stale,$coverage)['ready'], 'stale snapshot');
$rejected=$base; $rejected['snapshot_matches']=true; $rejected['decision']='rejected';
check(!GML_Page_Readiness_Policy::evaluate($rejected,$coverage)['ready'], 'current human rejection');
$global=$base; $global['global_language_percent']=37;
check(GML_Page_Readiness_Policy::evaluate($global,$coverage)['ready'], 'global 37 cannot block ready page');
$global['global_language_percent']=99; $global['translated_count']=550;
check(!GML_Page_Readiness_Policy::evaluate($global,$coverage)['ready'], 'global 99 cannot publish incomplete page');
$threshold=99;
check(!GML_Page_Readiness_Policy::evaluate($base,$coverage)['ready'], 'configured threshold');
echo "11 policy cases passed\n";
