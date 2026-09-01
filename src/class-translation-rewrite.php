<?php
/** Language rewrite lifecycle shared by both product adapters. */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-translation-state.php';
require_once __DIR__ . '/class-language-utils.php';

class GML_Translation_Rewrite {
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
        foreach ( self::rules() as $pattern => $query ) {
            add_rewrite_rule( $pattern, $query, 'top' );
        }
    }

    private static function is_language_rule( $query ) {
        return is_string( $query ) && preg_match( '/[?&]gml_lang=/', $query );
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
        delete_option( 'gml_translation_flush_rewrite_rules' );
    }
}
