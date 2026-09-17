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
        $content       = [ 'content_blocked', 'protected_term', 'empty_result', 'translation_contamination' ];
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

    /** Private, bounded diagnostics are separate from general logs and Translation Memory. */
    public static function store_diagnostic($item, array $failure) {
        $detail=$failure['diagnostic']??[];
        if (($failure['code']??'')!=='protected_term' || !is_array($detail)
            || !hash_equals(md5((string)$item->source_text),(string)($detail['source_hash']??''))
            || !hash_equals((string)$item->source_hash,(string)$detail['source_hash'])) return false;
        global $wpdb;
        $prefix='gml_translation_diagnostic_';
        $row=[
            'source_hash'=>$item->source_hash, 'language'=>$item->target_lang, 'context'=>$item->context_type,
            'at'=>time(), 'rule'=>sanitize_key($detail['rule']??'protected_term'),
            'source_count'=>max(0,(int)($detail['source_count']??0)),
            'candidate_count'=>max(0,(int)($detail['candidate_count']??0)),
            'first_mismatch'=>max(0,(int)($detail['first_mismatch']??0)),
        ];
        foreach (['source_token','candidate_token','candidate'] as $key) {
            $raw=$detail[$key]??null;
            $row[$key]=$raw===null?null:self::diagnostic_text((string)$raw,$key==='candidate'?16384:512);
        }
        $row['candidate_truncated']=isset($detail['candidate']) && mb_strlen($detail['candidate'])>16384;
        update_option($prefix.(int)$item->id,$row,false);
        $old=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name<>%s ORDER BY option_id DESC LIMIT 99,100",$wpdb->esc_like($prefix).'%',$prefix.(int)$item->id));
        foreach ((array)$old as $name) delete_option($name);
        return true;
    }

    public static function diagnostic($item) {
        if (!current_user_can('manage_options')) return [];
        $row=get_option('gml_translation_diagnostic_'.(int)$item->id,[]);
        if (!is_array($row) || ($row['source_hash']??'')!==$item->source_hash
            || ($row['language']??'')!==$item->target_lang || ($row['context']??'')!==$item->context_type) return [];
        return $row;
    }

    private static function diagnostic_text($text,$limit) {
        // URL queries, fragments and userinfo may contain credentials; retain only an identity digest.
        $text=preg_replace_callback('~https?://[^\s<>"\x27]+~u',static function($m) {
            $parts=wp_parse_url($m[0]);
            if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']))
                return '[URL sha256:'.hash('sha256',$m[0]).']';
            return $m[0];
        },$text);
        return GML_AI_HTTP_Transport::redact($text,$limit,true);
    }

    public static function label( $code ) {
        $labels = [
            'bad_request' => 'Invalid provider request (HTTP 400)', 'authentication_error' => 'Authentication or permission error',
            'not_found' => 'Model or API resource not found', 'rate_limited' => 'Rate limit or quota exceeded',
            'provider_unavailable' => 'Provider temporarily unavailable', 'network_error' => 'Network error', 'timeout' => 'Provider timeout',
            'empty_response' => 'Provider returned no final text', 'incomplete_response' => 'Provider returned an incomplete response',
            'output_limit' => 'Provider output was truncated', 'content_blocked' => 'Content was blocked by the provider',
            'empty_result' => 'Empty translation result', 'protected_term' => 'Protected term changed or removed',
            'translation_contamination' => 'Translation contains unsupported output instructions',
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
