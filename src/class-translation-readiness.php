<?php
/**
 * Read-only translation readiness for the current site corpus.
 *
 * Historical Translation Memory and queue rows remain available for audit and
 * reuse. Once the resource-manifest backfill is complete, only source hashes
 * referenced by the current manifest generation affect coverage, retries, and
 * public language readiness.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translation_Readiness {

    const MIN_LANGUAGE_COVERAGE = 1.0;

    /** @var array<string,bool>|null */
    private static $map = null;

    /** @var array<string,array<string,int|bool|string>>|null */
    private static $statistics = null;

    /** @var array<string,array<string,int|bool|string>>|null */
    private static $coverage = null;

    /** @var bool|null */
    private static $current_corpus_complete = null;

    public static function language_is_index_ready( $lang ) {
        $lang = self::normalize_language( $lang );
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

        $statistics = self::has_manifest_schema()
            ? self::current_corpus_coverage()
            : self::legacy_statistics();
        self::$map = [];
        foreach ( $statistics as $code => $row ) {
            self::$map[ $code ] = ! empty( $row['ready'] );
        }
        wp_cache_set( 'gml_readiness_map', self::$map, 'gml_translate', 60 );
        return self::$map;
    }

    /**
     * Return per-language counts without deleting or rewriting historical rows.
     *
     * @return array<string,array<string,int|bool|string>>
     */
    public static function language_statistics() {
        if ( is_array( self::$statistics ) ) {
            return self::$statistics;
        }

        $cached = wp_cache_get( 'gml_language_statistics', 'gml_translate' );
        if ( is_array( $cached ) ) {
            self::$statistics = $cached;
            return self::$statistics;
        }

        self::$statistics = self::has_manifest_schema()
            ? self::current_corpus_statistics()
            : self::legacy_statistics();
        wp_cache_set( 'gml_language_statistics', self::$statistics, 'gml_translate', 60 );
        return self::$statistics;
    }

    /** Whether current manifests, instead of the historical queue, are supported. */
    public static function uses_current_corpus() {
        return self::has_manifest_schema();
    }

    /**
     * Fail closed until the full current-site inventory is stable.
     *
     * Stale manifests may represent deleted content and therefore do not block a
     * completed backfill. Active dirty work and current render errors do block it.
     */
    public static function current_corpus_is_complete() {
        if ( self::$current_corpus_complete !== null ) {
            return self::$current_corpus_complete;
        }
        if ( ! self::has_manifest_schema() || ! class_exists( 'GML_Resource_Backfill' ) ) {
            self::$current_corpus_complete = false;
            return false;
        }
        $state = GML_Resource_Backfill::state();
        if ( ( $state['status'] ?? '' ) !== 'complete' || get_option( 'gml_resource_manifest_dirty', [] ) ) {
            self::$current_corpus_complete = false;
            return false;
        }

        global $wpdb;
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $generation = self::global_generation();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS manifest_count,
                    SUM(CASE WHEN discovery_state IN ('unknown','render_error') THEN 1 ELSE 0 END) AS blocking_count
             FROM $manifests WHERE global_generation=%d",
            $generation
        ) );
        self::$current_corpus_complete = $row
            && (int) $row->manifest_count > 0
            && (int) $row->blocking_count === 0
            && $wpdb->last_error === '';
        return self::$current_corpus_complete;
    }

    /**
     * SQL predicate limiting a queue alias to untranslated current source hashes.
     * An empty string means current-corpus filtering is not authoritative yet.
     */
    public static function current_queue_scope_sql( $queue_alias = 'q' ) {
        if ( ! self::current_corpus_is_complete() ) {
            return '';
        }
        $queue_alias = preg_match( '/^[a-z][a-z0-9_]*$/i', (string) $queue_alias ) ? $queue_alias : 'q';
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        global $wpdb;
        $index = $wpdb->prefix . 'gml_index';
        $generation = self::global_generation();
        return "EXISTS (
                    SELECT 1 FROM $relations gml_scope_s
                    INNER JOIN $manifests gml_scope_m
                        ON gml_scope_m.id=gml_scope_s.resource_id
                        AND gml_scope_m.manifest_generation=gml_scope_s.manifest_generation
                    WHERE gml_scope_s.source_hash=$queue_alias.source_hash
                        AND gml_scope_m.discovery_state='complete'
                        AND gml_scope_m.global_generation=$generation
                )
                AND NOT EXISTS (
                    SELECT 1 FROM $index gml_scope_i
                    WHERE gml_scope_i.source_hash=$queue_alias.source_hash
                        AND gml_scope_i.source_lang=$queue_alias.source_lang
                        AND gml_scope_i.target_lang=$queue_alias.target_lang
                        AND gml_scope_i.status IN ('auto','manual')
                )";
    }

    public static function clear_cache() {
        self::$map = null;
        self::$statistics = null;
        self::$coverage = null;
        self::$current_corpus_complete = null;
        wp_cache_delete( 'gml_readiness_map', 'gml_translate' );
        wp_cache_delete( 'gml_language_statistics', 'gml_translate' );
        wp_cache_delete( 'gml_current_corpus_coverage', 'gml_translate' );
    }

    private static function current_corpus_statistics() {
        global $wpdb;
        $languages = self::local_target_languages();
        if ( ! $languages ) {
            return [];
        }

        $index = $wpdb->prefix . 'gml_index';
        $queue = $wpdb->prefix . 'gml_queue';
        $source = self::normalize_language( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
        $placeholders = implode( ',', array_fill( 0, count( $languages ), '%s' ) );
        $coverage = self::current_corpus_coverage();
        $current = self::current_source_hashes_sql();
        $queue_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.target_lang,
                    COUNT(DISTINCT CASE WHEN q.status IN ('pending','processing') THEN q.source_hash END) AS pending_count,
                    COUNT(DISTINCT CASE WHEN q.status='failed' THEN q.source_hash END) AS failed_count
             FROM ($current) gml_current
             INNER JOIN $queue q ON q.source_hash=gml_current.source_hash AND q.source_lang=%s
             LEFT JOIN $index i ON i.source_hash=q.source_hash AND i.source_lang=q.source_lang
                AND i.target_lang=q.target_lang AND i.status IN ('auto','manual')
             WHERE q.target_lang IN ($placeholders) AND i.id IS NULL
             GROUP BY q.target_lang",
            array_merge( [ $source ], $languages )
        ) );
        $historical_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT target_lang,COUNT(*) AS failed_count FROM $queue
             WHERE source_lang=%s AND target_lang IN ($placeholders) AND status='failed'
             GROUP BY target_lang",
            array_merge( [ $source ], $languages )
        ) );

        $queued = self::rows_by_language( $queue_rows );
        $historical = self::rows_by_language( $historical_rows );
        $result = [];
        foreach ( $languages as $code ) {
            $base = $coverage[ $code ] ?? [
                'mode' => 'current', 'corpus_complete' => false, 'required' => 0,
                'translated' => 0, 'critical_missing' => 0, 'ready' => false,
            ];
            $required = (int) $base['required'];
            $done = (int) $base['translated'];
            $pending = (int) ( $queued[ $code ]->pending_count ?? 0 );
            $failed = (int) ( $queued[ $code ]->failed_count ?? 0 );
            $missing = max( 0, $required - $done );
            $historical_failed = (int) ( $historical[ $code ]->failed_count ?? 0 );
            $result[ $code ] = array_merge( $base, [
                'pending'               => min( $missing, $pending ),
                'failed'                => min( $missing, $failed ),
                'unqueued'              => max( 0, $missing - $pending - $failed ),
                'historical_failed'     => $historical_failed,
                'historical_irrelevant' => max( 0, $historical_failed - $failed ),
            ] );
        }
        return $result;
    }

    /**
     * Lightweight public coverage used by canonical/hreflang decisions.
     * Queue history is intentionally excluded so front-end requests do not scan it.
     */
    private static function current_corpus_coverage() {
        if ( is_array( self::$coverage ) ) {
            return self::$coverage;
        }
        $cached = wp_cache_get( 'gml_current_corpus_coverage', 'gml_translate' );
        if ( is_array( $cached ) ) {
            self::$coverage = $cached;
            return self::$coverage;
        }

        global $wpdb;
        $languages = self::local_target_languages();
        if ( ! $languages ) {
            self::$coverage = [];
            return self::$coverage;
        }
        $index = $wpdb->prefix . 'gml_index';
        $source = self::normalize_language( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
        $placeholders = implode( ',', array_fill( 0, count( $languages ), '%s' ) );
        $current = self::current_source_hashes_sql();
        $totals = $wpdb->get_row( "SELECT COUNT(*) AS required_count,COALESCE(SUM(critical),0) AS critical_count FROM ($current) gml_current" );
        $required = (int) ( $totals->required_count ?? 0 );
        $critical = (int) ( $totals->critical_count ?? 0 );
        $translated_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.target_lang,COUNT(DISTINCT i.source_hash) AS translated_count,
                    COUNT(DISTINCT CASE WHEN gml_current.critical=1 THEN i.source_hash END) AS critical_translated_count
             FROM ($current) gml_current
             INNER JOIN $index i ON i.source_hash=gml_current.source_hash
             WHERE i.source_lang=%s AND i.target_lang IN ($placeholders) AND i.status IN ('auto','manual')
             GROUP BY i.target_lang",
            array_merge( [ $source ], $languages )
        ) );
        $translated = self::rows_by_language( $translated_rows );
        $complete = self::current_corpus_is_complete();
        self::$coverage = [];
        foreach ( $languages as $code ) {
            $done = (int) ( $translated[ $code ]->translated_count ?? 0 );
            $critical_done = (int) ( $translated[ $code ]->critical_translated_count ?? 0 );
            self::$coverage[ $code ] = [
                'mode' => 'current', 'corpus_complete' => $complete, 'required' => $required,
                'translated' => $done, 'critical_missing' => max( 0, $critical - $critical_done ),
                'ready' => $complete && $done > 0 && $critical_done >= $critical
                    && ( $done / max( 1, $required ) ) >= self::MIN_LANGUAGE_COVERAGE,
            ];
        }
        wp_cache_set( 'gml_current_corpus_coverage', self::$coverage, 'gml_translate', 60 );
        return self::$coverage;
    }

    private static function current_source_hashes_sql() {
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $relations = GML_Resource_Manifest_Store::relation_table();
        $generation = self::global_generation();
        return "SELECT s.source_hash,MAX(s.critical) AS critical
                FROM $relations s
                INNER JOIN $manifests m
                    ON m.id=s.resource_id AND m.manifest_generation=s.manifest_generation
                WHERE m.discovery_state='complete' AND m.global_generation=$generation
                GROUP BY s.source_hash";
    }

    private static function legacy_statistics() {
        global $wpdb;
        $index_table = $wpdb->prefix . 'gml_index';
        $queue_table = $wpdb->prefix . 'gml_queue';
        $translated_rows = $wpdb->get_results(
            "SELECT target_lang,COUNT(*) AS item_count FROM $index_table
             WHERE status IN ('auto','manual') GROUP BY target_lang"
        );
        $queue_rows = $wpdb->get_results(
            "SELECT target_lang,
                    SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed_count
             FROM $queue_table WHERE status IN ('pending','processing','failed') GROUP BY target_lang"
        );
        $translated = self::rows_by_language( $translated_rows );
        $queued = self::rows_by_language( $queue_rows );
        $codes = array_unique( array_merge( array_keys( $translated ), array_keys( $queued ) ) );
        $result = [];
        foreach ( $codes as $code ) {
            $done = (int) ( $translated[ $code ]->item_count ?? 0 );
            $legacy_incomplete = (int) ( $queued[ $code ]->item_count ?? 0 );
            $pending = isset( $queued[ $code ]->pending_count )
                ? (int) $queued[ $code ]->pending_count
                : $legacy_incomplete;
            $failed = (int) ( $queued[ $code ]->failed_count ?? 0 );
            $required = $done + $pending + $failed;
            $result[ $code ] = [
                'mode' => 'legacy', 'corpus_complete' => true, 'required' => $required,
                'translated' => $done, 'pending' => $pending, 'failed' => $failed,
                'unqueued' => 0, 'historical_failed' => $failed, 'historical_irrelevant' => 0,
                'critical_missing' => 0,
                'ready' => $done > 0 && ( $done / max( 1, $required ) ) >= self::MIN_LANGUAGE_COVERAGE,
            ];
        }
        return $result;
    }

    private static function rows_by_language( $rows ) {
        $result = [];
        foreach ( (array) $rows as $row ) {
            $code = self::normalize_language( $row->target_lang ?? '' );
            if ( $code ) {
                $result[ $code ] = $row;
            }
        }
        return $result;
    }

    private static function local_target_languages() {
        $source = self::normalize_language( get_option( 'gml_source_lang', 'en' ) );
        $codes = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::local_configured_codes( false, true )
            : array_map( 'sanitize_key', (array) get_option( 'gml_languages', [] ) );
        return array_values( array_unique( array_filter( array_map( [ __CLASS__, 'normalize_language' ], $codes ), static function( $code ) use ( $source ) {
            return $code !== '' && $code !== $source;
        } ) ) );
    }

    private static function has_manifest_schema() {
        return class_exists( 'GML_Resource_Manifest_Store' )
            && GML_Resource_Manifest_Store::tables_ready();
    }

    private static function global_generation() {
        return class_exists( 'GML_Resource_Manifest_Manager' )
            ? GML_Resource_Manifest_Manager::global_generation()
            : max( 1, (int) get_option( 'gml_resource_manifest_global_generation', 1 ) );
    }

    private static function normalize_language( $lang ) {
        return class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::normalize_code( $lang )
            : sanitize_key( $lang );
    }
}
