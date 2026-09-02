<?php
/** DB-authoritative machine readiness for exact resource manifests. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Readiness {
    const COMPLETE_RATIO = 0.95;
    const READ_CHUNK = 500;
    const REVERSE_BATCH = 500;

    public static function get_status( $subject, $lang ) {
        $statuses = self::get_all_statuses( $subject );
        $lang = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $lang ) : sanitize_key( $lang );
        return $lang !== '' ? ( $statuses[ $lang ] ?? 'unknown' ) : 'disabled';
    }

    /** Exactly one indexed manifest/readiness query for one resource. */
    public static function get_all_statuses( $subject ) {
        global $wpdb;
        $key = self::resource_key( $subject );
        if ( $key === '' || ! GML_Resource_Manifest_Store::tables_ready() ) return [];
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.resource_key,m.manifest_generation,m.global_generation AS manifest_global_generation,m.discovery_state,
                    r.target_lang,r.manifest_generation AS readiness_manifest_generation,r.global_generation AS readiness_global_generation,r.status
             FROM $manifests m LEFT JOIN $readiness r ON r.resource_id=m.id WHERE m.resource_key=%s",
            $key
        ) );
        if ( ! $rows ) return [];
        $result = [];
        foreach ( $rows as $row ) {
            if ( $row->target_lang === null ) continue;
            $result[ $row->target_lang ] = self::effective_status( $row );
        }
        if ( ! $result ) {
            foreach ( self::configured_languages() as $lang ) $result[ $lang ] = self::manifest_state( $rows[0] );
        }
        return $result;
    }

    /** At most two indexed reads for 1,000 resource keys. */
    public static function get_bulk_statuses( array $subjects, array $languages = [] ) {
        global $wpdb;
        $keys = [];
        foreach ( $subjects as $subject ) {
            $key = self::resource_key( $subject );
            if ( $key !== '' ) $keys[ $key ] = true;
        }
        $keys = array_keys( $keys );
        $languages = array_values( array_filter( array_map( static function( $lang ) {
            return class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $lang ) : sanitize_key( $lang );
        }, $languages ?: self::configured_languages() ) ) );
        $result = [];
        foreach ( $keys as $key ) $result[ $key ] = array_fill_keys( $languages, 'unknown' );
        if ( ! $keys || ! GML_Resource_Manifest_Store::tables_ready() ) return $result;

        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        foreach ( array_chunk( $keys, self::READ_CHUNK ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT m.resource_key,m.manifest_generation,m.global_generation AS manifest_global_generation,m.discovery_state,
                        r.target_lang,r.manifest_generation AS readiness_manifest_generation,r.global_generation AS readiness_global_generation,r.status
                 FROM $manifests m LEFT JOIN $readiness r ON r.resource_id=m.id WHERE m.resource_key IN ($placeholders)",
                $chunk
            ) );
            $seen = [];
            foreach ( (array) $rows as $row ) {
                $seen[ $row->resource_key ] = $row;
                if ( $row->target_lang !== null && ( ! $languages || in_array( $row->target_lang, $languages, true ) ) ) {
                    $result[ $row->resource_key ][ $row->target_lang ] = self::effective_status( $row );
                }
            }
            foreach ( $seen as $key => $row ) {
                foreach ( $languages as $lang ) {
                    if ( $result[ $key ][ $lang ] === 'unknown' ) $result[ $key ][ $lang ] = self::manifest_state( $row );
                }
            }
        }
        return $result;
    }

    public static function recalculate_resources( array $resource_ids, array $languages = [] ) {
        global $wpdb;
        $resource_ids = array_values( array_unique( array_filter( array_map( 'intval', $resource_ids ) ) ) );
        if ( ! $resource_ids || ! GML_Resource_Manifest_Store::tables_ready() ) return 0;
        $languages = $languages ?: self::configured_languages();
        $source = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( get_option( 'gml_source_lang', 'en' ) ) : 'en';
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $index = $wpdb->prefix . 'gml_index';
        $ids = implode( ',', $resource_ids );
        $manifest_rows = $wpdb->get_results( "SELECT * FROM $manifests WHERE id IN ($ids)" );
        $written = 0;
        foreach ( (array) $manifest_rows as $manifest ) {
            foreach ( $languages as $lang ) {
                $lang = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $lang ) : sanitize_key( $lang );
                if ( $lang === '' ) continue;
                if ( $lang === $source ) {
                    $translated = (int) $manifest->required_count;
                    $critical_missing = 0;
                    $status = self::manifest_state( $manifest ) === 'complete' ? 'complete' : self::manifest_state( $manifest );
                } elseif ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $lang ) ) {
                    $translated = 0;
                    $critical_missing = (int) $manifest->critical_count;
                    $status = 'external_unverified';
                } elseif ( $manifest->discovery_state !== 'complete' || (int) $manifest->global_generation !== self::global_generation() ) {
                    $translated = 0;
                    $critical_missing = (int) $manifest->critical_count;
                    $status = self::manifest_state( $manifest );
                } else {
                    $counts = $wpdb->get_row( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT CASE WHEN i.id IS NOT NULL THEN s.source_hash END) AS translated_count,
                                SUM(CASE WHEN s.critical=1 AND i.id IS NULL THEN 1 ELSE 0 END) AS critical_missing
                         FROM $relations s LEFT JOIN $index i ON i.source_hash=s.source_hash AND i.source_lang=%s AND i.target_lang=%s AND i.status IN ('auto','manual')
                         WHERE s.resource_id=%d AND s.manifest_generation=%d",
                        $source, $lang, (int) $manifest->id, (int) $manifest->manifest_generation
                    ) );
                    $translated = (int) ( $counts->translated_count ?? 0 );
                    $critical_missing = (int) ( $counts->critical_missing ?? 0 );
                    $required = (int) $manifest->required_count;
                    $status = $critical_missing === 0 && ( $required === 0 || $translated / $required >= self::COMPLETE_RATIO ) ? 'complete' : 'incomplete';
                }
                $saved = $wpdb->replace( $readiness, [
                    'resource_id' => (int) $manifest->id, 'target_lang' => $lang,
                    'manifest_generation' => (int) $manifest->manifest_generation,
                    'global_generation' => (int) $manifest->global_generation,
                    'required_count' => (int) $manifest->required_count, 'translated_count' => $translated,
                    'critical_missing_count' => $critical_missing, 'status' => $status,
                    'calculated_at' => current_time( 'mysql' ),
                ] );
                if ( false !== $saved ) $written++;
            }
        }
        return $written;
    }

    /** Recalculate only resources related to a changed Translation Memory hash. */
    public static function translation_changed( $source_hash, $target_lang ) {
        global $wpdb;
        $source_hash = strtolower( sanitize_text_field( $source_hash ) );
        $target_lang = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $target_lang ) : sanitize_key( $target_lang );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $source_hash ) || $target_lang === '' || ! GML_Resource_Manifest_Store::tables_ready() ) return 0;
        $relations = GML_Resource_Manifest_Store::relation_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT resource_id FROM $relations WHERE source_hash=%s ORDER BY resource_id ASC LIMIT %d",
            $source_hash, self::REVERSE_BATCH + 1
        ) );
        if ( ! $ids ) return 0;
        $batch = array_slice( array_map( 'intval', $ids ), 0, self::REVERSE_BATCH );
        $id_sql = implode( ',', $batch );
        $wpdb->query( $wpdb->prepare( "UPDATE $readiness SET status='stale' WHERE target_lang=%s AND resource_id IN ($id_sql)", $target_lang ) );
        self::recalculate_resources( $batch, [ $target_lang ] );
        if ( count( $ids ) > self::REVERSE_BATCH ) {
            update_option( 'gml_resource_readiness_reverse_state', [
                'source_hash' => $source_hash, 'target_lang' => $target_lang, 'after_id' => end( $batch ), 'updated_at' => time(),
            ], false );
            if ( ! wp_next_scheduled( 'gml_resource_readiness_reverse' ) ) wp_schedule_single_event( time() + 5, 'gml_resource_readiness_reverse' );
        }
        return count( $batch );
    }

    public static function continue_reverse() {
        global $wpdb;
        $state = (array) get_option( 'gml_resource_readiness_reverse_state', [] );
        $hash = strtolower( sanitize_text_field( $state['source_hash'] ?? '' ) );
        $lang = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $state['target_lang'] ?? '' ) : sanitize_key( $state['target_lang'] ?? '' );
        $after = max( 0, (int) ( $state['after_id'] ?? 0 ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || $lang === '' ) { delete_option( 'gml_resource_readiness_reverse_state' ); return 0; }
        $relations = GML_Resource_Manifest_Store::relation_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT resource_id FROM $relations WHERE source_hash=%s AND resource_id>%d ORDER BY resource_id ASC LIMIT %d",
            $hash, $after, self::REVERSE_BATCH + 1
        ) );
        if ( ! $ids ) { delete_option( 'gml_resource_readiness_reverse_state' ); return 0; }
        $batch = array_slice( array_map( 'intval', $ids ), 0, self::REVERSE_BATCH );
        $id_sql = implode( ',', $batch );
        $wpdb->query( $wpdb->prepare( "UPDATE $readiness SET status='stale' WHERE target_lang=%s AND resource_id IN ($id_sql)", $lang ) );
        self::recalculate_resources( $batch, [ $lang ] );
        if ( count( $ids ) > self::REVERSE_BATCH ) {
            $state['after_id'] = end( $batch ); $state['updated_at'] = time();
            update_option( 'gml_resource_readiness_reverse_state', $state, false );
            wp_schedule_single_event( time() + 5, 'gml_resource_readiness_reverse' );
        } else delete_option( 'gml_resource_readiness_reverse_state' );
        return count( $batch );
    }

    private static function effective_status( $row ) {
        $manifest = self::manifest_state( $row );
        if ( $manifest !== 'complete' ) return $manifest;
        if ( (int) $row->readiness_manifest_generation !== (int) $row->manifest_generation
            || (int) $row->readiness_global_generation !== (int) $row->manifest_global_generation ) return 'stale';
        return (string) $row->status;
    }

    private static function manifest_state( $row ) {
        $state = (string) ( $row->discovery_state ?? 'unknown' );
        if ( $state === 'complete' && (int) ( $row->manifest_global_generation ?? $row->global_generation ?? 0 ) !== self::global_generation() ) return 'stale';
        return in_array( $state, [ 'complete', 'incomplete', 'stale', 'unknown', 'disabled', 'excluded', 'render_error', 'external_unverified' ], true ) ? $state : 'unknown';
    }

    private static function resource_key( $subject ) {
        if ( $subject instanceof GML_Resource_Identity ) return $subject->get_key();
        if ( is_string( $subject ) && preg_match( '/^(post|term|role|archive):/', $subject ) ) return substr( $subject, 0, 191 );
        $resource = GML_Resource_Identity::resolve( $subject );
        return $resource instanceof GML_Resource_Identity ? $resource->get_key() : '';
    }

    private static function configured_languages() {
        if ( class_exists( 'GML_Language_Utils' ) ) return GML_Language_Utils::configured_codes( true, true );
        return array_values( array_unique( array_merge( [ sanitize_key( get_option( 'gml_source_lang', 'en' ) ) ], array_map( 'sanitize_key', (array) get_option( 'gml_languages', [] ) ) ) ) );
    }

    private static function global_generation() {
        return class_exists( 'GML_Resource_Manifest_Manager' ) ? GML_Resource_Manifest_Manager::global_generation() : max( 1, (int) get_option( 'gml_resource_manifest_global_generation', 1 ) );
    }
}
