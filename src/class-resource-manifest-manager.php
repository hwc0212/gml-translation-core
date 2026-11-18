<?php
/** Hook registration and bounded invalidation for shadow manifests. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Manifest_Manager {
    const GLOBAL_OPTION = 'gml_resource_manifest_global_generation';
    const DIRTY_OPTION = 'gml_resource_manifest_dirty';
    const DIRTY_HOOK = 'gml_resource_manifest_dirty';
    const FAILURE_OPTION = 'gml_resource_manifest_last_failure';
    const RECOVERY_CURSOR = 'gml_inventory_recovery_cursor';
    const RECOVERY_PAGE = 64;
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
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta','added_term_meta','updated_term_meta','deleted_term_meta'] as $hook) {
            add_action($hook, [__CLASS__, 'seo_meta_changed'], 40, 4);
        }
        add_action( self::DIRTY_HOOK, [ __CLASS__, 'process_dirty' ] );
        add_action( GML_Resource_Readiness::REBUILD_HOOK, [ __CLASS__, 'recover_pending' ], 5 );
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
        if (!GML_Page_Cache::invalidate_all_clusters()) { self::$global_bumped = false; return false; }
        update_option( self::GLOBAL_OPTION, self::global_generation() + 1, false );
        GML_Resource_Backfill::reset_pending( sanitize_key( $reason ) );
        self::maybe_schedule();
        return true;
    }

    public static function post_changed( $post_id, $post = null, $update = false ) {
        unset( $update );
        $post_id = (int) $post_id;
        if ( $post_id < 1 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        self::invalidate_permanent_redirects();
        if ( ! $post instanceof WP_Post ) $post = get_post( $post_id );
        if ( $post instanceof WP_Post && self::is_global_layout_post( $post ) ) {
            self::bump_global_generation( 'layout_' . $post->post_type );
            return;
        }
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
        self::invalidate_permanent_redirects();
        $resource = GML_Resource_Identity::for_term( (int) $term_id, (string) $taxonomy );
        if ( ! $resource ) return;
        GML_Resource_Manifest_Store::mark_stale( $resource );
        self::add_dirty( $resource->get_key() );
    }

    public static function term_deleted( $term_id, $taxonomy = '' ) {
        self::term_changed( $term_id, 0, $taxonomy );
    }

    public static function post_deleted( $post_id, $post = null ) {
        self::invalidate_permanent_redirects();
        if ( ! $post instanceof WP_Post ) $post = get_post( (int) $post_id );
        if ( ! $post instanceof WP_Post ) return;
        if ( self::is_global_layout_post( $post ) ) {
            self::bump_global_generation( 'layout_deleted_' . $post->post_type );
            return;
        }
        self::stale_post_keys( $post );
    }

    public static function global_changed() { self::bump_global_generation( 'global_content' ); }

    /** SEO metadata can change publication membership without a save_post event. */
    public static function seo_meta_changed($meta_id, $object_id, $key, $value = null) {
        unset($meta_id, $value);
        if (!preg_match('/^(?:_seopress_|_yoast_wpseo_|rank_math_|_gml_seo_)/', (string)$key)) return;
        if (strpos(current_filter(), 'term_meta') !== false) {
            $term = get_term((int)$object_id);
            if ($term instanceof WP_Term) self::term_changed($term->term_id, 0, $term->taxonomy);
        } else self::post_changed((int)$object_id);
    }

    /** Redirect targets/chains can change without the old source post changing. */
    public static function invalidate_permanent_redirects() {
        global $wpdb;
        if ( ! GML_Resource_Manifest_Store::tables_ready() ) return;
        $table = GML_Resource_Manifest_Store::manifest_table();
        $keys = $wpdb->get_col("SELECT resource_key FROM $table WHERE discovery_state='permanent_redirect' LIMIT 201");
        if ( ! $keys ) return;
        // Invalidate every old proof first; a bounded dirty list is not the proof.
        if ( false === $wpdb->query("UPDATE $table SET discovery_state='unknown' WHERE discovery_state='permanent_redirect'") ) return;
        GML_Translation_Readiness::clear_cache();
        $dirty = (array)get_option(self::DIRTY_OPTION, []);
        if ( count($keys) + count($dirty) > 200 ) {
            GML_Resource_Backfill::reset_pending('redirect_targets_changed');
            GML_Resource_Backfill::maybe_schedule();
            return;
        }
        foreach ($keys as $key) self::add_dirty($key);
    }

    public static function option_changed( $option, $old_value, $value ) {
        if ( $old_value === $value ) return;
        if (in_array($option, ['blog_public','gml_languages','gml_source_lang','gml_multilingual_enabled'], true)) {
            GML_Page_Cache::invalidate_all_clusters();
            return;
        }
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
        $key = substr( (string) $key, 0, 191 );
        if ( $key === '' ) return false;
        $overflow = [];
        $saved = self::mutate_dirty( static function( $dirty ) use ( $key, &$overflow ) {
            $dirty[] = $key;
            $dirty = array_values( array_unique( $dirty ) );
            $overflow = array_slice( $dirty, 0, max( 0, count( $dirty ) - 200 ) );
            return array_slice( $dirty, -200 );
        } );
        if ( $overflow || ! $saved ) {
            foreach ( $overflow as $retired_key ) GML_Resource_Manifest_Store::exclude_retired_key( $retired_key );
            GML_Resource_Backfill::reset_pending( 'dirty_overflow' );
            GML_Resource_Backfill::maybe_schedule();
        }
        self::schedule_dirty();
        return $saved;
    }

    /** Atomic hints; the manifest table remains the durable work authority. */
    private static function mutate_dirty( callable $change ) {
        global $wpdb;
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::DIRTY_OPTION ) );
            if ( $wpdb->last_error !== '' ) return false;
            $dirty = array_values( array_unique( array_filter( (array) maybe_unserialize( $raw ) ) ) );
            $next = array_values( call_user_func( $change, $dirty ) );
            $value = maybe_serialize( $next );
            if ( $raw === $value ) return true;
            $saved = $raw === null
                ? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::DIRTY_OPTION, $value ) )
                : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s", $value, self::DIRTY_OPTION, $raw ) );
            if ( $saved === false ) return false;
            if ( $saved === 1 ) {
                wp_cache_delete( self::DIRTY_OPTION, 'options' );
                wp_cache_delete( 'alloptions', 'options' );
                wp_cache_delete( 'notoptions', 'options' );
                return true;
            }
        }
        return false;
    }

    private static function schedule_dirty( $when = null ) {
        $when = $when ?? time() + 30;
        $next = wp_next_scheduled( self::DIRTY_HOOK );
        // A new edit needs an earlier wakeup than a resource in backoff.
        if ( $next && $next > $when ) {
            if ( ! wp_unschedule_event( $next, self::DIRTY_HOOK ) ) return;
            $next = false;
        }
        if ( ! $next ) wp_schedule_single_event( $when, self::DIRTY_HOOK );
    }

    /** Recover work lost by an interrupted or older worker, without AI work. */
    public static function recover_pending( $mode = 'recovery' ) {
        if ( ! in_array( $mode, [ 'recovery', 'inventory' ], true ) || ! GML_Resource_Manifest_Store::tables_ready() ) return;
        global $wpdb;
        $dirty = array_fill_keys( (array) get_option( self::DIRTY_OPTION, [] ), true );
        if ( count( $dirty ) > 200 - GML_Resource_Backfill::BATCH ) {
            self::schedule_inventory();
            return;
        }
        $cursor = (int) get_option( self::RECOVERY_CURSOR, 0 );
        $manifests = GML_Resource_Manifest_Store::manifest_table();
        $readiness = GML_Resource_Manifest_Store::readiness_table();
        $languages = GML_Language_Utils::configured_codes( true, true );
        if ( ! $languages ) return;
        $placeholders = implode( ',', array_fill( 0, count( $languages ), '%s' ) );
        // Check a bounded page of manifest IDs, including complete rows whose
        // stored language snapshots still point to a different generation.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id,m.resource_key,m.discovery_state,m.global_generation,
                    (SELECT COUNT(*) FROM $readiness r WHERE r.resource_id=m.id
                     AND r.target_lang IN ($placeholders) AND r.manifest_generation=m.manifest_generation
                     AND r.global_generation=m.global_generation) AS matching_languages
             FROM $manifests m WHERE m.id>%d ORDER BY m.id LIMIT %d",
            array_merge( $languages, [ $cursor, self::RECOVERY_PAGE ] )
        ) );
        if ( $wpdb->last_error !== '' ) {
            self::schedule_inventory();
            return;
        }
        $global = self::global_generation();
        $queued = 0;
        foreach ( $rows as $row ) {
            $incomplete = in_array( $row->discovery_state, [ 'stale', 'unknown', 'render_error' ], true );
            $outdated = $row->discovery_state === 'complete' && (
                (int) $row->global_generation !== $global || (int) $row->matching_languages !== count( $languages )
            );
            if ( ( $incomplete || $outdated ) && ! isset( $dirty[ $row->resource_key ] ) ) {
                if ( ! self::add_dirty( $row->resource_key ) ) {
                    self::schedule_inventory();
                    return;
                }
                $queued++;
            }
            $cursor = (int) $row->id;
            if ( $queued >= GML_Resource_Backfill::BATCH ) break;
        }
        $more = count( $rows ) === self::RECOVERY_PAGE || $queued >= GML_Resource_Backfill::BATCH;
        update_option( self::RECOVERY_CURSOR, $more ? $cursor : 0, false );
        if ( $more ) self::schedule_inventory();
    }

    private static function schedule_inventory() {
        $args = [ 'inventory' ];
        if ( ! wp_next_scheduled( GML_Resource_Readiness::REBUILD_HOOK, $args ) ) {
            wp_schedule_single_event( time() + 30, GML_Resource_Readiness::REBUILD_HOOK, $args );
        }
    }

    public static function record_failure( $key, $result, $phase = 'dirty' ) {
        update_option( self::FAILURE_OPTION, [
            'resource_key' => substr( (string) $key, 0, 191 ),
            'error_code' => is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'gml_manifest_write',
            'phase' => sanitize_key( $phase ), 'updated_at' => time(),
        ], false );
    }

    public static function process_dirty() {
        if ( ! get_option( self::DIRTY_OPTION, [] ) ) {
            // Prefer interrupted work for the active generation. The periodic
            // cursor independently covers older generations and partial writes.
            global $wpdb;
            if ( ! GML_Resource_Manifest_Store::tables_ready() ) return;
            $table = GML_Resource_Manifest_Store::manifest_table();
            $keys = $wpdb->get_col( $wpdb->prepare(
                "SELECT resource_key FROM $table WHERE discovery_state IN ('stale','unknown','render_error')
                 AND global_generation=%d ORDER BY updated_at,id LIMIT %d",
                self::global_generation(), GML_Resource_Backfill::BATCH
            ) );
            foreach ( (array) $keys as $key ) self::add_dirty( $key );
        }
        if ( ! get_option( self::DIRTY_OPTION, [] ) ) return;
        // Persist the wakeup before claiming. A killed worker leaves stale
        // manifests that the next event can reconstruct into dirty hints.
        $token = GML_Atomic_Option_Lock::acquire( GML_Resource_Backfill::LOCK, 180 );
        if ( $token === '' ) {
            self::schedule_dirty();
            return;
        }
        $batch = [];
        try {
            $retries = [];
            $next_retry = null;
            foreach ( (array) get_option( self::DIRTY_OPTION, [] ) as $key ) {
                $retry = (array) get_transient( 'gml_manifest_retry_' . md5( $key ) );
                $after = (int) ( $retry['after'] ?? 0 );
                if ( $after > time() ) {
                    $next_retry = $next_retry === null ? $after : min( $next_retry, $after );
                    continue;
                }
                $retries[ $key ] = $retry;
                if ( count( $retries ) === GML_Resource_Backfill::BATCH ) break;
            }
            if ( ! $retries ) {
                if ( $next_retry !== null ) self::schedule_dirty( $next_retry );
                return;
            }
            self::schedule_dirty();
            if ( ! self::mutate_dirty( static function( $dirty ) use ( $retries, &$batch ) {
                $batch = array_values( array_intersect( array_keys( $retries ), $dirty ) );
                return array_values( array_diff( $dirty, $batch ) );
            } ) ) return;
            $discovery = new GML_Resource_Manifest_Discovery();
            foreach ( $batch as $key ) {
                if ( ! GML_Atomic_Option_Lock::refresh( GML_Resource_Backfill::LOCK, $token, 180 ) ) break;
                $retry_key = 'gml_manifest_retry_' . md5( $key );
                $retry = $retries[ $key ];
                $result = $discovery->discover( $key );
                $row = GML_Resource_Manifest_Store::get_by_key( $key );
                $terminal = $row && in_array( $row->discovery_state, [ 'excluded', 'permanent_redirect' ], true );
                if ( $result !== true && ! $terminal ) {
                    self::add_dirty( $key );
                    self::record_failure( $key, $result );
                    $attempt = min( 8, 1 + (int) ( $retry['attempt'] ?? 0 ) );
                    // The first retry may run immediately; sustained failures back off.
                    $delay = $attempt === 1 ? 0 : min( HOUR_IN_SECONDS, 30 * ( 2 ** ( $attempt - 1 ) ) );
                    set_transient( $retry_key, [ 'attempt' => $attempt, 'after' => time() + $delay ], DAY_IN_SECONDS );
                } else {
                    if ( $retry ) delete_transient( $retry_key );
                    if ( in_array( $key, (array) get_option( self::DIRTY_OPTION, [] ), true ) ) {
                        // An edit during rendering belongs to another pass.
                        GML_Resource_Manifest_Store::mark_stale_by_key( $key );
                    }
                }
                array_shift( $batch );
            }
            if ( ! get_option( self::DIRTY_OPTION, [] ) ) delete_option( self::FAILURE_OPTION );
        } finally {
            foreach ( $batch as $key ) self::add_dirty( $key );
            GML_Atomic_Option_Lock::release( GML_Resource_Backfill::LOCK, $token );
        }
    }

    /** Admin-only caller decides display; no private content or remote messages. */
    public static function diagnostics( $key ) {
        $row = GML_Resource_Manifest_Store::get_by_key( $key );
        $dirty = (array) get_option( self::DIRTY_OPTION, [] );
        $state = GML_Resource_Backfill::state();
        $failure = (array) get_option( self::FAILURE_OPTION, [] );
        $lock = GML_Atomic_Option_Lock::get( GML_Resource_Backfill::LOCK );
        $retry = (array) get_transient( 'gml_manifest_retry_' . md5( $key ) );
        return [
            'backfill' => array_intersect_key( $state, array_flip( [ 'status', 'phase', 'cursor', 'updated_at', 'reason' ] ) ),
            'dirty_count' => count( $dirty ), 'resource_pending' => in_array( $key, $dirty, true ),
            'manifest_state' => $row ? $row->discovery_state : 'missing',
            'manifest_global_generation' => $row ? (int) $row->global_generation : 0,
            'current_global_generation' => self::global_generation(),
            'manifest_updated_at' => $row ? $row->updated_at : '',
            'manifest_discovered_at' => $row ? $row->discovered_at : '',
            'lock_active' => (int) $lock['expires'] > time(), 'lock_expires_at' => (int) $lock['expires'],
            'next_inventory_recovery' => wp_next_scheduled( GML_Resource_Readiness::REBUILD_HOOK, [ 'recovery' ] ),
            'next_inventory_page' => wp_next_scheduled( GML_Resource_Readiness::REBUILD_HOOK, [ 'inventory' ] ),
            'resource_retry_after' => (int) ( $retry['after'] ?? 0 ),
            'next_dirty_run' => wp_next_scheduled( self::DIRTY_HOOK ),
            'next_backfill_run' => wp_next_scheduled( GML_Resource_Backfill::HOOK ),
            'last_failure' => ( $failure['resource_key'] ?? '' ) === $key
                ? array_intersect_key( $failure, array_flip( [ 'error_code', 'phase', 'updated_at' ] ) ) : [],
        ];
    }

    public static function process_reverse() { GML_Resource_Readiness::continue_reverse(); }

    public static function maybe_schedule() {
        if ( ! GML_Resource_Manifest_Store::tables_ready() ) return;
        GML_Resource_Readiness::migrate_legacy_continuation();
        GML_Resource_Readiness::ensure_recovery_schedule();
        if ( get_option( self::RECOVERY_CURSOR, false ) === false ) self::schedule_inventory();
        if ( get_option( self::DIRTY_OPTION, [] ) && ! wp_next_scheduled( self::DIRTY_HOOK ) ) wp_schedule_single_event( time() + 15, self::DIRTY_HOOK );
        GML_Resource_Backfill::maybe_schedule();
    }

    private static function is_global_layout_post( WP_Post $post ) {
        $types = (array) apply_filters( 'gml_translation_global_layout_post_types', [
            'wp_block',
            'wp_template',
            'wp_template_part',
            'gp_elements',
            'ct_template',
            'elementor_library',
        ] );
        $types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
        return in_array( sanitize_key( $post->post_type ), $types, true );
    }

    private static function stale_post_keys( WP_Post $post ) {
        global $wpdb;
        $keys = [ 'post:' . sanitize_key( $post->post_type ) . ':' . (int) $post->ID ];
        if ( (int) get_option( 'page_on_front', 0 ) === (int) $post->ID ) $keys[] = 'role:front_page:' . (int) $post->ID;
        if ( (int) get_option( 'page_for_posts', 0 ) === (int) $post->ID ) $keys[] = 'role:posts_page:' . (int) $post->ID;
        foreach ( $keys as $key ) {
            // A new draft or internal post has no published manifest to retire.
            if ( ! GML_Resource_Manifest_Store::get_by_key( $key ) && $wpdb->last_error === '' ) continue;
            GML_Resource_Manifest_Store::mark_stale_by_key( $key, 'unpublished:' . (string) $post->post_modified_gmt );
            self::add_dirty( $key );
        }
    }
}
