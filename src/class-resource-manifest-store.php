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
        return version_compare( get_option( 'gml_db_version', '0' ), '3.4.0', '>=' );
    }

    public static function save_complete( GML_Resource_Identity $resource, array $nodes, ?array $expected = null, $scanned_global = null ) {
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

        $global = class_exists( 'GML_Resource_Manifest_Manager' ) ? GML_Resource_Manifest_Manager::global_generation() : 1;
        $now = current_time( 'mysql' );
        $manifests = self::manifest_table();
        $relations = self::relation_table();
        $manifest = self::lock_current_snapshot( $resource->get_key(), $expected, $scanned_global, $global );
        if ( is_wp_error( $manifest ) ) return $manifest;

        // A repeated authoritative scan of the exact same source snapshot must
        // not revoke a human approval. Real source revisions, manifest content,
        // URL identity, or global presentation changes still advance the
        // generation and require a fresh review.
        $unchanged = $manifest
            && $manifest->discovery_state === 'complete'
            && hash_equals( (string) $manifest->manifest_fingerprint, $fingerprint )
            && hash_equals( (string) $manifest->source_revision, $resource->get_source_revision() )
            && hash_equals( (string) $manifest->source_url_hash, $resource->get_source_url_hash() )
            && (int) $manifest->global_generation === (int) $global
            && (int) $manifest->required_count === count( $strings )
            && (int) $manifest->critical_count === count( array_filter( $strings, static function( $row ) { return ! empty( $row['critical'] ); } ) );
        if ( $unchanged ) {
            $saved = $wpdb->update( $manifests, [
                'discovery_state' => 'complete', 'updated_at' => $now, 'discovered_at' => $now,
                'redirect_destination' => null, 'redirect_chain' => null,
            ], [ 'id' => (int) $manifest->id ] );
            if ( false === $saved || false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'gml_manifest_write', 'Resource manifest could not be refreshed.' );
            }
            $readiness = self::refresh_readiness( $resource, (int) $manifest->id, self::snapshot_version( $manifest ) );
            if ( is_wp_error( $readiness ) ) return $readiness;
            self::clear_language_readiness();
            return true;
        }

        $generation = $manifest ? (int) $manifest->manifest_generation + 1 : 1;

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
                'redirect_destination' => null, 'redirect_chain' => null,
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
            if (!GML_Page_Cache::remember_cluster($resource_id, $resource) || !GML_Page_Cache::invalidate_resources([$resource_id])) throw new RuntimeException('cluster_cache_failed');
            if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'manifest_commit_failed' );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            self::record_state( $resource, 'render_error', [], $expected, $scanned_global );
            return new WP_Error( 'gml_manifest_write', 'Resource manifest could not be saved.' );
        }

        $readiness = self::refresh_readiness( $resource, $resource_id, self::snapshot_version( (object) $data ) );
        if ( is_wp_error( $readiness ) ) return $readiness;
        self::clear_language_readiness();
        return true;
    }

    private static function refresh_readiness( GML_Resource_Identity $resource, $resource_id, array $expected ) {
        if ( ! class_exists( 'GML_Resource_Readiness' ) || GML_Resource_Readiness::refresh_manifest_snapshot( $resource_id ) ) return true;
        // The manifest committed, but a later language write failed.
        // Keep the resource private and retry; preserve every TM asset.
        $saved = self::record_state( $resource, 'stale', [], $expected, $expected[1] );
        if ( is_wp_error( $saved ) ) return $saved;
        if ( $saved === true ) GML_Resource_Manifest_Manager::add_dirty( $resource->get_key() );
        return new WP_Error( 'gml_readiness_write', 'Resource readiness snapshot could not be completed.' );
    }

    public static function snapshot_version( $manifest ) {
        if ( ! $manifest ) return [];
        return [ (int) $manifest->manifest_generation, (int) $manifest->global_generation,
            (string) $manifest->discovery_state, (string) $manifest->source_revision ];
    }

    /** Begin a write only if the rendered resource still matches its starting snapshot. */
    private static function lock_current_snapshot( $key, ?array $expected, $scanned_global, $global ) {
        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) return new WP_Error( 'gml_manifest_write' );
        $table = self::manifest_table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE resource_key=%s FOR UPDATE", $key ) );
        if ( $wpdb->last_error !== '' ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'gml_manifest_write' );
        }
        if ( ( $expected !== null && self::snapshot_version( $row ) !== $expected )
            || ( $scanned_global !== null && $global !== $scanned_global ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'gml_resource_changed', 'Source invalidated during discovery.' );
        }
        return $row;
    }

    public static function record_state( GML_Resource_Identity $resource, $state, array $redirect = [], ?array $expected = null, $scanned_global = null ) {
        global $wpdb;
        if ( ! self::tables_ready() ) return new WP_Error( 'gml_manifest_schema', 'Resource manifest schema is unavailable.' );
        $state = in_array( $state, [ 'unknown', 'stale', 'excluded', 'render_error', 'external_unverified', 'permanent_redirect' ], true ) ? $state : 'unknown';
        if ( $state === 'permanent_redirect' && ( empty($redirect['destination']) || empty($redirect['chain']) || count($redirect['chain']) < 2 || count($redirect['chain']) > 4 ) ) return false;
        $global = GML_Resource_Manifest_Manager::global_generation();
        $existing = self::lock_current_snapshot( $resource->get_key(), $expected, $scanned_global, $global );
        if ( is_wp_error( $existing ) ) return $expected === null ? false : $existing;
        $now = current_time( 'mysql' );
        $data = [
            'resource_type' => $resource->get_type(), 'object_id' => $resource->get_object_id(),
            'taxonomy' => $resource->get_taxonomy(), 'variant' => $resource->get_variant(),
            'source_url_hash' => $resource->get_source_url_hash(), 'source_revision' => $resource->get_source_revision(),
            'global_generation' => $global,
            'discovery_state' => $state, 'updated_at' => $now,
            // A failed recheck does not prove the old source is public again.
            // Retained hints exclude it, but only the state certifies the corpus.
            'redirect_destination' => $state === 'permanent_redirect' ? $redirect['destination'] : ($existing->redirect_destination ?? null),
            'redirect_chain' => $state === 'permanent_redirect' ? wp_json_encode($redirect['chain']) : ($existing->redirect_chain ?? null),
        ];
        if ( $state === 'permanent_redirect' ) {
            $data += [
                'manifest_generation' => $existing ? (int)$existing->manifest_generation + 1 : 1,
                'manifest_fingerprint' => hash('sha256', wp_json_encode($redirect)),
                'required_count' => 0, 'critical_count' => 0, 'discovered_at' => $now,
            ];
        }
        try {
            if ($existing) {
                $saved = $wpdb->update(self::manifest_table(), $data, ['id'=>(int)$existing->id]);
            } else {
                $data += [
                    'resource_key' => $resource->get_key(), 'manifest_generation' => 0, 'manifest_fingerprint' => '',
                    'required_count' => 0, 'critical_count' => 0, 'created_at' => $now, 'discovered_at' => null,
                ];
                $saved = $wpdb->insert(self::manifest_table(), $data);
            }
            $resource_id = $existing ? (int)$existing->id : (int)$wpdb->insert_id;
            if (false === $saved || !GML_Page_Cache::remember_cluster($resource_id, $resource) || !GML_Page_Cache::invalidate_resources([$resource_id])) throw new RuntimeException('state_write_failed');
            if (false === $wpdb->query('COMMIT')) throw new RuntimeException('state_commit_failed');
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        self::clear_language_readiness();
        return true;
    }

    /** Retain history, but finish discovery for a positively retired WP identity. */
    public static function exclude_retired_key($key) {
        global $wpdb;
        if (!is_string($key) || !self::tables_ready()) return false;
        $row = self::get_by_key($key);
        if ($wpdb->last_error !== '') return false;
        $retired = false;
        if (preg_match('/^post:([a-z0-9_-]+):(\d+)$/', $key, $m) && post_type_exists($m[1])) {
            $post = get_post((int)$m[2]);
            $type = get_post_type_object($m[1]);
            $retired = !$post || $post->post_status !== 'publish' || $post->post_type !== $m[1]
                || empty($type->public) || $m[1] === 'attachment';
        } elseif (preg_match('/^term:([a-z0-9_-]+):(\d+)$/', $key, $m) && taxonomy_exists($m[1])) {
            $term = get_term((int)$m[2], $m[1]);
            $retired = $term === null;
        } elseif (preg_match('/^role:(front_page|posts_page):(\d+)$/', $key, $m)) {
            $current = $m[1] === 'front_page' ? GML_Resource_Identity::front_page() : GML_Resource_Identity::posts_page();
            $post = (int)$m[2] > 0 ? get_post((int)$m[2]) : null;
            $retired = ($current && $current->get_key() !== $key) || ((int)$m[2] > 0 && (!$post || $post->post_status !== 'publish'));
        }
        if (!$retired || $wpdb->last_error !== '') return false;
        if (!$row) return true; // Pre-upgrade hint for a resource that was never published.
        if (false === $wpdb->query('START TRANSACTION')) return false;
        $saved = $wpdb->update(self::manifest_table(), [
            'discovery_state'=>'excluded', 'updated_at'=>current_time('mysql'),
            'global_generation'=>GML_Resource_Manifest_Manager::global_generation(),
            'redirect_destination'=>null, 'redirect_chain'=>null,
        ], ['id'=>(int)$row->id]);
        if (false === $saved || !GML_Page_Cache::invalidate_resources([(int)$row->id]) || false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        self::clear_language_readiness();
        return true;
    }

    public static function mark_stale( $subject, $revision = '' ) {
        global $wpdb;
        $resource = GML_Resource_Identity::resolve( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return false;
        $existing = self::get_by_key( $resource->get_key() );
        if ( ! $existing ) return self::record_state( $resource, 'stale' );
        if (false === $wpdb->query('START TRANSACTION')) return false;
        $saved = false !== $wpdb->update( self::manifest_table(), [
            // Every invalidation must be distinguishable, including metadata-only edits in the same second.
            'source_revision' => 'stale:' . wp_generate_uuid4(),
            'discovery_state' => 'stale', 'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => (int) $existing->id ] );
        $saved = $saved && GML_Page_Cache::remember_cluster((int)$existing->id, $resource)
            && GML_Page_Cache::invalidate_resources([(int)$existing->id]) && false !== $wpdb->query('COMMIT');
        if (!$saved) $wpdb->query('ROLLBACK');
        if ( $saved ) self::clear_language_readiness();
        return $saved;
    }

    public static function mark_stale_by_key( $key, $revision = '' ) {
        global $wpdb;
        if ( ! self::tables_ready() || ! is_string( $key ) || $key === '' ) return false;
        $data = [ 'discovery_state' => 'stale', 'updated_at' => current_time( 'mysql' ) ];
        $data['source_revision'] = 'stale:' . wp_generate_uuid4();
        $existing = self::get_by_key($key);
        if (!$existing || false === $wpdb->query('START TRANSACTION')) return false;
        $resource = GML_Resource_Identity::resolve($key);
        $saved = (!$resource || GML_Page_Cache::remember_cluster((int)$existing->id, $resource))
            && false !== $wpdb->update( self::manifest_table(), $data, [ 'resource_key' => substr( $key, 0, 191 ) ] )
            && GML_Page_Cache::invalidate_resources([(int)$existing->id]) && false !== $wpdb->query('COMMIT');
        if (!$saved) $wpdb->query('ROLLBACK');
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
