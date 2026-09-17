<?php
/** Resource-local human decisions. Never stored as Translation Memory. */
if (!defined('ABSPATH')) exit;
final class GML_Item_Resolution {
    const SCHEMA_VERSION = '3.6.0';
    public static function table() { global $wpdb; return $wpdb->prefix.'gml_item_resolutions'; }
    public static function available() { return version_compare(get_option('gml_db_version','0'),self::SCHEMA_VERSION,'>='); }
    public static function token(array $snapshot) { return hash('sha256',wp_json_encode($snapshot)); }

    public static function snapshot($queue_id,$resource_id,$locking=false) {
        if(!self::available()) return [];
        global $wpdb;
        $asset=GML_Manual_Translation::snapshot($queue_id,$locking);
        if(!$asset) return [];
        $manifest=null;
        foreach($asset['resources'] as $row) if((int)$row['id']===(int)$resource_id) $manifest=$row;
        if(!$manifest) return [];
        $q=$asset['queue'];
        $relation=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.GML_Resource_Manifest_Store::relation_table().' WHERE resource_id=%d AND manifest_generation=%d AND source_hash=%s AND context_type=%s'.($locking?' FOR UPDATE':''),$resource_id,$manifest['manifest_generation'],$q['source_hash'],$q['context_type']),ARRAY_A);
        if(!$relation) return [];
        $decision=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE active_slot=%s'.($locking?' FOR UPDATE':''),self::slot($resource_id,$q)),ARRAY_A);
        $review=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.GML_Resource_Approval::review_table().' WHERE resource_id=%d AND target_lang=%s'.($locking?' FOR UPDATE':''),$resource_id,$q['target_lang']),ARRAY_A);
        if($wpdb->last_error!=='') return [];
        return ['asset'=>$asset,'manifest'=>$manifest,'relation'=>$relation,'decision'=>$decision,'review'=>$review];
    }
    private static function slot($resource_id,array $queue) {
        return hash('sha256',wp_json_encode([(int)$resource_id,$queue['source_hash'],$queue['source_lang'],$queue['target_lang'],$queue['context_type']]));
    }

    /** CAS + worker lease + transactional cache invalidation; immutable audit rows. */
    public static function decide($queue_id,$resource_id,$expected,$action,$confirm_critical=false) {
        if(!current_user_can('manage_options')) return new WP_Error('forbidden',__('Permission denied.','gml-translate'));
        if(!in_array($action,['keep_source','revoke'],true)) return new WP_Error('invalid_action',__('Invalid decision.','gml-translate'));
        global $wpdb;
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,self::table()));
        if(strtoupper((string)$engine)!=='INNODB' || !GML_Resource_Approval::transaction_health(true)['ready'] || (int)$wpdb->get_var('SELECT @@in_transaction')!==0) return new WP_Error('storage',__('Transactional storage is required.','gml-translate'));
        $lock=GML_Atomic_Option_Lock::acquire(GML_Queue_Processor::LOCK_OPTION,30);
        if(!$lock) return new WP_Error('busy',__('Worker busy. Try again after the current request.','gml-translate'));
        try {
            if($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('storage');
            $s=self::snapshot($queue_id,$resource_id,true);
            if(!$s || !hash_equals(self::token($s),(string)$expected)) throw new RuntimeException('source_conflict');
            $q=$s['asset']['queue'];$m=$s['manifest'];
            if($q['source_lang']!==get_option('gml_source_lang','en')) throw new RuntimeException('source_conflict');
            if(!in_array($q['target_lang'],GML_Language_Utils::enabled_local_target_codes(),true)) throw new RuntimeException('language_disabled');
            if($action==='keep_source') {
                if(!empty($s['asset']['tm']) && !in_array($s['asset']['tm']['status'],['auto','manual'],true)) throw new RuntimeException('quality_hold');
                $review=GML_Resource_Approval::get_status($m['resource_key'],$q['target_lang']);
                if(($review['decision']??'')==='rejected' && !empty($review['snapshot_matches'])) throw new RuntimeException('rejected');
                if(!empty($s['relation']['critical']) && $confirm_critical!==true) throw new RuntimeException('critical_confirmation');
            }
            $now=current_time('mysql');$actor=get_current_user_id();
            if($s['decision']) {
                if($wpdb->update(self::table(),['active_slot'=>null,'revoked_at'=>$now,'revoked_by'=>$actor],['id'=>$s['decision']['id']])!==1) throw new RuntimeException('conflict');
            } elseif($action==='revoke') throw new RuntimeException('missing_decision');
            if($action==='keep_source') {
                $data=['active_slot'=>self::slot($resource_id,$q),'resource_id'=>(int)$resource_id,'source_hash'=>$q['source_hash'],
                    'source_digest'=>hash('sha256',$q['source_text']),'source_bytes'=>strlen($q['source_text']),'source_lang'=>$q['source_lang'],'target_lang'=>$q['target_lang'],
                    'context_type'=>$q['context_type'],'manifest_generation'=>$m['manifest_generation'],'manifest_fingerprint'=>$m['manifest_fingerprint'],
                    'global_generation'=>$m['global_generation'],'critical_confirmed'=>!empty($s['relation']['critical'])?1:0,'actor'=>$actor,'created_at'=>$now];
                if($wpdb->insert(self::table(),$data)!==1) throw new RuntimeException('write_failed');
            }
            $versions=GML_Resource_Approval::version_table();
            if($wpdb->query($wpdb->prepare("INSERT INTO $versions (resource_id,target_lang,generation,updated_at) VALUES (%d,%s,2,%s) ON DUPLICATE KEY UPDATE generation=generation+1,updated_at=VALUES(updated_at)",$resource_id,$q['target_lang'],$now))===false) throw new RuntimeException('write_failed');
            if(!GML_Page_Cache::invalidate_resources([(int)$resource_id])) throw new RuntimeException('cache_failed');
            if($wpdb->query('COMMIT')===false) throw new RuntimeException('commit_failed');
            return true;
        } catch(Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('resolution_'.$error->getMessage(),__('Decision not saved. Refresh and check the source, quality hold, critical confirmation and permissions.','gml-translate'));
        } finally { GML_Atomic_Option_Lock::release(GML_Queue_Processor::LOCK_OPTION,$lock); }
    }

    /** SQL aliases are internal constants, never user input. */
    public static function join_sql($manifest='m',$relation='s',$language='p.lang',$source='p.source_lang') {
        return ' LEFT JOIN '.self::table()." k ON k.resource_id=$manifest.id AND k.source_hash=$relation.source_hash
            AND k.context_type=$relation.context_type AND k.target_lang=$language AND k.source_lang=$source
            AND k.active_slot IS NOT NULL AND k.manifest_generation=$manifest.manifest_generation
            AND k.global_generation=$manifest.global_generation AND k.manifest_fingerprint=$manifest.manifest_fingerprint
            AND ($relation.critical=0 OR k.critical_confirmed=1) ";
    }

    /** Current snapshots only. One indexed read per resource/language, no TM write. */
    public static function kept_hashes($subject,$language) {
        if(!self::available()) return [];
        $resource=GML_Resource_Identity::resolve($subject);
        if(!$resource) return [];
        global $wpdb;
        $m=GML_Resource_Manifest_Store::manifest_table();$s=GML_Resource_Manifest_Store::relation_table();
        $source=get_option('gml_source_lang','en');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT k.source_hash,k.source_digest,k.context_type FROM '.self::table()." k
            INNER JOIN $m m ON m.id=k.resource_id AND m.manifest_generation=k.manifest_generation AND m.global_generation=k.global_generation AND m.manifest_fingerprint=k.manifest_fingerprint
            INNER JOIN $s s ON s.resource_id=m.id AND s.manifest_generation=m.manifest_generation AND s.source_hash=k.source_hash AND s.context_type=k.context_type
            LEFT JOIN {$wpdb->prefix}gml_index i ON i.source_hash=k.source_hash AND i.source_lang=k.source_lang AND i.target_lang=k.target_lang
            WHERE m.resource_key=%s AND m.discovery_state='complete' AND m.global_generation=%d
            AND k.source_lang=%s AND k.target_lang=%s AND k.active_slot IS NOT NULL
            AND (s.critical=0 OR k.critical_confirmed=1) AND (i.id IS NULL OR i.status IN ('auto','manual'))",
            $resource->get_key(),GML_Resource_Manifest_Manager::global_generation(),$source,$language),ARRAY_A);
        $result=[];
        foreach((array)$rows as $row) $result[$row['source_hash']]=$row;
        return $result;
    }
}
