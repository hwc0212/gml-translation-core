<?php
/** Language rewrite lifecycle shared by both product adapters. */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-translation-state.php';
require_once __DIR__ . '/class-language-utils.php';

class GML_Translation_Rewrite {
    const REFRESH_OPTION = 'gml_translation_flush_rewrite_rules';

    /** @var bool */
    private static $hooks_registered = false;

    /**
     * Register option-change detection and the one-shot safe refresh.
     */
    public static function register_hooks() {
        if ( self::$hooks_registered ) return;
        self::$hooks_registered = true;

        add_action( 'updated_option', [ __CLASS__, 'option_updated' ], 10, 3 );
        add_action( 'added_option', [ __CLASS__, 'option_added' ], 10, 2 );
        add_action( 'init', [ __CLASS__, 'maybe_flush_deferred' ], 99 );
    }

    public static function rules() {
        if ( ! GML_Translation_State::multilingual_enabled() ) return [];
        $pattern = GML_Language_Utils::language_pattern( GML_Language_Utils::enabled_local_target_codes() );
        if ( $pattern === '' ) return [];
        return [
            "^({$pattern})/(.+?)/?$" => 'index.php?gml_lang=$matches[1]&gml_path=$matches[2]',
            "^({$pattern})/?$" => 'index.php?gml_lang=$matches[1]',
        ];
    }

    public static function register() {
        self::register_hooks();
        foreach ( self::rules() as $pattern => $query ) {
            add_rewrite_rule( $pattern, $query, 'top' );
        }
    }

    public static function option_updated( $option, $old_value, $value ) {
        if ( self::routing_signature( $option, $old_value ) !== self::routing_signature( $option, $value ) ) {
            self::mark_refresh();
        }
    }

    public static function option_added( $option, $value ) {
        if ( self::routing_signature( $option, null ) !== self::routing_signature( $option, $value ) ) {
            self::mark_refresh();
        }
    }

    /**
     * Record one deferred refresh without flushing during a bulk import.
     */
    public static function mark_refresh() {
        if ( get_option( self::REFRESH_OPTION ) === '1' ) return false;
        return update_option( self::REFRESH_OPTION, '1', false );
    }

    /**
     * Soft-flush once at init after a routing-affecting option changed.
     */
    public static function maybe_flush_deferred() {
        if ( get_option( self::REFRESH_OPTION ) !== '1' || wp_doing_ajax() || wp_doing_cron() ) return false;

        self::discard_registered_language_rules();
        self::register();
        try {
            flush_rewrite_rules( false );
        } finally {
            // The refresh was attempted. A later consistency check can repair
            // missing rules without leaving an expensive frontend loop behind.
            delete_option( self::REFRESH_OPTION );
        }
        return true;
    }

    private static function routing_signature( $option, $value ) {
        if ( in_array( $option, [ 'gml_multilingual_enabled', 'gml_translation_enabled' ], true ) ) {
            return $option . ':' . ( (bool) $value ? '1' : '0' );
        }
        if ( $option === 'gml_source_lang' ) {
            return $option . ':' . GML_Language_Utils::normalize_code( $value );
        }
        if ( $option !== 'gml_languages' ) {
            return null;
        }

        $routing = [];
        foreach ( (array) $value as $language ) {
            if ( ! is_array( $language ) ) continue;
            $code = GML_Language_Utils::normalize_code( $language['code'] ?? '' );
            if ( ! $code ) continue;
            $routing[] = [
                'code'          => $code,
                'enabled'       => ! array_key_exists( 'enabled', $language ) || (bool) $language['enabled'],
                'site_mode'     => (string) ( $language['site_mode'] ?? GML_Language_Utils::SITE_MODE_LOCAL ),
                'url_prefix'    => (string) ( $language['url_prefix'] ?? '/' . $code . '/' ),
                'external_url'  => (string) ( $language['external_url'] ?? '' ),
                'external_path' => (string) ( $language['external_path_mode'] ?? '' ),
            ];
        }
        usort( $routing, static function( $a, $b ) { return strcmp( $a['code'], $b['code'] ); } );
        return $option . ':' . md5( (string) wp_json_encode( $routing ) );
    }

    private static function is_language_rule( $query ) {
        return is_string( $query ) && preg_match( '/[?&]gml_lang=/', $query );
    }

    /**
     * Remove only stale in-memory GML rules before regenerating the final set.
     * This matters when an importer runs after init in the current request.
     */
    private static function discard_registered_language_rules() {
        global $wp_rewrite;
        if ( ! is_object( $wp_rewrite ) ) return;

        foreach ( [ 'extra_rules_top', 'extra_rules' ] as $property ) {
            if ( ! isset( $wp_rewrite->{$property} ) || ! is_array( $wp_rewrite->{$property} ) ) continue;
            $wp_rewrite->{$property} = array_filter(
                $wp_rewrite->{$property},
                static function( $query ) { return ! self::is_language_rule( $query ); }
            );
        }
    }

    public static function maybe_repair() {
        if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) return;

        $expected = self::rules();
        $stored = get_option( 'rewrite_rules' );
        if ( ! is_array( $stored ) || ! $stored ) {
            if ( ! $expected ) return;
            self::register();
            flush_rewrite_rules( false );
            $stored = get_option( 'rewrite_rules', [] );
        }
        if ( ! is_array( $stored ) ) return;

        $current = array_filter( $stored, [ __CLASS__, 'is_language_rule' ] );
        if ( $current !== $expected ) {
            // Replace only our rules; retain every other plugin's persisted routes.
            $updated = $expected + array_diff_key( $stored, $current );
            update_option( 'rewrite_rules', $updated );
            if ( get_option( 'rewrite_rules' ) !== $updated ) return;
        }
        delete_option( self::REFRESH_OPTION );
    }
}
