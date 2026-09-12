<?php
/** Select a bounded window of one current resource/language in the existing queue. */
if ( ! defined( 'ABSPATH' ) ) exit;
final class GML_Page_Work_Scheduler {
    const WINDOW = 'gml_page_work_window';
    public static function important_post_ids() {
        $ids=[];
        $keys=self::important_keys();
        arsort($keys,SORT_NUMERIC);
        foreach($keys as $key=>$weight) {
            $resource=GML_Resource_Identity::resolve($key);
            if($resource && in_array($resource->get_type(),['post','role'],true) && $resource->get_object_id()) $ids[]=$resource->get_object_id();
        }
        $home=(int)get_option('page_on_front',0);
        if($home) array_unshift($ids,$home);
        return array_values(array_unique($ids));
    }

    public static function important_keys() {
        $keys = [];
        $home = GML_Resource_Identity::resolve( home_url('/') );
        if ( $home ) $keys[$home->get_key()] = 300;
        $locations = get_nav_menu_locations();
        $location = (string) get_option('gml_priority_menu_location', 'primary');
        $menu = $locations[$location] ?? 0;
        foreach ( $menu ? (array) wp_get_nav_menu_items($menu) : [] as $item ) {
            if ( (int) $item->menu_item_parent !== 0 ) continue;
            $resource = GML_Resource_Identity::resolve($item->url);
            if ( $resource && $resource->is_eligible() && ! isset($keys[$resource->get_key()]) ) $keys[$resource->get_key()] = 200;
        }
        foreach ( array_slice((array)get_option('gml_priority_resource_keys', []),0,100) as $key ) {
            $resource = GML_Resource_Identity::resolve($key);
            if ($resource && $resource->is_eligible()) $keys[$resource->get_key()] = 400;
        }
        return $keys;
    }

    public static function select_items($scope_sql, $limit) {
        global $wpdb;
        if (!GML_Resource_Manifest_Store::tables_ready()) return null;
        $q=$wpdb->prefix.'gml_queue';
        $m=GML_Resource_Manifest_Store::manifest_table();
        $s=GML_Resource_Manifest_Store::relation_table();
        $important=self::important_keys();
        $cases='0';
        foreach($important as $key=>$weight) $cases.=$wpdb->prepare(' + IF(m.resource_key=%s,%d,0)',$key,$weight);
        // The scope is constructed by the worker from trusted, prepared fragments.
        $rows=$wpdb->get_results("SELECT m.id,m.resource_key,q.target_lang,MAX(q.priority) AS priority,
            MIN(q.created_at) AS waiting_since,COUNT(*) AS pending
            FROM $q q INNER JOIN $s s ON s.source_hash=q.source_hash
            INNER JOIN $m m ON m.id=s.resource_id AND m.manifest_generation=s.manifest_generation
            WHERE q.status='pending' AND q.attempts<3 AND m.discovery_state='complete' $scope_sql
            GROUP BY m.id,m.resource_key,q.target_lang
            ORDER BY (IF(MAX(q.priority)>=1000,1000000,0)+$cases+LEAST(10000,GREATEST(0,TIMESTAMPDIFF(DAY,MIN(q.created_at),UTC_TIMESTAMP()))*10)) DESC,
                MIN(q.created_at),m.id LIMIT 1000", ARRAY_A);
        if ($wpdb->last_error !== '' || !$rows) return null;
        $window=(array)get_option(self::WINDOW, []);
        $demands=class_exists('GML_Page_Demand') ? GML_Page_Demand::scores(array_column($rows,'id')) : [];
        // Optional read-only adapters may merge already available GSC/GA aggregates.
        $demands=apply_filters('gml_page_demand_scores',$demands,array_map('intval',array_column($rows,'id')));
        if(!is_array($demands)) $demands=[];
        $languages=array_flip(GML_Language_Utils::enabled_local_target_codes());
        $source=get_option('gml_source_lang','en');
        foreach($rows as &$row) {
            $age=max(0,(int)floor((time()-strtotime($row['waiting_since'].' UTC'))/86400));
            $demand=(int)($demands[$row['id']][$source]??0)+2*(int)($demands[$row['id']][$row['target_lang']]??0);
            $row['score']=(int)$row['priority']>=1000 ? 1000000 : ($important[$row['resource_key']]??0)+min(300,max(0,$demand))+min(10000,$age*10);
            $row['language_order']=$languages[$row['target_lang']]??999;
            if (($window['resource_id']??0)==$row['id'] && ($window['lang']??'')===$row['target_lang']
                && ($window['until']??0)>time()) $row['score']+=1000;
        }
        unset($row);
        usort($rows,static function($a,$b) {
            return ($b['score']<=>$a['score']) ?: ($a['pending']<=>$b['pending']) ?: ($a['language_order']<=>$b['language_order']) ?: strcmp($a['waiting_since'],$b['waiting_since']);
        });
        $chosen=$rows[0];
        if (($window['resource_id']??0)!=$chosen['id'] || ($window['lang']??'')!==$chosen['target_lang'] || ($window['until']??0)<=time()) {
            update_option(self::WINDOW,['resource_id'=>(int)$chosen['id'],'lang'=>$chosen['target_lang'],'until'=>time()+180],false);
        }
        return $wpdb->get_results($wpdb->prepare("SELECT q.* FROM $q q
            WHERE q.status='pending' AND q.attempts<3 AND q.target_lang=%s $scope_sql
            AND EXISTS(SELECT 1 FROM $s s INNER JOIN $m m ON m.id=s.resource_id AND m.manifest_generation=s.manifest_generation
                WHERE s.source_hash=q.source_hash AND m.id=%d AND m.discovery_state='complete')
            ORDER BY q.priority DESC,q.context_type,q.created_at,q.id LIMIT %d",
            $chosen['target_lang'],(int)$chosen['id'],min(30,max(1,(int)$limit))));
    }
}
