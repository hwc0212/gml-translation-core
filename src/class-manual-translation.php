<?php
/** Explicit one-item requests use the existing queue worker and its lease. */
if (!defined('ABSPATH')) exit;
final class GML_Manual_Translation {
    const JOB = 'gml_manual_translation_job';

    public static function snapshot($id,$locking=false) {
        global $wpdb;
        $queue=$wpdb->get_row($wpdb->prepare("SELECT id,source_hash,source_text,source_lang,target_lang,context_type FROM {$wpdb->prefix}gml_queue WHERE id=%d".($locking?' FOR UPDATE':''),(int)$id),ARRAY_A);
        if(!$queue || !hash_equals(md5($queue['source_text']),$queue['source_hash'])) return [];
        $m=GML_Resource_Manifest_Store::manifest_table();
        $s=GML_Resource_Manifest_Store::relation_table();
        $refs=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT m.id,m.resource_key,m.manifest_generation,m.manifest_fingerprint,m.global_generation,m.discovery_state
            FROM $m m INNER JOIN $s s ON s.resource_id=m.id AND s.manifest_generation=m.manifest_generation
            WHERE s.source_hash=%s ORDER BY m.id".($locking?' FOR UPDATE':''),$queue['source_hash']),ARRAY_A);
        if(!$refs || $wpdb->last_error!=='') return [];
        foreach($refs as $ref) if($ref['discovery_state']!=='complete' || (int)$ref['global_generation']!==GML_Resource_Manifest_Manager::global_generation()) return [];
        $tm=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gml_index WHERE source_hash=%s AND source_lang=%s AND target_lang=%s".($locking?' FOR UPDATE':''),$queue['source_hash'],$queue['source_lang'],$queue['target_lang']),ARRAY_A);
        if($wpdb->last_error!=='') return [];
        return ['queue'=>$queue,'resources'=>$refs,'tm'=>$tm];
    }

