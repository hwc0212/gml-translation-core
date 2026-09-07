<?php
/** Database persistence for exact resource-to-source-hash manifests. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Manifest_Store {
    public static function manifest_table() { global $wpdb; return $wpdb->prefix . 'gml_resource_manifests'; }
    public static function relation_table() { global $wpdb; return $wpdb->prefix . 'gml_resource_strings'; }
    public static function readiness_table() { global $wpdb; return $wpdb->prefix . 'gml_resource_readiness'; }

    public static function tables_ready() {
        // The version is written only after every additive CREATE succeeds.
        // Avoid a SHOW TABLES query on each status read.
        return version_compare( get_option( 'gml_db_version', '0' ), '3.0.0', '>=' );
    }

    public static function save_complete( GML_Resource_Identity $resource, array $nodes ) {
        global $wpdb;
        if ( ! self::tables_ready() ) return new WP_Error( 'gml_manifest_schema', 'Resource manifest schema is unavailable.' );
        if ( ! $resource->is_eligible() ) return self::record_state( $resource, 'excluded' );

        $strings = [];
        foreach ( $nodes as $node ) {
            $text = isset( $node['text'] ) ? trim( (string) $node['text'] ) : '';
            $hash = isset( $node['hash'] ) && preg_match( '/^[a-f0-9]{32}$/i', (string) $node['hash'] )
                ? strtolower( (string) $node['hash'] ) : md5( $text );
            if ( $text === '' ) continue;
            $context = sanitize_key( $node['context_type'] ?? 'text' ) ?: 'text';
            $critical = in_array( $context, [ 'seo_title', 'seo_meta' ], true );
            $critical = (bool) apply_filters( 'gml_resource_manifest_critical', $critical, $context, $node, $resource );
            if ( ! isset( $strings[ $hash ] ) ) {
                $strings[ $hash ] = [
                    'context_type' => substr( $context, 0, 20 ),
                    'context_key'  => substr( sanitize_text_field( $node['context_key'] ?? ( $node['attr'] ?? '' ) ), 0, 191 ),
                    'critical'     => $critical ? 1 : 0,
                ];
            } elseif ( $critical ) {
                $strings[ $hash ]['critical'] = 1;
            }
        }
        ksort( $strings );
        $fingerprint = hash( 'sha256', wp_json_encode( $strings ) );
        $manifest = self::get_by_key( $resource->get_key() );
        $global = class_exists( 'GML_Resource_Manifest_Manager' ) ? GML_Resource_Manifest_Manager::global_generation() : 1;
        $now = current_time( 'mysql' );
        $manifests = self::manifest_table();
        $relations = self::relation_table();

        // A repeated authoritative scan of the exact same source snapshot must
        // not revoke a human approval. Real source revisions, manifest content,
        // URL identity, or global presentation changes still advance the
        // generation and require a fresh review.
        $unchanged = $manifest
            && hash_equals( (string) $manifest->manifest_fingerprint, $fingerprint )
            && hash_equals( (string) $manifest->source_revision, $resource->get_source_revision() )
            && hash_equals( (string) $manifest->source_url_hash, $resource->get_source_url_hash() )
            && (int) $manifest->global_generation === (int) $global
            && (int) $manifest->required_count === count( $strings )
            && (int) $manifest->critical_count === count( array_filter( $strings, static function( $row ) { return ! empty( $row['critical'] ); } ) );
        if ( $unchanged ) {
            $saved = $wpdb->update( $manifests, [
                'discovery_state' => 'complete', 'updated_at' => $now, 'discovered_at' => $now,
            ], [ 'id' => (int) $manifest->id ] );
            if ( false === $saved ) return new WP_Error( 'gml_manifest_write', 'Resource manifest could not be refreshed.' );
            if ( class_exists( 'GML_Resource_Readiness' ) ) GML_Resource_Readiness::recalculate_resources( [ (int) $manifest->id ] );
            self::clear_language_readiness();
            return true;
        }

        $generation = $manifest ? (int) $manifest->manifest_generation + 1 : 1;

        $wpdb->query( 'START TRANSACTION' );
        try {
            $data = [
                'resource_key' => $resource->get_key(), 'resource_type' => $resource->get_type(),
                'object_id' => $resource->get_object_id(), 'taxonomy' => $resource->get_taxonomy(),
                'variant' => $resource->get_variant(), 'source_url_hash' => $resource->get_source_url_hash(),
                'source_revision' => $resource->get_source_revision(), 'manifest_generation' => $generation,
                'manifest_fingerprint' => $fingerprint, 'global_generation' => $global,
                'required_count' => count( $strings ),
                'critical_count' => count( array_filter( $strings, static function( $row ) { return ! empty( $row['critical'] ); } ) ),
                'discovery_state' => 'complete', 'updated_at' => $now, 'discovered_at' => $now,
            ];
            if ( $manifest ) {
                $saved = $wpdb->update( $manifests, $data, [ 'id' => (int) $manifest->id ] );
                $resource_id = (int) $manifest->id;
            } else {
                $data['created_at'] = $now;
                $saved = $wpdb->insert( $manifests, $data );
                $resource_id = (int) $wpdb->insert_id;
            }
            if ( false === $saved || $resource_id < 1 ) throw new RuntimeException( 'manifest_write_failed' );
            if ( false === $wpdb->delete( $relations, [ 'resource_id' => $resource_id ] ) ) throw new RuntimeException( 'relation_delete_failed' );
            foreach ( array_chunk( $strings, 250, true ) as $chunk ) {
                $values = [];
                $args = [];
                foreach ( $chunk as $hash => $row ) {
                    $values[] = '(%d,%d,%s,%s,%s,%d,%s)';
                    array_push( $args, $resource_id, $generation, $hash, $row['context_type'], $row['context_key'], $row['critical'], $now );
                }
                if ( $values ) {
                    $sql = $wpdb->prepare(
                        "INSERT INTO $relations (resource_id,manifest_generation,source_hash,context_type,context_key,critical,created_at) VALUES " . implode( ',', $values ),
                        $args
                    );
                    if ( false === $wpdb->query( $sql ) ) throw new RuntimeException( 'relation_write_failed' );
                }
            }
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            self::record_state( $resource, 'render_error' );
            return new WP_Error( 'gml_manifest_write', 'Resource manifest could not be saved.' );
        }

        if ( class_exists( 'GML_Resource_Readiness' ) ) GML_Resource_Readiness::recalculate_resources( [ $resource_id ] );
        self::clear_language_readiness();
        return true;
    }

    public static function record_state( GML_Resource_Identity $resource, $state ) {
        global $wpdb;
        if ( ! self::tables_ready() ) return new WP_Error( 'gml_manifest_schema', 'Resource manifest schema is unavailable.' );
        $state = in_array( $state, [ 'unknown', 'stale', 'excluded', 'render_error', 'external_unverified' ], true ) ? $state : 'unknown';
        $existing = self::get_by_key( $resource->get_key() );
        $now = current_time( 'mysql' );
        $data = [
            'resource_type' => $resource->get_type(), 'object_id' => $resource->get_object_id(),
            'taxonomy' => $resource->get_taxonomy(), 'variant' => $resource->get_variant(),
            'source_url_hash' => $resource->get_source_url_hash(), 'source_revision' => $resource->get_source_revision(),
            'global_generation' => class_exists( 'GML_Resource_Manifest_Manager' ) ? GML_Resource_Manifest_Manager::global_generation() : 1,
            'discovery_state' => $state, 'updated_at' => $now,
        ];
        if ( $existing ) {
            $saved = false === $wpdb->update( self::manifest_table(), $data, [ 'id' => (int) $existing->id ] ) ? false : true;
            if ( $saved ) self::clear_language_readiness();
            return $saved;
        }
        $data += [
            'resource_key' => $resource->get_key(), 'manifest_generation' => 0, 'manifest_fingerprint' => '',
            'required_count' => 0, 'critical_count' => 0, 'created_at' => $now, 'discovered_at' => null,
        ];
        $saved = false !== $wpdb->insert( self::manifest_table(), $data );
        if ( $saved ) self::clear_language_readiness();
        return $saved;
    }

    public static function mark_stale( $subject, $revision = '' ) {
        global $wpdb;
        $resource = GML_Resource_Identity::resolve( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return false;
        $existing = self::get_by_key( $resource->get_key() );
        if ( ! $existing ) return self::record_state( $resource, 'stale' );
        $saved = false !== $wpdb->update( self::manifest_table(), [
            'source_revision' => substr( (string) ( $revision !== '' ? $revision : $resource->get_source_revision() ), 0, 191 ),
            'discovery_state' => 'stale', 'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => (int) $existing->id ] );
        if ( $saved ) self::clear_language_readiness();
        return $saved;
    }

    public static function mark_stale_by_key( $key, $revision = '' ) {
        global $wpdb;
        if ( ! self::tables_ready() || ! is_string( $key ) || $key === '' ) return false;
        $data = [ 'discovery_state' => 'stale', 'updated_at' => current_time( 'mysql' ) ];
        if ( $revision !== '' ) $data['source_revision'] = substr( (string) $revision, 0, 191 );
        $saved = false !== $wpdb->update( self::manifest_table(), $data, [ 'resource_key' => substr( $key, 0, 191 ) ] );
        if ( $saved ) self::clear_language_readiness();
        return $saved;
    }

    public static function get_by_key( $key ) {
        global $wpdb;
        if ( ! self::tables_ready() ) return null;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::manifest_table() . ' WHERE resource_key=%s LIMIT 1', (string) $key ) );
    }

    /** Resolve canonical source URL hashes in bounded indexed reads. */
    public static function get_resource_keys_by_url_hashes( array $hashes ) {
        global $wpdb;
        $valid = [];
        foreach ( array_slice( $hashes, 0, 5000 ) as $hash ) {
            $hash = strtolower( sanitize_text_field( (string) $hash ) );
            if ( preg_match( '/^[a-f0-9]{64}$/', $hash ) ) $valid[ $hash ] = true;
        }
        $valid = array_keys( $valid );
        if ( ! $valid || ! self::tables_ready() ) return [];

        $result = [];
        $allowed = array_fill_keys( $valid, true );
        $table = self::manifest_table();
        foreach ( array_chunk( $valid, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_url_hash,resource_key FROM $table WHERE source_url_hash IN ($placeholders)",
                $chunk
            ) );
            if ( $wpdb->last_error !== '' ) return [];
            foreach ( (array) $rows as $row ) {
                $hash = strtolower( (string) $row->source_url_hash );
                if ( isset( $allowed[ $hash ] ) ) {
                    $result[ $hash ] = (string) $row->resource_key;
                }
            }
        }
        return $result;
    }

    private static function clear_language_readiness() {
        if ( class_exists( 'GML_Translation_Readiness' ) ) GML_Translation_Readiness::clear_cache();
    }
}
