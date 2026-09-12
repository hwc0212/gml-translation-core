<?php
/** Consume exact-URL cache outbox only through explicitly configured adapters. */
if (!defined('ABSPATH')) exit;
final class GML_Resource_Cache_Worker {
    const HOOK='gml_resource_cache_consume';
    public static function register_hooks() {
        add_action(self::HOOK,[__CLASS__,'run']);
        add_action('gml_resource_cache_continue',[__CLASS__,'run']);
        if(is_admin() && current_user_can('manage_options') && !wp_next_scheduled(self::HOOK)) wp_schedule_event(time()+60,'hourly',self::HOOK);
    }
    public static function run() {
        if(!wp_doing_cron() || !GML_Resource_Manifest_Store::tables_ready()) return;
        $adapters=apply_filters('gml_resource_cluster_cache_adapters',[]);
        if(!is_array($adapters) || !$adapters || array_filter($adapters,'is_callable')!==$adapters) {
            update_option('gml_resource_cache_worker_status',['state'=>'adapter_required','time'=>time()],false);
            return;
        }
        $lease=GML_Atomic_Option_Lock::acquire('gml_resource_cache_worker',180);
        if(!$lease) return;
        $completed=0; $failed=0; $processed=0; $start=microtime(true);
        try {
            foreach(GML_Page_Cache::pending_clusters(5,true) as $cluster) {
                if(!empty($cluster['blocked'])) { $failed++; continue; }
                $signature=hash('sha256',wp_json_encode([$cluster['name'],$cluster['token'],$cluster['urls'],array_keys($adapters)]));
                $cursor=(array)get_option('gml_resource_cache_cursor',[]);
                $offset=($cursor['signature']??'')===$signature?(int)($cursor['offset']??0):0;
                $ok=true;
                foreach(array_slice($cluster['urls'],$offset) as $url) {
                    if($processed>=20 || microtime(true)-$start>=45) { $ok=false; break; }
                    if(GML_URL_Helper::internal_absolute_path($url)===null) { $ok=false; break; }
                    foreach($adapters as $adapter) {
                        if(!GML_Atomic_Option_Lock::refresh('gml_resource_cache_worker',$lease,180)) return;
                        try { $result=call_user_func($adapter,$url,$cluster); }
                        catch(Throwable $error) { $result=false; }
                        if(!GML_Atomic_Option_Lock::refresh('gml_resource_cache_worker',$lease,180)) return;
                        if($result!==true) { $ok=false; break 2; }
                    }
                    $processed++; $offset++;
                    update_option('gml_resource_cache_cursor',['signature'=>$signature,'offset'=>$offset],false);
                }
                if($ok && GML_Atomic_Option_Lock::refresh('gml_resource_cache_worker',$lease,180)
                    && GML_Page_Cache::acknowledge_cluster($cluster['name'],$cluster['token'],true)) {
                    $completed++; delete_option('gml_resource_cache_cursor');
                }
                else $failed++;
                // One resource per run; resume with a token-bound cursor instead of global purges.
                break;
            }
            $remaining=GML_Page_Cache::pending_clusters(1,true);
            update_option('gml_resource_cache_worker_status',['state'=>($failed||$remaining)?'retry_pending':'completed','time'=>time(),'completed'=>$completed,'failed'=>$failed,'urls'=>$processed],false);
            if($remaining && !wp_next_scheduled('gml_resource_cache_continue')) wp_schedule_single_event(time()+60,'gml_resource_cache_continue');
        } finally { GML_Atomic_Option_Lock::release('gml_resource_cache_worker',$lease); }
    }
}
