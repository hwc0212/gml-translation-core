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
        $parsed = wp_parse_url( $url_or_path );
        $path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : (string) $url_or_path;
        $query  = is_array( $parsed ) && isset( $parsed['query'] ) ? $parsed['query'] : '';

        $path = self::strip_language_prefix( $path ?: '/', $languages );
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
