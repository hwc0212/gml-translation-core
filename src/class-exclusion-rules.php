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
	const MAX_RULES        = 500;
	const MAX_VALUE_LENGTH = 512;
	const MAX_REGEX_LENGTH = 256;

    /** @var array Cached rules from options */
    private $rules = [];

    public function __construct() {
        $rules = get_option( 'gml_exclusion_rules', [] );
        $this->rules = is_array( $rules ) ? array_slice( $rules, 0, self::MAX_RULES ) : [];
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
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        }
		$request_uri = substr( (string) $request_uri, 0, 2048 );

        // Strip language prefix for matching
        $path = strtok( $request_uri, '?' );
        $all_langs = $this->get_all_language_codes();
        if ( ! empty( $all_langs ) ) {
            $pat  = implode( '|', array_map( static function( $code ) {
				return preg_quote( $code, '#' );
			}, $all_langs ) );
            $path = preg_replace( '#^/(' . $pat . ')(/|$)#', '/', $path );
        }
        $path = '/' . ltrim( $path, '/' );

        foreach ( $this->rules as $rule ) {
            if ( ! isset( $rule['type'], $rule['value'] ) ) continue;
            if ( empty( $rule['enabled'] ) ) continue;

            // Only check URL-type rules here
            $value = self::truncate( sanitize_text_field( $rule['value'] ), self::MAX_VALUE_LENGTH );
            switch ( sanitize_key( $rule['type'] ) ) {
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
					if ( self::safe_regex_match( $value, $path ) ) return true;
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
            if ( sanitize_key( $rule['type'] ) === 'selector' && ! empty( $rule['value'] ) ) {
				$value = self::truncate( sanitize_text_field( $rule['value'] ), 102 );
				if ( preg_match( '/^[.#][A-Za-z_][A-Za-z0-9_-]{0,100}$/', $value ) ) {
					$selectors[] = $value;
				}
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
		$allowed_types = [ 'url_is', 'url_starts', 'url_contains', 'url_regex', 'selector' ];
        foreach ( array_slice( is_array( $rules ) ? $rules : [], 0, self::MAX_RULES ) as $rule ) {
            if ( ! is_array( $rule ) || empty( $rule['type'] ) || empty( $rule['value'] ) ) continue;
			$type  = sanitize_key( $rule['type'] );
			$value = self::truncate( sanitize_text_field( $rule['value'] ), self::MAX_VALUE_LENGTH );
			if ( ! in_array( $type, $allowed_types, true ) || $value === '' ) continue;
			if ( $type === 'selector' && ! preg_match( '/^[.#][A-Za-z_][A-Za-z0-9_-]{0,100}$/', $value ) ) continue;
			if ( $type === 'url_regex' && ! self::valid_regex( $value ) ) continue;
            $sanitized[] = [
                'type'    => $type,
                'value'   => $value,
                'enabled' => ! empty( $rule['enabled'] ),
				'note'    => self::truncate( sanitize_text_field( $rule['note'] ?? '' ), 200 ),
            ];
        }
        return update_option( 'gml_exclusion_rules', $sanitized, false );
    }

    private function get_all_language_codes() {
        $source = get_option( 'gml_source_lang', 'en' );
        $codes  = [ $source ];
        foreach ( get_option( 'gml_languages', [] ) as $lang ) {
			if ( ! empty( $lang['code'] ) ) $codes[] = $lang['code'];
        }
		$codes = array_map( static function( $code ) {
			return class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $code ) : sanitize_key( $code );
		}, $codes );
        return array_values( array_filter( array_unique( $codes ) ) );
    }

	private static function valid_regex( $pattern ) {
		return strlen( $pattern ) <= self::MAX_REGEX_LENGTH && @preg_match( $pattern, '' ) !== false;
	}

	private static function safe_regex_match( $pattern, $value ) {
		if ( ! self::valid_regex( $pattern ) ) return false;
		$previous = ini_get( 'pcre.backtrack_limit' );
		@ini_set( 'pcre.backtrack_limit', '10000' );
		$result = @preg_match( $pattern, substr( (string) $value, 0, 2048 ) ) === 1;
		if ( $previous !== false ) @ini_set( 'pcre.backtrack_limit', (string) $previous );
		return $result;
	}

	private static function truncate( $value, $length ) {
		$value = trim( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
