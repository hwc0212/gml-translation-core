<?php
/** Technical-safe cleanup shared by provider responses and cached rendering. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Translation_Text {
    public static function clean_markdown_wrappers( $text ) {
        // Single inline asterisks and intraword underscores can be dimensions,
        // operators or model identifiers, not Markdown emphasis.
        $text = preg_replace( '/(?<![\p{L}\p{N}_*])(\*\*|__)(?=\S)(.+?)(?<=\S)\1(?![\p{L}\p{N}_*])/us', '$2', (string) $text );
        return preg_replace( '/^\*(\p{L}[^*\r\n]*)\*$/u', '$1', trim( $text ) );
    }
}
