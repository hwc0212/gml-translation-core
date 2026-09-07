<?php
/**
 * Translation page-cache lifecycle.
 *
 * Uses a generation token instead of enumerating transient rows. This also
 * invalidates Redis/Memcached-backed transients, where deleting rows directly
 * from wp_options has no effect.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Page_Cache {

    const GENERATION_OPTION = 'gml_page_cache_generation';

    /** @var bool Prevent repeated generation bumps during one request. */
    private static $invalidated = false;

    public function __construct() {
        add_action( 'save_post', [ __CLASS__, 'invalidate_for_post' ], 20, 2 );
        add_action( 'deleted_post', [ __CLASS__, 'invalidate' ] );
        add_action( 'trashed_post', [ __CLASS__, 'invalidate' ] );
        add_action( 'untrashed_post', [ __CLASS__, 'invalidate' ] );
        add_action( 'created_term', [ __CLASS__, 'invalidate' ] );
        add_action( 'edited_term', [ __CLASS__, 'invalidate' ] );
        add_action( 'delete_term', [ __CLASS__, 'invalidate' ] );
        add_action( 'wp_update_nav_menu', [ __CLASS__, 'invalidate' ] );
        add_action( 'customize_save_after', [ __CLASS__, 'invalidate' ] );
        add_action( 'switch_theme', [ __CLASS__, 'invalidate' ] );
        add_action( 'updated_option', [ __CLASS__, 'maybe_invalidate_for_option' ], 20, 3 );
    }

    public static function invalidate_for_post( $post_id, $post = null ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        self::invalidate();
    }

    public static function maybe_invalidate_for_option( $option, $old_value = null, $value = null ) {
        if ( $option === self::GENERATION_OPTION ) {
            return;
        }

        $global_options = [
            'blogname',
            'blogdescription',
            'page_on_front',
            'page_for_posts',
            'permalink_structure',
            'show_on_front',
            'sidebars_widgets',
            'nav_menu_options',
            'woocommerce_shop_page_id',
            'gml_seo',
            'gml_source_lang',
            'gml_languages',
            'gml_protected_terms',
            'gml_exclusion_rules',
            'gml_exclude_selectors',
        ];
        $global_prefixes = [ 'theme_mods_', 'widget_', 'generate_', 'gml_switcher_' ];

        if ( in_array( $option, $global_options, true ) ) {
            self::invalidate();
            return;
        }
        foreach ( $global_prefixes as $prefix ) {
            if ( strpos( $option, $prefix ) === 0 ) {
                self::invalidate();
                return;
            }
        }
    }

    /**
     * Invalidate every translated page without flushing unrelated object cache.
     */
    public static function invalidate() {
        if ( self::$invalidated ) {
            return;
        }
        self::force_invalidate();
    }

    /**
     * Rotate the cache namespace even if this request invalidated it earlier.
     *
     * Uninstall cleanup uses this path because persistent object-cache entries
     * cannot be portably enumerated by transient-name prefix.
     */
    public static function force_invalidate() {
        global $wpdb;

        // Reconcile a persistent-cache value that may be ahead of the database,
        // then increment atomically. This prevents both concurrent lost updates
        // and namespace reuse after a database restore with stale Redis data.
        $observed = max( 0, (int) get_option( self::GENERATION_OPTION, 0 ) );
        if ( $observed < 1 ) {
            $observed = wp_rand( 1000000, 2147480000 );
        }
        $update = static function() use ( $wpdb, $observed ) {
            return $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value=GREATEST(CAST(option_value AS UNSIGNED),%d)+1 WHERE option_name=%s",
                $observed,
                self::GENERATION_OPTION
            ) );
        };
        $updated = $update();
        if ( $updated === 0 ) {
            $inserted = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",
                self::GENERATION_OPTION,
                (string) $observed
            ) );
            if ( $inserted === false ) return false;
            $updated = $update();
        }
        if ( $updated !== 1 ) {
            return false;
        }

        self::$invalidated = true;
        wp_cache_delete( self::GENERATION_OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return true;
    }

    public static function generation() {
        $generation = (int) get_option( self::GENERATION_OPTION, 0 );
        if ( $generation < 1 ) {
            $generation = wp_rand( 1000000, 2147480000 );
            update_option( self::GENERATION_OPTION, $generation, false );
        }
        return $generation;
    }

    /**
     * Build a stable cache key while discarding advertising attribution params.
     * Functional query parameters remain part of the key.
     */
    public static function key( $target_lang, $request_uri = null ) {
        if ( $request_uri === null ) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        }

        $normalized = self::normalize_request_uri( $request_uri );
        return 'gml_page_' . md5(
            GML_VERSION . '|' . self::generation() . '|' . $target_lang . '|' . $normalized
        );
    }

    public static function normalize_request_uri( $request_uri ) {
        $parts = wp_parse_url( (string) $request_uri );
        if ( $parts === false ) {
            return '/';
        }

        $path  = isset( $parts['path'] ) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            parse_str( $parts['query'], $query );
        }

        foreach ( array_keys( $query ) as $key ) {
            $normalized_key = strtolower( (string) $key );
            if (
                strpos( $normalized_key, 'utm_' ) === 0 ||
                in_array(
                    $normalized_key,
                    [ 'gclid', 'dclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'mc_cid', 'mc_eid', 'gad_source', 'gad_campaignid' ],
                    true
                )
            ) {
                unset( $query[ $key ] );
            }
        }

        if ( empty( $query ) ) {
            return $path;
        }

        ksort( $query );
        return $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Tracking values may be copied into forms or analytics markup. Such a
     * request must bypass shared HTML cache even though these parameters are
     * intentionally omitted from the cache key to prevent key explosion.
     */
    public static function has_tracking_parameters( $request_uri ) {
        $parts = wp_parse_url( (string) $request_uri );
        if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
            return false;
        }

        $query = [];
        parse_str( $parts['query'], $query );
        foreach ( array_keys( $query ) as $key ) {
            $normalized_key = strtolower( (string) $key );
            if (
                strpos( $normalized_key, 'utm_' ) === 0 ||
                in_array(
                    $normalized_key,
                    [ 'gclid', 'dclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'mc_cid', 'mc_eid', 'gad_source', 'gad_campaignid' ],
                    true
                )
            ) {
                return true;
            }
        }
        return false;
    }
}
