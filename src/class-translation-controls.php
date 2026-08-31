<?php
/** Shared administration commands; scanning never controls the AI worker. */
if ( ! defined( 'ABSPATH' ) ) exit;

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
            return self::refresh_cache();
        }
        if ( isset( $post['gml_global_action'] ) || isset( $post['gml_lang_action'] ) ) {
            check_admin_referer( 'gml_translation_action', 'gml_translation_nonce' );
            $lang = isset( $post['gml_lang_action'] ) ? sanitize_key( $post['gml_lang_code'] ?? '' ) : '';
            $action = sanitize_key( $post['gml_global_action'] ?? $post['gml_lang_action'] );
            if ( isset( $post['gml_lang_action'] ) && $lang === '' ) return new WP_Error( 'invalid_language', 'Choose a configured language.' );
            if ( in_array( $action, [ 'start_all', 'start_lang' ], true ) ) return self::start( $lang );
            if ( in_array( $action, [ 'pause_all', 'pause_lang' ], true ) ) return self::pause( $lang );
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
        if ( get_option( GML_Queue_Processor::SAMPLE_OPTION, [] ) ) return new WP_Error( 'sample_running', 'A limited translation sample is still running.' );
        $languages = (array) get_option( 'gml_languages', [] );
        $found = false;
        foreach ( $languages as &$language ) {
            if ( ( ! isset( $language['enabled'] ) || $language['enabled'] ) && ( $lang === '' || ( $language['code'] ?? '' ) === $lang ) ) {
                $language['paused'] = false;
                $found = true;
            }
        }
        unset( $language );
        if ( ! $found ) return new WP_Error( 'invalid_language', 'Choose a configured language.' );
        if ( ! wp_next_scheduled( GML_Queue_Processor::CRON_HOOK ) ) {
            $scheduled = wp_schedule_single_event( time() + 5, GML_Queue_Processor::CRON_HOOK, [], true );
            if ( ! $scheduled || is_wp_error( $scheduled ) ) return new WP_Error( 'schedule_failed', 'WordPress could not schedule translation. Pause settings were kept.' );
        }
        update_option( 'gml_languages', $languages );
        update_option( 'gml_translation_paused', false, false );
        return true;
    }

    public static function pause( $lang = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        if ( $lang === '' ) {
            update_option( 'gml_translation_paused', true, false );
            GML_Queue_Processor::unschedule_cron();
            return true;
        }
        $languages = (array) get_option( 'gml_languages', [] );
        foreach ( $languages as &$language ) {
            if ( ( $language['code'] ?? '' ) === $lang ) {
                $language['paused'] = true;
                update_option( 'gml_languages', $languages );
                return true;
            }
        }
        return new WP_Error( 'invalid_language', 'Choose a configured language.' );
    }

    public static function refresh_cache() {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Unauthorized' );
        GML_Page_Cache::invalidate();
        return true;
    }

    public static function queue_status( $lang = '', $pending = null ) {
        global $wpdb;
        if ( $pending === null ) {
            $where = $lang === '' ? '' : $wpdb->prepare( ' AND target_lang = %s', $lang );
            $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gml_queue WHERE status IN ('pending','processing')$where" );
        }
        $lock = (array) get_option( GML_Queue_Processor::LOCK_OPTION, [] );
        $last = (array) get_option( 'gml_translation_last_batch', [] );
        $next = wp_next_scheduled( GML_Queue_Processor::CRON_HOOK );
        $paused = (bool) get_option( 'gml_translation_paused', false );
        if ( $lang === '' ) {
            $runnable = false;
            foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
                if ( empty( $language['paused'] ) && ( ! isset( $language['enabled'] ) || $language['enabled'] ) ) $runnable = true;
            }
            if ( ! $runnable ) $paused = true;
        }
        if ( $lang !== '' ) {
            foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
                if ( ( $language['code'] ?? '' ) === $lang && ( ! empty( $language['paused'] ) || isset( $language['enabled'] ) && ! $language['enabled'] ) ) $paused = true;
            }
        }
        $active = (int) ( $lock['expires'] ?? 0 ) > time() && ! empty( $lock['token'] )
            && ( $lang === '' || ( $last['language'] ?? '' ) === $lang )
            && ( $last['token'] ?? '' ) === ( $lock['token'] ?? '' );
        if ( ! GML_Translation_State::multilingual_enabled() || ! GML_Translation_State::ai_available() ) $state = 'unavailable';
        elseif ( GML_Queue_Processor::circuit_is_open() ) $state = 'safety_paused';
        elseif ( $paused ) $state = $active ? 'pausing' : 'paused';
        elseif ( $active ) $state = 'processing';
        elseif ( ! $pending ) $state = 'idle';
        elseif ( ! $next ) $state = 'not_scheduled';
        elseif ( $next < time() - 120 ) $state = 'overdue';
        else $state = 'scheduled';
        return [ 'state' => $state, 'last_activity' => (int) ( $last['finished'] ?? $last['started'] ?? 0 ), 'next_run' => $next ?: 0 ];
    }
}
