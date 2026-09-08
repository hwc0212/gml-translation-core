<?php
/** Shared administration commands; scanning never controls the AI worker. */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-translation-queue-scope.php';

class GML_Translation_Controls {
    public static function handle_request( array $post ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        if ( isset( $post['gml_cache_action'] ) ) {
            check_admin_referer( 'gml_cache_action', 'gml_cache_nonce' );
            $action = sanitize_key( $post['gml_cache_action'] );
            // Old forms/bookmarks must never turn a cache refresh into data deletion.
            if ( ! in_array( $action, [ 'clear_all_cache', 'clear_lang_cache', 'refresh_page_cache' ], true ) ) {
                return new WP_Error( 'removed_action', 'This cache action is no longer available. No translations or queue items were deleted.' );
            }
            return self::refresh_cache( $post['gml_cache_confirmation'] ?? '' );
        }
        if ( isset( $post['gml_global_action'] ) || isset( $post['gml_lang_action'] ) ) {
            check_admin_referer( 'gml_translation_action', 'gml_translation_nonce' );
            $lang = isset( $post['gml_lang_action'] ) ? sanitize_key( $post['gml_lang_code'] ?? '' ) : '';
            $action = sanitize_key( $post['gml_global_action'] ?? $post['gml_lang_action'] );
            if ( isset( $post['gml_lang_action'] ) && $lang === '' ) return new WP_Error( 'invalid_language', 'Choose a configured language.' );
            if ( in_array( $action, [ 'start_all', 'start_lang' ], true ) ) return self::start( $lang );
            if ( in_array( $action, [ 'pause_all', 'pause_lang' ], true ) ) return self::pause( $lang );
            if ( $action === 'resume_sample' ) return self::resume_sample();
            if ( $action === 'pause_sample' ) return self::pause_sample();
            return new WP_Error( 'invalid_action', 'Invalid translation action.' );
        }
        return null;
    }

