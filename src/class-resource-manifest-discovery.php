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

    /** Optional target explicitly bridges authoritative discovery to the queue. */
    public function discover( $subject, $queue_language = '' ) {
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
            GML_Resource_Manifest_Store::record_state( $resource, 'excluded' );
            return new WP_Error( 'gml_resource_excluded', 'Resource is excluded from manifest discovery.' );
        }
        $generation = GML_Resource_Manifest_Manager::global_generation();
        $html = $this->renderer->render( $resource );
        if ( $generation !== GML_Resource_Manifest_Manager::global_generation() ) {
            GML_Resource_Manifest_Store::mark_stale( $resource, $resource->get_source_revision() );
            return new WP_Error( 'gml_resource_changed', 'Global content changed during authoritative discovery.' );
        }
        $current = in_array($resource->get_type(), ['post','term','role'], true)
            ? GML_Resource_Identity::resolve($resource->get_key()) : GML_Resource_Identity::refresh($resource);
        if ( ! $current instanceof GML_Resource_Identity || $current->get_source_revision() !== $resource->get_source_revision() ) {
            GML_Resource_Manifest_Store::mark_stale( $resource, $current instanceof GML_Resource_Identity ? $current->get_source_revision() : '' );
            return new WP_Error( 'gml_resource_changed', 'Resource changed during authoritative discovery.' );
        }
        if ( is_wp_error( $html ) ) {
            if ( $html->get_error_code() === 'gml_resource_permanent_redirect' ) {
                return GML_Resource_Manifest_Store::record_state( $current, 'permanent_redirect', (array) $html->get_error_data() );
            }
            GML_Resource_Manifest_Store::record_state( $current, 'render_error' );
            return $html;
        }
        $parsed = $this->parser->parse( $html );
        $saved = GML_Resource_Manifest_Store::save_complete( $current, (array) ( $parsed['nodes'] ?? [] ) );
        if ( $saved !== true || $queue_language === '' ) return $saved;
        require_once __DIR__ . '/class-translator.php';
        $queued = ( new GML_Translation_Translator() )->discover( $parsed, $queue_language );
        if ( ( $queued['enqueue_result'] ?? false ) === false ) {
            return new WP_Error( 'gml_discovery_queue_failed', 'Manifest saved, but queue discovery was blocked or failed. Retrying discovery never resumes AI work.' );
        }
        return true;
    }
}
