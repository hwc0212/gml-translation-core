<?php
/** Bounded operational metadata; never store prompts, translations or credentials. */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-ai-http-transport.php';

class GML_Translation_Activity {
    const PREFIX = 'gml_translation_event_';
    const LIMIT = 100;

    public static function record( $event, array $context = [] ) {
        global $wpdb;
        $row = [ 'at' => time(), 'event' => sanitize_key( $event ) ];
        foreach ( [ 'engine', 'model', 'language', 'code', 'category', 'context', 'finish_reason' ] as $key ) {
            if ( isset( $context[$key] ) ) {
                $value = GML_AI_HTTP_Transport::redact( $context[$key] );
                $row[$key] = substr( sanitize_text_field( $value ), 0, 120 );
            }
        }
        foreach ( [ 'status', 'queue_id', 'items', 'attempts', 'until', 'input_tokens', 'output_tokens', 'max_output_tokens', 'latency_ms', 'calls', 'actor' ] as $key ) {
            if ( isset( $context[$key] ) && is_numeric( $context[$key] ) ) $row[$key] = max( 0, (int) $context[$key] );
        }
        if ( isset( $context['source_hash'] ) && preg_match( '/^[a-f0-9]{32}$/D', $context['source_hash'] ) ) $row['source_hash'] = $context['source_hash'];
        // Independent rows avoid a read/modify/write race between admin and worker.
        $name = self::PREFIX . wp_generate_uuid4();
        if ( ! add_option( $name, $row, '', false ) ) return false;
        $pattern = $wpdb->esc_like( self::PREFIX ) . '%';
        $old = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d, 100", $pattern, self::LIMIT ) );
        foreach ( (array) $old as $option ) delete_option( $option );
        return true;
    }

    public static function recent( $limit = 20 ) {
        if ( ! current_user_can( 'manage_options' ) ) return [];
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d", $wpdb->esc_like( self::PREFIX ) . '%', max( 1, min( self::LIMIT, (int) $limit ) ) ) );
        return array_values( array_filter( array_map( 'maybe_unserialize', (array) $rows ), 'is_array' ) );
    }
}
