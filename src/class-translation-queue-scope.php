<?php
/** Separate ordinary pending work from explicitly approved failed-item samples. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Translation_Queue_Scope {
    const NORMAL_OPTION = 'gml_translation_normal_queue_enabled';
    const SAMPLE_PAUSED_OPTION = 'gml_translation_retry_sample_paused';

    public static function normal_enabled() {
        // Legacy sample-only runs must not expand to the full queue on upgrade.
        return (bool) get_option( self::NORMAL_OPTION, ! get_option( 'gml_translation_retry_sample_ids', [] ) );
    }

    public static function enabled_languages() {
        $codes = [];
        foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
            if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) continue;
            if ( ! empty( $language['code'] ) && ( ! isset( $language['enabled'] ) || $language['enabled'] ) ) $codes[] = sanitize_key( $language['code'] );
        }
        return array_values( array_unique( $codes ) );
    }

    public static function normal_languages() {
        if ( ! self::normal_enabled() ) return [];
        $codes = [];
        foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
            if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) continue;
            if ( ! empty( $language['code'] ) && empty( $language['paused'] ) && ( ! isset( $language['enabled'] ) || $language['enabled'] ) ) $codes[] = sanitize_key( $language['code'] );
        }
        return array_values( array_unique( $codes ) );
    }

    public static function sample_paused() {
        if ( get_option( 'gml_translation_paused', false ) ) return true;
        $saved = get_option( self::SAMPLE_PAUSED_OPTION, null );
        if ( $saved !== null ) return (bool) $saved;
        $ids = (array) get_option( 'gml_translation_retry_sample_ids', [] );
        if ( ! $ids ) return false;
        global $wpdb;
        $lang = $wpdb->get_var( $wpdb->prepare( "SELECT target_lang FROM {$wpdb->prefix}gml_queue WHERE id = %d", (int) reset( $ids ) ) );
        foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
            if ( ( $language['code'] ?? '' ) === $lang ) {
                if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) return true;
                return ! empty( $language['paused'] );
            }
        }
        return true;
    }

    public static function finish_sample() {
        // Snapshot the legacy default before removing the marker that defines it.
        update_option( self::NORMAL_OPTION, (int) self::normal_enabled(), false );
        delete_option( 'gml_translation_retry_sample_ids' );
        delete_option( self::SAMPLE_PAUSED_OPTION );
        if ( ! self::normal_languages() ) {
            update_option( 'gml_translation_paused', true, false );
            GML_Queue_Processor::unschedule_cron();
        }
    }

    public static function has_work_scope() {
        return (bool) self::normal_languages()
            || ( ! self::sample_paused() && (bool) get_option( 'gml_translation_retry_sample_ids', [] ) );
    }
}
