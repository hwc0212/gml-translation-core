<?php
/**
 * GML Exclusion Rules — Flexible translation exclusion by URL, CSS selector, or content.
 *
 * Allows admins to define rules that prevent specific pages or elements
 * from being translated. Similar to Weglot's exclusion rules feature.
 *
 * Rule types:
 *  - url_is:         Exact URL match
 *  - url_starts:     URL starts with prefix
 *  - url_contains:   URL contains substring
 *  - url_regex:      URL matches regex pattern
 *  - selector:       CSS selector (class or ID) to exclude from translation
 *
 * @package GML_Translate
 * @since 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Exclusion_Rules {

    /** @var array Cached rules from options */
    private $rules = [];

    public function __construct() {
        $this->rules = get_option( 'gml_exclusion_rules', [] );
    }

    /**
     * Check if the current page should be excluded from translation.
     *
     * @param string $request_uri The current REQUEST_URI
     * @return bool True if the page should NOT be translated
     */
    public function is_page_excluded( $request_uri = '' ) {
        if ( empty( $this->rules ) ) {
            return false;
        }

        if ( ! $request_uri ) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        }

        // Strip language prefix for matching
        $path = strtok( $request_uri, '?' );
        $all_langs = $this->get_all_language_codes();
        if ( ! empty( $all_langs ) ) {
            $pat  = implode( '|', array_map( 'preg_quote', $all_langs ) );
            $path = preg_replace( '#^/(' . $pat . ')(/|$)#', '/', $path );
        }
        $path = '/' . ltrim( $path, '/' );

        foreach ( $this->rules as $rule ) {
            if ( ! isset( $rule['type'], $rule['value'] ) ) continue;
            if ( empty( $rule['enabled'] ) ) continue;

            // Only check URL-type rules here
            $value = $rule['value'];
            switch ( $rule['type'] ) {
                case 'url_is':
                    $compare = '/' . ltrim( trim( $value ), '/' );
                    $compare = rtrim( $compare, '/' ) . '/';
                    $path_norm = rtrim( $path, '/' ) . '/';
                    if ( $path_norm === $compare ) return true;
                    break;

                case 'url_starts':
                    if ( strpos( $path, $value ) === 0 ) return true;
                    break;

                case 'url_contains':
                    if ( strpos( $path, $value ) !== false ) return true;
                    break;

                case 'url_regex':
                    if ( @preg_match( $value, $path ) ) return true;
                    break;
            }
        }

        return false;
    }

    /**
     * Get CSS selectors that should be excluded from translation.
     * These are merged with the existing gml_exclude_selectors option.
     *
     * @return array Array of CSS selectors
     */
    public function get_excluded_selectors() {
        $selectors = [];
        foreach ( $this->rules as $rule ) {
            if ( ! isset( $rule['type'], $rule['value'] ) ) continue;
            if ( empty( $rule['enabled'] ) ) continue;
            if ( $rule['type'] === 'selector' && ! empty( $rule['value'] ) ) {
                $selectors[] = $rule['value'];
            }
        }
        return $selectors;
    }

    /**
     * Get all rules.
     */
    public function get_rules() {
        return $this->rules;
    }

    /**
     * Save rules.
     */
    public static function save_rules( $rules ) {
        $sanitized = [];
        foreach ( $rules as $rule ) {
            if ( empty( $rule['type'] ) || empty( $rule['value'] ) ) continue;
            $sanitized[] = [
                'type'    => sanitize_text_field( $rule['type'] ),
                'value'   => sanitize_text_field( $rule['value'] ),
                'enabled' => ! empty( $rule['enabled'] ),
                'note'    => sanitize_text_field( $rule['note'] ?? '' ),
            ];
        }
        update_option( 'gml_exclusion_rules', $sanitized );
    }

    private function get_all_language_codes() {
        $source = get_option( 'gml_source_lang', 'en' );
        $codes  = [ $source ];
        foreach ( get_option( 'gml_languages', [] ) as $lang ) {
            $codes[] = $lang['code'];
        }
        return array_unique( $codes );
    }
}
