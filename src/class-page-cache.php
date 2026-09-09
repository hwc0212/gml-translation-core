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
    const CLUSTER_PREFIX = 'gml_cache_cluster_dirty_';
    const URL_PREFIX = 'gml_cache_cluster_urls_';

    /** Exact local members, including ineligible targets whose cached HTML may survive. */
    public static function cluster_urls( GML_Resource_Identity $resource ) {
        $source = $resource->get_source_url();
        if ( $source === '' ) return [];
        $languages = (array) get_option( 'gml_languages', [] );
        $source_lang = get_option( 'gml_source_lang', 'en' );
        $urls = [ $source ];
        foreach ( $languages as $language ) {
            if ( ! is_array( $language ) || GML_Language_Utils::is_external_language( $language ) ) continue;
            $code = GML_Language_Utils::normalize_code( $language['code'] ?? '' );
            if ( $code !== '' ) $urls[] = GML_URL_Helper::get_language_url( $source, $code, $source_lang, $languages );
        }
        return array_values( array_unique( array_filter( $urls ) ) );
    }

    /** Called inside the resource transaction; retain old URLs across slug/language changes. */
    public static function remember_cluster( $id, GML_Resource_Identity $resource ) {
        global $wpdb;
        if ( (int) $id < 1 ) return false;
        $name = self::URL_PREFIX . (int) $id;
        if ( false === $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'[]','no')", $name
        ) ) ) return false;
        $old = $wpdb->get_var( $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $name) );
        $urls = json_decode( (string) $old, true );
        if ( ! is_array( $urls ) || $wpdb->last_error !== '' ) return false;
        $urls = array_values( array_unique( array_merge( $urls, self::cluster_urls( $resource ) ) ) );
        // Never silently discard an old URL that still needs maintenance.
        if ( count( $urls ) > 256 ) return false;
        return false !== $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s,autoload='no' WHERE option_name=%s", wp_json_encode( $urls ), $name
        ) );
    }

    /** Transactional durable outbox; no external IO or Redis authority. */
    public static function invalidate_resources( array $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        if ( ! $ids ) return true;
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            if ( ! self::record_cluster_select( 'm.id IN (' . implode( ',', $chunk ) . ')' ) ) return false;
        }
        return self::force_invalidate();
    }

    public static function invalidate_translation_clusters( array $hashes ) {
        global $wpdb;
        if ( ! $hashes ) return true;
        foreach ( $hashes as $hash ) if ( ! preg_match( '/^[a-f0-9]{32}$/D', $hash ) ) return false;
        $relations = GML_Resource_Manifest_Store::relation_table();
        $where = $wpdb->prepare(
            'EXISTS (SELECT 1 FROM ' . $relations . ' s WHERE s.resource_id=m.id AND s.manifest_generation=m.manifest_generation AND s.source_hash IN (' . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . '))',
            $hashes
        );
        return self::record_cluster_select( $where );
    }

    public static function invalidate_all_clusters() {
        if ( ! GML_Resource_Manifest_Store::tables_ready() ) return true;
        return self::record_cluster_select( '1=1' ) && self::force_invalidate();
    }

    private static function record_cluster_select( $where ) {
        global $wpdb;
        $table = GML_Resource_Manifest_Store::manifest_table();
        return false !== $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name,option_value,autoload)
             SELECT CONCAT(%s,m.id),%s,'no' FROM $table m WHERE $where
             ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",
            self::CLUSTER_PREFIX, wp_generate_uuid4()
        ) );
    }

    /** Bounded maintenance plan. Unresolved legacy URLs remain pending, never guessed. */
    public static function pending_clusters( $limit = 20 ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) return [];
        $table = GML_Resource_Manifest_Store::manifest_table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.option_name,d.option_value AS token,m.id,m.resource_key,m.source_url_hash,u.option_value AS urls
             FROM {$wpdb->options} d
             LEFT JOIN $table m ON m.id=CAST(SUBSTRING(d.option_name,%d) AS UNSIGNED)
             LEFT JOIN {$wpdb->options} u ON u.option_name=CONCAT(%s,SUBSTRING(d.option_name,%d))
             WHERE d.option_name LIKE %s ORDER BY d.option_id LIMIT %d",
            strlen(self::CLUSTER_PREFIX)+1, self::URL_PREFIX, strlen(self::CLUSTER_PREFIX)+1,
            $wpdb->esc_like( self::CLUSTER_PREFIX ) . '%', max( 1, min( 100, (int) $limit ) )
        ) );
        $result = [];
        foreach ( (array) $rows as $row ) {
            $urls = json_decode( (string) $row->urls, true );
            $urls = is_array( $urls ) ? $urls : [];
            $resource = $row->resource_key ? GML_Resource_Identity::resolve( $row->resource_key ) : null;
            if ( $resource && hash_equals( (string) $row->source_url_hash, $resource->get_source_url_hash() ) ) {
                $urls = array_values( array_unique( array_merge( $urls, self::cluster_urls( $resource ) ) ) );
            }
            $result[] = [ 'name' => $row->option_name, 'token' => $row->token, 'resource_id' => (int) $row->id,
                'resource_key' => $row->resource_key, 'urls' => $urls, 'blocked' => ! $urls ];
        }
        return $result;
    }

    /** Call only after every exact URL succeeded at every configured external layer. */
    public static function acknowledge_cluster( $name, $token ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) || ! preg_match( '/^' . self::CLUSTER_PREFIX . '[1-9][0-9]*$/D', $name ) || ! wp_is_uuid( $token ) ) return false;
        return 1 === $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $token
        ) );
    }

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
        global $wpdb;
        // A concurrent reader can refill Redis with the pre-commit option.
        // One indexed DB read per cache-key calculation makes that refill inert.
        $generation = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::GENERATION_OPTION
        ));
        if ( $generation < 1 ) {
            if (self::force_invalidate()) {
                $generation = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::GENERATION_OPTION
                ));
            }
            // No usable DB authority: do not reuse any existing cached page.
            if ($generation < 1) return 'unavailable-' . wp_generate_uuid4();
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