    public static function token(array $snapshot) { return hash('sha256',wp_json_encode($snapshot)); }
    public static function job() { return (array)get_option(self::JOB,[]); }
    public static function pending_id() {
        $job=self::job();
        return in_array($job['state']??'',['accepted','generating','waiting'],true) && ($job['expires']??0)>time() ? (int)($job['id']??0) : 0;
    }
    public static function request($id,$expected) {
        if(!current_user_can('manage_options')) return new WP_Error('forbidden','Permission denied.');
        if(!GML_Translation_State::multilingual_enabled() || !GML_Translation_State::ai_available()) return new WP_Error('ai_disabled','AI translation is disabled or its key is unavailable.');
        if(GML_Queue_Processor::circuit_is_open()) return new WP_Error('provider_circuit','Test and repair the provider configuration first.');
        $lock=GML_Atomic_Option_Lock::acquire(GML_Queue_Processor::LOCK_OPTION,30);
        if(!$lock) return new WP_Error('worker_busy','A worker request is already running. Retry when it finishes.');
        try {
            if(self::pending_id()) return new WP_Error('manual_busy','Another explicit item is being processed.');
            $snapshot=self::snapshot($id);
            if(!$snapshot || !hash_equals(self::token($snapshot),(string)$expected)) return new WP_Error('source_conflict','The source or translation changed. Refresh and review it again.');
            $previous=self::job();
            if(($previous['requested_at']??0)>time()-30) return new WP_Error('rate_limited','Wait 30 seconds before another explicit request.');
            if(!in_array($snapshot['queue']['target_lang'],GML_Language_Utils::enabled_local_target_codes(),true)) return new WP_Error('language_disabled','This local language is disabled.');
            global $wpdb;
            $queue_engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$wpdb->prefix.'gml_queue'));
            if(strtoupper((string)$queue_engine)!=='INNODB') return new WP_Error('transaction_unavailable','The queue must use InnoDB for an atomic explicit request.');
            if(!GML_Resource_Approval::transaction_health(true)['ready'] || (int)$wpdb->get_var('SELECT @@in_transaction')!==0) return new WP_Error('transaction_unavailable','Transactional storage is required.');
            if($wpdb->query('START TRANSACTION')===false) return new WP_Error('transaction_unavailable','Could not begin the request.');
            $fresh=self::snapshot($id,true);
            if(!$fresh || !hash_equals((string)$expected,self::token($fresh))) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('source_conflict','The source or translation changed. Refresh and review it again.');
            }
            $saved=$wpdb->update($wpdb->prefix.'gml_queue',['status'=>'pending','attempts'=>0,'priority'=>1000],['id'=>(int)$id]);
            if($saved===false) { $wpdb->query('ROLLBACK'); return new WP_Error('queue_write','The request could not be saved.'); }
            $job=['id'=>(int)$id,'state'=>'accepted','snapshot'=>$expected,'requested_at'=>time(),'expires'=>time()+1800,'actor'=>get_current_user_id()];
            $written=$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",self::JOB,maybe_serialize($job)));
            if($written===false || $wpdb->query('COMMIT')===false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('job_write','The request could not be recorded.');
            }
            wp_cache_delete(self::JOB,'options');
            wp_cache_delete('notoptions','options');
            wp_cache_delete('alloptions','options');
            wp_schedule_single_event(time()+1,GML_Queue_Processor::CRON_HOOK);
            GML_Queue_Processor::ensure_scheduled();
            GML_Translation_Activity::record('manual_requested',['queue_id'=>(int)$id,'actor'=>get_current_user_id()]);
            return ['id'=>(int)$id,'state'=>'accepted'];
        } finally { GML_Atomic_Option_Lock::release(GML_Queue_Processor::LOCK_OPTION,$lock); }
    }

    public static function set_state($id,$state,array $extra=[]) {
        $job=self::job();
        if((int)($job['id']??0)!==(int)$id) return;
        $job=array_merge($job,$extra,['state'=>$state,'updated_at'=>time()]);
        update_option(self::JOB,$job,false);
    }

    /** Existing auto/manual/held text gets a candidate, never an implicit overwrite. */
    public static function accept_result($item,$text) {
        $id=(int)$item->id;
        $job=self::job();
        $snapshot=self::snapshot($id);
        if(!$snapshot || !hash_equals((string)($job['snapshot']??''),self::token($snapshot))) {
            self::set_state($id,'failed',['error'=>'source_conflict']);
            return false;
        }
        if(GML_Translation_Text::obvious_contamination($item->source_text,$text)) {
            self::set_state($id,'failed',['error'=>'translation_contamination']);
            return false;
        }
        if(!empty($snapshot['tm'])) {
            global $wpdb;
            $name='gml_translation_candidate_'.$id;
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('gml_translation_candidate_').'%'));
            if($count>=100 && get_option($name,null)===null) {
                self::set_state($id,'failed',['error'=>'candidate_capacity_review_existing']);
                return false;
            }
            update_option($name,['text'=>$text,'snapshot'=>$job['snapshot'],'created_at'=>time()],false);
            self::set_state($id,'candidate',['candidate'=>$text]);
            return true;
        }
        $guard=static function() use($id,$job) { $now=self::snapshot($id,true); return $now && hash_equals($job['snapshot'],self::token($now)); };
        $saved=GML_Translation_Memory::insert_missing_batch([array_merge($snapshot['queue'],['translated_text'=>$text,'status'=>'auto'])],$guard);
        if(!$saved || (int)$saved['inserted']!==1) {
            self::set_state($id,'failed',['error'=>'write_conflict']);
            return false;
        }
        self::set_state($id,'saved');
        return true;
    }

    public static function save_manual($id,$expected,$text,$release_hold=false) {
        if(!current_user_can('manage_options')) return false;
        $snapshot=self::snapshot($id);
        if(!$snapshot || !hash_equals(self::token($snapshot),(string)$expected)) return false;
        $text=GML_Translation_Text::plain_text($text);
        if(trim($text)==='' || strlen($text)>100000 || GML_Translation_Text::obvious_contamination($snapshot['queue']['source_text'],$text)) return false;
        $guard=static function() use($id,$expected) { $now=self::snapshot($id,true); return $now && hash_equals($expected,self::token($now)); };
        if($snapshot['tm']) {
            $saved=GML_Translation_Memory::update_reviewed($snapshot['tm']['id'],$text,GML_Translation_Memory::edit_token($snapshot['tm']),$release_hold,$guard);
            if($saved) delete_option('gml_translation_candidate_'.(int)$id);
            if($saved) self::resolve_queue_error($id);
            return $saved;
        }
        $result=GML_Translation_Memory::insert_missing_batch([array_merge($snapshot['queue'],['translated_text'=>$text,'status'=>'manual'])],$guard);
        $saved=$result && (int)$result['inserted']===1;
        if($saved) self::resolve_queue_error($id);
        return $saved;
    }
    private static function resolve_queue_error($id) {
        global $wpdb;
        $wpdb->update($wpdb->prefix.'gml_queue',['status'=>'completed','processed_at'=>current_time('mysql')],['id'=>(int)$id,'status'=>'failed']);
    }
}
