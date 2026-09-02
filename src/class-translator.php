<?php
/**
 * Shared translation memory lookup and atomic queue enqueue service.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-translation-text.php';

class GML_Translation_Translator {
	const MAX_SOURCE_BYTES = 32768;

    private static $memory_cache = [];
	private static $known_missing = [];
	private static $dict_loaded   = [];

    public function translate( $parsed, $target_lang ) {
        return $this->translate_nodes( $parsed, $target_lang, false );
    }

    /** Discovery queues text but never changes the AI worker pause state. */
    public function discover( $parsed, $target_lang ) {
        return $this->translate_nodes( $parsed, $target_lang, true );
    }

    private function translate_nodes( $parsed, $target_lang, $discovery ) {
        global $wpdb;
        $source_lang  = sanitize_key( get_option( 'gml_source_lang', 'en' ) );
        $target_lang  = sanitize_key( $target_lang );
        $nodes        = is_array( $parsed['nodes'] ?? null ) ? $parsed['nodes'] : [];
        $replacements = [];
        if ( empty( $nodes ) || $target_lang === '' ) {
            $parsed['replacements'] = [];
            return $parsed;
        }

        $unique = [];
        foreach ( $nodes as $item ) {
            $hash = sanitize_text_field( $item['hash'] ?? '' );
            $text = (string) ( $item['text'] ?? '' );
            if ( preg_match( '/^[a-f0-9]{32}$/', $hash ) && $text !== '' && ! isset( $unique[ $hash ] ) ) {
                $unique[ $hash ] = [
                    'text'         => $text,
                    'context_type' => sanitize_key( $item['context_type'] ?? 'text' ) ?: 'text',
                ];
            }
        }

		$dictionary = $this->load_dictionary_for_hashes( $source_lang, $target_lang, array_keys( $unique ) );
        $uncached   = [];
        foreach ( $unique as $hash => $item ) {
            if ( isset( $dictionary[ $hash ] ) ) {
                $replacements[ $item['text'] ] = $dictionary[ $hash ];
            } else {
                $uncached[ $hash ] = $item;
            }
        }

        if ( $uncached && $this->ai_translation_available()
            && ( ! get_option( 'gml_translation_paused', false ) || $discovery )
            && ! is_array( get_option( 'gml_translation_circuit_breaker', false ) ) ) {
            $this->enqueue_missing( $uncached, $source_lang, $target_lang );
        }

        $parsed['replacements'] = $replacements;
        return $parsed;
    }

    public static function enqueue_lock_name( $source_lang, $target_lang ) {
        global $wpdb;
        return 'gml-enqueue-' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $source_lang . ':' . $target_lang );
    }

    private function enqueue_missing( array $uncached, $source_lang, $target_lang ) {
        global $wpdb;
        $inserted = 0;
        $lock = self::enqueue_lock_name( $source_lang, $target_lang );
        // Legacy queues lack a unique key. Never wait on a competing page request.
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== 1 ) return;
        try {
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
				if ( strlen( $item['text'] ) > self::MAX_SOURCE_BYTES ) {
					continue;
				}
                // Rechecking inside the lock also protects unchanged legacy tables.
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
                $inserted += max( 0, (int) $wpdb->rows_affected );
            }
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
        if ( $inserted ) {
            if ( class_exists( 'GML_Translation_Readiness' ) ) GML_Translation_Readiness::clear_cache();
            if ( class_exists( 'GML_Page_Cache' ) ) GML_Page_Cache::invalidate();
        }
    }

    protected function ai_translation_available() {
        return class_exists( 'GML_Translation_State' ) && GML_Translation_State::ai_available();
    }

	private function load_dictionary_for_hashes( $source_lang, $target_lang, array $hashes ) {
		global $wpdb;
		$pair = self::pair_key( $source_lang, $target_lang );
		if ( ! isset( self::$memory_cache[ $pair ] ) ) self::$memory_cache[ $pair ] = [];
		if ( ! isset( self::$known_missing[ $pair ] ) ) self::$known_missing[ $pair ] = [];

		$missing = [];
		foreach ( array_values( array_unique( $hashes ) ) as $hash ) {
			if ( ! isset( self::$memory_cache[ $pair ][ $hash ] ) && ! isset( self::$known_missing[ $pair ][ $hash ] ) ) {
				$missing[] = $hash;
			}
		}
		if ( empty( $missing ) ) return self::$memory_cache[ $pair ];

		$table = $wpdb->prefix . 'gml_index';
		foreach ( array_chunk( $missing, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT source_hash, translated_text FROM $table
				 WHERE source_hash IN ($placeholders)
				 AND source_lang = %s AND target_lang = %s AND status IN ('auto','manual')",
				array_merge( $chunk, [ $source_lang, $target_lang ] )
			) );
			foreach ( (array) $rows as $row ) {
				$translated = (string) $row->translated_text;
				self::$memory_cache[ $pair ][ $row->source_hash ] = strpos( $translated, '<' ) !== false
					? GML_Translation_Text::plain_text( $translated )
					: $translated;
			}
			foreach ( $chunk as $hash ) {
				if ( ! isset( self::$memory_cache[ $pair ][ $hash ] ) ) self::$known_missing[ $pair ][ $hash ] = true;
			}
		}
		return self::$memory_cache[ $pair ];
	}

    public static function invalidate_cache( $source_lang, $target_lang ) {
        $source_lang = sanitize_key( $source_lang );
        $target_lang = sanitize_key( $target_lang );
        wp_cache_delete( 'gml_dict_' . $source_lang . '_' . $target_lang, 'gml_translate' );
		$pair = self::pair_key( $source_lang, $target_lang );
        unset( self::$memory_cache[ $pair ], self::$known_missing[ $pair ], self::$dict_loaded[ $pair ] );
    }

    public function get_dictionary( $target_lang ) {
		global $wpdb;
		$source_lang = sanitize_key( get_option( 'gml_source_lang', 'en' ) );
        $target_lang = sanitize_key( $target_lang );
		$pair = self::pair_key( $source_lang, $target_lang );
		if ( empty( self::$dict_loaded[ $pair ] ) ) {
			$table = $wpdb->prefix . 'gml_index';
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT source_hash, translated_text FROM $table
				 WHERE source_lang = %s AND target_lang = %s AND status IN ('auto','manual')",
				$source_lang,
				$target_lang
			) );
			self::$memory_cache[ $pair ] = [];
			foreach ( (array) $rows as $row ) {
				$translated = (string) $row->translated_text;
				self::$memory_cache[ $pair ][ $row->source_hash ] = strpos( $translated, '<' ) !== false
					? GML_Translation_Text::plain_text( $translated )
					: $translated;
			}
			self::$dict_loaded[ $pair ] = true;
		}
        return self::$memory_cache[ $pair ] ?? [];
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
                if ( class_exists( 'GML_Resource_Readiness' ) ) GML_Resource_Readiness::translation_changed( $hash, $target_lang );
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

		$pair = self::pair_key( $source_lang, $target_lang );
        if ( isset( self::$memory_cache[ $pair ] ) ) {
            $clean = (string) $translated_text;
			self::$memory_cache[ $pair ][ $hash ] = strpos( $clean, '<' ) !== false
                ? GML_Translation_Text::plain_text( $clean )
                : $clean;
			unset( self::$known_missing[ $pair ][ $hash ] );
        }
        wp_cache_delete( 'gml_dict_' . $source_lang . '_' . $target_lang, 'gml_translate' );
        if ( class_exists( 'GML_Resource_Readiness' ) ) GML_Resource_Readiness::translation_changed( $hash, $target_lang );
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

	private static function pair_key( $source_lang, $target_lang ) {
		return sanitize_key( $source_lang ) . '>' . sanitize_key( $target_lang );
	}
}
