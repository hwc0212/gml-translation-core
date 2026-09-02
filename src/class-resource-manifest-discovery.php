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

    public function discover( $subject ) {
        $resource = GML_Resource_Identity::resolve( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return new WP_Error( 'gml_resource_unknown', 'Resource identity could not be resolved.' );
        if ( ! $resource->is_eligible() ) {
            GML_Resource_Manifest_Store::record_state( $resource, 'excluded' );
            return new WP_Error( 'gml_resource_excluded', 'Resource is excluded from manifest discovery.' );
        }
        $generation = GML_Resource_Manifest_Manager::global_generation();
        $html = $this->renderer->render( $resource );
        if ( is_wp_error( $html ) ) {
            GML_Resource_Manifest_Store::record_state( $resource, 'render_error' );
            return $html;
        }
        if ( $generation !== GML_Resource_Manifest_Manager::global_generation() ) {
            GML_Resource_Manifest_Store::mark_stale( $resource, $resource->get_source_revision() );
            return new WP_Error( 'gml_resource_changed', 'Global content changed during authoritative discovery.' );
        }
        $current = GML_Resource_Identity::refresh( $resource );
        if ( ! $current instanceof GML_Resource_Identity || $current->get_source_revision() !== $resource->get_source_revision() ) {
            GML_Resource_Manifest_Store::mark_stale( $resource, $current instanceof GML_Resource_Identity ? $current->get_source_revision() : '' );
            return new WP_Error( 'gml_resource_changed', 'Resource changed during authoritative discovery.' );
        }
        $parsed = $this->parser->parse( $html );
        return GML_Resource_Manifest_Store::save_complete( $current, (array) ( $parsed['nodes'] ?? [] ) );
    }
}
