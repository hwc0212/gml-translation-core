<?php
/** Page-local SEO readiness; never a permission to hide a language URL. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Page_Readiness_Policy {
    public static function threshold() {
        return max( 1, min( 100, (int) get_option( 'gml_page_ready_percent', 98 ) ) );
    }

    /** Compare integer products, not rounded percentages shown in the UI. */
    public static function evaluate( array $review, array $coverage = [] ) {
        $required = max( 0, (int) ( $review['required_count'] ?? 0 ) );
        $translated = max( 0, min( $required, (int) ( $review['translated_count'] ?? 0 ) ) );
        $threshold = self::threshold();
        $machine = $review['machine_status'] ?? 'unknown';
        $critical = max( 0, (int) ( $review['critical_missing_count'] ?? 0 ) );
        $current = in_array( $machine, [ 'complete', 'incomplete' ], true )
            && strlen( (string) ( $review['translation_fingerprint'] ?? '' ) ) === 64;
        $count_ready = $required > 0 && $translated * 100 >= $required * $threshold;
        $length_ready = ! empty( $coverage['source_bytes'] )
            && (int) ( $coverage['unknown_count'] ?? 1 ) === 0
            && (int) ( $coverage['translated_bytes'] ?? 0 ) * 100 >= (int) $coverage['source_bytes'] * $threshold;
        $held = (int) ( $coverage['held_count'] ?? 0 );
        $rejected = ! empty( $review['snapshot_matches'] ) && ( $review['decision'] ?? '' ) === 'rejected';
        $ready = $current && $critical === 0 && ! $rejected && $held === 0
            && ( $machine === 'complete' || ( $count_ready && $length_ready ) );
        return [
            'ready' => $ready, 'threshold' => $threshold,
            'required_count' => $required, 'translated_count' => $translated,
            'percent' => $required ? floor( $translated * 1000 / $required ) / 10 : 0,
            'critical_missing_count' => $critical, 'held_count' => $held,
            'source_bytes' => (int) ( $coverage['source_bytes'] ?? 0 ),
            'translated_bytes' => (int) ( $coverage['translated_bytes'] ?? 0 ),
            'reason' => $rejected ? 'rejected' : ( $held ? 'quality_hold' : ( ! $current ? $machine : ( $critical ? 'critical_missing' : ( $ready ? 'ready' : 'below_page_threshold' ) ) ) ),
        ];
    }

    /** Indexed tuple joins; no per-string queries and no translated-text logging. */
    public static function evaluate_bulk( array $reviews ) {
        global $wpdb;
        $result = [];
        $snapshots = [];
        foreach ( $reviews as $key => $languages ) {
            foreach ( $languages as $lang => $review ) {
                $result[$key][$lang] = self::evaluate( $review );
                if ( ( $review['machine_status'] ?? '' ) === 'incomplete' && ! empty( $review['resource_id'] ) ) {
                    $snapshots[] = [ $key, $lang, $review ];
                }
            }
        }
        $relations = GML_Resource_Manifest_Store::relation_table();
        $index = $wpdb->prefix . 'gml_index';
        $queue = $wpdb->prefix . 'gml_queue';
        $source = get_option( 'gml_source_lang', 'en' );
        foreach ( array_chunk( $snapshots, 100 ) as $chunk ) {
            $selects = [];
            $args = [];
            foreach ( $chunk as $snapshot ) {
                $selects[] = 'SELECT %d AS resource_id,%d AS generation,%s AS lang';
                array_push( $args, $snapshot[2]['resource_id'], $snapshot[2]['manifest_generation'], $snapshot[1] );
            }
            $plan = $wpdb->prepare( implode( ' UNION ALL ', $selects ), $args );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.resource_id,p.lang,
                    SUM(LENGTH(COALESCE(i.source_text,q.source_text,''))) AS source_bytes,
                    SUM(CASE WHEN i.status IN ('auto','manual') THEN LENGTH(i.source_text) ELSE 0 END) AS translated_bytes,
                    SUM(CASE WHEN COALESCE(i.source_text,q.source_text,'')='' THEN 1 ELSE 0 END) AS unknown_count,
                    SUM(CASE WHEN i.id IS NOT NULL AND i.status NOT IN ('auto','manual') THEN 1 ELSE 0 END) AS held_count
                 FROM ($plan) p INNER JOIN $relations s ON s.resource_id=p.resource_id AND s.manifest_generation=p.generation
                 LEFT JOIN $index i ON i.source_hash=s.source_hash AND i.source_lang=%s AND i.target_lang=p.lang
                 LEFT JOIN $queue q ON q.source_hash=s.source_hash AND q.source_lang=%s AND q.target_lang=p.lang
                 GROUP BY p.resource_id,p.lang", $source, $source
            ), ARRAY_A );
            $coverage = [];
            if ( $wpdb->last_error === '' ) foreach ( (array) $rows as $row ) $coverage[$row['resource_id']][$row['lang']] = $row;
            foreach ( $chunk as $snapshot ) {
                $result[$snapshot[0]][$snapshot[1]] = self::evaluate( $snapshot[2], $coverage[$snapshot[2]['resource_id']][$snapshot[1]] ?? [] );
            }
        }
        return $result;
    }
}
