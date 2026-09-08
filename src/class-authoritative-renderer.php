<?php
/** Cookie-free same-origin renderer used only for shadow manifest discovery. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Authoritative_Renderer {
    const MAX_BYTES = 524288;
    const MAX_REDIRECTS = 3;

    public function render( GML_Resource_Identity $resource ) {
        if ( ! $resource->is_eligible() ) return new WP_Error( 'gml_resource_excluded', 'Resource is excluded from authoritative discovery.' );
        $url = $resource->get_source_url();
        if ( ! class_exists( 'GML_URL_Helper' ) || GML_URL_Helper::internal_absolute_path( $url ) === null ) {
            return new WP_Error( 'gml_resource_origin', 'Resource URL is outside this WordPress installation.' );
        }

        $source = $url;
        $visited = [];
        for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
            $url = $this->local_url( $url, $source );
            if ( $url === '' || isset( $visited[$url] ) ) return new WP_Error( 'gml_resource_redirect', 'Unsafe or cyclic resource redirect.' );
            $visited[$url] = true;
            $response = $this->request( $url );
            if ( is_wp_error( $response ) ) return $response;
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( in_array( $code, [301,308], true ) ) {
                if ( $hop === self::MAX_REDIRECTS ) return new WP_Error( 'gml_resource_redirect_limit', 'Resource redirect limit exceeded.' );
                $next = $this->local_url( (string) wp_remote_retrieve_header( $response, 'location' ), $url );
                if ( $next === '' || isset( $visited[$next] ) ) return new WP_Error( 'gml_resource_redirect', 'Unsafe or cyclic resource redirect.' );
                $url = $next;
                continue;
            }
            if ( $code !== 200 ) return new WP_Error( 'gml_resource_http', 'Authoritative resource render did not return HTTP 200.' );
            $body = $this->html_body( $response );
            if ( is_wp_error( $body ) || $hop === 0 ) return $body;
            // This is a terminal relationship, never HTML belonging to source.
            return new WP_Error( 'gml_resource_permanent_redirect', 'Permanent same-site resource redirect.', [
                'destination'=>$url, 'chain'=>array_keys($visited),
            ] );
        }
        return new WP_Error( 'gml_resource_redirect_limit', 'Resource redirect limit exceeded.' );
    }

    private function local_url( $url, $base ) {
        if ( $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) ) return '';
        $url = WP_Http::make_absolute_url( $url, $base );
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if ( ! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || strtolower($parts['host'] ?? '') !== strtolower($home['host'] ?? '')
            || strtolower($parts['scheme'] ?? '') !== strtolower($home['scheme'] ?? '')
            || (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== (int)($home['port'] ?? ($home['scheme'] === 'https' ? 443 : 80))
            || GML_URL_Helper::internal_absolute_path($url) === null ) return '';
        $url = preg_replace('/#.*$/', '', $url);
        return remove_query_arg('gml_crawl', $url);
    }

    private function request( $url ) {
        $url = add_query_arg( 'gml_crawl', '1', $url );
        $token = class_exists( 'GML_Translation_Content_Crawler' )
            ? GML_Translation_Content_Crawler::request_token()
            : hash_hmac( 'sha256', home_url( '/' ), wp_salt( 'nonce' ) );
        return wp_safe_remote_get( $url, [
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
    }

    private function html_body( $response ) {
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