    public static function start( $lang = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        if ( ! GML_Translation_State::multilingual_enabled() || ! GML_Translation_State::ai_available() ) {
            return new WP_Error( 'ai_unavailable', 'Enable the multilingual site and configure AI Translation first.' );
        }
        if ( GML_Queue_Processor::circuit_is_open() || GML_Queue_Processor::maybe_open_for_existing_failures() ) {
            return new WP_Error( 'safety_pause', 'Translation is safety-paused. Test the saved AI connection and retry a limited language sample first.' );
        }
        $languages = (array) get_option( 'gml_languages', [] );
        $found = false;
        $isolated_start = get_option( 'gml_translation_paused', false ) || ! GML_Translation_Queue_Scope::normal_enabled();
        foreach ( $languages as &$language ) {
            if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) {
                $language['paused'] = true;
                continue;
            }
            if ( $lang !== '' && $isolated_start ) $language['paused'] = true;
            if ( ( ! isset( $language['enabled'] ) || $language['enabled'] ) && ( $lang === '' || ( $language['code'] ?? '' ) === $lang ) ) {
                $language['paused'] = false;
                $found = true;
            }
        }
        unset( $language );
        if ( ! $found ) return new WP_Error( 'invalid_language', 'Choose a configured language.' );
        if ( ! GML_Queue_Processor::ensure_scheduled() ) return new WP_Error( 'schedule_failed', 'WordPress could not schedule translation. Pause settings were kept.' );
        update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, (int) GML_Translation_Queue_Scope::sample_paused(), false );
        update_option( GML_Translation_Queue_Scope::NORMAL_OPTION, 1, false );
        update_option( 'gml_languages', $languages );
        update_option( 'gml_translation_paused', false, false );
        return true;
    }

    /** Read at most one approved sample; never scan the full translation queue. */
    public static function sample_status() {
        $raw = (array) get_option( GML_Queue_Processor::SAMPLE_OPTION, [] );
        $state = [ 'active' => ! empty( $raw ), 'valid' => false, 'total' => count( $raw ), 'remaining' => 0, 'language' => '', 'paused' => GML_Translation_Queue_Scope::sample_paused() ];
        if ( ! $raw || count( $raw ) > GML_Queue_Processor::RETRY_LIMIT ) return $state;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ), static function( $id ) { return $id > 0; } ) ) );
        if ( count( $ids ) !== count( $raw ) ) return $state;
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT target_lang, status, attempts FROM {$wpdb->prefix}gml_queue WHERE id IN (" . implode( ',', $ids ) . ')' );
        if ( ! is_array( $rows ) ) return $state;
        $targets = array_values( array_unique( array_column( $rows, 'target_lang' ) ) );
        if ( count( $targets ) !== 1 ) return $state;
        $state['language'] = $targets[0];
        foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
            if (
                ( $language['code'] ?? '' ) === $state['language']
                && ( ! isset( $language['enabled'] ) || $language['enabled'] )
                && ( ! class_exists( 'GML_Language_Utils' ) || ! GML_Language_Utils::is_external_language( $language ) )
            ) {
                $state['valid'] = true;
            }
        }
        foreach ( $rows as $row ) {
            if ( in_array( $row->status, [ 'pending', 'processing' ], true ) && (int) $row->attempts < 3 ) $state['remaining']++;
        }
        return $state;
    }

    public static function resume_sample() {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        if ( ! GML_Translation_State::multilingual_enabled() || ! GML_Translation_State::ai_available() ) {
            return new WP_Error( 'ai_unavailable', 'Enable the multilingual site and configure AI Translation first.' );
        }
        if ( GML_Queue_Processor::circuit_is_open() || GML_Queue_Processor::maybe_open_for_existing_failures() ) {
            return new WP_Error( 'safety_pause', 'Translation is safety-paused. Test the saved AI connection and retry a limited language sample first.' );
        }
        $sample = self::sample_status();
        if ( ! $sample['active'] || ! $sample['valid'] ) return new WP_Error( 'invalid_sample', 'No valid limited sample is available for an enabled language.' );
        $lock = GML_Atomic_Option_Lock::get( GML_Queue_Processor::LOCK_OPTION );
        if ( ! empty( $lock['token'] ) && (int) ( $lock['expires'] ?? 0 ) > time() ) {
            return new WP_Error( 'sample_busy', 'The current translation batch is still finishing. Try again after it stops.' );
        }
        if ( ! $sample['remaining'] ) {
            GML_Translation_Queue_Scope::finish_sample();
            return true;
        }
        if ( ! GML_Queue_Processor::ensure_scheduled() ) return new WP_Error( 'schedule_failed', 'WordPress could not schedule translation. Pause settings were kept.' );
        // Resuming a sample never implicitly resumes ordinary pending work.
        update_option( GML_Translation_Queue_Scope::NORMAL_OPTION, (int) ( ! get_option( 'gml_translation_paused', false ) && GML_Translation_Queue_Scope::normal_enabled() ), false );
        update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, 0, false );
        update_option( 'gml_translation_paused', false, false );
        return true;
    }

    public static function pause_sample() {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, 1, false );
        if ( ! GML_Translation_Queue_Scope::has_work_scope() ) GML_Queue_Processor::unschedule_cron();
        return true;
    }

    public static function pause( $lang = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        if ( $lang === '' ) {
            update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, 1, false );
            update_option( 'gml_translation_paused', true, false );
            GML_Queue_Processor::unschedule_cron();
            return true;
        }
        $languages = (array) get_option( 'gml_languages', [] );
        foreach ( $languages as &$language ) {
            if ( ( $language['code'] ?? '' ) === $lang ) {
                if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) {
                    return new WP_Error( 'external_language', 'External language sites do not use this translation queue.' );
                }
                $language['paused'] = true;
                update_option( 'gml_languages', $languages );
                if ( ! GML_Translation_Queue_Scope::has_work_scope() ) GML_Queue_Processor::unschedule_cron();
                return true;
            }
        }
        return new WP_Error( 'invalid_language', 'Choose a configured language.' );
    }

    public static function refresh_cache( $confirmation = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        if ( ! is_string( $confirmation ) || ! hash_equals( 'REFRESH', trim( $confirmation ) ) ) return new WP_Error( 'confirmation_required', 'Type REFRESH to confirm page cache refresh. No changes were made.' );
        if ( ! GML_Page_Cache::force_invalidate() ) {
            return new WP_Error( 'cache_refresh_failed', 'The translated page-cache generation could not be updated. No cache refresh was confirmed.' );
        }
        return true;
    }

    public static function queue_status( $lang = '', $pending = null ) {
        global $wpdb;
        if ( $pending === null ) {
            $where = $lang === '' ? '' : $wpdb->prepare( ' AND target_lang = %s', $lang );
            $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gml_queue WHERE status IN ('pending','processing')$where" );
        }
        $lock = GML_Atomic_Option_Lock::get( GML_Queue_Processor::LOCK_OPTION );
        $last = (array) get_option( 'gml_translation_last_batch', [] );
        $next = wp_next_scheduled( GML_Queue_Processor::CRON_HOOK );
        $paused = (bool) get_option( 'gml_translation_paused', false );
        $normal = GML_Translation_Queue_Scope::normal_languages();
        $runnable = $lang === '' ? ! empty( $normal ) : in_array( $lang, $normal, true );
        if ( $lang === '' && ! $runnable ) {
            $sample = self::sample_status();
            $runnable = $sample['valid'] && ! $sample['paused'] && $sample['remaining'] > 0;
        }
        if ( ! $runnable ) $paused = true;
        $active = (int) ( $lock['expires'] ?? 0 ) > time() && ! empty( $lock['token'] )
            && ( $lang === '' || ( $last['language'] ?? '' ) === $lang )
            && ( $last['token'] ?? '' ) === ( $lock['token'] ?? '' );
        if ( ! GML_Translation_State::multilingual_enabled() || ! GML_Translation_State::ai_available() ) $state = 'unavailable';
        elseif ( GML_Queue_Processor::circuit_is_open() ) $state = 'safety_paused';
        elseif ( GML_Queue_Processor::backoff_is_active() ) $state = 'waiting';
        elseif ( $paused ) $state = $active ? 'pausing' : 'paused';
        elseif ( $active ) $state = 'processing';
        elseif ( ! $pending ) $state = 'idle';
        elseif ( ! $next ) $state = 'not_scheduled';
        elseif ( $next < time() - 120 ) $state = 'overdue';
        else $state = 'scheduled';
        $backoff = GML_Queue_Processor::get_backoff();
        return [ 'state' => $state, 'last_activity' => (int) ( $last['finished'] ?? $last['started'] ?? 0 ), 'next_run' => $next ?: 0, 'retry_after' => (int) ( $backoff['until'] ?? 0 ) ];
    }
}
