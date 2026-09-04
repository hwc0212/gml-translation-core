<?php
/**
 * Translation data retention and uninstall cleanup.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GML_Translation_Uninstaller {

    const TABLE_SUFFIXES = [
        'gml_plan_items',
        'gml_plans',
        'gml_queue',
        'gml_index',
        'gml_resource_review_audit',
        'gml_resource_reviews',
        'gml_resource_translation_versions',
        'gml_resource_readiness',
        'gml_resource_strings',
        'gml_resource_manifests',
    ];

    const CRON_HOOKS = [
        'gml_process_queue',
        'gml_crawl_content',
        'gml_discover_changed_content',
        'gml_resource_manifest_backfill',
        'gml_resource_manifest_dirty',
        'gml_resource_readiness_reverse',
        'gml_resource_readiness_rebuild',
    ];

    /**
     * Apply the saved per-site uninstall preference.
     *
     * Shared data is left completely untouched while another installed GML
     * product can still use it.
     */
    public static function uninstall( $preference_option, $shared_consumer_installed = false ) {
        if ( $shared_consumer_installed ) {
            return;
        }

        self::for_each_site( static function() use ( $preference_option ) {
            $delete_data = (bool) get_option( $preference_option, false );

            self::cleanup_runtime();
            if ( $delete_data ) {
                self::delete_site_data();
            }
        } );
    }

    /**
     * Stop background work and remove disposable cache state.
     *
     * Translation memory, queue rows, settings, glossary, and credentials are
     * deliberately retained by this method.
     */
    public static function cleanup_runtime() {
        foreach ( self::CRON_HOOKS as $hook ) {
            self::clear_scheduled_hook( $hook );
        }

        self::delete_page_cache_transients();
        self::clear_translation_object_cache();
        self::remove_cache_directory();

        // Force a lazy rewrite rebuild after the plugin is removed or restored.
        delete_option( 'rewrite_rules' );
    }

    /**
     * Permanently remove all shared translation data for the current site.
     */
    public static function delete_site_data() {
        self::cleanup_runtime();
        self::drop_translation_tables();
        self::delete_translation_options();
    }

    /**
     * Run a callback in each site's option/table context.
     */
    public static function for_each_site( $callback ) {
        if ( ! is_callable( $callback ) ) {
            return;
        }

        if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
            call_user_func( $callback );
            return;
        }

        $site_ids = get_sites( [
            'fields' => 'ids',
            'number' => 0,
        ] );

        foreach ( $site_ids as $site_id ) {
            switch_to_blog( (int) $site_id );
            try {
                call_user_func( $callback );
            } finally {
                restore_current_blog();
            }
        }
    }

    /**
     * Remove every event for a hook, including single events with arguments.
     */
    private static function clear_scheduled_hook( $hook ) {
        wp_clear_scheduled_hook( $hook );

        if ( ! function_exists( '_get_cron_array' ) ) {
            return;
        }

        $cron = _get_cron_array();
        if ( ! is_array( $cron ) ) {
            return;
        }

        foreach ( $cron as $timestamp => $hooks ) {
            if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
                continue;
            }
            foreach ( $hooks[ $hook ] as $event ) {
                wp_unschedule_event( (int) $timestamp, $hook, (array) ( $event['args'] ?? [] ) );
            }
        }
    }

    private static function drop_translation_tables() {
        global $wpdb;

        foreach ( self::TABLE_SUFFIXES as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }
    }

    /**
     * Delete current and legacy translation options without touching GML SEO.
     */
    private static function delete_translation_options() {
        global $wpdb;

        $translation_like = $wpdb->esc_like( 'gml_' ) . '%';
        $seo_like         = $wpdb->esc_like( 'gml_seo' ) . '%';
        $transient_like   = $wpdb->esc_like( '_transient_gml_' ) . '%';
        $timeout_like     = $wpdb->esc_like( '_transient_timeout_gml_' ) . '%';
        $seo_transient    = $wpdb->esc_like( '_transient_gml_seo' ) . '%';
        $seo_timeout      = $wpdb->esc_like( '_transient_timeout_gml_seo' ) . '%';

        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE (
                    (option_name LIKE %s AND option_name NOT LIKE %s AND option_name <> %s)
                 OR (option_name LIKE %s AND option_name NOT LIKE %s)
                 OR (option_name LIKE %s AND option_name NOT LIKE %s)
             )",
            $translation_like,
            $seo_like,
            'gml_indexnow_key',
            $transient_like,
            $seo_transient,
            $timeout_like,
            $seo_timeout
        ) );

        foreach ( array_unique( (array) $names ) as $name ) {
            delete_option( $name );
        }

        wp_cache_delete( 'alloptions', 'options' );
    }

    private static function delete_page_cache_transients() {
        global $wpdb;

        $like  = $wpdb->esc_like( '_transient_gml_page_' ) . '%';
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ) );

        foreach ( (array) $names as $name ) {
            $transient = substr( $name, strlen( '_transient_' ) );
            if ( $transient !== '' ) {
                delete_transient( $transient );
            }
        }
    }

    private static function clear_translation_object_cache() {
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
            wp_cache_flush_group( 'gml_translate' );
            return;
        }

        wp_cache_delete( 'gml_readiness_map', 'gml_translate' );

        $source    = sanitize_key( (string) get_option( 'gml_source_lang', 'en' ) );
        $languages = (array) get_option( 'gml_languages', [] );
        foreach ( $languages as $language ) {
            $target = sanitize_key( (string) ( $language['code'] ?? '' ) );
            if ( $source !== '' && $target !== '' ) {
                wp_cache_delete( 'gml_dict_' . $source . '_' . $target, 'gml_translate' );
            }
        }
    }

    private static function remove_cache_directory() {
        $uploads = wp_upload_dir( null, false );
        if ( empty( $uploads['basedir'] ) ) {
            return;
        }

        $base   = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
        $target = $base . '/gml-cache';
        if ( strpos( $target, $base . '/' ) !== 0 ) {
            return;
        }

        self::remove_path( $target );
    }

    private static function remove_path( $path ) {
        if ( is_link( $path ) || is_file( $path ) ) {
            @unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }

        $items = scandir( $path );
        if ( ! is_array( $items ) ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            self::remove_path( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}
