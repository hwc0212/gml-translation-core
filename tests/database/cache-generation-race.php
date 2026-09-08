<?php
require __DIR__.'/bootstrap.php';
$mode=$argv[1]??'';
if ($mode==='cached' || $mode==='authoritative') {
    $value=$mode==='cached' ? get_option(GML_Page_Cache::GENERATION_OPTION) : GML_Page_Cache::generation();
    echo 'GENERATION='.$value."\n";
    exit;
}
gml_test_product_init();
gml_db_assert(true===GML_Installer::activate(), 'cache race schema ready');
$run=static function($mode) {
    $output=[];$status=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($mode).' 2>&1', $output, $status);
    gml_db_assert($status===0 && preg_match('/GENERATION=(\d+)/',implode("\n",$output),$match), 'independent reader returns a generation');
    return (int)$match[1];
};
gml_db_assert(GML_Page_Cache::force_invalidate(), 'establish a clean committed baseline');
$before=GML_Page_Cache::generation();
$old_key=GML_Page_Cache::key('de','/cache-race/');
set_transient($old_key,'Old public HTML',3600);
gml_db_assert(false!==$wpdb->query('START TRANSACTION'),'publisher opens transaction');
try {
    gml_db_assert(GML_Page_Cache::force_invalidate(), 'publisher updates generation and clears Redis before commit');
    // The child has a separate DB connection. It refills Redis with the old value.
    gml_db_assert($run('cached')===$before, 'pre-commit reader sees and caches the old committed generation');
    gml_db_assert(false!==$wpdb->query('COMMIT'), 'publisher commits after the old cache refill');
} catch(Throwable $e) {
    $wpdb->query('ROLLBACK');
    throw $e;
}
gml_db_assert($run('authoritative')>$before, 'post-commit new request ignores the stale Redis option');
gml_db_assert(GML_Page_Cache::key('de','/cache-race/')!==$old_key, 'old translated HTML remains unreachable');
gml_db_assert(get_transient($old_key)==='Old public HTML', 'old HTML was not deleted to fake cache safety');
echo 'METRIC persistent_cache='.(wp_using_ext_object_cache()?'yes':'no')."\n";
echo "OK cross-process cache generation race\n";
