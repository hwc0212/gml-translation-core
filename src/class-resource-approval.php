<?php
/** Human review and approval bound to exact resource translation snapshots. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Approval {
    const SCHEMA_VERSION = '3.2.0';
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
                    r.translated_count,r.critical_missing_count,r.translation_fingerprint,r.status,r.calculated_at,
                    COALESCE(v.generation,1) AS translation_generation,
                    rv.decision,rv.manifest_generation AS review_manifest_generation,
                    rv.manifest_fingerprint AS review_manifest_fingerprint,
                    rv.global_generation AS review_global_generation,
                    rv.translation_generation AS review_translation_generation,
                    rv.translation_fingerprint AS review_translation_fingerprint,
                    rv.review_revision,rv.reviewer_user_id,rv.review_note,rv.reviewed_at
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
        $review_state = sanitize_key( $args['review_state'] ?? '' );
        if ( ! in_array( $review_state, self::review_states(), true ) ) $review_state = '';
        if ( ! self::tables_ready() || ! $languages ) {
            return [ 'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page, 'pages' => 0 ];
        }

        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $versions = self::version_table();
        $reviews = self::review_table();
        $placeholders = implode( ',', array_fill( 0, count( $languages ), '%s' ) );
        $where = "r.target_lang IN ($placeholders)";
        $where_args = $languages;
        if ( $review_state !== '' ) {
            $where .= ' AND (' . self::review_state_sql() . ')=%s';
            $where_args[] = $review_state;
        }
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $readiness r
             INNER JOIN $manifests m ON m.id=r.resource_id
             LEFT JOIN $versions v ON v.resource_id=m.id AND v.target_lang=r.target_lang
             LEFT JOIN $reviews rv ON rv.resource_id=m.id AND rv.target_lang=r.target_lang
             WHERE $where",
            $where_args
        ) );
        $offset = ( $page - 1 ) * $per_page;
        $query_args = array_merge( $where_args, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id AS resource_id,m.resource_key,m.resource_type,m.object_id,m.taxonomy,m.variant,
                    m.manifest_generation,m.manifest_fingerprint,m.global_generation AS manifest_global_generation,
                    m.required_count,m.critical_count,m.discovery_state,m.updated_at AS manifest_updated_at,
                    r.target_lang,r.manifest_generation AS readiness_manifest_generation,
                    r.global_generation AS readiness_global_generation,r.required_count AS readiness_required_count,
                    r.translated_count,r.critical_missing_count,r.translation_fingerprint,r.status,r.calculated_at,
                    COALESCE(v.generation,1) AS translation_generation,
                    rv.decision,rv.manifest_generation AS review_manifest_generation,
                    rv.manifest_fingerprint AS review_manifest_fingerprint,
                    rv.global_generation AS review_global_generation,
                    rv.translation_generation AS review_translation_generation,
                    rv.translation_fingerprint AS review_translation_fingerprint,
                    rv.review_revision,rv.reviewer_user_id,rv.review_note,rv.reviewed_at
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

    public static function approve( $subject, $lang, $user_id, $note = '', $expected_snapshot = null ) {
        return self::decide( $subject, $lang, 'approved', $user_id, $note, $expected_snapshot );
    }

    public static function reject( $subject, $lang, $user_id, $note, $expected_snapshot = null ) {
        return self::decide( $subject, $lang, 'rejected', $user_id, $note, $expected_snapshot );
    }

    /** Return the bounded concurrency expectation rendered by the Review page. */
    public static function expected_snapshot( array $status ) {
        return [
            'manifest_fingerprint' => (string) ( $status['manifest_fingerprint'] ?? '' ),
            'manifest_generation' => (int) ( $status['manifest_generation'] ?? 0 ),
            'global_generation' => (int) ( $status['global_generation'] ?? 0 ),
            'translation_generation' => (int) ( $status['translation_generation'] ?? 0 ),
            'translation_fingerprint' => (string) ( $status['translation_fingerprint'] ?? '' ),
            'machine_status' => sanitize_key( $status['machine_status'] ?? '' ),
            'review_revision' => (int) ( $status['review_revision'] ?? 0 ),
        ];
    }

    /**
     * Advance only resources that already have a human decision. The caller
     * owns the surrounding transaction so readiness stale state and this
     * generation cannot diverge.
     */
    public static function bump_translation_generations( $source_hash, $target_lang ) {
        return self::bump_translation_generations_for_changes( [
            [ 'source_hash' => $source_hash, 'target_lang' => $target_lang ],
        ] );
    }

    /** Advance each affected reviewed resource/language exactly once per batch. */
    public static function bump_translation_generations_for_changes( array $changes ) {
        global $wpdb;
        if ( ! self::tables_ready() ) return 0;
        $normalized = [];
        foreach ( array_slice( $changes, 0, 100 ) as $change ) {
            $source_hash = strtolower( sanitize_text_field( $change['source_hash'] ?? '' ) );
            $target_lang = self::normalize_language( $change['target_lang'] ?? '' );
            if ( preg_match( '/^[a-f0-9]{32}$/', $source_hash ) && $target_lang !== '' ) {
                $normalized[ $target_lang . ':' . $source_hash ] = [ $source_hash, $target_lang ];
            }
        }
        if ( ! $normalized ) return 0;
        $versions = self::version_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $reviews = self::review_table();
        $now = current_time( 'mysql' );
        $parts = [];
        $pair_args = [];
        foreach ( $normalized as $change ) {
            $parts[] = 'SELECT %s AS source_hash,%s AS target_lang';
            array_push( $pair_args, $change[0], $change[1] );
        }
        $pairs = implode( ' UNION ALL ', $parts );
        $args = array_merge( [ $now ], $pair_args );
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO $versions (resource_id,target_lang,generation,updated_at)
             SELECT DISTINCT s.resource_id,p.target_lang,2,%s
             FROM $relations s
             INNER JOIN ($pairs) p ON p.source_hash=s.source_hash
             INNER JOIN $manifests m ON m.id=s.resource_id AND m.manifest_generation=s.manifest_generation
             INNER JOIN $reviews rv ON rv.resource_id=s.resource_id AND rv.target_lang=p.target_lang
             ON DUPLICATE KEY UPDATE generation=generation+1,updated_at=VALUES(updated_at)",
            $args
        ) );
    }

    public static function get_audit( $subject, $lang, $limit = 20 ) {
        global $wpdb;
        $key = self::resource_key( $subject );
        $lang = self::normalize_language( $lang );
        $limit = min( 100, max( 1, (int) $limit ) );
        if ( $key === '' || $lang === '' || ! self::tables_ready() ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id,decision,manifest_generation,manifest_fingerprint,global_generation,translation_generation,translation_fingerprint,review_revision,machine_status,actor_user_id,review_note,created_at'
            . ' FROM ' . self::audit_table() . ' WHERE resource_key=%s AND target_lang=%s ORDER BY id DESC LIMIT %d',
            $key, $lang, $limit
        ) );
    }

    private static function decide( $subject, $lang, $decision, $user_id, $note, $expected_snapshot ) {
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
        $expected_snapshot = self::normalize_expected_snapshot( $expected_snapshot );
        if ( ! $expected_snapshot ) return new WP_Error( 'gml_review_snapshot', 'Refresh the Review page before recording a decision.' );
        $health = self::transaction_health();
        if ( ! $health['ready'] ) return new WP_Error( 'gml_review_storage_engine', 'Human Review requires transactional InnoDB tables.' );

        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $versions = self::version_table();
        $reviews = self::review_table();
        $audit = self::audit_table();
        if ( false === self::transaction_command( 'START TRANSACTION' ) ) {
            return new WP_Error( 'gml_review_transaction', 'The review transaction could not start. No decision was saved.' );
        }
        try {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT m.id AS resource_id,m.resource_key,m.resource_type,m.object_id,m.taxonomy,m.variant,
                        m.manifest_generation,m.manifest_fingerprint,m.global_generation AS manifest_global_generation,
                        m.required_count,m.critical_count,m.discovery_state,m.updated_at AS manifest_updated_at,
                        r.target_lang,r.manifest_generation AS readiness_manifest_generation,
                        r.global_generation AS readiness_global_generation,r.required_count AS readiness_required_count,
                        r.translated_count,r.critical_missing_count,r.translation_fingerprint,r.status,r.calculated_at
                 FROM $manifests m INNER JOIN $readiness r ON r.resource_id=m.id AND r.target_lang=%s
                 WHERE m.resource_key=%s LIMIT 1 FOR UPDATE",
                $lang, $key
            ) );
            if ( ! $row ) throw new RuntimeException( 'missing_manifest' );

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
            $review = $wpdb->get_row( $wpdb->prepare(
                "SELECT id,decision,review_revision FROM $reviews WHERE resource_id=%d AND target_lang=%s FOR UPDATE",
                $row->resource_id, $lang
            ) );
            if ( $wpdb->last_error !== '' ) throw new RuntimeException( 'review_read_failed' );
            $machine = GML_Resource_Readiness::evaluate_status_row( $row );
            $current_snapshot = [
                'manifest_fingerprint' => (string) $row->manifest_fingerprint,
                'manifest_generation' => (int) $row->manifest_generation,
                'global_generation' => (int) $row->manifest_global_generation,
                'translation_generation' => $translation_generation,
                'translation_fingerprint' => (string) $row->translation_fingerprint,
                'machine_status' => $machine,
                'review_revision' => $review ? (int) $review->review_revision : 0,
            ];
            if ( ! self::snapshots_match( $expected_snapshot, $current_snapshot ) ) throw new RuntimeException( 'snapshot_conflict' );
            if ( $machine !== 'complete' ) throw new RuntimeException( 'machine_not_complete' );

            $review_revision = $current_snapshot['review_revision'] + 1;
            $review_data = [
                'decision' => $decision,
                'manifest_generation' => (int) $row->manifest_generation,
                'manifest_fingerprint' => (string) $row->manifest_fingerprint,
                'global_generation' => (int) $row->manifest_global_generation,
                'translation_generation' => $translation_generation,
                'translation_fingerprint' => (string) $row->translation_fingerprint,
                'review_revision' => $review_revision,
                'reviewer_user_id' => $user_id,
                'review_note' => $note,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ];
            if ( $review ) {
                $saved = $wpdb->update( $reviews, $review_data, [
                    'id' => (int) $review->id,
                    'review_revision' => (int) $review->review_revision,
                ] );
            } else {
                $saved = $wpdb->insert( $reviews, $review_data + [
                    'resource_id' => (int) $row->resource_id,
                    'target_lang' => $lang,
                ] );
            }
            if ( $saved !== 1 ) throw new RuntimeException( 'review_write_failed' );
            if ( false === $wpdb->insert( $audit, [
                'resource_id' => (int) $row->resource_id,
                'resource_key' => $row->resource_key,
                'target_lang' => $lang,
                'decision' => $decision,
                'manifest_generation' => (int) $row->manifest_generation,
                'manifest_fingerprint' => $row->manifest_fingerprint,
                'global_generation' => (int) $row->manifest_global_generation,
                'translation_generation' => $translation_generation,
                'translation_fingerprint' => (string) $row->translation_fingerprint,
                'review_revision' => $review_revision,
                'machine_status' => $machine,
                'actor_user_id' => $user_id,
                'review_note' => $note,
                'created_at' => $now,
            ] ) ) throw new RuntimeException( 'audit_write_failed' );
            if ( false === self::transaction_command( 'COMMIT' ) ) throw new RuntimeException( 'commit_failed' );
        } catch ( Throwable $error ) {
            self::transaction_command( 'ROLLBACK', false );
            if ( $error->getMessage() === 'snapshot_conflict' ) {
                $code = 'gml_review_conflict';
                $message = 'The resource or review decision changed after this page was loaded. Refresh and review the current snapshot.';
            } elseif ( $error->getMessage() === 'machine_not_complete' ) {
                $code = 'gml_review_machine';
                $message = 'The current translation is not machine-complete and cannot be approved or rejected.';
            } elseif ( $error->getMessage() === 'commit_failed' ) {
                $code = 'gml_review_transaction';
                $message = 'The review transaction could not commit. No success was reported.';
            } else {
                $code = 'gml_review_write';
                $message = 'The review decision could not be saved. No partial decision was kept.';
            }
            return new WP_Error( $code, $message );
        }
        return self::get_status( $key, $lang );
    }

    /** Fail closed when any table in the Human Review transaction is non-transactional. */
    public static function transaction_health( $refresh = false ) {
        static $cached = null;
        global $wpdb;
        if ( $cached !== null && ! $refresh ) return $cached;
        $tables = [
            $wpdb->prefix . 'gml_index',
            GML_Resource_Manifest_Store::manifest_table(),
            GML_Resource_Manifest_Store::relation_table(),
            GML_Resource_Manifest_Store::readiness_table(),
            self::version_table(), self::review_table(), self::audit_table(),
        ];
        $placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME IN ($placeholders)",
            array_merge( [ DB_NAME ], $tables )
        ) );
        $engines = [];
        foreach ( (array) $rows as $row ) $engines[ (string) $row->TABLE_NAME ] = strtoupper( (string) $row->ENGINE );
        $unsupported = [];
        foreach ( $tables as $table ) {
            if ( ( $engines[ $table ] ?? '' ) !== 'INNODB' ) $unsupported[ $table ] = $engines[ $table ] ?? 'MISSING';
        }
        $cached = [
            'ready' => $wpdb->last_error === '' && ! $unsupported,
            'engines' => $engines,
            'unsupported' => $unsupported,
        ];
        return $cached;
    }

    public static function get_reviewable_languages() { return self::reviewable_languages(); }

    public static function review_states() { return [ 'unreviewed', 'approved', 'rejected', 'stale', 'blocked' ]; }

    private static function normalize_expected_snapshot( $snapshot ) {
        if ( ! is_array( $snapshot ) ) return null;
        $normalized = self::expected_snapshot( $snapshot );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $normalized['manifest_fingerprint'] )
            || ! preg_match( '/^[a-f0-9]{64}$/', $normalized['translation_fingerprint'] )
            || $normalized['manifest_generation'] < 1
            || $normalized['global_generation'] < 1
            || $normalized['translation_generation'] < 1
            || $normalized['review_revision'] < 0
            || ! in_array( $normalized['machine_status'], [ 'complete', 'incomplete', 'stale', 'render_error', 'unknown', 'blocked' ], true ) ) {
            return null;
        }
        return $normalized;
    }

    private static function snapshots_match( array $expected, array $current ) {
        return $expected['manifest_generation'] === $current['manifest_generation']
            && hash_equals( $expected['manifest_fingerprint'], $current['manifest_fingerprint'] )
            && $expected['global_generation'] === $current['global_generation']
            && $expected['translation_generation'] === $current['translation_generation']
            && hash_equals( $expected['translation_fingerprint'], $current['translation_fingerprint'] )
            && hash_equals( $expected['machine_status'], $current['machine_status'] )
            && $expected['review_revision'] === $current['review_revision'];
    }

    private static function transaction_command( $command, $testable = true ) {
        global $wpdb;
        $command = strtoupper( trim( (string) $command ) );
        if ( $testable && false === apply_filters( 'gml_resource_approval_transaction_command', true, $command ) ) return false;
        return $wpdb->query( $command );
    }

    private static function review_state_sql() {
        $global = class_exists( 'GML_Resource_Manifest_Manager' )
            ? (int) GML_Resource_Manifest_Manager::global_generation()
            : max( 1, (int) get_option( 'gml_resource_manifest_global_generation', 1 ) );
        return "CASE
            WHEN rv.id IS NOT NULL AND (
                rv.manifest_generation<>m.manifest_generation
                OR NOT (rv.manifest_fingerprint <=> m.manifest_fingerprint)
                OR rv.global_generation<>m.global_generation
                OR rv.translation_generation<>COALESCE(v.generation,1)
                OR rv.translation_fingerprint='' OR r.translation_fingerprint=''
                OR NOT (rv.translation_fingerprint <=> r.translation_fingerprint)
            ) THEN 'stale'
            WHEN m.discovery_state<>'complete' OR m.global_generation<>$global
                OR r.manifest_generation<>m.manifest_generation OR r.global_generation<>m.global_generation
                OR r.status<>'complete' THEN 'blocked'
            WHEN rv.id IS NULL THEN 'unreviewed'
            ELSE rv.decision END";
    }

    private static function decorate_row( $row, $lang ) {
        $machine = GML_Resource_Readiness::evaluate_status_row( $row );
        $decision = in_array( (string) ( $row->decision ?? '' ), [ 'approved', 'rejected' ], true ) ? (string) $row->decision : 'unreviewed';
        $snapshot_matches = $decision !== 'unreviewed'
            && (int) $row->review_manifest_generation === (int) $row->manifest_generation
            && hash_equals( (string) $row->review_manifest_fingerprint, (string) $row->manifest_fingerprint )
            && (int) $row->review_global_generation === (int) $row->manifest_global_generation
            && (int) $row->review_translation_generation === (int) $row->translation_generation
            && strlen( (string) ( $row->review_translation_fingerprint ?? '' ) ) === 64
            && hash_equals( (string) $row->review_translation_fingerprint, (string) ( $row->translation_fingerprint ?? '' ) );
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
            'translation_fingerprint' => (string) ( $row->translation_fingerprint ?? '' ),
            'review_revision' => (int) ( $row->review_revision ?? 0 ),
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
            'manifest_fingerprint' => '', 'manifest_generation' => 0, 'global_generation' => 0,
            'translation_generation' => 0, 'translation_fingerprint' => '', 'review_revision' => 0,
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
