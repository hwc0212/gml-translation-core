<?php
/**
 * URL helpers for WordPress installs at the domain root or in a subdirectory.
 *
 * All internal translation paths are site-relative. The home path is removed
 * before language prefixes are inspected and restored only when a public URL
 * is built.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_URL_Helper {

    public static function get_home_path() {
        $path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( ! is_string( $path ) ) {
            return '';
        }

        $path = '/' . trim( $path, '/' );
        return $path === '/' ? '' : $path;
    }

    public static function strip_home_path( $path ) {
        $path = is_string( $path ) && $path !== '' ? $path : '/';
        if ( $path[0] !== '/' ) {
            $path = '/' . $path;
        }

        $home_path = self::get_home_path();
        if ( $home_path !== '' && ( $path === $home_path || strpos( $path, $home_path . '/' ) === 0 ) ) {
            $path = substr( $path, strlen( $home_path ) );
        }

        return '/' . ltrim( $path ?: '/', '/' );
    }

    public static function get_request_path() {
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        return self::strip_home_path( $path );
    }

    public static function detect_language( $path, $languages ) {
        $languages = array_values( array_filter( array_unique( (array) $languages ) ) );
        if ( empty( $languages ) ) {
            return null;
        }

        $path    = self::strip_home_path( strtok( (string) $path, '?' ) );
        $pattern = implode( '|', array_map( 'preg_quote', $languages ) );
        if ( preg_match( '#^/(' . $pattern . ')(/|$)#i', $path, $matches ) ) {
            return strtolower( $matches[1] );
        }

        return null;
    }

    public static function strip_language_prefix( $path, $languages ) {
        $query = '';
        $parts = explode( '?', (string) $path, 2 );
        $path  = self::strip_home_path( $parts[0] );
        if ( isset( $parts[1] ) ) {
            $query = '?' . $parts[1];
        }

        $languages = array_values( array_filter( array_unique( (array) $languages ) ) );
        if ( ! empty( $languages ) ) {
            $pattern = implode( '|', array_map( 'preg_quote', $languages ) );
            $path    = preg_replace( '#^/(' . $pattern . ')(/|$)#i', '/', $path, 1 );
        }

        $path = '/' . ltrim( $path ?: '/', '/' );
        return preg_replace( '#/+#', '/', $path ) . $query;
    }

    public static function get_language_url( $url_or_path, $lang, $source_lang, $languages ) {
        if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $lang ) ) {
            return self::get_external_language_url( $url_or_path, $lang );
        }

        $parsed = wp_parse_url( $url_or_path );
        $path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : (string) $url_or_path;
        $query  = is_array( $parsed ) && isset( $parsed['query'] ) ? $parsed['query'] : '';

        $local_languages = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::local_configured_codes( true, false )
            : $languages;
        $path = self::strip_language_prefix( $path ?: '/', $local_languages );
        $path = strtok( $path, '?' );
        $path = '/' . ltrim( $path, '/' );
        if ( $lang !== $source_lang ) {
            $path = '/' . $lang . $path;
        }

        $url = home_url( $path );
        if ( $query !== '' ) {
            $url .= '?' . $query;
        }
        return $url;
    }

    /**
     * Build a switcher URL for a language hosted by another website.
     *
     * The default same-path mode maps /products/item/ to the configured
     * external base URL. Homepage mode always links to the external homepage.
     * Query strings and fragments are intentionally not copied across domains.
     */
    public static function get_external_language_url( $url_or_path, $lang ) {
        if ( ! class_exists( 'GML_Language_Utils' ) ) {
            return '';
        }

        $language = GML_Language_Utils::get_language_config( $lang );
        $base_url = GML_Language_Utils::get_external_site_url( $language );
        if ( ! $base_url ) {
            return '';
        }

        if ( GML_Language_Utils::get_external_path_mode( $language ) === GML_Language_Utils::EXTERNAL_PATH_HOMEPAGE ) {
            $mapped_url = $base_url;
        } else {
            $parsed = wp_parse_url( $url_or_path );
            $path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : (string) $url_or_path;
            $path   = self::strip_language_prefix(
                $path ?: '/',
                GML_Language_Utils::local_configured_codes( true, false )
            );
            $path       = strtok( self::strip_home_path( $path ), '?' );
            $mapped_url = untrailingslashit( $base_url ) . '/' . ltrim( $path ?: '/', '/' );
        }

        /**
         * Filter a mapped external language URL for installations with manual
         * slug mappings. The filtered URL must remain on the configured origin.
         */
        $filtered = apply_filters( 'gml_external_language_url', $mapped_url, $lang, $url_or_path, $language );
        return self::validate_external_mapped_url( $filtered, $base_url ) ?: $mapped_url;
    }

    /**
     * Return an external alternate URL suitable for hreflang and sitemap use.
     * Homepage-only external sites are not advertised as equivalents of every
     * inner page because that would send conflicting SEO signals.
     */
    public static function get_external_hreflang_url( $url_or_path, $lang ) {
        if ( ! class_exists( 'GML_Language_Utils' ) ) {
            return '';
        }
        $language = GML_Language_Utils::get_language_config( $lang );
        if (
            GML_Language_Utils::get_external_path_mode( $language ) === GML_Language_Utils::EXTERNAL_PATH_HOMEPAGE
            && ! self::is_home_url( $url_or_path )
        ) {
            return '';
        }
        return self::get_external_language_url( $url_or_path, $lang );
    }

    public static function is_home_url( $url_or_path ) {
        $parsed = wp_parse_url( $url_or_path );
        $path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : (string) $url_or_path;
        $codes  = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::local_configured_codes( true, false )
            : [];
        $path = self::strip_language_prefix( $path ?: '/', $codes );
        $path = strtok( self::strip_home_path( $path ), '?' );
        return trim( (string) $path, '/' ) === '';
    }

    private static function validate_external_mapped_url( $url, $base_url ) {
        $url       = esc_url_raw( (string) $url, [ 'https' ] );
        $parts     = wp_parse_url( $url );
        $base      = wp_parse_url( $base_url );
        $port      = static function( $parsed ) {
            return isset( $parsed['port'] ) ? (int) $parsed['port'] : 443;
        };
        if (
            ! is_array( $parts )
            || ! is_array( $base )
            || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
            || strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $base['host'] ?? '' ) )
            || $port( $parts ) !== $port( $base )
            || isset( $parts['user'] )
            || isset( $parts['pass'] )
        ) {
            return '';
        }
        return $url;
    }

    public static function to_root_relative_path( $relative_path ) {
        $parts = wp_parse_url( home_url( '/' . ltrim( $relative_path, '/' ) ) );
        if ( ! is_array( $parts ) ) {
            return '/';
        }
        $result = (string) ( $parts['path'] ?? '/' );
        if ( ! empty( $parts['query'] ) ) {
            $result .= '?' . $parts['query'];
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $result .= '#' . $parts['fragment'];
        }
        return $result !== '' ? $result : '/';
    }

    /**
     * Return the path/query/fragment for an absolute URL inside this WordPress
     * installation, or null for another origin or a path outside home_url().
     */
    public static function internal_absolute_path( $url ) {
        $url = (string) $url;
        if ( strpos( $url, '//' ) === 0 ) {
            $home_scheme = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ?: 'https';
            $url = $home_scheme . ':' . $url;
        }
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            return null;
        }

        $home = wp_parse_url( home_url( '/' ) );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $home ) || ! is_array( $parts ) ) {
            return null;
        }

        $home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
        $url_scheme  = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $home_host   = strtolower( (string) ( $home['host'] ?? '' ) );
        $url_host    = strtolower( (string) ( $parts['host'] ?? '' ) );
        $default_port = static function( $scheme ) {
            return $scheme === 'https' ? 443 : 80;
        };
        $home_port = isset( $home['port'] ) ? (int) $home['port'] : $default_port( $home_scheme );
        $url_port  = isset( $parts['port'] ) ? (int) $parts['port'] : $default_port( $url_scheme );
        if ( $home_scheme !== $url_scheme || $home_host === '' || $home_host !== $url_host || $home_port !== $url_port ) {
            return null;
        }

        $home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );
        $path      = (string) ( $parts['path'] ?? '/' );
        if ( $path === '' ) {
            $path = '/';
        }
        if ( $home_path !== '' && $home_path !== '/' && $path !== $home_path && strpos( $path, $home_path . '/' ) !== 0 ) {
            return null;
        }
        if ( isset( $parts['query'] ) && $parts['query'] !== '' ) {
            $path .= '?' . $parts['query'];
        }
        if ( isset( $parts['fragment'] ) && $parts['fragment'] !== '' ) {
            $path .= '#' . $parts['fragment'];
        }
        return $path;
    }
}
