<?php
/**
 * Shared, bounded WP-Cron translation queue processor.
 *
 * Product adapters only decide whether AI work is enabled. Queue locking,
 * circuit breaking, retry limits, persistence, and cache invalidation stay in
 * one implementation.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/class-translation-queue-scope.php';
require_once __DIR__ . '/class-translation-error.php';
require_once __DIR__ . '/class-translation-text.php';
require_once __DIR__ . '/class-atomic-option-lock.php';

abstract class GML_Translation_Queue_Processor {

    const TEXT_DOMAIN = 'gml-translate';
    const CRON_HOOK = 'gml_process_queue';
    const CIRCUIT_OPTION = 'gml_translation_circuit_breaker';
    const FAILURE_ACK_OPTION = 'gml_translation_failure_ack';
    const SAMPLE_OPTION = 'gml_translation_retry_sample_ids';
    const LOCK_OPTION = 'gml_translation_process_lock';
    const BACKOFF_OPTION = 'gml_translation_provider_backoff';
    const LEGACY_FAILURE_THRESHOLD = 100;
    const RETRY_LIMIT = 25;
    const SINGLE_FALLBACK_LIMIT = 3;
    const LOCK_TTL = 600;
    const BATCH_SIZE = 30;
	const MAX_BATCH_INPUT_BYTES = 24576;

    public function __construct() {
        add_filter( 'cron_schedules', [ static::class, 'add_cron_interval' ] );
        add_action( static::CRON_HOOK, [ $this, 'process_batch' ] );
        add_action( 'wp_loaded', [ $this, 'maybe_schedule_cron' ] );
    }

    public static function add_cron_interval( $schedules ) {
        if ( ! isset( $schedules['every_minute'] ) ) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every Minute', static::TEXT_DOMAIN ),
            ];
        }
        return $schedules;
    }

    public function maybe_schedule_cron() {
        if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }
        if (
            ! $this->translation_work_enabled() ||
            ! $this->ai_translation_available() ||
            ! GML_Translation_Queue_Scope::has_work_scope() ||
            static::circuit_is_open() ||
            static::maybe_open_for_existing_failures()
        ) {
            static::unschedule_cron();
            return;
        }
        if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
            static::ensure_scheduled();
        }
    }

    public static function ensure_scheduled() {
        $event = wp_get_scheduled_event( static::CRON_HOOK );
        if ( $event && $event->schedule === 'every_minute' ) return true;
        add_filter( 'cron_schedules', [ static::class, 'add_cron_interval' ] );
        $when = time() + 5;
        $result = wp_schedule_event( $when, 'every_minute', static::CRON_HOOK, [], true );
        if ( ! $result || is_wp_error( $result ) ) return false;
        if ( $event && $event->timestamp !== $when ) wp_unschedule_event( $event->timestamp, static::CRON_HOOK );
        return true;
    }

    public static function unschedule_cron() {
		wp_clear_scheduled_hook( static::CRON_HOOK );
    }

    protected function translation_work_enabled() {
        return class_exists( 'GML_Translation_State' ) && GML_Translation_State::work_enabled();
    }

    protected function ai_translation_available() {
        return class_exists( 'GML_Translation_State' ) && GML_Translation_State::ai_translation_enabled();
    }

    protected function create_api() {
        return new GML_Gemini_API();
    }

    protected function create_translator() {
        return new GML_Translator();
    }

    protected function create_parser() {
        return new GML_HTML_Parser();
    }

    public function process_batch() {
        if (
            get_option( 'gml_translation_paused', false ) ||
            ! $this->translation_work_enabled() ||
            ! $this->ai_translation_available() ||
            static::circuit_is_open() ||
            static::backoff_is_active() ||
            static::maybe_open_for_existing_failures()
        ) {
            return;
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . 'gml_queue';
        $sample_ids  = static::sample_ids();
        if ( get_option( static::SAMPLE_OPTION, [] ) && ! $sample_ids ) return;
        if ( ! GML_Translation_Queue_Scope::has_work_scope() ) {
            static::unschedule_cron();
            return;
        }
        $lock_token  = static::acquire_process_lock();
        if ( $lock_token === '' ) {
            return;
        }
        register_shutdown_function( [ static::class, 'release_process_lock' ], $lock_token );

        try {
            // Only the current lease owner may recover work left by a crashed worker.
            if ( ! static::recover_processing_rows( $lock_token, $wpdb, $queue_table ) ) return;

            $normal_languages = GML_Translation_Queue_Scope::normal_languages();
            $enabled_languages = GML_Translation_Queue_Scope::enabled_languages();
            $scopes = [];
            if ( $normal_languages ) {
                $placeholders = implode( ',', array_fill( 0, count( $normal_languages ), '%s' ) );
                $normal_sql = $wpdb->prepare( "target_lang IN ($placeholders)", $normal_languages );
                if ( $sample_ids ) $normal_sql .= ' AND id NOT IN (' . implode( ',', $sample_ids ) . ')';
                $current_scope = class_exists( 'GML_Translation_Readiness' )
                    ? GML_Translation_Readiness::current_queue_scope_sql( 'q' )
                    : '';
                if ( $current_scope !== '' ) $normal_sql .= ' AND ' . $current_scope;
                $scopes[] = '(' . $normal_sql . ')';
            }
            if ( $sample_ids && ! GML_Translation_Queue_Scope::sample_paused() && $enabled_languages ) {
                $placeholders = implode( ',', array_fill( 0, count( $enabled_languages ), '%s' ) );
                $scopes[] = '(' . $wpdb->prepare( "target_lang IN ($placeholders)", $enabled_languages ) . ' AND id IN (' . implode( ',', $sample_ids ) . '))';
            }
            if ( ! $scopes ) return;
            $scope_sql = ' AND (' . implode( ' OR ', $scopes ) . ')';
            $limit      = (int) static::BATCH_SIZE;
            $query      = "SELECT q.* FROM $queue_table q
                 WHERE status = 'pending' AND attempts < 3
                 $scope_sql";
            $order      = " ORDER BY target_lang ASC, context_type ASC, priority DESC, created_at ASC, id ASC LIMIT $limit";
            $last_batch = get_option( 'gml_translation_last_batch', [] );
            $last_lang  = is_array( $last_batch ) ? sanitize_key( $last_batch['language'] ?? '' ) : '';
            // Rotate languages under the existing worker lock; one batch still runs per tick.
            $items = $last_lang !== ''
                ? $wpdb->get_results( $query . $wpdb->prepare( ' AND target_lang > %s', $last_lang ) . $order )
                : [];
            if ( ! $items ) {
                $items = $wpdb->get_results( $query . $order );
            }

            if ( empty( $items ) ) {
                if ( $sample_ids && ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table WHERE status IN ('pending','processing') AND attempts < 3 AND id IN (" . implode( ',', $sample_ids ) . ')' ) ) {
                    static::complete_sample_mode();
                }
                return;
            }

            // One provider batch per cron run keeps runtime and API cost bounded.
            $first      = reset( $items );
            $first_type = static::api_type( $first->context_type ?? '' );
            $items      = array_values( array_filter( $items, static function( $item ) use ( $first, $first_type ) {
                return $item->target_lang === $first->target_lang
                    && static::api_type( $item->context_type ?? '' ) === $first_type;
            } ) );
			if ( ! static::renew_process_lock( $lock_token ) ) return;
			$bounded_items = [];
			$input_bytes   = 0;
			$max_item      = class_exists( 'GML_Translator' ) ? GML_Translator::MAX_SOURCE_BYTES : 32768;
			foreach ( $items as $item ) {
				$item_bytes = strlen( (string) $item->source_text );
				if ( $item_bytes > $max_item ) {
					$wpdb->update( $queue_table, [
						'status'        => 'failed',
						'attempts'      => 3,
						'error_message' => GML_Translation_Error::stored_message( [ 'code' => 'source_too_large' ], 'Source segment exceeds the translation size limit.' ),
						'processed_at'  => current_time( 'mysql' ),
					], [ 'id' => (int) $item->id ] );
					continue;
				}
				if ( $bounded_items && $input_bytes + $item_bytes > static::MAX_BATCH_INPUT_BYTES ) {
					break;
				}
				$bounded_items[] = $item;
				$input_bytes += $item_bytes;
			}
			$items = $bounded_items;
            $ids = array_map( static function( $item ) { return (int) $item->id; }, $items );
            if ( empty( $ids ) ) {
                return;
            }
            if ( ! static::renew_process_lock( $lock_token ) ) return;
            $wpdb->query( "UPDATE $queue_table SET status = 'processing' WHERE id IN (" . implode( ',', $ids ) . ')' );

            $api        = $this->create_api();
            $translator = $this->create_translator();
            $parser     = $this->create_parser();
            $saved      = false;
            $api_items  = [];
            foreach ( $items as $item ) {
                if ( GML_Translation_Text::is_technical_only( $item->source_text ) ) {
                    if ( ! static::renew_process_lock( $lock_token ) ) return;
                    $saved = $this->save_translation_result(
                        $wpdb,
                        $queue_table,
                        $item,
                        (string) $item->source_text,
                        $translator,
                        $parser
                    ) || $saved;
                    continue;
                }
                $api_items[] = $item;
            }
            $items = $api_items;
            if ( empty( $items ) ) {
                if ( $saved ) {
                    static::clear_backoff();
                    static::invalidate_translation_state();
                }
                if ( $sample_ids && ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_table WHERE status IN ('pending','processing') AND attempts < 3 AND id IN (" . implode( ',', $sample_ids ) . ')' ) ) {
                    static::complete_sample_mode();
                }
                return;
            }

            $texts      = array_map( static function( $item ) { return (string) $item->source_text; }, $items );
            $source     = (string) $first->source_lang;
            $target     = (string) $first->target_lang;
            $activity = [ 'token' => $lock_token, 'started' => time(), 'language' => $target ];

            try {
                if ( ! static::renew_process_lock( $lock_token ) ) return;
                update_option( 'gml_translation_last_batch', $activity, false );
                $translated = $api->translate_batch( $texts, $source, $target, $first_type );
                if ( ! static::renew_process_lock( $lock_token ) ) return;
            } catch ( Throwable $exception ) {
                if ( ! static::renew_process_lock( $lock_token ) ) return;
                $diagnostic = static::provider_failure( $api, $exception->getMessage() );
                if ( $diagnostic['category'] === 'transient' ) {
                    $this->release_processing_items( $wpdb, $queue_table, $ids );
                    static::register_backoff( $diagnostic, $api );
                    return;
                }
                if ( static::is_provider_wide_failure( $exception->getMessage(), $api ) ) {
                    if ( $sample_ids ) {
                        $this->restore_sample_as_failed( $wpdb, $queue_table, $sample_ids, $exception->getMessage() );
                    }
                    $this->release_processing_items( $wpdb, $queue_table, $ids );
                    static::open_circuit( $exception->getMessage(), [
                        'engine' => method_exists( $api, 'get_engine' ) ? $api->get_engine() : '',
                        'model'  => method_exists( $api, 'get_model' ) ? $api->get_model() : '',
                    ] );
                    return;
                }

                // A malformed batch response can be isolated with at most three
                // single-item calls; all remaining rows return to pending.
                foreach ( $items as $index => $item ) {
                    if ( $index >= static::SINGLE_FALLBACK_LIMIT ) {
                        $this->release_processing_items( $wpdb, $queue_table, [ $item->id ] );
                        continue;
                    }
                    try {
                        if ( ! static::renew_process_lock( $lock_token ) ) return;
                        $single = $api->translate_batch( [ $item->source_text ], $source, $target, $first_type );
                        if ( ! static::renew_process_lock( $lock_token ) ) return;
                        $saved  = $this->save_translation_result( $wpdb, $queue_table, $item, $single[0] ?? null, $translator, $parser ) || $saved;
                    } catch ( Throwable $single_exception ) {
                        if ( ! static::renew_process_lock( $lock_token ) ) return;
                        $diagnostic = static::provider_failure( $api, $single_exception->getMessage() );
                        if ( $diagnostic['category'] === 'transient' ) {
                            $this->release_processing_items( $wpdb, $queue_table, $ids );
                            static::register_backoff( $diagnostic, $api );
                            if ( $saved ) static::invalidate_translation_state();
                            return;
                        }
                        if ( static::is_provider_wide_failure( $single_exception->getMessage(), $api ) ) {
                            if ( $sample_ids ) $this->restore_sample_as_failed( $wpdb, $queue_table, $sample_ids, $single_exception->getMessage() );
                            $this->release_processing_items( $wpdb, $queue_table, $ids );
                            static::open_circuit( $single_exception->getMessage(), [
                                'engine' => method_exists( $api, 'get_engine' ) ? $api->get_engine() : '',
                                'model'  => method_exists( $api, 'get_model' ) ? $api->get_model() : '',
                            ] );
                            return;
                        }
                        $this->fail_or_retry_item( $wpdb, $queue_table, $item, $single_exception->getMessage(), $diagnostic );
                    }
                }
                $translated = null;
            }

            if ( is_array( $translated ) ) {
                foreach ( $items as $index => $item ) {
                    if ( ! static::renew_process_lock( $lock_token ) ) return;
                    $saved = $this->save_translation_result(
                        $wpdb,
                        $queue_table,
                        $item,
                        $translated[ $index ] ?? null,
                        $translator,
                        $parser
                    ) || $saved;
                }
            }

            if ( ! static::renew_process_lock( $lock_token ) ) return;
            if ( $saved ) {
                static::clear_backoff();
                static::invalidate_translation_state();
            }

            if ( $sample_ids ) {
                $remaining = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $queue_table
                     WHERE status IN ('pending','processing') AND attempts < 3
                     AND id IN (" . implode( ',', $sample_ids ) . ')'
                );
                if ( $remaining === 0 ) {
                    static::complete_sample_mode();
                }
            }
        } finally {
            if ( isset( $activity ) && GML_Atomic_Option_Lock::is_owner( static::LOCK_OPTION, $lock_token ) ) {
                $activity['finished'] = time();
                update_option( 'gml_translation_last_batch', $activity, false );
            }
            static::release_process_lock( $lock_token );
            // Replace legacy single events after a batch, without waking a paused queue.
            if ( $this->translation_work_enabled() && GML_Translation_Queue_Scope::has_work_scope() && ! static::circuit_is_open() ) static::ensure_scheduled();
        }
    }

    private static function api_type( $context ) {
        $context = trim( (string) $context );
        if ( $context === 'seo_title' ) return 'seo_title';
        if ( $context === 'seo_meta' ) return 'seo';
        return 'text';
    }

    private static function sample_ids() {
        $raw = (array) get_option( static::SAMPLE_OPTION, [] );
        if ( count( $raw ) > static::RETRY_LIMIT ) return [];
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ), static function( $id ) { return $id > 0; } ) ) );
        return count( $ids ) === count( $raw ) ? $ids : [];
    }

    protected static function acquire_process_lock() {
        return GML_Atomic_Option_Lock::acquire( static::LOCK_OPTION, static::LOCK_TTL );
    }

    public static function release_process_lock( $token ) {
        return GML_Atomic_Option_Lock::release( static::LOCK_OPTION, $token );
    }

    protected static function renew_process_lock( $token ) {
        return GML_Atomic_Option_Lock::refresh( static::LOCK_OPTION, $token, static::LOCK_TTL );
    }

    protected static function recover_processing_rows( $token, $wpdb, $table ) {
        if ( ! static::renew_process_lock( $token ) ) return false;
        $wpdb->query( "UPDATE $table SET status = 'pending' WHERE status = 'processing'" );
        return true;
    }

    private function release_processing_items( $wpdb, $table, array $ids ) {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( $ids ) {
            $wpdb->query( "UPDATE $table SET status = 'pending' WHERE status = 'processing' AND id IN (" . implode( ',', $ids ) . ')' );
        }
    }

    private function restore_sample_as_failed( $wpdb, $table, array $ids, $message ) {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) return;
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET status = 'failed', attempts = 3, error_message = %s, processed_at = %s
             WHERE status IN ('pending','processing') AND id IN (" . implode( ',', $ids ) . ')',
            GML_Translation_Error::stored_message( [], $message ),
            current_time( 'mysql' )
        ) );
    }

    private function save_translation_result( $wpdb, $table, $item, $translated, $translator, $parser ) {
        if ( ! is_string( $translated ) || trim( $translated ) === '' ) {
            $this->fail_or_retry_item( $wpdb, $table, $item, 'Empty translation result', [ 'code' => 'empty_result' ] );
            return false;
        }
        if ( ! $parser->verify_brand_protection( $item->source_text, $translated ) ) {
            $this->fail_or_retry_item( $wpdb, $table, $item, 'Protected brand term changed or was removed', [ 'code' => 'protected_term' ] );
            return false;
        }
        try {
            $saved = $translator->save_to_index(
                $item->source_hash,
                $item->source_text,
                $translated,
                $item->source_lang,
                $item->target_lang,
                $item->context_type,
                'auto'
            );
            if ( $saved !== true ) {
                throw new RuntimeException( 'Translation index write failed' );
            }
            if ( false === $wpdb->update( $table, [
                'status'       => 'completed',
                'processed_at' => current_time( 'mysql' ),
                'error_message'=> null,
            ], [ 'id' => $item->id ] ) ) {
                throw new RuntimeException( 'Translation queue update failed' );
            }
            return true;
        } catch ( Throwable $exception ) {
            unset( $exception );
            $this->fail_or_retry_item( $wpdb, $table, $item, 'Local translation save failed', [ 'code' => 'local_save_failed' ] );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GML: Local translation save failed for queue item #' . (int) $item->id . '.' );
			}
            return false;
        }
    }

    private function fail_or_retry_item( $wpdb, $table, $item, $message, array $error = [] ) {
        $attempts = (int) $item->attempts + 1;
        $wpdb->update( $table, [
            'status'        => $attempts >= 3 ? 'failed' : 'pending',
            'attempts'      => $attempts,
            'error_message' => GML_Translation_Error::stored_message( $error, $message ),
            'processed_at'  => current_time( 'mysql' ),
        ], [ 'id' => $item->id ] );
    }

    private static function safe_error_message( $message ) {
        $message = class_exists( 'GML_AI_HTTP_Transport' )
            ? GML_AI_HTTP_Transport::redact( $message )
            : sanitize_text_field( $message );
        return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
    }

    public static function get_queue_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'gml_queue';
        $stats = [];
        foreach ( [ 'pending', 'processing', 'completed', 'failed' ] as $status ) {
            $stats[ $status ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) );
        }
        $stats['total'] = array_sum( $stats );
        return $stats;
    }

    public static function clear_completed( $days_old = 7 ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'gml_queue';
        $days_old = max( 1, min( 365, (int) $days_old ) );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ) );
    }

    public static function retry_failed( $lang = '', $limit = null ) {
        if ( static::circuit_is_open() || $lang === '' || get_option( static::SAMPLE_OPTION, [] ) || ! in_array( $lang, GML_Translation_Queue_Scope::enabled_languages(), true ) ) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'gml_queue';
        static::reconcile_resolved_failures( $lang );
        $limit = max( 1, min( static::RETRY_LIMIT, null === $limit ? static::RETRY_LIMIT : (int) $limit ) );
        $current_scope = class_exists( 'GML_Translation_Readiness' )
            ? GML_Translation_Readiness::current_queue_scope_sql( 'q' )
            : '';
        $current_scope = $current_scope !== '' ? ' AND ' . $current_scope : '';
        $ids   = $wpdb->get_col( $wpdb->prepare(
            "SELECT q.id FROM $table q WHERE q.status = 'failed' AND q.target_lang = %s$current_scope
             ORDER BY priority DESC, created_at ASC LIMIT %d",
            sanitize_key( $lang ),
            $limit
        ) );
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        if ( ! $ids ) return 0;

        $updated = $wpdb->query(
            "UPDATE $table SET status = 'pending', attempts = 0, error_message = NULL
             WHERE status = 'failed' AND id IN (" . implode( ',', $ids ) . ')'
        );
        if ( ! $updated ) return 0;

        update_option( GML_Translation_Queue_Scope::NORMAL_OPTION, (int) ( ! get_option( 'gml_translation_paused', false ) && GML_Translation_Queue_Scope::normal_enabled() ), false );
        update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, 1, false );
        update_option( static::SAMPLE_OPTION, $ids, false );
        static::clear_readiness_cache();
        return (int) $updated;
    }

    private static function complete_sample_mode() {
        GML_Translation_Queue_Scope::finish_sample();
    }

    public static function circuit_is_open() {
        return is_array( get_option( static::CIRCUIT_OPTION, false ) );
    }

    public static function get_circuit_breaker() {
        $value = get_option( static::CIRCUIT_OPTION, false );
        return is_array( $value ) ? $value : [];
    }

    public static function open_circuit( $message, array $context = [] ) {
        $failure = GML_Translation_Error::classify( [], $message );
        update_option( static::CIRCUIT_OPTION, [
            'opened_at' => current_time( 'mysql' ),
            'message'   => static::safe_error_message( $message ),
            'code'      => $failure['code'],
            'category'  => $failure['category'],
            'engine'    => sanitize_key( $context['engine'] ?? '' ),
            'model'     => sanitize_text_field( $context['model'] ?? '' ),
        ], false );
        update_option( 'gml_translation_paused', true, false );
        // Keep the retry IDs isolated after an error; normal Start All must not absorb them.
        update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, 1, false );
        static::clear_readiness_cache();
        static::unschedule_cron();
    }

    public static function clear_circuit_breaker() {
        $cleared = static::circuit_is_open() || (bool) get_option( static::BACKOFF_OPTION, false );
        if ( static::circuit_is_open() ) delete_option( static::CIRCUIT_OPTION );
        static::clear_backoff();
        update_option( static::FAILURE_ACK_OPTION, [
            'count' => static::failed_count(),
            'at'    => current_time( 'mysql' ),
        ], false );
        update_option( 'gml_translation_paused', true, false );
        static::clear_readiness_cache();
        return $cleared;
    }

    public static function maybe_open_for_existing_failures() {
        if ( static::circuit_is_open() ) return true;
        $counts = static::get_actionable_failure_counts();
        if ( $counts['new'] < static::LEGACY_FAILURE_THRESHOLD ) return false;
        static::open_circuit( sprintf(
            __( 'Translation paused for safety: %d failed items require provider verification and a limited retry sample.', static::TEXT_DOMAIN ),
            $counts['new']
        ) );
        return true;
    }

    private static function failed_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gml_queue WHERE status = 'failed'" );
    }

    public static function is_provider_wide_failure( $message, $provider = null ) {
        $failure = static::provider_failure( $provider, $message );
        if ( $failure['category'] === 'configuration' ) return true;

        $message = strtolower( (string) $message );
        if ( $message === '' || strpos( $message, 'prompt blocked:' ) !== false ) return false;
        foreach ( [
            'api key not configured', ' api error:', 'unauthorized',
            'forbidden', 'authentication', 'invalid api key', 'model not found',
            'model is no longer available',
        ] as $pattern ) {
            if ( strpos( $message, $pattern ) !== false ) return true;
        }
        return false;
    }

    public static function language_is_index_ready( $lang ) {
        return class_exists( 'GML_Translation_Readiness' )
            && GML_Translation_Readiness::language_is_index_ready( $lang );
    }

    public static function get_failure_summary( $lang = '', $limit = 5 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gml_queue';
        $limit = max( 1, min( 10, (int) $limit ) );
        $where = "WHERE q.status = 'failed'";
        if ( $lang !== '' ) {
            $where .= $wpdb->prepare( ' AND q.target_lang = %s', sanitize_key( $lang ) );
        }
        $rows = $wpdb->get_results(
            "SELECT q.error_message, COUNT(*) AS item_count FROM $table q $where
             GROUP BY q.error_message ORDER BY item_count DESC LIMIT 100"
        );
        return static::group_failure_summary( $rows, $limit );
    }

    /** Current, unresolved failure reasons for administration and recovery. */
    public static function get_actionable_failure_summary( $lang = '', $limit = 5 ) {
        $scope = class_exists( 'GML_Translation_Readiness' )
            ? GML_Translation_Readiness::current_queue_scope_sql( 'q' )
            : '';
        if ( $scope === '' ) return static::get_failure_summary( $lang, $limit );

        global $wpdb;
        $table = $wpdb->prefix . 'gml_queue';
        $limit = max( 1, min( 10, (int) $limit ) );
        $where = "WHERE q.status='failed' AND $scope";
        if ( $lang !== '' ) {
            $where .= $wpdb->prepare( ' AND q.target_lang=%s', sanitize_key( $lang ) );
        }
        $rows = $wpdb->get_results(
            "SELECT q.error_message,COUNT(*) AS item_count FROM $table q $where
             GROUP BY q.error_message ORDER BY item_count DESC LIMIT 100"
        );
        return static::group_failure_summary( $rows, $limit );
    }

    private static function group_failure_summary( $rows, $limit ) {
        $groups = [];
        foreach ( (array) $rows as $row ) {
            $failure = GML_Translation_Error::classify( [], $row->error_message );
            $code = $failure['code'];
            if ( ! isset( $groups[ $code ] ) ) {
                $groups[ $code ] = (object) [
                    'error_code' => $code, 'category' => $failure['category'],
                    'error_message' => GML_Translation_Error::label( $code ), 'item_count' => 0,
                ];
            }
            $groups[ $code ]->item_count += (int) $row->item_count;
        }
        usort( $groups, static function( $a, $b ) { return $b->item_count <=> $a->item_count; } );
        return array_slice( $groups, 0, $limit );
    }

    public static function get_failure_counts() {
        $failed = static::failed_count();
        $ack = get_option( static::FAILURE_ACK_OPTION, 0 );
        if ( is_array( $ack ) && ! empty( $ack['at'] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'gml_queue';
            $new = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status='failed' AND processed_at >= %s",
                sanitize_text_field( $ack['at'] )
            ) );
            $acknowledged = max( 0, $failed - $new );
        } else {
            $acknowledged = min( $failed, max( 0, (int) $ack ) );
        }
        return [ 'total' => $failed, 'acknowledged' => $acknowledged, 'new' => max( 0, $failed - $acknowledged ) ];
    }

    /**
     * Failures eligible for retry and safety accounting on the current site.
     * Historical rows remain stored and visible through get_failure_counts().
     */
    public static function get_actionable_failure_counts() {
        $scope = class_exists( 'GML_Translation_Readiness' )
            ? GML_Translation_Readiness::current_queue_scope_sql( 'q' )
            : '';
        if ( $scope === '' ) return static::get_failure_counts();

        $cached = wp_cache_get( 'gml_actionable_failure_counts', 'gml_translate' );
        if ( is_array( $cached ) ) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'gml_queue';
        $failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table q WHERE q.status='failed' AND $scope" );
        $ack = get_option( static::FAILURE_ACK_OPTION, 0 );
        if ( is_array( $ack ) && ! empty( $ack['at'] ) ) {
            $new = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table q WHERE q.status='failed' AND q.processed_at >= %s AND $scope",
                sanitize_text_field( $ack['at'] )
            ) );
            $acknowledged = max( 0, $failed - $new );
        } else {
            $acknowledged = min( $failed, max( 0, (int) $ack ) );
        }
        $counts = [ 'total' => $failed, 'acknowledged' => $acknowledged, 'new' => max( 0, $failed - $acknowledged ) ];
        wp_cache_set( 'gml_actionable_failure_counts', $counts, 'gml_translate', 60 );
        return $counts;
    }

    public static function get_failure_details( $lang = '', $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gml_queue';
        $limit = max( 1, min( 50, (int) $limit ) );
        $where = "WHERE status='failed'";
        if ( $lang !== '' ) $where .= $wpdb->prepare( ' AND target_lang=%s', sanitize_key( $lang ) );
        $rows = $wpdb->get_results( "SELECT id, source_text, target_lang, context_type, attempts, error_message, created_at, processed_at FROM $table $where ORDER BY COALESCE(processed_at,created_at) DESC, id DESC LIMIT $limit" );
        foreach ( (array) $rows as $row ) {
            $failure = GML_Translation_Error::classify( [], $row->error_message );
            $row->error_code = $failure['code'];
            $row->error_label = GML_Translation_Error::label( $failure['code'] );
            $row->category = $failure['category'];
            $row->safe_message = $failure['message'];
        }
        return $rows;
    }

    public static function get_backoff() {
        $value = get_option( static::BACKOFF_OPTION, [] );
        return is_array( $value ) ? $value : [];
    }

    public static function backoff_is_active() {
        $value = static::get_backoff();
        if ( ! $value || (int) ( $value['until'] ?? 0 ) <= time() ) {
            if ( $value ) static::clear_backoff();
            return false;
        }
        return true;
    }

    private static function register_backoff( array $failure, $provider ) {
        $previous = static::get_backoff();
        $count = ( $previous['code'] ?? '' ) === $failure['code'] ? min( 8, (int) ( $previous['count'] ?? 0 ) + 1 ) : 1;
        $base = max( $failure['retry_after'], min( 3600, 60 * ( 2 ** ( $count - 1 ) ) ) );
        $delay = min( 3600, $base + wp_rand( 0, min( 30, max( 1, (int) floor( $base / 4 ) ) ) ) );
        update_option( static::BACKOFF_OPTION, [
            'code' => $failure['code'], 'message' => $failure['message'], 'count' => $count, 'until' => time() + $delay,
            'engine' => is_object( $provider ) && method_exists( $provider, 'get_engine' ) ? sanitize_key( $provider->get_engine() ) : '',
            'model' => is_object( $provider ) && method_exists( $provider, 'get_model' ) ? sanitize_text_field( $provider->get_model() ) : '',
        ], false );
    }

    private static function clear_backoff() {
        delete_option( static::BACKOFF_OPTION );
    }

    private static function provider_failure( $provider, $message ) {
        $error = is_object( $provider ) && method_exists( $provider, 'get_last_error' ) ? $provider->get_last_error() : [];
        return GML_Translation_Error::classify( $error, $message );
    }

    private static function invalidate_translation_state() {
        if ( class_exists( 'GML_Page_Cache' ) ) GML_Page_Cache::invalidate();
        static::clear_readiness_cache();
    }

    private static function reconcile_resolved_failures( $lang ) {
        global $wpdb;
        $queue = $wpdb->prefix . 'gml_queue';
        $index = $wpdb->prefix . 'gml_index';
        $where = $wpdb->prepare( 'q.target_lang=%s', sanitize_key( $lang ) );
        $updated = (int) $wpdb->query(
            "UPDATE $queue q INNER JOIN $index i
             ON i.source_hash=q.source_hash AND i.source_lang=q.source_lang AND i.target_lang=q.target_lang
             SET q.status='completed', q.error_message=NULL, q.processed_at=NOW()
             WHERE q.status='failed' AND $where AND i.status IN ('auto','manual')"
        );
        if ( $updated ) static::clear_readiness_cache();
        return $updated;
    }

    public static function clear_readiness_cache( $lang = '' ) {
        unset( $lang );
        wp_cache_delete( 'gml_actionable_failure_counts', 'gml_translate' );
        if ( class_exists( 'GML_Translation_Readiness' ) ) {
            GML_Translation_Readiness::clear_cache();
        }
    }
}
