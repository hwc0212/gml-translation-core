<?php
/**
 * Shared translation memory lookup and atomic queue enqueue service.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translation_Translator {

    private static $memory_cache = [];
    private static $dict_loaded = [];

    public function translate( $parsed, $target_lang ) {
        global $wpdb;
        $source_lang  = sanitize_key( get_option( 'gml_source_lang', 'en' ) );
        $target_lang  = sanitize_key( $target_lang );
        $nodes        = is_array( $parsed['nodes'] ?? null ) ? $parsed['nodes'] : [];
        $replacements = [];
        if ( empty( $nodes ) || $target_lang === '' ) {
            $parsed['replacements'] = [];
            return $parsed;
        }

        $this->maybe_preload_dictionary( $source_lang, $target_lang );
        $unique = [];
        foreach ( $nodes as $item ) {
            $hash = sanitize_text_field( $item['hash'] ?? '' );
            $text = (string) ( $item['text'] ?? '' );
            if ( $hash !== '' && $text !== '' && ! isset( $unique[ $hash ] ) ) {
                $unique[ $hash ] = [
                    'text'         => $text,
                    'context_type' => sanitize_key( $item['context_type'] ?? 'text' ) ?: 'text',
                ];
            }
        }

        $dictionary = self::$memory_cache[ $target_lang ] ?? [];
        $uncached   = [];
        foreach ( $unique as $hash => $item ) {
            if ( isset( $dictionary[ $hash ] ) ) {
                $replacements[ $item['text'] ] = $dictionary[ $hash ];
            } else {
                $uncached[ $hash ] = $item;
            }
        }

        if ( $uncached && $this->ai_translation_available() ) {
            $queue_table    = $wpdb->prefix . 'gml_queue';
            $already_queued = [];
            foreach ( array_chunk( array_keys( $uncached ), 500 ) as $hashes ) {
                $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT source_hash FROM $queue_table
                     WHERE source_hash IN ($placeholders)
                     AND source_lang = %s AND target_lang = %s",
                    array_merge( $hashes, [ $source_lang, $target_lang ] )
                ) );
                foreach ( (array) $rows as $row ) {
                    $already_queued[ $row->source_hash ] = true;
                }
            }

            $now = current_time( 'mysql' );
            foreach ( $uncached as $hash => $item ) {
                if ( isset( $already_queued[ $hash ] ) ) {
                    continue;
                }
                // The Core 2.5 queue has a unique (hash, source, target) key.
                // INSERT IGNORE makes concurrent logged-out page requests safe.
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO $queue_table
                        (source_hash, source_text, source_lang, target_lang, context_type, priority, status, attempts, created_at)
                     VALUES (%s, %s, %s, %s, %s, %d, 'pending', 0, %s)",
                    $hash,
                    $item['text'],
                    $source_lang,
                    $target_lang,
                    $item['context_type'],
                    $this->calculate_priority( $item['text'], $item['context_type'] ),
                    $now
                ) );
            }
        }

        $parsed['replacements'] = $replacements;
        return $parsed;
    }

    protected function ai_translation_available() {
        return class_exists( 'GML_Translation_State' ) && GML_Translation_State::ai_available();
    }

    private function maybe_preload_dictionary( $source_lang, $target_lang ) {
        if ( ! empty( self::$dict_loaded[ $target_lang ] ) ) {
            return;
        }
        $cache_key = 'gml_dict_' . $source_lang . '_' . $target_lang;
        $cached    = wp_cache_get( $cache_key, 'gml_translate' );
        if ( is_array( $cached ) ) {
            self::$memory_cache[ $target_lang ] = $cached;
            self::$dict_loaded[ $target_lang ]  = true;
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT source_hash, translated_text FROM $table
             WHERE source_lang = %s AND target_lang = %s AND status IN ('auto','manual')",
            $source_lang,
            $target_lang
        ) );
        $dictionary = [];
        foreach ( (array) $rows as $row ) {
            $translated = (string) $row->translated_text;
            if ( strpos( $translated, '<' ) !== false ) {
                $translated = wp_strip_all_tags( $translated );
            }
            $dictionary[ $row->source_hash ] = $translated;
        }
        self::$memory_cache[ $target_lang ] = $dictionary;
        self::$dict_loaded[ $target_lang ]  = true;
        wp_cache_set( $cache_key, $dictionary, 'gml_translate', 300 );
    }

    public static function invalidate_cache( $source_lang, $target_lang ) {
        $source_lang = sanitize_key( $source_lang );
        $target_lang = sanitize_key( $target_lang );
        wp_cache_delete( 'gml_dict_' . $source_lang . '_' . $target_lang, 'gml_translate' );
        unset( self::$memory_cache[ $target_lang ], self::$dict_loaded[ $target_lang ] );
    }

    public function get_dictionary( $target_lang ) {
        $target_lang = sanitize_key( $target_lang );
        $this->maybe_preload_dictionary( sanitize_key( get_option( 'gml_source_lang', 'en' ) ), $target_lang );
        return self::$memory_cache[ $target_lang ] ?? [];
    }

    public function save_to_index( $hash, $source_text, $translated_text, $source_lang, $target_lang, $context_type = 'text', $status = 'auto' ) {
        global $wpdb;
        $table       = $wpdb->prefix . 'gml_index';
        $hash        = sanitize_text_field( $hash );
        $source_lang = sanitize_key( $source_lang );
        $target_lang = sanitize_key( $target_lang );
        $status      = $status === 'manual' ? 'manual' : 'auto';

        if ( $status === 'auto' ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM $table WHERE source_hash = %s AND source_lang = %s AND target_lang = %s",
                $hash,
                $source_lang,
                $target_lang
            ) );
            if ( $existing === 'manual' ) {
                return true;
            }
        }

        $saved = $wpdb->replace( $table, [
            'source_hash'     => $hash,
            'source_text'     => (string) $source_text,
            'source_lang'     => $source_lang,
            'target_lang'     => $target_lang,
            'translated_text' => (string) $translated_text,
            'context_type'    => sanitize_key( $context_type ) ?: 'text',
            'status'          => $status,
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        ] );
        if ( false === $saved ) {
            return false;
        }

        if ( isset( self::$memory_cache[ $target_lang ] ) ) {
            $clean = (string) $translated_text;
            self::$memory_cache[ $target_lang ][ $hash ] = strpos( $clean, '<' ) !== false
                ? wp_strip_all_tags( $clean )
                : $clean;
        }
        wp_cache_delete( 'gml_dict_' . $source_lang . '_' . $target_lang, 'gml_translate' );
        return true;
    }

    private function calculate_priority( $text, $context_type ) {
        if ( $context_type === 'seo_title' || $context_type === 'seo_meta' ) return 10;
        if ( $context_type === 'attribute' ) return 8;
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
        if ( $length < 50 ) return 7;
        if ( $length < 200 ) return 5;
        return 3;
    }
}
