<?php
/** Hook registration and bounded invalidation for shadow manifests. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Manifest_Manager {
    const GLOBAL_OPTION = 'gml_resource_manifest_global_generation';
    const DIRTY_OPTION = 'gml_resource_manifest_dirty';
    const DIRTY_HOOK = 'gml_resource_manifest_dirty';
    private static $registered = false;
    private static $global_bumped = false;

    public static function register_hooks() {
        if ( self::$registered ) return;
        self::$registered = true;
        add_action( 'save_post', [ __CLASS__, 'post_changed' ], 40, 3 );
        add_action( 'before_delete_post', [ __CLASS__, 'post_deleted' ], 40, 2 );
        add_action( 'created_term', [ __CLASS__, 'term_changed' ], 40, 3 );
        add_action( 'edited_term', [ __CLASS__, 'term_changed' ], 40, 3 );
        add_action( 'pre_delete_term', [ __CLASS__, 'term_deleted' ], 40, 2 );
        add_action( 'wp_update_nav_menu', [ __CLASS__, 'global_changed' ] );
        add_action( 'customize_save_after', [ __CLASS__, 'global_changed' ] );
        add_action( 'switch_theme', [ __CLASS__, 'global_changed' ] );
        add_action( 'updated_option', [ __CLASS__, 'option_changed' ], 40, 3 );
        add_action( self::DIRTY_HOOK, [ __CLASS__, 'process_dirty' ] );
        add_action( 'gml_resource_readiness_reverse', [ __CLASS__, 'process_reverse' ] );
        GML_Resource_Readiness::register_hooks();
        GML_Resource_Backfill::register_hooks();
        add_action( 'wp_loaded', [ __CLASS__, 'maybe_schedule' ] );
    }

    public static function global_generation() {
        return max( 1, (int) get_option( self::GLOBAL_OPTION, 1 ) );
    }

    public static function bump_global_generation( $reason = '' ) {
        if ( self::$global_bumped ) return false;
        self::$global_bumped = true;
        update_option( self::GLOBAL_OPTION, self::global_generation() + 1, false );
        GML_Resource_Backfill::reset_pending( sanitize_key( $reason ) );
        self::maybe_schedule();
        return true;
    }

    public static function post_changed( $post_id, $post = null, $update = false ) {
        unset( $update );
        $post_id = (int) $post_id;
        if ( $post_id < 1 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! $post instanceof WP_Post ) $post = get_post( $post_id );
        $resource = $post instanceof WP_Post ? GML_Resource_Identity::for_post( $post ) : null;
        if ( ! $resource ) {
            if ( $post instanceof WP_Post ) self::stale_post_keys( $post );
            return;
        }
        GML_Resource_Manifest_Store::mark_stale( $resource );
        self::add_dirty( $resource->get_key() );
    }

    public static function term_changed( $term_id, $tt_id = 0, $taxonomy = '' ) {
        unset( $tt_id );
        $resource = GML_Resource_Identity::for_term( (int) $term_id, (string) $taxonomy );
        if ( ! $resource ) return;
        GML_Resource_Manifest_Store::mark_stale( $resource );
        self::add_dirty( $resource->get_key() );
    }

    public static function term_deleted( $term_id, $taxonomy = '' ) {
        self::term_changed( $term_id, 0, $taxonomy );
    }

    public static function post_deleted( $post_id, $post = null ) {
        if ( ! $post instanceof WP_Post ) $post = get_post( (int) $post_id );
        if ( $post instanceof WP_Post ) self::stale_post_keys( $post );
    }

    public static function global_changed() { self::bump_global_generation( 'global_content' ); }

    public static function option_changed( $option, $old_value, $value ) {
        if ( $old_value === $value ) return;
        $fixed = [ 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts', 'show_on_front', 'permalink_structure', 'sidebars_widgets', 'theme_mods_' . get_option( 'stylesheet' ) ];
        $woo = [
            'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id',
            'woocommerce_myaccount_page_id', 'woocommerce_permalinks', 'woocommerce_default_country',
            'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_decimal_sep',
            'woocommerce_price_thousand_sep', 'woocommerce_price_num_decimals',
            'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart',
            'woocommerce_weight_unit', 'woocommerce_dimension_unit',
        ];
        if ( in_array( $option, $fixed, true ) || in_array( $option, $woo, true ) || strpos( $option, 'widget_' ) === 0 ) {
            self::bump_global_generation( $option );
        }
    }

    public static function add_dirty( $key ) {
        $dirty = array_values( array_unique( array_filter( (array) get_option( self::DIRTY_OPTION, [] ) ) ) );
        $dirty[] = substr( (string) $key, 0, 191 );
        $dirty = array_slice( array_values( array_unique( $dirty ) ), -200 );
        update_option( self::DIRTY_OPTION, $dirty, false );
        if ( ! wp_next_scheduled( self::DIRTY_HOOK ) ) wp_schedule_single_event( time() + 15, self::DIRTY_HOOK );
    }

    public static function process_dirty() {
        $dirty = (array) get_option( self::DIRTY_OPTION, [] );
        if ( ! $dirty ) return;
        $token = GML_Atomic_Option_Lock::acquire( GML_Resource_Backfill::LOCK, 180 );
        if ( $token === '' ) { wp_schedule_single_event( time() + 30, self::DIRTY_HOOK ); return; }
        try {
            $batch = array_slice( $dirty, 0, 5 );
            $discovery = new GML_Resource_Manifest_Discovery();
            foreach ( $batch as $key ) {
                if ( ! GML_Atomic_Option_Lock::refresh( GML_Resource_Backfill::LOCK, $token, 180 ) ) return;
                $discovery->discover( $key );
            }
            $dirty = array_slice( $dirty, count( $batch ) );
            if ( $dirty ) {
                update_option( self::DIRTY_OPTION, $dirty, false );
                wp_schedule_single_event( time() + 30, self::DIRTY_HOOK );
            } else delete_option( self::DIRTY_OPTION );
        } finally {
            GML_Atomic_Option_Lock::release( GML_Resource_Backfill::LOCK, $token );
        }
    }

    public static function process_reverse() { GML_Resource_Readiness::continue_reverse(); }

    public static function maybe_schedule() {
        if ( ! GML_Resource_Manifest_Store::tables_ready() ) return;
        GML_Resource_Readiness::migrate_legacy_continuation();
        GML_Resource_Readiness::ensure_recovery_schedule();
        if ( get_option( self::DIRTY_OPTION, [] ) && ! wp_next_scheduled( self::DIRTY_HOOK ) ) wp_schedule_single_event( time() + 15, self::DIRTY_HOOK );
        GML_Resource_Backfill::maybe_schedule();
    }

    private static function stale_post_keys( WP_Post $post ) {
        $keys = [ 'post:' . sanitize_key( $post->post_type ) . ':' . (int) $post->ID ];
        if ( (int) get_option( 'page_on_front', 0 ) === (int) $post->ID ) $keys[] = 'role:front_page:' . (int) $post->ID;
        if ( (int) get_option( 'page_for_posts', 0 ) === (int) $post->ID ) $keys[] = 'role:posts_page:' . (int) $post->ID;
        foreach ( $keys as $key ) {
            GML_Resource_Manifest_Store::mark_stale_by_key( $key, 'unpublished:' . (string) $post->post_modified_gmt );
            self::add_dirty( $key );
        }
    }
}
