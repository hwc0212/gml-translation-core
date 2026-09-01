<?php
/** Classify provider and content failures without storing secrets or raw responses. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Translation_Error {
    const PREFIX_PATTERN = '/^\[([a-z0-9_]+)\]\s*/';

    public static function classify( $error = [], $message = '' ) {
        $error   = is_array( $error ) ? $error : [];
        $message = self::safe_message( $message !== '' ? $message : ( $error['message'] ?? '' ) );
        $code    = sanitize_key( $error['code'] ?? '' );
        $status  = isset( $error['status'] ) ? (int) $error['status'] : 0;

        if ( preg_match( self::PREFIX_PATTERN, $message, $match ) ) {
            $code    = sanitize_key( $match[1] );
            $message = preg_replace( self::PREFIX_PATTERN, '', $message, 1 );
        }
        if ( ! $status && preg_match( '/\bHTTP\s+(400|401|403|404|408|429|5\d\d)\b/i', $message, $match ) ) $status = (int) $match[1];
        $lower = strtolower( (string) $message );
        if ( strpos( $lower, 'api key not valid' ) !== false || strpos( $lower, 'invalid api key' ) !== false ) $code = 'authentication_error';
        elseif ( strpos( $lower, 'model not found' ) !== false || strpos( $lower, 'model is no longer available' ) !== false ) $code = 'not_found';
        if ( $code === '' || $code === 'provider_error' ) $code = self::legacy_code( $message, $status );

        $configuration = [ 'provider_not_configured', 'unsafe_endpoint', 'encode_error', 'request_too_large', 'bad_request', 'authentication_error', 'not_found' ];
        $transient     = [ 'network_error', 'timeout', 'rate_limited', 'provider_unavailable' ];
        $content       = [ 'content_blocked', 'protected_term', 'empty_result' ];
        $response      = [ 'invalid_json', 'empty_response', 'incomplete_response', 'output_limit', 'response_too_large' ];
        $local         = [ 'local_save_failed', 'source_too_large' ];
        if ( in_array( $code, $configuration, true ) ) $category = 'configuration';
        elseif ( in_array( $code, $transient, true ) ) $category = 'transient';
        elseif ( in_array( $code, $content, true ) ) $category = 'content';
        elseif ( in_array( $code, $response, true ) ) $category = 'response';
        elseif ( in_array( $code, $local, true ) ) $category = 'local';
        elseif ( ! empty( $error['retryable'] ) ) $category = 'transient';
        elseif ( $status >= 400 && $status < 500 && ! in_array( $status, [ 408, 429 ], true ) ) $category = 'configuration';
        else $category = 'unknown';

        return [
            'code' => $code ?: 'unknown', 'category' => $category, 'status' => $status,
            'retryable' => $category === 'transient',
            'retry_after' => max( 0, min( 3600, (int) ( $error['retry_after'] ?? 0 ) ) ),
            'message' => $message ?: 'Unknown translation error',
        ];
    }

    public static function stored_message( $error = [], $message = '' ) {
        $failure = self::classify( $error, $message );
        return '[' . $failure['code'] . '] ' . $failure['message'];
    }

    public static function label( $code ) {
        $labels = [
            'bad_request' => 'Invalid provider request (HTTP 400)', 'authentication_error' => 'Authentication or permission error',
            'not_found' => 'Model or API resource not found', 'rate_limited' => 'Rate limit or quota exceeded',
            'provider_unavailable' => 'Provider temporarily unavailable', 'network_error' => 'Network error', 'timeout' => 'Provider timeout',
            'empty_response' => 'Provider returned no final text', 'incomplete_response' => 'Provider returned an incomplete response',
            'output_limit' => 'Provider output was truncated', 'content_blocked' => 'Content was blocked by the provider',
            'empty_result' => 'Empty translation result', 'protected_term' => 'Protected term changed or removed',
            'local_save_failed' => 'Local translation save failed', 'source_too_large' => 'Source segment exceeds the size limit',
            'invalid_json' => 'Invalid provider response',
            'provider_not_configured' => 'AI provider is not configured', 'unsafe_endpoint' => 'Provider endpoint is not allowed',
            'encode_error' => 'Translation request could not be encoded', 'request_too_large' => 'Translation request exceeds the safety limit',
            'response_too_large' => 'Provider response exceeds the safety limit', 'http_error' => 'Provider request failed',
            'transport_error' => 'Provider transport failed', 'provider_error' => 'Provider request failed',
            'unknown' => 'Other translation error',
        ];
        return $labels[ sanitize_key( $code ) ] ?? 'Other translation error';
    }

    private static function legacy_code( $message, $status ) {
        if ( $status === 400 ) return 'bad_request';
        if ( $status === 401 || $status === 403 ) return 'authentication_error';
        if ( $status === 404 ) return 'not_found';
        if ( $status === 408 ) return 'timeout';
        if ( $status === 429 ) return 'rate_limited';
        if ( $status >= 500 ) return 'provider_unavailable';
        $lower = strtolower( (string) $message );
        if ( strpos( $lower, 'empty translation result' ) !== false ) return 'empty_result';
        if ( strpos( $lower, 'protected brand term' ) !== false ) return 'protected_term';
        if ( strpos( $lower, 'local translation save failed' ) !== false ) return 'local_save_failed';
        if ( strpos( $lower, 'source segment exceeds' ) !== false ) return 'source_too_large';
        if ( strpos( $lower, 'no text in ' ) !== false ) return 'empty_response';
        return 'unknown';
    }

    private static function safe_message( $message ) {
        $message = class_exists( 'GML_AI_HTTP_Transport' ) ? GML_AI_HTTP_Transport::redact( $message ) : sanitize_text_field( $message );
        return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
    }
}
