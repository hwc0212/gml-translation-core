<?php
/** DB-authoritative machine readiness for exact resource manifests. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Readiness {
    const COMPLETE_RATIO = 0.95;
    const READ_CHUNK = 500;
    const REVERSE_BATCH = 500;
    const REBUILD_BATCH = 500;
    const REBUILD_HOOK = 'gml_resource_readiness_rebuild';
    const REBUILD_LOCK = 'gml_resource_readiness_rebuild_lock';
    const CLAIM_TTL = 180;

    public static function register_hooks() {
        add_action( self::REBUILD_HOOK, [ __CLASS__, 'run_rebuild_batch' ], 10, 1 );
    }

    /** Keep an infrequent recovery event; readiness rows, not Cron, own work state. */
    public static function ensure_recovery_schedule() {
        if ( ! GML_Resource_Manifest_Store::tables_ready() ) return false;
        if ( ! wp_next_scheduled( self::REBUILD_HOOK, [ 'recovery' ] ) ) {
            return false !== wp_schedule_event( time() + 300, 'hourly', self::REBUILD_HOOK, [ 'recovery' ] );
        }
        return true;
    }

    public static function schedule_rebuild() {
        self::ensure_recovery_schedule();
        if ( ! wp_next_scheduled( self::REBUILD_HOOK, [ 'drain' ] ) ) {
            return false !== wp_schedule_single_event( time() + 5, self::REBUILD_HOOK, [ 'drain' ] );
        }
        return true;
    }

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
            $result[ $row->target_lang ] = self::evaluate_status_row( $row );
        }
        if ( ! $result ) {
            foreach ( self::configured_languages() as $lang ) $result[ $lang ] = self::evaluate_manifest_row( $rows[0] );
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
                    $result[ $row->resource_key ][ $row->target_lang ] = self::evaluate_status_row( $row );
                }
            }
            foreach ( $seen as $key => $row ) {
                foreach ( $languages as $lang ) {
                    if ( $result[ $key ][ $lang ] === 'unknown' ) $result[ $key ][ $lang ] = self::evaluate_manifest_row( $row );
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
                    $status = self::evaluate_manifest_row( $manifest ) === 'complete' ? 'complete' : self::evaluate_manifest_row( $manifest );
                } elseif ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $lang ) ) {
                    $translated = 0;
                    $critical_missing = (int) $manifest->critical_count;
                    $status = 'external_unverified';
                } elseif ( $manifest->discovery_state !== 'complete' || (int) $manifest->global_generation !== self::global_generation() ) {
                    $translated = 0;
                    $critical_missing = (int) $manifest->critical_count;
                    $status = self::evaluate_manifest_row( $manifest );
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

    /** Fail closed for every current resource related to a changed TM hash. */
    public static function translation_changed( $source_hash, $target_lang ) {
        self::migrate_legacy_continuation();
        return self::invalidate_translation_hash( $source_hash, $target_lang );
    }

    /**
     * Atomically mutate one translation and invalidate every dependent
     * readiness/approval snapshot. The callback must return false on failure.
     */
    public static function apply_translation_change( $source_hash, $target_lang, $mutation ) {
        if ( ! is_callable( $mutation ) ) return false;
        self::migrate_legacy_continuation();
        return self::invalidate_translation_hash( $source_hash, $target_lang, $mutation, true );
    }

    /** Convert the old single continuation into durable stale rows once. */
    public static function migrate_legacy_continuation() {
        $state = (array) get_option( 'gml_resource_readiness_reverse_state', [] );
        if ( ! $state ) return 0;
        $hash = strtolower( sanitize_text_field( $state['source_hash'] ?? '' ) );
        $lang = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $state['target_lang'] ?? '' ) : sanitize_key( $state['target_lang'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || $lang === '' ) {
            delete_option( 'gml_resource_readiness_reverse_state' );
            return 0;
        }
        $affected = self::invalidate_translation_hash( $hash, $lang );
        if ( $affected !== false ) delete_option( 'gml_resource_readiness_reverse_state' );
        return $affected === false ? 0 : (int) $affected;
    }

    private static function invalidate_translation_hash( $source_hash, $target_lang, $mutation = null, $return_mutation = false ) {
        global $wpdb;
        $source_hash = strtolower( sanitize_text_field( $source_hash ) );
        $target_lang = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $target_lang ) : sanitize_key( $target_lang );
        $mutation = is_callable( $mutation ) ? $mutation : null;
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $source_hash ) || $target_lang === '' || ! GML_Resource_Manifest_Store::tables_ready() ) {
            if ( $mutation ) return call_user_func( $mutation );
            return 0;
        }
        self::ensure_recovery_schedule();
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $track_reviews = class_exists( 'GML_Resource_Approval' ) && GML_Resource_Approval::tables_ready();
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) return false;
        try {
            $affected = $wpdb->query( $wpdb->prepare(
                "UPDATE $readiness r
                 INNER JOIN $relations s ON s.resource_id=r.resource_id
                 INNER JOIN $manifests m ON m.id=s.resource_id AND m.manifest_generation=s.manifest_generation
                 SET r.status='stale',r.calculated_at=%s
                 WHERE s.source_hash=%s AND r.target_lang=%s",
                current_time( 'mysql' ), $source_hash, $target_lang
            ) );
            if ( $affected === false ) throw new RuntimeException( 'readiness_invalidation_failed' );
            if ( $track_reviews && false === GML_Resource_Approval::bump_translation_generations( $source_hash, $target_lang ) ) {
                throw new RuntimeException( 'approval_invalidation_failed' );
            }
            $mutation_result = $mutation ? call_user_func( $mutation ) : true;
            if ( $mutation_result === false ) throw new RuntimeException( 'translation_mutation_failed' );
            if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'translation_commit_failed' );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        if ( $affected > 0 ) self::schedule_rebuild();
        return $return_mutation ? $mutation_result : (int) $affected;
    }

    /** Compatibility entry point for already-scheduled rc.13 reverse events. */
    public static function continue_reverse() {
        self::migrate_legacy_continuation();
        return self::run_rebuild_batch( 'legacy' );
    }

    /** Rebuild at most 500 durable stale resource/language rows. */
    public static function run_rebuild_batch( $mode = '' ) {
        unset( $mode );
        if ( ! GML_Resource_Manifest_Store::tables_ready() ) return 0;
        $token = GML_Atomic_Option_Lock::acquire( self::REBUILD_LOCK, self::CLAIM_TTL );
        if ( $token === '' ) return 0;
        try {
            $ids = self::find_rebuild_candidates();
            if ( ! $ids ) return 0;

            // Persist the next wakeup before claiming rows. A process exit after
            // this point leaves rebuilding rows recoverable without a cursor.
            self::schedule_rebuild();
            $ids = self::claim_rebuild_candidates( $ids );
            if ( ! $ids ) return 0;
            do_action( 'gml_resource_readiness_batch_claimed', count( $ids ) );
            if ( ! GML_Atomic_Option_Lock::refresh( self::REBUILD_LOCK, $token, self::CLAIM_TTL ) ) return 0;

            $rows = self::calculate_claimed_rows( $ids );
            $written = self::persist_claimed_rows( $rows, $token );
            if ( count( $ids ) >= self::REBUILD_BATCH ) self::schedule_rebuild();
            return $written;
        } finally {
            GML_Atomic_Option_Lock::release( self::REBUILD_LOCK, $token );
        }
    }

    private static function find_rebuild_candidates() {
        global $wpdb;
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $readiness USE INDEX (status_id) WHERE status='stale' ORDER BY id ASC LIMIT %d",
            self::REBUILD_BATCH
        ) ) );
        $remaining = self::REBUILD_BATCH - count( $ids );
        if ( $remaining < 1 ) return $ids;
        $cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::CLAIM_TTL );
        $expired = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $readiness USE INDEX (status_id) WHERE status='rebuilding' AND calculated_at<=%s ORDER BY id ASC LIMIT %d",
            $cutoff, $remaining
        ) ) );
        return array_values( array_unique( array_merge( $ids, $expired ) ) );
    }

    private static function claim_rebuild_candidates( array $ids ) {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) return [];
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $id_sql = implode( ',', $ids );
        $cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::CLAIM_TTL );
        $claimed_at = current_time( 'mysql' );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $readiness SET status='rebuilding',calculated_at=%s
             WHERE id IN ($id_sql) AND (status='stale' OR (status='rebuilding' AND calculated_at<=%s))",
            $claimed_at, $cutoff
        ) );
        return array_map( 'intval', (array) $wpdb->get_col(
            "SELECT id FROM $readiness WHERE id IN ($id_sql) AND status='rebuilding' AND calculated_at='" . esc_sql( $claimed_at ) . "'"
        ) );
    }

    private static function calculate_claimed_rows( array $ids ) {
        global $wpdb;
        if ( ! $ids ) return [];
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        $index = $wpdb->prefix . 'gml_index';
        $id_sql = implode( ',', array_map( 'intval', $ids ) );
        $source = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( get_option( 'gml_source_lang', 'en' ) ) : 'en';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id,r.resource_id,r.target_lang,m.manifest_generation,m.global_generation AS manifest_global_generation,
                    m.discovery_state,m.required_count,m.critical_count,
                    COUNT(DISTINCT CASE WHEN i.id IS NOT NULL THEN s.source_hash END) AS translated_count,
                    COALESCE(SUM(CASE WHEN s.critical=1 AND i.id IS NULL THEN 1 ELSE 0 END),0) AS critical_missing
             FROM $readiness r
             INNER JOIN $manifests m ON m.id=r.resource_id
             LEFT JOIN $relations s ON s.resource_id=m.id AND s.manifest_generation=m.manifest_generation
             LEFT JOIN $index i ON i.source_hash=s.source_hash AND i.source_lang=%s AND i.target_lang=r.target_lang AND i.status IN ('auto','manual')
             WHERE r.id IN ($id_sql) AND r.status='rebuilding'
             GROUP BY r.id,r.resource_id,r.target_lang,m.manifest_generation,m.global_generation,m.discovery_state,m.required_count,m.critical_count",
            $source
        ) );
        $result = [];
        foreach ( (array) $rows as $row ) {
            $required = (int) $row->required_count;
            $translated = (int) $row->translated_count;
            $critical_missing = (int) $row->critical_missing;
            $manifest_state = self::evaluate_manifest_row( $row );
            if ( $row->target_lang === $source ) {
                $translated = $required;
                $critical_missing = 0;
                $status = $manifest_state;
            } elseif ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $row->target_lang ) ) {
                $translated = 0;
                $critical_missing = (int) $row->critical_count;
                $status = 'external_unverified';
            } elseif ( $manifest_state !== 'complete' ) {
                $translated = 0;
                $critical_missing = (int) $row->critical_count;
                $status = $manifest_state;
            } else {
                $status = $critical_missing === 0 && ( $required === 0 || $translated / $required >= self::COMPLETE_RATIO ) ? 'complete' : 'incomplete';
            }
            $result[] = [
                'id' => (int) $row->id,
                'resource_id' => (int) $row->resource_id,
                'target_lang' => (string) $row->target_lang,
                'manifest_generation' => (int) $row->manifest_generation,
                'global_generation' => (int) $row->manifest_global_generation,
                'required_count' => $required,
                'translated_count' => $translated,
                'critical_missing_count' => $critical_missing,
                'status' => $status,
            ];
        }
        return $result;
    }

    private static function persist_claimed_rows( array $rows, $token ) {
        global $wpdb;
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $written = 0;
        foreach ( array_chunk( $rows, 100 ) as $chunk ) {
            if ( ! GML_Atomic_Option_Lock::refresh( self::REBUILD_LOCK, $token, self::CLAIM_TTL ) ) break;
            $values = [];
            $args = [];
            $now = current_time( 'mysql' );
            foreach ( $chunk as $row ) {
                $values[] = '(%d,%d,%s,%d,%d,%d,%d,%d,%s,%s)';
                array_push( $args, $row['id'], $row['resource_id'], $row['target_lang'], $row['manifest_generation'], $row['global_generation'], $row['required_count'], $row['translated_count'], $row['critical_missing_count'], $row['status'], $now );
            }
            $sql = $wpdb->prepare(
                "INSERT INTO $readiness (id,resource_id,target_lang,manifest_generation,global_generation,required_count,translated_count,critical_missing_count,status,calculated_at) VALUES " . implode( ',', $values ) .
                " ON DUPLICATE KEY UPDATE
                    manifest_generation=IF(status='rebuilding',VALUES(manifest_generation),manifest_generation),
                    global_generation=IF(status='rebuilding',VALUES(global_generation),global_generation),
                    required_count=IF(status='rebuilding',VALUES(required_count),required_count),
                    translated_count=IF(status='rebuilding',VALUES(translated_count),translated_count),
                    critical_missing_count=IF(status='rebuilding',VALUES(critical_missing_count),critical_missing_count),
                    calculated_at=IF(status='rebuilding',VALUES(calculated_at),calculated_at),
                    status=IF(status='rebuilding',VALUES(status),status)",
                $args
            );
            if ( false === $wpdb->query( $sql ) ) break;
            $written += count( $chunk );
        }
        return $written;
    }

    /** Evaluate one manifest/readiness join row without another database read. */
    public static function evaluate_status_row( $row ) {
        $manifest = self::evaluate_manifest_row( $row );
        if ( $manifest !== 'complete' ) return $manifest;
        if ( ! isset( $row->target_lang ) || $row->target_lang === null ) return 'unknown';
        if ( (int) $row->readiness_manifest_generation !== (int) $row->manifest_generation
            || (int) $row->readiness_global_generation !== (int) $row->manifest_global_generation ) return 'stale';
        return (string) $row->status;
    }

    /** Evaluate the discovery half of a manifest/readiness join row. */
    public static function evaluate_manifest_row( $row ) {
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
