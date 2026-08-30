<?php
/**
 * Read-only index readiness derived from translation storage and queue state.
 *
 * API credentials, provider outages, and circuit breakers never affect this
 * read path. Existing complete translations therefore remain discoverable when
 * AI generation is disabled or temporarily unavailable.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translation_Readiness {

    /** @var array<string,bool>|null */
    private static $map = null;

    public static function language_is_index_ready( $lang ) {
        $lang = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::normalize_code( $lang )
            : sanitize_key( $lang );
        if ( ! $lang ) {
            return false;
        }
        $map = self::readiness_map();
        return ! empty( $map[ $lang ] );
    }

    public static function readiness_map() {
        if ( is_array( self::$map ) ) {
            return self::$map;
        }

        $cached = wp_cache_get( 'gml_readiness_map', 'gml_translate' );
        if ( is_array( $cached ) ) {
            self::$map = $cached;
            return self::$map;
        }

        global $wpdb;
        $index_table = $wpdb->prefix . 'gml_index';
        $queue_table = $wpdb->prefix . 'gml_queue';
        $translated_rows = $wpdb->get_results(
            "SELECT target_lang, COUNT(*) AS item_count
             FROM $index_table
             WHERE status IN ('auto','manual')
             GROUP BY target_lang"
        );
        $incomplete_rows = $wpdb->get_results(
            "SELECT target_lang, COUNT(*) AS item_count
             FROM $queue_table
             WHERE status IN ('pending','processing','failed')
             GROUP BY target_lang"
        );

        $translated = [];
        $incomplete = [];
        foreach ( (array) $translated_rows as $row ) {
            $code = class_exists( 'GML_Language_Utils' )
                ? GML_Language_Utils::normalize_code( $row->target_lang )
                : sanitize_key( $row->target_lang );
            if ( $code ) {
                $translated[ $code ] = (int) $row->item_count;
            }
        }
        foreach ( (array) $incomplete_rows as $row ) {
            $code = class_exists( 'GML_Language_Utils' )
                ? GML_Language_Utils::normalize_code( $row->target_lang )
                : sanitize_key( $row->target_lang );
            if ( $code ) {
                $incomplete[ $code ] = (int) $row->item_count;
            }
        }

        self::$map = [];
        foreach ( array_unique( array_merge( array_keys( $translated ), array_keys( $incomplete ) ) ) as $code ) {
            self::$map[ $code ] = ! empty( $translated[ $code ] ) && empty( $incomplete[ $code ] );
        }
        wp_cache_set( 'gml_readiness_map', self::$map, 'gml_translate', 60 );
        return self::$map;
    }

    public static function clear_cache() {
        self::$map = null;
        wp_cache_delete( 'gml_readiness_map', 'gml_translate' );
    }
}
