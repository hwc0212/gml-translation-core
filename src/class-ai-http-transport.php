<?php
/**
 * Safe JSON transport shared by GML AI provider adapters.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_AI_HTTP_Transport {

    const MAX_REQUEST_BYTES  = 1048576;
    const MAX_RESPONSE_BYTES = 2097152;

    /** @var array<string,mixed>|null */
    private $last_error = null;

    public function post_json( $url, array $headers, array $body, array $options = [] ) {
        $provider = isset( $options['provider'] ) ? sanitize_text_field( $options['provider'] ) : 'AI';
        $allowed  = isset( $options['allowed_hosts'] ) ? (array) $options['allowed_hosts'] : [];
        $endpoint = self::validate_endpoint( $url, $allowed );

        if ( is_wp_error( $endpoint ) ) {
            return $this->failure( 'unsafe_endpoint', $provider . ': ' . $endpoint->get_error_message(), 0, false );
        }

        $json = wp_json_encode( $body );
        if ( ! is_string( $json ) ) {
            return $this->failure( 'encode_error', $provider . ': request JSON could not be encoded.', 0, false );
        }
        if ( strlen( $json ) > self::MAX_REQUEST_BYTES ) {
            return $this->failure( 'request_too_large', $provider . ': request exceeds the 1 MB safety limit.', 0, false );
        }

        $timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : 60;
        $timeout = max( 5, min( 90, $timeout ) );
        $retries = isset( $options['retries'] ) ? (int) $options['retries'] : 1;
        $retries = max( 0, min( 1, $retries ) );

        for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
            $response = wp_safe_remote_post( $endpoint, [
                'headers'             => $headers,
                'body'                => $json,
                'timeout'             => $timeout,
                'redirection'         => 0,
                'reject_unsafe_urls'  => true,
                'limit_response_size' => self::MAX_RESPONSE_BYTES,
            ] );

            if ( is_wp_error( $response ) ) {
                $error = $this->error_payload(
                    'network_error',
                    $provider . ': network request failed: ' . $response->get_error_message(),
                    0,
                    true,
                    1
                );
            } else {
                $status = (int) wp_remote_retrieve_response_code( $response );
                $raw    = (string) wp_remote_retrieve_body( $response );
                if ( $status >= 200 && $status < 300 ) {
                    $data = json_decode( $raw, true );
                    if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
                        return $this->failure( 'invalid_json', $provider . ': invalid JSON response.', $status, false );
                    }
                    $this->last_error = null;
                    return [
                        'ok'     => true,
                        'status' => $status,
                        'data'   => $data,
                        'error'  => null,
                    ];
                }

                $retry_after = self::retry_after_seconds( $response );
                $retryable   = $status === 429 || $status >= 500;
                $error       = $this->error_payload(
                    self::status_code( $status ),
                    self::format_http_message( $provider, $status, $raw ),
                    $status,
                    $retryable,
                    $retry_after
                );
            }

            if ( $attempt < $retries && ! empty( $error['retryable'] ) ) {
                self::pause( isset( $error['retry_after'] ) ? $error['retry_after'] : 1 );
                continue;
            }
            $this->last_error = $error;
            return [
                'ok'     => false,
                'status' => isset( $error['status'] ) ? $error['status'] : 0,
                'data'   => null,
                'error'  => $error,
            ];
        }

        return $this->failure( 'transport_error', $provider . ': request failed.', 0, false );
    }

    public function get_last_error() {
        return $this->last_error;
    }

    public static function validate_endpoint( $url, array $allowed_hosts ) {
        $url   = untrailingslashit( esc_url_raw( (string) $url ) );
        $parts = wp_parse_url( $url );
        $host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
        $allowed_hosts = array_values( array_filter( array_map( static function( $value ) {
            return strtolower( trim( (string) $value ) );
        }, $allowed_hosts ) ) );

        if (
            ! is_array( $parts )
            || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
            || $host === ''
            || ! empty( $parts['user'] )
            || ! empty( $parts['pass'] )
            || isset( $parts['query'] )
            || isset( $parts['fragment'] )
            || ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 )
            || filter_var( $host, FILTER_VALIDATE_IP )
            || empty( $allowed_hosts )
            || ! in_array( $host, $allowed_hosts, true )
        ) {
            return new WP_Error( 'unsafe_ai_endpoint', 'The provider endpoint is not on the HTTPS allowlist.' );
        }

        return $url;
    }

    public static function redact( $value ) {
        $value = wp_strip_all_tags( (string) $value );
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+/i',
            '/\b(?:api[_ -]?key|x-goog-api-key|authorization)\s*[:=]\s*[^\s,;]+/i',
            '/\bsk-[A-Za-z0-9_-]{8,}\b/',
            '/\bAIza[A-Za-z0-9_-]{12,}\b/',
        ];
        $value = preg_replace( $patterns, '[redacted]', $value );
        $value = trim( preg_replace( '/\s+/', ' ', $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 320 ) : substr( $value, 0, 320 );
    }

    private function failure( $code, $message, $status, $retryable, $retry_after = 0 ) {
        $error = $this->error_payload( $code, $message, $status, $retryable, $retry_after );
        $this->last_error = $error;
        return [
            'ok'     => false,
            'status' => $status,
            'data'   => null,
            'error'  => $error,
        ];
    }

    private function error_payload( $code, $message, $status, $retryable, $retry_after = 0 ) {
        return [
            'code'        => sanitize_key( $code ),
            'message'     => self::redact( $message ),
            'status'      => (int) $status,
            'retryable'   => (bool) $retryable,
            'retry_after' => max( 0, min( 5, (int) $retry_after ) ),
        ];
    }

    private static function status_code( $status ) {
        if ( $status === 400 ) return 'bad_request';
        if ( $status === 401 || $status === 403 ) return 'authentication_error';
        if ( $status === 404 ) return 'not_found';
        if ( $status === 408 ) return 'timeout';
        if ( $status === 429 ) return 'rate_limited';
        if ( $status >= 500 ) return 'provider_unavailable';
        return 'http_error';
    }

    private static function format_http_message( $provider, $status, $raw ) {
        $detail = '';
        $data   = json_decode( (string) $raw, true );
        if ( is_array( $data ) ) {
            $detail = $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? '';
        }
        $detail = self::redact( $detail );
        return sprintf(
            '%s API HTTP %d%s',
            $provider,
            (int) $status,
            $detail !== '' ? ': ' . $detail : ''
        );
    }

    private static function retry_after_seconds( $response ) {
        $value = function_exists( 'wp_remote_retrieve_header' )
            ? wp_remote_retrieve_header( $response, 'retry-after' )
            : '';
        return is_numeric( $value ) ? max( 1, min( 5, (int) $value ) ) : 1;
    }

    private static function pause( $seconds ) {
        $seconds = max( 1, min( 5, (int) $seconds ) );
        sleep( $seconds );
    }
}

