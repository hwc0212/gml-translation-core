<?php
/** Central, approval-aware mutation contract for Translation Memory. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Translation_Memory {
    const BATCH_SIZE = 100;

    /**
     * Insert absent tuples only. Existing rows of ANY status are immutable here.
     * Conflicts are per tuple; DB/invalidation errors roll back the entire batch
     * and return false. A returned ledger describes committed outcomes only.
     */
    public static function insert_missing_batch( array $records ) {
        global $wpdb;
        if ( count( $records ) > self::BATCH_SIZE ) return false;
        $normalized = [];
        $inputs = [];
        foreach ( array_values( $records ) as $i => $record ) {
            if ( ! is_array( $record ) ) return false;
            $record = self::normalize_record( $record );
            if ( ! $record || ! hash_equals( md5( $record['source_text'] ), $record['source_hash'] ) ) return false;
            $key = self::record_key( $record );
            $inputs[] = $key;
            if ( ! isset( $normalized[ $key ] ) ) $normalized[ $key ] = $record;
            elseif ( $normalized[ $key ] !== $record ) return false;
        }
        $summary = [ 'committed' => true, 'inserted' => 0, 'skipped' => 0, 'items' => [] ];
        if ( ! $normalized ) return $summary;
        if ( ! class_exists( 'GML_Resource_Approval' )
            || ! GML_Resource_Manifest_Store::tables_ready()
            || ! GML_Resource_Approval::transaction_health( true )['ready'] ) return false;
        // Never implicitly commit a caller's transaction via START TRANSACTION.
        $active = $wpdb->get_var( 'SELECT @@in_transaction' );
        if ( $wpdb->last_error !== '' || (int) $active !== 0 ) return false;
        $table = $wpdb->prefix . 'gml_index';
        $indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name='hash_lang'", ARRAY_A );
        if ( $wpdb->last_error !== '' || count( (array) $indexes ) !== 3 ) return false;
        usort( $indexes, static function( $a, $b ) { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; } );
        if ( array_column( $indexes, 'Column_name' ) !== [ 'source_hash', 'source_lang', 'target_lang' ] ) return false;
        foreach ( $indexes as $index ) if ( (int) $index['Non_unique'] !== 0 || $index['Sub_part'] !== null ) return false;

        ksort( $normalized ); // Consistent lock order for overlapping batches.
        $changes = [];
        $outcomes = [];
        $mutate = static function() use ( $wpdb, $table, $normalized, &$changes, &$outcomes ) {
            $now = current_time( 'mysql' );
            foreach ( $normalized as $key => $record ) {
                // The unique index resolves the race atomically. Do not use
                // INSERT IGNORE: truncation and other DB errors must abort.
                $quiet = $wpdb->suppress_errors( true );
                try {
                    $affected = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO $table
                     (source_hash,source_text,source_lang,target_lang,translated_text,context_type,status,created_at,updated_at)
                     VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                    $record['source_hash'], $record['source_text'], $record['source_lang'], $record['target_lang'],
                    $record['translated_text'], $record['context_type'], $record['status'], $now, $now
                    ) );
                    $duplicate = $affected === false && $wpdb->dbh instanceof mysqli && mysqli_errno( $wpdb->dbh ) === 1062;
                } finally {
                    $wpdb->suppress_errors( $quiet );
                }
                if ( $affected !== 1 && ! $duplicate ) return false;
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id,status FROM $table WHERE source_hash=%s AND source_lang=%s AND target_lang=%s FOR UPDATE",
                    $record['source_hash'], $record['source_lang'], $record['target_lang']
                ), ARRAY_A );
                if ( ! $row || $wpdb->last_error !== '' ) return false;
                $outcomes[ $key ] = [
                    'source_hash' => $record['source_hash'], 'source_lang' => $record['source_lang'],
                    'target_lang' => $record['target_lang'], 'id' => (int) $row['id'],
                    'outcome' => $affected === 1 ? 'inserted' : 'existing',
                    'existing_status' => $affected === 1 ? null : $row['status'],
                ];
                if ( $affected === 1 ) $changes[] = [ 'source_hash' => $record['source_hash'], 'target_lang' => $record['target_lang'] ];
            }
            return ! $changes || ! class_exists( 'GML_Page_Cache' ) || GML_Page_Cache::force_invalidate();
        };
        $planned = array_map( static function( $r ) { return [ 'source_hash' => $r['source_hash'], 'target_lang' => $r['target_lang'] ]; }, array_values( $normalized ) );
        $result = GML_Resource_Readiness::apply_translation_changes( $planned, $mutate, static function() use ( &$changes ) { return $changes; } );
        if ( $result === false ) return false;
        $seen = [];
        foreach ( $inputs as $i => $key ) {
            $item = $outcomes[ $key ];
            if ( isset( $seen[ $key ] ) ) $item['outcome'] = 'duplicate_input';
            $item['index'] = $i;
            $summary['items'][] = $item;
            $summary[ $item['outcome'] === 'inserted' ? 'inserted' : 'skipped' ]++;
            $seen[ $key ] = true;
        }
        if ( $changes ) {
            if ( class_exists( 'GML_Translation_Readiness' ) ) GML_Translation_Readiness::clear_cache();
            foreach ( $outcomes as $key => $outcome ) if ( $outcome['outcome'] === 'inserted' ) {
                if ( class_exists( 'GML_Translation_Translator' ) ) {
                    GML_Translation_Translator::invalidate_cache( $normalized[ $key ]['source_lang'], $normalized[ $key ]['target_lang'] );
                } else {
                    wp_cache_delete( 'gml_dict_' . $normalized[ $key ]['source_lang'] . '_' . $normalized[ $key ]['target_lang'], 'gml_translate' );
                }
            }
        }
        return $summary;
    }

    public static function upsert( array $record, $protect_manual = true ) {
        $result = self::upsert_batch( [ $record ], $protect_manual );
        return $result === false ? false : true;
    }

    /**
     * Upsert a bounded batch. Effective changes are invalidated once per
     * resource/language pair inside the same transaction as the TM writes.
     */
    public static function upsert_batch( array $records, $protect_manual = true ) {
        global $wpdb;
        if ( count( $records ) > self::BATCH_SIZE ) return false;

        $normalized = [];
        foreach ( $records as $record ) {
            $record = self::normalize_record( $record );
            if ( ! $record ) return false;
            $normalized[ self::record_key( $record ) ] = $record;
        }
        if ( ! $normalized ) return [ 'written' => 0, 'unchanged' => 0, 'skipped_manual' => 0 ];

        $existing = self::load_existing( array_values( $normalized ) );
        if ( $existing === false ) return false;
        $writes = [];
        $changes = [];
        $summary = [ 'written' => 0, 'unchanged' => 0, 'skipped_manual' => 0 ];
        foreach ( $normalized as $key => $record ) {
            $current = $existing[ $key ] ?? null;
            if ( $protect_manual && $record['status'] === 'auto' && $current && $current->status === 'manual' ) {
                $summary['skipped_manual']++;
                continue;
            }
            if ( $current
                && hash_equals( (string) $current->translated_text, $record['translated_text'] )
                && hash_equals( (string) $current->status, $record['status'] ) ) {
                $summary['unchanged']++;
                continue;
            }
            $writes[] = $record;
            $changes[] = [ 'source_hash' => $record['source_hash'], 'target_lang' => $record['target_lang'] ];
        }
        if ( ! $writes ) return $summary;

        $table = $wpdb->prefix . 'gml_index';
        $mutate = static function () use ( $wpdb, $table, $writes, $protect_manual, &$summary ) {
            $now = current_time( 'mysql' );
            foreach ( $writes as $record ) {
                $protect_existing_manual = $protect_manual && $record['status'] === 'auto';
                $updates = $protect_existing_manual
                    ? "source_text=IF(status='manual',source_text,VALUES(source_text)),
                        translated_text=IF(status='manual',translated_text,VALUES(translated_text)),
                        context_type=IF(status='manual',context_type,VALUES(context_type)),
                        status=IF(status='manual',status,VALUES(status)),
                        updated_at=IF(status='manual',updated_at,VALUES(updated_at))"
                    : 'source_text=VALUES(source_text),translated_text=VALUES(translated_text),'
                        . 'context_type=VALUES(context_type),status=VALUES(status),updated_at=VALUES(updated_at)';
                $sql = $wpdb->prepare(
                    "INSERT INTO $table
                        (source_hash,source_text,source_lang,target_lang,translated_text,context_type,status,created_at,updated_at)
                     VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
                     ON DUPLICATE KEY UPDATE $updates",
                    $record['source_hash'], $record['source_text'], $record['source_lang'], $record['target_lang'],
                    $record['translated_text'], $record['context_type'], $record['status'], $now, $now
                );
                $affected = $wpdb->query( $sql );
                if ( false === $affected ) return false;
                if ( $affected === 0 ) {
                    $current = $wpdb->get_row( $wpdb->prepare(
                        "SELECT translated_text,status FROM $table
                         WHERE source_hash=%s AND source_lang=%s AND target_lang=%s",
                        $record['source_hash'], $record['source_lang'], $record['target_lang']
                    ) );
                    if ( ! $current || $wpdb->last_error !== '' ) return false;
                    if ( $protect_existing_manual && $current->status === 'manual' ) {
                        $summary['skipped_manual']++;
                        continue;
                    }
                    if ( hash_equals( (string) $current->translated_text, $record['translated_text'] )
                        && hash_equals( (string) $current->status, $record['status'] ) ) {
                        $summary['unchanged']++;
                        continue;
                    }
                    return false;
                }
                $summary['written']++;
            }
            return $summary;
        };

        if ( class_exists( 'GML_Resource_Readiness' ) ) {
            return GML_Resource_Readiness::apply_translation_changes( $changes, $mutate );
        }
        return $mutate();
    }

    public static function update_by_id( $id, $translated_text, $status = 'manual' ) {
        global $wpdb;
        $id = (int) $id;
        $translated_text = (string) $translated_text;
        $status = $status === 'manual' ? 'manual' : 'auto';
        if ( $id < 1 || $translated_text === '' ) return false;
        $table = $wpdb->prefix . 'gml_index';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id,source_hash,source_lang,target_lang,translated_text,status FROM $table WHERE id=%d",
            $id
        ) );
        if ( ! $row ) return false;
        if ( hash_equals( (string) $row->translated_text, $translated_text ) && hash_equals( (string) $row->status, $status ) ) return true;
        $mutate = static function () use ( $wpdb, $table, $id, $translated_text, $status ) {
            return $wpdb->update( $table, [
                'translated_text' => $translated_text,
                'status' => $status,
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => $id ] );
        };
        return class_exists( 'GML_Resource_Readiness' )
            ? false !== GML_Resource_Readiness::apply_translation_changes( [ [ 'source_hash' => $row->source_hash, 'target_lang' => $row->target_lang ] ], $mutate )
            : false !== $mutate();
    }

    public static function delete_by_id( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( $id < 1 ) return false;
        $table = $wpdb->prefix . 'gml_index';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id,source_hash,source_lang,target_lang FROM $table WHERE id=%d",
            $id
        ) );
        if ( ! $row ) return false;
        $mutate = static function () use ( $wpdb, $table, $id ) {
            return $wpdb->delete( $table, [ 'id' => $id ] );
        };
        return class_exists( 'GML_Resource_Readiness' )
            ? false !== GML_Resource_Readiness::apply_translation_changes( [ [ 'source_hash' => $row->source_hash, 'target_lang' => $row->target_lang ] ], $mutate )
            : false !== $mutate();
    }

    /** Explicit operator hold; preserve text, reject stale snapshots and manual rows. */
    public static function hold_auto_by_id( $id, array $expected ) {
        global $wpdb;
        if (!current_user_can('manage_options') || (int)$id<1 || ($expected['status']??'')!=='auto' || !GML_Resource_Manifest_Store::tables_ready()) return false;
        $fields=['source_hash','source_text','source_lang','target_lang','translated_text','status','updated_at'];
        foreach($fields as $field) if(!isset($expected[$field]) || !is_string($expected[$field])) return false;
        if (!preg_match('/^[a-f0-9]{32}$/D', $expected['source_hash'])
            || $expected['source_lang']==='' || $expected['target_lang']===''
            || self::normalize_language($expected['source_lang']) !== $expected['source_lang']
            || self::normalize_language($expected['target_lang']) !== $expected['target_lang']
            || !GML_Resource_Approval::transaction_health(true)['ready']) return false;
        $table=$wpdb->prefix.'gml_index';
        $mutation=static function() use($wpdb,$table,$id,$expected,$fields) {
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id),ARRAY_A);
            if(!$row) return false;
            foreach($fields as $field) if(!hash_equals($expected[$field],(string)$row[$field])) return false;
            if(1!==$wpdb->update($table,['status'=>'pending','updated_at'=>current_time('mysql')],['id'=>(int)$id,'status'=>'auto'])) return false;
            return !class_exists('GML_Page_Cache') || false!==GML_Page_Cache::force_invalidate();
        };
        $saved=GML_Resource_Readiness::apply_translation_changes([['source_hash'=>$expected['source_hash'],'target_lang'=>$expected['target_lang']]],$mutation);
        if($saved===false) return false;
        GML_Translation_Readiness::clear_cache();
        if(class_exists('GML_Translator')) GML_Translator::invalidate_cache($expected['source_lang'],$expected['target_lang']);
        return true;
    }

    /** Fingerprint only effective translations required by one exact manifest. */
    public static function snapshot_fingerprint( $resource_id, $manifest_generation, $target_lang, $source_lang = '' ) {
        $fingerprints = self::snapshot_fingerprints( [ [
            'resource_id' => $resource_id,
            'manifest_generation' => $manifest_generation,
            'target_lang' => $target_lang,
        ] ], $source_lang );
        $target_lang = self::normalize_language( $target_lang );
        return (string) ( $fingerprints[ (int) $resource_id ][ (int) $manifest_generation ][ $target_lang ] ?? '' );
    }

    /**
     * Calculate up to 500 exact resource/language fingerprints in one query.
     * Only SHA-256 translation digests cross the DB boundary.
     */
    public static function snapshot_fingerprints( array $snapshots, $source_lang = '' ) {
        global $wpdb;
        $source_lang = self::normalize_language( $source_lang ?: get_option( 'gml_source_lang', 'en' ) );
        if ( $source_lang === '' || count( $snapshots ) > 500 ) return [];

        $normalized = [];
        foreach ( $snapshots as $snapshot ) {
            $resource_id = (int) ( $snapshot['resource_id'] ?? 0 );
            $manifest_generation = (int) ( $snapshot['manifest_generation'] ?? 0 );
            $target_lang = self::normalize_language( $snapshot['target_lang'] ?? '' );
            if ( $resource_id < 1 || $manifest_generation < 1 || $target_lang === '' ) continue;
            $normalized[ $resource_id . ':' . $manifest_generation . ':' . $target_lang ] = [
                'resource_id' => $resource_id,
                'manifest_generation' => $manifest_generation,
                'target_lang' => $target_lang,
            ];
        }
        if ( ! $normalized ) return [];

        $relations = GML_Resource_Manifest_Store::relation_table();
        $index = $wpdb->prefix . 'gml_index';
        $parts = [];
        $args = [];
        $contexts = [];
        foreach ( $normalized as $snapshot ) {
            $parts[] = 'SELECT %d AS resource_id,%d AS manifest_generation,%s AS target_lang';
            array_push( $args, $snapshot['resource_id'], $snapshot['manifest_generation'], $snapshot['target_lang'] );
            $context = hash_init( 'sha256' );
            self::hash_component( $context, 'gml-translation-snapshot-v1' );
            self::hash_component( $context, $snapshot['target_lang'] );
            $contexts[ $snapshot['resource_id'] ][ $snapshot['manifest_generation'] ][ $snapshot['target_lang'] ] = $context;
        }
        $pairs = implode( ' UNION ALL ', $parts );
        $args[] = $source_lang;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.resource_id,p.manifest_generation,p.target_lang,s.source_hash,i.status,
                    CASE WHEN i.id IS NULL THEN NULL ELSE SHA2(i.translated_text,256) END AS translated_hash
             FROM ($pairs) p
             LEFT JOIN $relations s ON s.resource_id=p.resource_id AND s.manifest_generation=p.manifest_generation
             LEFT JOIN $index i ON i.source_hash=s.source_hash AND i.source_lang=%s
                AND i.target_lang=p.target_lang AND i.status IN ('auto','manual')
             ORDER BY p.resource_id,p.manifest_generation,p.target_lang,s.source_hash",
            $args
        ) );
        if ( $wpdb->last_error !== '' ) return [];
        foreach ( (array) $rows as $row ) {
            if ( $row->source_hash === null ) continue;
            $context = $contexts[ (int) $row->resource_id ][ (int) $row->manifest_generation ][ (string) $row->target_lang ] ?? null;
            if ( ! $context ) continue;
            self::hash_component( $context, strtolower( (string) $row->source_hash ) );
            self::hash_component( $context, $row->status === null ? 'missing' : (string) $row->status );
            self::hash_component( $context, $row->translated_hash === null ? '' : strtolower( (string) $row->translated_hash ) );
        }
        $result = [];
        foreach ( $contexts as $resource_id => $generations ) {
            foreach ( $generations as $manifest_generation => $languages ) {
                foreach ( $languages as $target_lang => $context ) {
                    $result[ $resource_id ][ $manifest_generation ][ $target_lang ] = hash_final( $context );
                }
            }
        }
        return $result;
    }

    private static function normalize_record( array $record ) {
        $source_text = (string) ( $record['source_text'] ?? '' );
        $translated_text = (string) ( $record['translated_text'] ?? '' );
        $source_hash = strtolower( sanitize_text_field( $record['source_hash'] ?? md5( $source_text ) ) );
        $source_lang = self::normalize_language( $record['source_lang'] ?? '' );
        $target_lang = self::normalize_language( $record['target_lang'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $source_hash ) || $source_text === '' || $translated_text === '' || $source_lang === '' || $target_lang === '' ) return null;
        return [
            'source_hash' => $source_hash,
            'source_text' => $source_text,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'translated_text' => $translated_text,
            'context_type' => sanitize_key( $record['context_type'] ?? 'text' ) ?: 'text',
            'status' => ( $record['status'] ?? 'auto' ) === 'manual' ? 'manual' : 'auto',
        ];
    }

    private static function load_existing( array $records ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';
        $groups = [];
        foreach ( $records as $record ) $groups[ $record['source_lang'] . '>' . $record['target_lang'] ][] = $record['source_hash'];
        $result = [];
        foreach ( $groups as $pair => $hashes ) {
            list( $source, $target ) = explode( '>', $pair, 2 );
            $hashes = array_values( array_unique( $hashes ) );
            $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_hash,source_lang,target_lang,translated_text,status FROM $table
                 WHERE source_lang=%s AND target_lang=%s AND source_hash IN ($placeholders)",
                array_merge( [ $source, $target ], $hashes )
            ) );
            if ( $wpdb->last_error !== '' ) return false;
            foreach ( (array) $rows as $row ) $result[ $row->source_hash . '|' . $row->source_lang . '|' . $row->target_lang ] = $row;
        }
        return $result;
    }

    private static function record_key( array $record ) {
        return $record['source_hash'] . '|' . $record['source_lang'] . '|' . $record['target_lang'];
    }

    private static function normalize_language( $language ) {
        return class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $language ) : sanitize_key( $language );
    }

    private static function hash_component( $context, $value ) {
        $value = (string) $value;
        hash_update( $context, strlen( $value ) . ':' . $value . ';' );
    }
}
