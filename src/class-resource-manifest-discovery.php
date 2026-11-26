<?php
/** Builds a manifest from one authoritative rendered resource. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Manifest_Discovery {
    private $renderer;
    private $parser;

    public function __construct( $renderer = null, $parser = null ) {
        $this->renderer = $renderer ?: new GML_Authoritative_Renderer();
        $this->parser = $parser ?: new GML_HTML_Parser();
    }

    /** Optional target bridges discovery to the queue; output fences retry state. */
    public function discover( $subject, $queue_language = '', &$written_snapshot = null ) {
        return $this->discover_resource( $subject, $queue_language, $written_snapshot );
    }

    /** Explicit single-resource maintenance: no translation work or new recovery hints. */
    public function refresh_inventory( $subject, array $expected, $source_revision ) {
        $written_snapshot = [];
        return $this->discover_resource( $subject, '', $written_snapshot, [ $expected, $source_revision ] );
    }

    private function discover_resource( $subject, $queue_language, &$written_snapshot, ?array $inventory_request = null ) {
        global $wpdb;
        $written_snapshot = [];
        if ( $queue_language !== '' ) {
            $queue_language = GML_Language_Utils::normalize_code( $queue_language );
            $source = GML_Language_Utils::normalize_code( get_option( 'gml_source_lang', 'en' ) );
            if ( $queue_language === '' || $queue_language === $source
                || ! in_array( $queue_language, GML_Language_Utils::local_configured_codes( false, true ), true )
                || ! GML_Translation_State::multilingual_enabled() || ! GML_Translation_State::ai_available()
                || is_array( get_option( 'gml_translation_circuit_breaker', false ) ) ) {
                return new WP_Error( 'gml_discovery_queue_disabled', 'Select an enabled local target with available AI credentials and no active circuit breaker.' );
            }
        }
        if (is_string($subject) && GML_Resource_Manifest_Store::exclude_retired_key($subject)) return true;
        $resource = GML_Resource_Identity::resolve( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return new WP_Error( 'gml_resource_unknown', 'Resource identity could not be resolved.' );
        if ( ! $resource->is_eligible() ) {
            GML_Resource_Manifest_Store::record_state( $resource, 'excluded', [], null, null, $written_snapshot );
            return new WP_Error( 'gml_resource_excluded', 'Resource is excluded from manifest discovery.' );
        }
        $generation = GML_Resource_Manifest_Manager::global_generation();
        $before = GML_Resource_Manifest_Store::get_by_key( $resource->get_key() );
        if ( $wpdb->last_error !== '' ) return new WP_Error( 'gml_manifest_read' );
        $expected = GML_Resource_Manifest_Store::snapshot_version( $before );
        $written_snapshot = $expected;
        if ( $inventory_request !== null && ( $inventory_request[0] !== $expected
            || ! hash_equals( (string) $inventory_request[1], $resource->get_source_revision() ) ) ) {
            return new WP_Error( 'gml_resource_changed', 'Resource changed. Reload before refreshing inventory.' );
        }
        // Reuse the existing cache epoch as a conservative fence for post changes
        // during this explicit render, including products embedded by the page.
        $cache_generation = $inventory_request !== null ? GML_Page_Cache::generation() : null;
        $html = $this->renderer->render( $resource, $inventory_request !== null );
        if ( $generation !== GML_Resource_Manifest_Manager::global_generation()
            || ( $cache_generation !== null && $cache_generation !== GML_Page_Cache::generation() ) ) {
            GML_Resource_Manifest_Store::record_state( $resource, 'stale', [], $expected, $generation, $written_snapshot );
            return new WP_Error( 'gml_resource_changed', 'Global content changed during authoritative discovery.' );
        }
        $current = in_array($resource->get_type(), ['post','term','role'], true)
            ? GML_Resource_Identity::resolve($resource->get_key()) : GML_Resource_Identity::refresh($resource);
        if ( ! $current instanceof GML_Resource_Identity || $current->get_source_revision() !== $resource->get_source_revision() ) {
            GML_Resource_Manifest_Store::record_state( $current instanceof GML_Resource_Identity ? $current : $resource, 'stale', [], $expected, $generation, $written_snapshot );
            return new WP_Error( 'gml_resource_changed', 'Resource changed during authoritative discovery.' );
        }
        if ( is_wp_error( $html ) ) {
            if ( $html->get_error_code() === 'gml_resource_permanent_redirect' ) {
                return GML_Resource_Manifest_Store::record_state( $current, 'permanent_redirect', (array) $html->get_error_data(), $expected, $generation, $written_snapshot );
            }
            $saved = GML_Resource_Manifest_Store::record_state( $current, 'render_error', [], $expected, $generation, $written_snapshot );
            return is_wp_error( $saved ) ? $saved : $html;
        }
        $parsed = $this->parser->parse( $html );
        if ( $cache_generation !== null && $cache_generation !== GML_Page_Cache::generation() ) {
            GML_Resource_Manifest_Store::record_state( $resource, 'stale', [], $expected, $generation, $written_snapshot );
            return new WP_Error( 'gml_resource_changed', 'Source changed while parsing inventory.' );
        }
        $saved = GML_Resource_Manifest_Store::save_complete( $current, (array) ( $parsed['nodes'] ?? [] ), $expected, $generation, $written_snapshot, $inventory_request === null );
        if ( $saved !== true || $queue_language === '' ) return $saved;
        require_once __DIR__ . '/class-translator.php';
        $parsed['resource']=$current;
        $queued = ( new GML_Translation_Translator() )->discover( $parsed, $queue_language );
        if ( ( $queued['enqueue_result'] ?? false ) === false ) {
            return new WP_Error( 'gml_discovery_queue_failed', 'Manifest saved, but queue discovery was blocked or failed. Retrying discovery never resumes AI work.' );
        }
        return true;
    }
}
