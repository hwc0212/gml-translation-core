<?php
/**
 * Shared language/path helpers for both translation adapters.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Language_Utils {

    /**
     * Normalize language codes used in URL prefixes.
     *
     * Supports plain language codes and region variants such as zh-cn,
     * pt-br, en-gb. Underscores are accepted and normalized to hyphens.
     */
    public static function normalize_code( $code ) {
        $code = strtolower( trim( (string) $code ) );
        $code = str_replace( '_', '-', $code );
        $code = preg_replace( '/[^a-z0-9-]/', '', $code );
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code ) ) {
            return '';
        }
        return $code;
    }

    /**
     * Return configured language codes, optionally including the source code.
     */
    public static function configured_codes( $include_source = true, $enabled_only = false ) {
        $codes = [];
        if ( $include_source ) {
            $source = self::normalize_code( get_option( 'gml_source_lang', 'en' ) );
            if ( $source ) {
                $codes[] = $source;
            }
        }

        foreach ( (array) get_option( 'gml_languages', [] ) as $lang ) {
            if ( $enabled_only && isset( $lang['enabled'] ) && ! $lang['enabled'] ) {
                continue;
            }
            $code = self::normalize_code( $lang['code'] ?? '' );
            if ( $code ) {
                $codes[] = $code;
            }
        }

        $codes = array_values( array_unique( $codes ) );
        usort( $codes, static function( $a, $b ) {
            return strlen( $b ) <=> strlen( $a );
        } );
        return $codes;
    }

    /**
     * Return enabled target language codes only.
     */
    public static function enabled_target_codes() {
        return self::configured_codes( false, true );
    }

    /**
     * Build a safe regex alternation for language prefixes.
     */
    public static function language_pattern( array $codes ) {
        $codes = array_values( array_filter( array_map( [ __CLASS__, 'normalize_code' ], $codes ) ) );
        $codes = array_values( array_unique( $codes ) );
        usort( $codes, static function( $a, $b ) {
            return strlen( $b ) <=> strlen( $a );
        } );
        return implode( '|', array_map( static function( $code ) {
            return preg_quote( $code, '#' );
        }, $codes ) );
    }

    /**
     * Detect an enabled language prefix in a public request path.
     */
    public static function detect_prefix_from_path( $path, $enabled_only = true ) {
        $path = (string) $path;
        if ( $path === '' ) {
            return '';
        }
        $codes = self::configured_codes( true, $enabled_only );
        if ( class_exists( 'GML_URL_Helper' ) ) {
            return self::normalize_code( GML_URL_Helper::detect_language( $path, $codes ) );
        }

        $path    = '/' . ltrim( (string) strtok( $path, '?' ), '/' );
        $pattern = self::language_pattern( $codes );
        return $pattern && preg_match( '#^/(' . $pattern . ')(/|$)#i', $path, $m )
            ? self::normalize_code( $m[1] )
            : '';
    }

    /**
     * Strip a configured language prefix from a path.
     */
    public static function strip_prefix_from_path( $path, $enabled_only = false ) {
        $codes = self::configured_codes( true, $enabled_only );
        if ( class_exists( 'GML_URL_Helper' ) ) {
            return GML_URL_Helper::strip_language_prefix( $path, $codes );
        }

        $query = '';
        $parts = explode( '?', (string) $path, 2 );
        $path  = '/' . ltrim( $parts[0], '/' );
        if ( isset( $parts[1] ) ) {
            $query = '?' . $parts[1];
        }
        $pattern = self::language_pattern( $codes );
        if ( $pattern ) {
            $path = preg_replace( '#^/(' . $pattern . ')(/|$)#i', '/', $path, 1 );
        }

        $path = '/' . ltrim( $path, '/' );
        $path = preg_replace( '#/+#', '/', $path );
        return $path . $query;
    }

    /**
     * Whether the multilingual site output is enabled.
     */
    public static function multilingual_enabled() {
        if ( class_exists( 'GML_Translation_State' ) ) {
            return GML_Translation_State::multilingual_enabled();
        }
        return (bool) get_option( 'gml_translation_enabled', false );
    }
}
