<?php
/** Cookie-free same-origin renderer used only for shadow manifest discovery. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Authoritative_Renderer {
    const MAX_BYTES = 524288;

    public function render( GML_Resource_Identity $resource ) {
        if ( ! $resource->is_eligible() ) return new WP_Error( 'gml_resource_excluded', 'Resource is excluded from authoritative discovery.' );
        $url = $resource->get_source_url();
        if ( ! class_exists( 'GML_URL_Helper' ) || GML_URL_Helper::internal_absolute_path( $url ) === null ) {
            return new WP_Error( 'gml_resource_origin', 'Resource URL is outside this WordPress installation.' );
        }

        $url = add_query_arg( 'gml_crawl', '1', $url );
        $token = class_exists( 'GML_Translation_Content_Crawler' )
            ? GML_Translation_Content_Crawler::request_token()
            : hash_hmac( 'sha256', home_url( '/' ), wp_salt( 'nonce' ) );
        $response = wp_safe_remote_get( $url, [
            'timeout'             => 15,
            'redirection'         => 0,
            'reject_unsafe_urls'  => true,
            'sslverify'           => true,
            'limit_response_size' => self::MAX_BYTES,
            'cookies'             => [],
            'user-agent'          => 'GML-Resource-Manifest/' . ( defined( 'GML_TRANSLATION_CORE_VERSION' ) ? GML_TRANSLATION_CORE_VERSION : 'shadow' ),
            'headers'             => [
                'Accept'      => 'text/html,application/xhtml+xml',
                'X-GML-Crawl' => $token,
            ],
        ] );
        if ( is_wp_error( $response ) ) return $response;
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'gml_resource_http', 'Authoritative resource render did not return HTTP 200.' );
        }
        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( strpos( $content_type, 'text/html' ) === false && strpos( $content_type, 'application/xhtml+xml' ) === false ) {
            return new WP_Error( 'gml_resource_content_type', 'Authoritative resource render was not HTML.' );
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $body === '' || strlen( $body ) > self::MAX_BYTES || ! preg_match( '/<(?:!doctype\s+html|html)\b/i', $body ) || stripos( $body, '</html>' ) === false ) {
            return new WP_Error( 'gml_resource_body', 'Authoritative resource render was empty, oversized, or incomplete.' );
        }
        return $body;
    }
}
