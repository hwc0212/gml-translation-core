<?php
/** Bounded, crash-safe shadow manifest backfill. It never starts AI work. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Backfill {
    const HOOK = 'gml_resource_manifest_backfill';
    const OPTION = 'gml_resource_backfill_state';
    const LOCK = 'gml_resource_manifest_backfill_lock';
    const BATCH = 5;

    public static function register_hooks() { add_action( self::HOOK, [ __CLASS__, 'run_batch' ] ); }

    public static function state() {
        $state = (array) get_option( self::OPTION, [] );
        return wp_parse_args( $state, [ 'status' => 'pending', 'phase' => 'roles', 'cursor' => 0, 'updated_at' => 0 ] );
    }

    public static function reset_pending( $reason = '' ) {
        update_option( self::OPTION, [ 'status' => 'pending', 'phase' => 'roles', 'cursor' => 0, 'reason' => substr( $reason, 0, 64 ), 'updated_at' => time() ], false );
        self::clear_language_readiness();
    }

    public static function pause() {
        $state = self::state(); $state['status'] = 'paused'; $state['updated_at'] = time();
        update_option( self::OPTION, $state, false ); wp_clear_scheduled_hook( self::HOOK );
        self::clear_language_readiness();
    }

    public static function resume() {
        $state = self::state(); $state['status'] = 'pending'; $state['updated_at'] = time();
        update_option( self::OPTION, $state, false ); self::maybe_schedule();
        self::clear_language_readiness();
    }

    public static function maybe_schedule() {
        $state = self::state();
        if ( in_array( $state['status'], [ 'pending', 'running' ], true ) && ! wp_next_scheduled( self::HOOK ) ) wp_schedule_single_event( time() + 30, self::HOOK );
    }

    public static function run_batch() {
        $state = self::state();
        if ( $state['status'] === 'paused' || $state['status'] === 'complete' || ! GML_Resource_Manifest_Store::tables_ready() ) return;
        self::maybe_schedule(); // A process exit must not consume the only wakeup.
        $token = GML_Atomic_Option_Lock::acquire( self::LOCK, 180 );
        if ( $token === '' ) { self::maybe_schedule(); return; }
        try {
            add_option( self::OPTION, self::state(), '', false );
            $state = self::state();
            if ( in_array( $state['status'], [ 'paused', 'complete' ], true ) ) return;
            $running = $state;
            $running['status'] = 'running'; $running['updated_at'] = time();
            if ( ! self::checkpoint( $state, $running ) && self::state() !== $running ) return;
            $state = $running;
            $resources = self::next_resources( $state );
            if ( $resources === null ) return; // Database error: retry from the saved checkpoint.
            $discovery = new GML_Resource_Manifest_Discovery();
            foreach ( $resources as $resource ) {
                if ( ! GML_Atomic_Option_Lock::refresh( self::LOCK, $token, 180 ) ) return;
                // The dirty event owns these resources (including backoff).
                if ( in_array( $resource->get_key(), (array) get_option( GML_Resource_Manifest_Manager::DIRTY_OPTION, [] ), true ) ) continue;
                $result = $discovery->discover( $resource );
                if ( $result !== true ) {
                    $row = GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
                    if ( ! $row || ! in_array( $row->discovery_state, [ 'excluded', 'permanent_redirect' ], true ) ) {
                        GML_Resource_Manifest_Manager::add_dirty( $resource->get_key() );
                        GML_Resource_Manifest_Manager::record_failure( $resource->get_key(), $result, 'backfill' );
                    }
                }
            }
            $state['status'] = $state['phase'] === 'complete' ? 'complete' : 'pending';
            $state['updated_at'] = time();
            self::checkpoint( $running, $state );
            self::clear_language_readiness();
        } finally {
            GML_Atomic_Option_Lock::release( self::LOCK, $token );
            self::maybe_schedule();
        }
        if ( $state['status'] !== 'complete' ) self::maybe_schedule();
    }

    /** Do not overwrite a reset or explicit pause made during rendering. */
    private static function checkpoint( array $expected, array $next ) {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION ) );
        $current = wp_parse_args( (array) maybe_unserialize( $raw ), [ 'status'=>'pending', 'phase'=>'roles', 'cursor'=>0, 'updated_at'=>0 ] );
        if ( $raw === null || $current != $expected ) return false;
        $saved = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s,autoload='no'
             WHERE option_name=%s AND BINARY option_value=BINARY %s",
            maybe_serialize( $next ), self::OPTION, $raw
        ) );
        if ( $saved === 1 ) {
            wp_cache_delete( self::OPTION, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
        return $saved === 1;
    }

    private static function next_resources( array &$state ) {
        if ( $state['phase'] === 'roles' ) {
            $state['phase'] = 'posts'; $state['cursor'] = 0;
            return array_values( array_filter( [ GML_Resource_Identity::front_page(), GML_Resource_Identity::posts_page() ] ) );
        }
        if ( $state['phase'] === 'posts' ) {
            global $wpdb;
            $types = get_post_types( [ 'public' => true ], 'names' ); unset( $types['attachment'] );
            $types = array_values( array_map( 'sanitize_key', $types ) );
            $type_sql = implode( ',', array_fill( 0, count( $types ), '%s' ) );
            $args = array_merge( $types, [ (int) $state['cursor'], self::BATCH ] );
            $ids = $types ? $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($type_sql) AND ID>%d ORDER BY ID ASC LIMIT %d",
                $args
            ) ) : [];
            if ( $types && $wpdb->last_error !== '' ) return null;
            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
            if ( $ids ) { $state['cursor'] = max( $ids ); return array_values( array_filter( array_map( [ 'GML_Resource_Identity', 'for_post' ], $ids ) ) ); }
            $state['phase'] = 'terms'; $state['cursor'] = 0;
        }
        if ( $state['phase'] === 'terms' ) {
            global $wpdb;
            $taxonomies = array_values( array_map( 'sanitize_key', get_taxonomies( [ 'public' => true ], 'names' ) ) );
            $tax_sql = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
            $args = array_merge( $taxonomies, [ (int) $state['cursor'], self::BATCH ] );
            $rows = $taxonomies ? $wpdb->get_results( $wpdb->prepare(
                "SELECT term_id,taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($tax_sql) AND term_id>%d ORDER BY term_id ASC LIMIT %d",
                $args
            ) ) : [];
            if ( $taxonomies && $wpdb->last_error !== '' ) return null;
            if ( $rows ) {
                $state['cursor'] = max( array_map( static function( $row ) { return (int) $row->term_id; }, $rows ) );
                $terms = array_map( static function( $row ) { return get_term( (int) $row->term_id, $row->taxonomy ); }, $rows );
                return array_values( array_filter( array_map( [ 'GML_Resource_Identity', 'for_term' ], $terms ) ) );
            }
            $state['phase'] = 'archives'; $state['cursor'] = 0;
        }
        if ( $state['phase'] === 'archives' ) {
            $types = array_values( get_post_types( [ 'public' => true, 'has_archive' => true ], 'names' ) );
            $slice = array_slice( $types, (int) $state['cursor'], self::BATCH );
            if ( $slice ) { $state['cursor'] += count( $slice ); return array_values( array_filter( array_map( [ 'GML_Resource_Identity', 'for_archive' ], $slice ) ) ); }
            $state['phase'] = 'complete'; $state['cursor'] = 0;
        }
        return [];
    }

    private static function clear_language_readiness() {
        if ( class_exists( 'GML_Translation_Readiness' ) ) GML_Translation_Readiness::clear_cache();
    }
}
