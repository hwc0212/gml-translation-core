<?php
/** Human review and approval bound to exact resource translation snapshots. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Approval {
    const SCHEMA_VERSION = '3.1.0';
    const MAX_PAGE_SIZE = 100;
    const MAX_REVIEW_NOTE = 4000;

    public static function review_table() { global $wpdb; return $wpdb->prefix . 'gml_resource_reviews'; }
    public static function audit_table() { global $wpdb; return $wpdb->prefix . 'gml_resource_review_audit'; }
    public static function version_table() { global $wpdb; return $wpdb->prefix . 'gml_resource_translation_versions'; }

    public static function tables_ready() {
        return version_compare( get_option( 'gml_db_version', '0' ), self::SCHEMA_VERSION, '>=' );
    }

    /**
     * Read the effective human-review state for one resource/language pair.
     * Machine readiness and human approval intentionally remain separate.
     */
    public static function get_status( $subject, $lang ) {
        global $wpdb;
        $key = self::resource_key( $subject );
        $lang = self::normalize_language( $lang );
        if ( $key === '' || $lang === '' || ! self::tables_ready() ) {
            return self::empty_status( $key, $lang, 'unavailable' );
        }

        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $versions = self::version_table();
        $reviews = self::review_table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.id AS resource_id,m.resource_key,m.resource_type,m.object_id,m.taxonomy,m.variant,
                    m.manifest_generation,m.manifest_fingerprint,m.global_generation AS manifest_global_generation,
                    m.required_count,m.critical_count,m.discovery_state,m.updated_at AS manifest_updated_at,
                    r.target_lang,r.manifest_generation AS readiness_manifest_generation,
                    r.global_generation AS readiness_global_generation,r.required_count AS readiness_required_count,
                    r.translated_count,r.critical_missing_count,r.status,r.calculated_at,
                    COALESCE(v.generation,1) AS translation_generation,
                    rv.decision,rv.manifest_generation AS review_manifest_generation,
                    rv.manifest_fingerprint AS review_manifest_fingerprint,
                    rv.global_generation AS review_global_generation,
                    rv.translation_generation AS review_translation_generation,
                    rv.reviewer_user_id,rv.review_note,rv.reviewed_at
             FROM $manifests m
             LEFT JOIN $readiness r ON r.resource_id=m.id AND r.target_lang=%s
             LEFT JOIN $versions v ON v.resource_id=m.id AND v.target_lang=%s
             LEFT JOIN $reviews rv ON rv.resource_id=m.id AND rv.target_lang=%s
             WHERE m.resource_key=%s LIMIT 1",
            $lang, $lang, $lang, $key
        ) );
        if ( ! $row ) return self::empty_status( $key, $lang, 'missing_manifest' );
        return self::decorate_row( $row, $lang );
    }

    /** Two indexed queries regardless of the number of returned rows. */
    public static function list_resources( array $args = [] ) {
        global $wpdb;
        $page = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = min( self::MAX_PAGE_SIZE, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
        $languages = isset( $args['languages'] ) ? self::normalize_languages( (array) $args['languages'] ) : self::reviewable_languages();
        if ( ! self::tables_ready() || ! $languages ) {
            return [ 'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page, 'pages' => 0 ];
        }

        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $versions = self::version_table();
        $reviews = self::review_table();
        $placeholders = implode( ',', array_fill( 0, count( $languages ), '%s' ) );
        $where = "r.target_lang IN ($placeholders)";
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $readiness r INNER JOIN $manifests m ON m.id=r.resource_id WHERE $where",
            $languages
        ) );
        $offset = ( $page - 1 ) * $per_page;
        $query_args = array_merge( $languages, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id AS resource_id,m.resource_key,m.resource_type,m.object_id,m.taxonomy,m.variant,
                    m.manifest_generation,m.manifest_fingerprint,m.global_generation AS manifest_global_generation,
                    m.required_count,m.critical_count,m.discovery_state,m.updated_at AS manifest_updated_at,
                    r.target_lang,r.manifest_generation AS readiness_manifest_generation,
                    r.global_generation AS readiness_global_generation,r.required_count AS readiness_required_count,
                    r.translated_count,r.critical_missing_count,r.status,r.calculated_at,
                    COALESCE(v.generation,1) AS translation_generation,
                    rv.decision,rv.manifest_generation AS review_manifest_generation,
                    rv.manifest_fingerprint AS review_manifest_fingerprint,
                    rv.global_generation AS review_global_generation,
                    rv.translation_generation AS review_translation_generation,
                    rv.reviewer_user_id,rv.review_note,rv.reviewed_at
             FROM $readiness r
             INNER JOIN $manifests m ON m.id=r.resource_id
             LEFT JOIN $versions v ON v.resource_id=m.id AND v.target_lang=r.target_lang
             LEFT JOIN $reviews rv ON rv.resource_id=m.id AND rv.target_lang=r.target_lang
             WHERE $where
             ORDER BY m.updated_at DESC,m.id DESC,r.target_lang ASC LIMIT %d OFFSET %d",
            $query_args
        ) );
        $decorated = [];
        foreach ( (array) $rows as $row ) $decorated[] = self::decorate_row( $row, $row->target_lang );
        return [
            'rows' => $decorated,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
        ];
    }

    /** Return one bounded review page; source/translation text is never copied to audit rows. */
    public static function get_review_payload( $subject, $lang, $page = 1, $per_page = 50 ) {
        global $wpdb;
        $status = self::get_status( $subject, $lang );
        if ( empty( $status['resource_id'] ) ) return new WP_Error( 'gml_review_missing_manifest', 'The current resource manifest is unavailable.' );
        $page = max( 1, (int) $page );
        $per_page = min( self::MAX_PAGE_SIZE, max( 1, (int) $per_page ) );
        $offset = ( $page - 1 ) * $per_page;
        $source = self::normalize_language( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
        $target = $status['target_lang'];
        $relations = GML_Resource_Manifest_Store::relation_table();
        $index = $wpdb->prefix . 'gml_index';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.source_hash,s.context_type,s.context_key,s.critical,
                    i.source_text,i.translated_text,i.status AS translation_status
             FROM $relations s
             LEFT JOIN $index i ON i.source_hash=s.source_hash AND i.source_lang=%s AND i.target_lang=%s AND i.status IN ('auto','manual')
             WHERE s.resource_id=%d AND s.manifest_generation=%d
             ORDER BY s.critical DESC,s.id ASC LIMIT %d OFFSET %d",
            $source, $target, $status['resource_id'], $status['manifest_generation'], $per_page, $offset
        ) );

        $missing = [];
        foreach ( (array) $rows as $row ) {
            if ( $row->source_text === null || $row->source_text === '' ) $missing[] = $row->source_hash;
        }
        $queue_sources = [];
        if ( $missing ) {
            $queue = $wpdb->prefix . 'gml_queue';
            $placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
            $query_args = array_merge( [ $source, $target ], $missing );
            $queued = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_hash,MAX(source_text) AS source_text FROM $queue
                 WHERE source_lang=%s AND target_lang=%s AND source_hash IN ($placeholders)
                 GROUP BY source_hash",
                $query_args
            ) );
            foreach ( (array) $queued as $row ) $queue_sources[ $row->source_hash ] = (string) $row->source_text;
        }
        foreach ( (array) $rows as $row ) {
            if ( $row->source_text === null || $row->source_text === '' ) {
                $row->source_text = $queue_sources[ $row->source_hash ] ?? '';
            }
        }

        $status['label'] = self::resource_label( $status );
        $status['source_url'] = self::resource_url( $status );
        $status['translated_url'] = self::translated_url( $status['source_url'], $target );
        return [
            'summary' => $status,
            'strings' => array_values( (array) $rows ),
            'page' => $page,
            'per_page' => $per_page,
            'pages' => $status['required_count'] > 0 ? (int) ceil( $status['required_count'] / $per_page ) : 0,
            'audit' => self::get_audit( $status['resource_key'], $target, 20 ),
        ];
    }

    public static function approve( $subject, $lang, $user_id, $note = '' ) {
        return self::decide( $subject, $lang, 'approved', $user_id, $note );
    }

    public static function reject( $subject, $lang, $user_id, $note ) {
        return self::decide( $subject, $lang, 'rejected', $user_id, $note );
    }

    /**
     * Advance only resources that already have a human decision. The caller
     * owns the surrounding transaction so readiness stale state and this
     * generation cannot diverge.
     */
    public static function bump_translation_generations( $source_hash, $target_lang ) {
        global $wpdb;
        if ( ! self::tables_ready() ) return 0;
        $source_hash = strtolower( sanitize_text_field( $source_hash ) );
        $target_lang = self::normalize_language( $target_lang );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $source_hash ) || $target_lang === '' ) return 0;
        $versions = self::version_table();
        $reviews = self::review_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $now = current_time( 'mysql' );
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO $versions (resource_id,target_lang,generation,updated_at)
             SELECT DISTINCT s.resource_id,%s,2,%s
             FROM $relations s
             INNER JOIN $manifests m ON m.id=s.resource_id AND m.manifest_generation=s.manifest_generation
             INNER JOIN $reviews rv ON rv.resource_id=s.resource_id AND rv.target_lang=%s
             WHERE s.source_hash=%s
             ON DUPLICATE KEY UPDATE generation=generation+1,updated_at=VALUES(updated_at)",
            $target_lang, $now, $target_lang, $source_hash
        ) );
    }

    public static function get_audit( $subject, $lang, $limit = 20 ) {
        global $wpdb;
        $key = self::resource_key( $subject );
        $lang = self::normalize_language( $lang );
        $limit = min( 100, max( 1, (int) $limit ) );
        if ( $key === '' || $lang === '' || ! self::tables_ready() ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id,decision,manifest_generation,manifest_fingerprint,global_generation,translation_generation,machine_status,actor_user_id,review_note,created_at'
            . ' FROM ' . self::audit_table() . ' WHERE resource_key=%s AND target_lang=%s ORDER BY id DESC LIMIT %d',
            $key, $lang, $limit
        ) );
    }

    private static function decide( $subject, $lang, $decision, $user_id, $note ) {
        global $wpdb;
        $key = self::resource_key( $subject );
        $lang = self::normalize_language( $lang );
        $user_id = max( 0, (int) $user_id );
        $note = self::truncate( sanitize_textarea_field( (string) $note ), self::MAX_REVIEW_NOTE );
        if ( ! self::tables_ready() ) return new WP_Error( 'gml_review_schema', 'The review database schema is unavailable.' );
        if ( $key === '' || $lang === '' || ! in_array( $lang, self::reviewable_languages(), true ) ) {
            return new WP_Error( 'gml_review_language', 'Only configured local target languages can be reviewed.' );
        }
        if ( $user_id < 1 ) return new WP_Error( 'gml_review_user', 'A signed-in reviewer is required.' );
        if ( $decision === 'rejected' && $note === '' ) return new WP_Error( 'gml_review_note', 'Add a reason before rejecting this translation.' );

        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $versions = self::version_table();
        $reviews = self::review_table();
        $audit = self::audit_table();
        $wpdb->query( 'START TRANSACTION' );
        try {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT m.id AS resource_id,m.resource_key,m.resource_type,m.object_id,m.taxonomy,m.variant,
                        m.manifest_generation,m.manifest_fingerprint,m.global_generation AS manifest_global_generation,
                        m.required_count,m.critical_count,m.discovery_state,m.updated_at AS manifest_updated_at,
                        r.target_lang,r.manifest_generation AS readiness_manifest_generation,
                        r.global_generation AS readiness_global_generation,r.required_count AS readiness_required_count,
                        r.translated_count,r.critical_missing_count,r.status,r.calculated_at
                 FROM $manifests m INNER JOIN $readiness r ON r.resource_id=m.id AND r.target_lang=%s
                 WHERE m.resource_key=%s LIMIT 1 FOR UPDATE",
                $lang, $key
            ) );
            if ( ! $row ) throw new RuntimeException( 'missing_manifest' );
            $machine = GML_Resource_Readiness::evaluate_status_row( $row );
            if ( $machine !== 'complete' ) throw new RuntimeException( 'machine_not_complete' );

            $now = current_time( 'mysql' );
            if ( false === $wpdb->query( $wpdb->prepare(
                "INSERT INTO $versions (resource_id,target_lang,generation,updated_at) VALUES (%d,%s,1,%s)
                 ON DUPLICATE KEY UPDATE updated_at=updated_at",
                $row->resource_id, $lang, $now
            ) ) ) throw new RuntimeException( 'version_write_failed' );
            $translation_generation = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT generation FROM $versions WHERE resource_id=%d AND target_lang=%s FOR UPDATE",
                $row->resource_id, $lang
            ) );
            if ( $translation_generation < 1 ) throw new RuntimeException( 'version_read_failed' );

            $saved = $wpdb->query( $wpdb->prepare(
                "INSERT INTO $reviews
                    (resource_id,target_lang,decision,manifest_generation,manifest_fingerprint,global_generation,translation_generation,reviewer_user_id,review_note,reviewed_at,updated_at)
                 VALUES (%d,%s,%s,%d,%s,%d,%d,%d,%s,%s,%s)
                 ON DUPLICATE KEY UPDATE decision=VALUES(decision),manifest_generation=VALUES(manifest_generation),
                    manifest_fingerprint=VALUES(manifest_fingerprint),global_generation=VALUES(global_generation),
                    translation_generation=VALUES(translation_generation),reviewer_user_id=VALUES(reviewer_user_id),
                    review_note=VALUES(review_note),reviewed_at=VALUES(reviewed_at),updated_at=VALUES(updated_at)",
                $row->resource_id, $lang, $decision, $row->manifest_generation, $row->manifest_fingerprint,
                $row->manifest_global_generation, $translation_generation, $user_id, $note, $now, $now
            ) );
            if ( false === $saved ) throw new RuntimeException( 'review_write_failed' );
            if ( false === $wpdb->insert( $audit, [
                'resource_id' => (int) $row->resource_id,
                'resource_key' => $row->resource_key,
                'target_lang' => $lang,
                'decision' => $decision,
                'manifest_generation' => (int) $row->manifest_generation,
                'manifest_fingerprint' => $row->manifest_fingerprint,
                'global_generation' => (int) $row->manifest_global_generation,
                'translation_generation' => $translation_generation,
                'machine_status' => $machine,
                'actor_user_id' => $user_id,
                'review_note' => $note,
                'created_at' => $now,
            ] ) ) throw new RuntimeException( 'audit_write_failed' );
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            $code = $error->getMessage() === 'machine_not_complete' ? 'gml_review_machine' : 'gml_review_write';
            $message = $code === 'gml_review_machine'
                ? 'The current translation is not machine-complete and cannot be approved or rejected.'
                : 'The review decision could not be saved. No partial decision was kept.';
            return new WP_Error( $code, $message );
        }
        return self::get_status( $key, $lang );
    }

    private static function decorate_row( $row, $lang ) {
        $machine = GML_Resource_Readiness::evaluate_status_row( $row );
        $decision = in_array( (string) ( $row->decision ?? '' ), [ 'approved', 'rejected' ], true ) ? (string) $row->decision : 'unreviewed';
        $snapshot_matches = $decision !== 'unreviewed'
            && (int) $row->review_manifest_generation === (int) $row->manifest_generation
            && hash_equals( (string) $row->review_manifest_fingerprint, (string) $row->manifest_fingerprint )
            && (int) $row->review_global_generation === (int) $row->manifest_global_generation
            && (int) $row->review_translation_generation === (int) $row->translation_generation;
        if ( $decision !== 'unreviewed' && ! $snapshot_matches ) $review_status = 'stale';
        elseif ( $machine !== 'complete' ) $review_status = 'blocked';
        else $review_status = $decision;

        return [
            'resource_id' => (int) $row->resource_id,
            'resource_key' => (string) $row->resource_key,
            'resource_type' => (string) $row->resource_type,
            'object_id' => (int) $row->object_id,
            'taxonomy' => (string) $row->taxonomy,
            'variant' => (string) $row->variant,
            'target_lang' => self::normalize_language( $lang ),
            'machine_status' => $machine,
            'review_status' => $review_status,
            'decision' => $decision,
            'snapshot_matches' => $snapshot_matches,
            'manifest_generation' => (int) $row->manifest_generation,
            'manifest_fingerprint' => (string) $row->manifest_fingerprint,
            'global_generation' => (int) $row->manifest_global_generation,
            'translation_generation' => (int) $row->translation_generation,
            'required_count' => (int) $row->required_count,
            'translated_count' => (int) ( $row->translated_count ?? 0 ),
            'critical_count' => (int) $row->critical_count,
            'critical_missing_count' => (int) ( $row->critical_missing_count ?? 0 ),
            'reviewer_user_id' => (int) ( $row->reviewer_user_id ?? 0 ),
            'review_note' => (string) ( $row->review_note ?? '' ),
            'reviewed_at' => (string) ( $row->reviewed_at ?? '' ),
            'manifest_updated_at' => (string) ( $row->manifest_updated_at ?? '' ),
        ];
    }

    private static function empty_status( $key, $lang, $reason ) {
        return [
            'resource_id' => 0, 'resource_key' => (string) $key, 'target_lang' => (string) $lang,
            'machine_status' => 'unknown', 'review_status' => 'blocked', 'decision' => 'unreviewed',
            'snapshot_matches' => false, 'reason' => $reason,
        ];
    }

    private static function reviewable_languages() {
        $source = self::normalize_language( get_option( 'gml_source_lang', 'en' ) );
        $result = [];
        foreach ( (array) get_option( 'gml_languages', [] ) as $language ) {
            $code = self::normalize_language( is_array( $language ) ? ( $language['code'] ?? '' ) : $language );
            if ( $code === '' || $code === $source || ( is_array( $language ) && isset( $language['enabled'] ) && ! $language['enabled'] ) ) continue;
            if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) continue;
            $result[ $code ] = true;
        }
        return array_keys( $result );
    }

    private static function normalize_languages( array $languages ) {
        $allowed = array_flip( self::reviewable_languages() );
        $result = [];
        foreach ( $languages as $language ) {
            $code = self::normalize_language( $language );
            if ( isset( $allowed[ $code ] ) ) $result[ $code ] = true;
        }
        return array_keys( $result );
    }

    private static function normalize_language( $lang ) {
        return class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $lang ) : sanitize_key( $lang );
    }

    private static function resource_key( $subject ) {
        if ( $subject instanceof GML_Resource_Identity ) return $subject->get_key();
        if ( is_string( $subject ) && preg_match( '/^(post|term|role|archive):/', $subject ) ) return substr( $subject, 0, 191 );
        $resource = GML_Resource_Identity::resolve( $subject );
        return $resource instanceof GML_Resource_Identity ? $resource->get_key() : '';
    }

    private static function resolve_resource( array $status ) {
        $key = $status['resource_key'];
        if ( strpos( $key, 'role:front_page:' ) === 0 ) return GML_Resource_Identity::front_page();
        if ( strpos( $key, 'role:posts_page:' ) === 0 ) return GML_Resource_Identity::posts_page();
        return GML_Resource_Identity::resolve( $key );
    }

    private static function resource_label( array $status ) {
        if ( $status['object_id'] > 0 && in_array( $status['resource_type'], [ 'post', 'role' ], true ) ) {
            $title = get_the_title( $status['object_id'] );
            if ( is_string( $title ) && $title !== '' ) return $title;
        }
        if ( $status['resource_type'] === 'term' && $status['object_id'] > 0 ) {
            $term = get_term( $status['object_id'], $status['taxonomy'] );
            if ( $term instanceof WP_Term ) return $term->name;
        }
        if ( $status['resource_type'] === 'archive' ) {
            $type = get_post_type_object( $status['variant'] );
            if ( $type && isset( $type->labels->name ) ) return $type->labels->name;
        }
        if ( $status['resource_type'] === 'role' ) return ucwords( str_replace( '_', ' ', $status['variant'] ) );
        return $status['resource_key'];
    }

    private static function resource_url( array $status ) {
        $resource = self::resolve_resource( $status );
        return $resource instanceof GML_Resource_Identity ? $resource->get_source_url() : '';
    }

    private static function translated_url( $source_url, $lang ) {
        if ( $source_url === '' || ! class_exists( 'GML_URL_Helper' ) ) return '';
        $source = self::normalize_language( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
        $languages = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::configured_codes( true, true ) : array_merge( [ $source ], self::reviewable_languages() );
        return GML_URL_Helper::get_language_url( $source_url, $lang, $source, $languages );
    }

    private static function truncate( $value, $limit ) {
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
    }
}
