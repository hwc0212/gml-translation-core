<?php
/**
 * GML Glossary — Translation rules for consistent terminology
 *
 * Extends the existing "protected terms" (never translate) with:
 *  - "Always translate X as Y" rules per target language
 *  - Global glossary rules (apply to all languages)
 *
 * These rules are injected into the Gemini API prompt to ensure
 * consistent translations across the entire site.
 *
 * @package GML_Translation_Core
 * @since 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Glossary {
	const MAX_RULES        = 500;
	const MAX_PROMPT_RULES = 100;
	const MAX_PROMPT_BYTES = 8192;
	const MAX_TERM_LENGTH  = 200;

    /**
     * Get all glossary rules.
     *
     * @return array [ [ 'source' => 'X', 'target' => 'Y', 'lang' => 'es'|'all', 'enabled' => true ], ... ]
     */
    public static function get_rules() {
        $rules = get_option( 'gml_glossary_rules', [] );
        return is_array( $rules ) ? array_slice( $rules, 0, self::MAX_RULES ) : [];
    }

    /**
     * Save glossary rules.
     */
    public static function save_rules( $rules ) {
        $sanitized = [];
        foreach ( array_slice( is_array( $rules ) ? $rules : [], 0, self::MAX_RULES ) as $rule ) {
            if ( ! is_array( $rule ) || empty( $rule['source'] ) ) continue;
            $source = self::truncate( sanitize_text_field( $rule['source'] ), self::MAX_TERM_LENGTH );
            $target = self::truncate( sanitize_text_field( $rule['target'] ?? '' ), self::MAX_TERM_LENGTH );
            $lang   = sanitize_key( $rule['lang'] ?? 'all' );
            if ( $source === '' ) continue;
            if ( $lang !== 'all' && class_exists( 'GML_Language_Utils' ) ) {
                $lang = GML_Language_Utils::normalize_code( $lang );
            }
            if ( $lang === '' ) $lang = 'all';
            $sanitized[] = [
                'source'  => $source,
                'target'  => $target,
                'lang'    => $lang,
                'enabled' => ! empty( $rule['enabled'] ),
            ];
        }
        return update_option( 'gml_glossary_rules', $sanitized, false );
    }

    /**
     * Build glossary instruction string for the Gemini API prompt.
     *
     * @param string $target_lang Target language code
     * @return string Instruction text to append to system prompt, or empty string
     */
    public static function build_prompt_instruction( $target_lang, $source_text = '' ) {
        $rules = self::get_rules();
        if ( empty( $rules ) ) {
            return '';
        }

        $target_lang  = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::normalize_code( $target_lang )
            : sanitize_key( $target_lang );
        $translations = [];
        $bytes        = 0;
        foreach ( $rules as $rule ) {
            if ( count( $translations ) >= self::MAX_PROMPT_RULES ) break;
            if ( empty( $rule['enabled'] ) ) continue;
            if ( empty( $rule['source'] ) ) continue;
            if ( $source_text !== '' && ! self::contains( $source_text, (string) $rule['source'] ) ) continue;

            // Rule applies to this language or all languages
            $rule_lang = sanitize_key( $rule['lang'] ?? 'all' );
            if ( $rule_lang !== 'all' && class_exists( 'GML_Language_Utils' ) ) {
                $rule_lang = GML_Language_Utils::normalize_code( $rule_lang );
            }
            $applies = ( $rule_lang === 'all' || $rule_lang === $target_lang );
            if ( ! $applies ) continue;

            if ( ! empty( $rule['target'] ) ) {
                $encoded = wp_json_encode(
                    [ (string) $rule['source'] => (string) $rule['target'] ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if ( ! is_string( $encoded ) || $bytes + strlen( $encoded ) + 3 > self::MAX_PROMPT_BYTES ) break;
                $translations[] = '- ' . $encoded;
                $bytes += strlen( $encoded ) + 3;
            }
            // If target is empty, it's a "never translate" rule — already handled
            // by protected_terms, but we include it for completeness
        }

        if ( empty( $translations ) ) {
            return '';
        }

        return "Glossary (MUST follow these exact translations):\n" . implode( "\n", $translations ) . "\n";
    }

	private static function truncate( $value, $length ) {
		$value = trim( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private static function contains( $haystack, $needle ) {
		if ( $needle === '' ) return false;
		return function_exists( 'mb_stripos' )
			? mb_stripos( (string) $haystack, (string) $needle, 0, 'UTF-8' ) !== false
			: stripos( (string) $haystack, (string) $needle ) !== false;
	}
}
