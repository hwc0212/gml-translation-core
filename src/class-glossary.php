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

    /**
     * Get all glossary rules.
     *
     * @return array [ [ 'source' => 'X', 'target' => 'Y', 'lang' => 'es'|'all', 'enabled' => true ], ... ]
     */
    public static function get_rules() {
        return get_option( 'gml_glossary_rules', [] );
    }

    /**
     * Save glossary rules.
     */
    public static function save_rules( $rules ) {
        $sanitized = [];
        foreach ( $rules as $rule ) {
            if ( empty( $rule['source'] ) ) continue;
            $sanitized[] = [
                'source'  => sanitize_text_field( $rule['source'] ),
                'target'  => sanitize_text_field( $rule['target'] ?? '' ),
                'lang'    => sanitize_text_field( $rule['lang'] ?? 'all' ),
                'enabled' => ! empty( $rule['enabled'] ),
            ];
        }
        update_option( 'gml_glossary_rules', $sanitized );
    }

    /**
     * Build glossary instruction string for the Gemini API prompt.
     *
     * @param string $target_lang Target language code
     * @return string Instruction text to append to system prompt, or empty string
     */
    public static function build_prompt_instruction( $target_lang ) {
        $rules = self::get_rules();
        if ( empty( $rules ) ) {
            return '';
        }

        $translations = [];
        foreach ( $rules as $rule ) {
            if ( empty( $rule['enabled'] ) ) continue;
            if ( empty( $rule['source'] ) ) continue;

            // Rule applies to this language or all languages
            $applies = ( $rule['lang'] === 'all' || $rule['lang'] === $target_lang );
            if ( ! $applies ) continue;

            if ( ! empty( $rule['target'] ) ) {
                $translations[] = '"' . $rule['source'] . '" → "' . $rule['target'] . '"';
            }
            // If target is empty, it's a "never translate" rule — already handled
            // by protected_terms, but we include it for completeness
        }

        if ( empty( $translations ) ) {
            return '';
        }

        return 'Glossary (MUST follow these exact translations): ' . implode( ', ', $translations ) . '. ';
    }
}
