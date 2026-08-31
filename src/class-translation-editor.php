<?php
/**
 * GML Translation Editor — AJAX handlers for viewing/editing translations
 *
 * Provides a Weglot-like interface for managing translated content:
 * - Browse all translations per language
 * - Search translations
 * - Manually edit translations (saved as 'manual' status)
 * - Delete individual translations
 *
 * @package GML_Translation_Core
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translation_Editor_Core {

    const TEXT_DOMAIN = 'gml-translate';

    public function __construct() {
        add_action( 'wp_ajax_gml_get_translations',    [ $this, 'ajax_get_translations' ] );
        add_action( 'wp_ajax_gml_save_translation',    [ $this, 'ajax_save_translation' ] );
        add_action( 'wp_ajax_gml_delete_translation',  [ $this, 'ajax_delete_translation' ] );
        add_action( 'wp_ajax_gml_retry_failed',        [ $this, 'ajax_retry_failed' ] );
        add_action( 'wp_ajax_gml_crawl_action',        [ $this, 'ajax_crawl_action' ] );
        add_action( 'wp_ajax_gml_crawl_status',        [ $this, 'ajax_crawl_status' ] );
    }

    /**
     * Get paginated translations for a language.
     */
    public function ajax_get_translations() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';

        $lang   = sanitize_key( $_POST['lang'] ?? '' );
        $search = sanitize_text_field( $_POST['search'] ?? '' );
        $page   = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $per    = 20;
        $offset = ( $page - 1 ) * $per;
        $filter = sanitize_text_field( $_POST['filter'] ?? 'all' ); // all, auto, manual

        $where = $wpdb->prepare( "WHERE target_lang = %s AND status IN ('auto','manual')", $lang );

        if ( $filter === 'auto' ) {
            $where .= " AND status = 'auto'";
        } elseif ( $filter === 'manual' ) {
            $where .= " AND status = 'manual'";
        }

        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND (source_text LIKE %s OR translated_text LIKE %s)", $like, $like );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
        $rows  = $wpdb->get_results(
            "SELECT id, source_hash, source_text, translated_text, context_type, status, updated_at
             FROM $table $where
             ORDER BY updated_at DESC
             LIMIT $per OFFSET $offset"
        );

        wp_send_json_success( [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'pages'      => ceil( $total / $per ),
            'per_page'   => $per,
        ] );
    }

    /**
     * Save a manual translation edit.
     */
    public function ajax_save_translation() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';

        $id         = (int) ( $_POST['id'] ?? 0 );
        $translated = sanitize_textarea_field( wp_unslash( $_POST['translated_text'] ?? '' ) );

        if ( ! $id || $translated === '' || strlen( $translated ) > 100000 ) {
            wp_send_json_error( 'Missing parameters' );
        }

        $saved = $wpdb->update( $table, [
            // Translation rows are plain text inserted into existing DOM text
            // nodes/attributes. Markup belongs to the source template and must
            // never be introduced by a manual translation.
            'translated_text' => $translated,
            'status'          => 'manual',
            'updated_at'      => current_time( 'mysql' ),
        ], [ 'id' => $id ] );
        if ( false === $saved ) {
            wp_send_json_error( __( 'Translation could not be saved.', static::TEXT_DOMAIN ) );
        }

        // A generation bump also invalidates Redis-backed page transients.
        GML_Page_Cache::invalidate();
        GML_Queue_Processor::clear_readiness_cache();

        // Invalidate translation dictionary cache
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT source_lang, target_lang FROM $table WHERE id = %d", $id
        ) );
        if ( $row ) {
            GML_Translator::invalidate_cache( $row->source_lang, $row->target_lang );
        }

        wp_send_json_success( [ 'message' => __( 'Translation saved.', static::TEXT_DOMAIN ) ] );
    }

    /**
     * Delete a single translation.
     */
    public function ajax_delete_translation() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
        }

        $table = $wpdb->prefix . 'gml_index';

        // Get lang info before deleting for cache invalidation
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT source_lang, target_lang FROM $table WHERE id = %d", $id
        ) );

        if ( false === $wpdb->delete( $table, [ 'id' => $id ] ) ) {
            wp_send_json_error( __( 'Translation could not be deleted.', static::TEXT_DOMAIN ) );
        }

        // Invalidate caches
        if ( $row ) {
            GML_Translator::invalidate_cache( $row->source_lang, $row->target_lang );
        }
        GML_Page_Cache::invalidate();
        GML_Queue_Processor::clear_readiness_cache();

        wp_send_json_success();
    }

    /**
     * Retry a bounded sample for one explicitly selected language.
     */
    public function ajax_retry_failed() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! $this->ai_translation_available() ) {
            wp_send_json_error( __( 'Multilingual AI Translation is disabled or the selected AI API key is missing.', static::TEXT_DOMAIN ) );
        }
        if ( GML_Queue_Processor::circuit_is_open() ) {
            wp_send_json_error( __( 'Translation is safety-paused. Test the saved AI connection successfully before retrying a sample.', static::TEXT_DOMAIN ) );
        }

        $lang = sanitize_key( $_POST['lang'] ?? '' );
        if ( $lang === '' ) {
            wp_send_json_error( __( 'Choose one language. Retrying every failed translation at once is disabled for quota safety.', static::TEXT_DOMAIN ) );
        }

        $count = GML_Queue_Processor::retry_failed( $lang, GML_Queue_Processor::RETRY_LIMIT );
        if ( $count < 1 ) {
            wp_send_json_error( __( 'No failed items were queued. Another retry sample may still be running.', static::TEXT_DOMAIN ) );
        }

        // This click is explicit approval for one limited sample only.
        update_option( 'gml_translation_paused', false );
        $languages = (array) get_option( 'gml_languages', [] );
        foreach ( $languages as &$language ) {
            $language['paused'] = ( $language['code'] ?? '' ) !== $lang;
        }
        unset( $language );
        update_option( 'gml_languages', $languages );
        wp_schedule_single_event( time(), GML_Queue_Processor::CRON_HOOK );

        wp_send_json_success( [
            'message' => sprintf( __( '%d failed items queued as a limited test sample.', static::TEXT_DOMAIN ), $count ),
            'count'   => $count,
        ] );
    }

    /**
     * Start/stop content crawl.
     */
    public function ajax_crawl_action() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $action = sanitize_text_field( $_POST['crawl_action'] ?? '' );

        if ( $action === 'start' ) {
            if ( ! $this->ai_translation_available() ) {
                wp_send_json_error( __( 'Multilingual AI Translation is disabled or the selected AI API key is missing.', static::TEXT_DOMAIN ) );
            }

            if ( GML_Queue_Processor::circuit_is_open() || GML_Queue_Processor::maybe_open_for_existing_failures() ) {
                wp_send_json_error( __( 'Translation is safety-paused. Test the saved AI connection and retry one limited language sample first.', static::TEXT_DOMAIN ) );
            }
            $started = GML_Content_Crawler::start_crawl( true );
            if ( is_wp_error( $started ) ) {
                wp_send_json_error( $started->get_error_message() );
            }
            if ( ! $started ) {
                wp_send_json_error( __( 'WordPress could not schedule the content crawl. The translation queue remains unchanged.', static::TEXT_DOMAIN ) );
            }

            // Resume the AI worker without changing multilingual site output.
            update_option( 'gml_translation_paused', false );

            // Un-pause all languages
            $langs = get_option( 'gml_languages', [] );
            foreach ( $langs as &$l ) {
                $l['paused'] = false;
            }
            update_option( 'gml_languages', $langs );

            wp_send_json_success( [ 'message' => __( 'Content crawl started.', static::TEXT_DOMAIN ) ] );
        } elseif ( $action === 'stop' ) {
            GML_Content_Crawler::stop_crawl();
            wp_send_json_success( [ 'message' => __( 'Content crawl stopped.', static::TEXT_DOMAIN ) ] );
        }

        wp_send_json_error( 'Invalid action' );
    }

    /**
     * Get crawl status.
     */
    public function ajax_crawl_status() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        wp_send_json_success( GML_Content_Crawler::get_status() );
    }

    protected function ai_translation_available() {
        return class_exists( 'GML_Translation_State' )
            && GML_Translation_State::multilingual_enabled()
            && GML_Translation_State::ai_available();
    }
}
