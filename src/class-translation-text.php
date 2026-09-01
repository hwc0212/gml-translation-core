<?php
/** Technical-safe cleanup shared by provider responses and cached rendering. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Translation_Text {
    /** Strip provider-supplied markup without treating <40°C as an HTML tag. */
    public static function plain_text( $text ) {
        $tokens = [
            '<' => '__GML_TECHNICAL_LESS_THAN_8D9A__',
            '>' => '__GML_TECHNICAL_GREATER_THAN_8D9A__',
        ];
        $text = preg_replace_callback( '/[<>](?=\s*[+-]?\d)/u', static function( $match ) use ( $tokens ) {
            return $tokens[ $match[0] ];
        }, (string) $text );
        $text = wp_strip_all_tags( $text );
        return strtr( $text, array_flip( $tokens ) );
    }

    /**
     * Values made only of measurements or comparison limits do not need AI.
     * Keeping them byte-for-byte also prevents providers from changing product
     * specifications such as 90*45*30mm or <40°C.
     */
    public static function is_technical_only( $text ) {
        $text = trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $text === '' ) {
            return false;
        }

        $number = '[+-]?\d+(?:[.,]\d+)?';
        $unit   = '(?:%|°\s*[CFK]|px|em|rem|vh|vw|pt|pc|mm|cm|km|m|in|inch|ft|µm|μm|um|kg|mg|g|lb|oz|l|ml|kv|mv|v|ma|a|kw|mw|w|ghz|mhz|khz|hz|mpa|kpa|pa|bar|psi|rpm|db|nm)';

        if ( preg_match( '/^(?:<|>|≤|≥|~|≈)\s*' . $number . '\s*(?:' . $unit . ')?$/iu', $text ) ) {
            return true;
        }
        if ( preg_match( '/^' . $number . '\s*' . $unit . '$/iu', $text ) ) {
            return true;
        }
        return (bool) preg_match(
            '/^' . $number . '(?:\s*(?:x|×|\*)\s*' . $number . '){1,4}\s*(?:mm|cm|km|m|in|inch|ft|µm|μm|um)?$/iu',
            $text
        );
    }

    public static function clean_markdown_wrappers( $text ) {
        // Single inline asterisks and intraword underscores can be dimensions,
        // operators or model identifiers, not Markdown emphasis.
        $text = preg_replace( '/(?<![\p{L}\p{N}_*])(\*\*|__)(?=\S)(.+?)(?<=\S)\1(?![\p{L}\p{N}_*])/us', '$2', (string) $text );
        return preg_replace( '/^\*(\p{L}[^*\r\n]*)\*$/u', '$1', trim( $text ) );
    }
}
