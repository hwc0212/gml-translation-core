<?php
/** Bounded first-party aggregate demand. Never creates resources or AI work. */
if ( ! defined( 'ABSPATH' ) ) exit;
final class GML_Page_Demand {
    public static function retention_days() {
        return max(1,min(30,(int)get_option('gml_page_demand_days',7)));
    }

    public static function scores(array $ids) {
        global $wpdb;
        if (!get_option('gml_page_demand_enabled',false) || version_compare(get_option('gml_db_version','0'),'3.5.0','<')) return [];
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        if (!$ids) return [];
        $table=$wpdb->prefix.'gml_page_demand';
        $cutoff=gmdate('Y-m-d',time()-self::retention_days()*DAY_IN_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT resource_id,language,SUM(views) AS views FROM $table
            WHERE resource_id IN (".implode(',',array_slice($ids,0,1000)).") AND day>=%s GROUP BY resource_id,language",$cutoff));
        $result=[];
        foreach((array)$rows as $row) $result[$row->resource_id][$row->language]=(int)$row->views;
        return $result;
    }

    public static function token($id,$lang,$day) {
        return hash_hmac('sha256',(int)$id.'|'.$lang.'|'.$day,wp_salt('nonce'));
    }

    public static function record($id,$lang,$day,$token,$client) {
        global $wpdb;
        if (!get_option('gml_page_demand_enabled',false) || version_compare(get_option('gml_db_version','0'),'3.5.0','<')) return false;
        $source=get_option('gml_source_lang','en');
        if (!in_array($lang,array_merge([$source],GML_Language_Utils::enabled_local_target_codes()),true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)
            || strtotime($day.' UTC')<time()-7*DAY_IN_SECONDS || strtotime($day.' UTC')>time()
            || !hash_equals(self::token($id,$lang,$day),(string)$token)) return false;
        $manifest=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.GML_Resource_Manifest_Store::manifest_table().' WHERE id=%d',(int)$id));
        $resource=$manifest ? GML_Resource_Identity::resolve($manifest->resource_key) : null;
        if (!$resource || !$resource->is_eligible() || $manifest->discovery_state==='permanent_redirect') return false;
        $lock=GML_Atomic_Option_Lock::acquire('gml_page_demand_counter',10);
        if (!$lock) return false;
        try {
            // Irreversible, daily-rotated identifiers expire; no IP or user-agent is stored.
            $client=hash_hmac('sha256',(string)$client,wp_salt('nonce').gmdate('Y-m-d'));
            $dedup='gml_demand_seen_'.hash('sha256',$client.'|'.(int)$id.'|'.$lang);
            $rate='gml_demand_rate_'.substr($client,0,32);
            $global=(int)get_transient('gml_demand_global_rate');
            $count=(int)get_transient($rate);
            if(get_transient($dedup) || $count>=60 || $global>=3000) return false;
            $table=$wpdb->prefix.'gml_page_demand';
            $saved=$wpdb->query($wpdb->prepare("INSERT INTO $table(resource_id,language,day,views) VALUES(%d,%s,%s,1)
                ON DUPLICATE KEY UPDATE views=LEAST(views+1,1000)",(int)$id,$lang,gmdate('Y-m-d')));
            if($saved===false) return false;
            set_transient($dedup,1,1800);
            set_transient($rate,$count+1,HOUR_IN_SECONDS);
            set_transient('gml_demand_global_rate',$global+1,HOUR_IN_SECONDS);
            return true;
        } finally {
            GML_Atomic_Option_Lock::release('gml_page_demand_counter',$lock);
        }
    }

    public static function cleanup() {
        global $wpdb;
        if(version_compare(get_option('gml_db_version','0'),'3.5.0','<')) return;
        $table=$wpdb->prefix.'gml_page_demand';
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE day<%s LIMIT 1000",gmdate('Y-m-d',time()-self::retention_days()*DAY_IN_SECONDS)));
    }
}
