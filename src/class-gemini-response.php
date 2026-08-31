<?php
/** Parse final Gemini text without exposing thoughts or raw provider diagnostics. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Gemini_Response {
    const TEST_MAX_TOKENS = 1024;
    const MAX_TEXT_BYTES = 60000;

    public static function text( array $response ) {
        $candidate = $response['candidates'][0] ?? [];
        $candidate = is_array( $candidate ) ? $candidate : [];
        $reason = self::reason( $candidate['finishReason'] ?? '' );
        $usage = $response['usageMetadata'] ?? [];
        $usage = is_array( $usage ) ? $usage : [];
        $diagnostic = 'finish=' . $reason;
        foreach ( [ 'thoughtsTokenCount' => 'thoughts', 'candidatesTokenCount' => 'output' ] as $field => $label ) {
            if ( isset( $usage[$field] ) && is_numeric( $usage[$field] ) ) $diagnostic .= ', ' . $label . '=' . max( 0, min( 1000000, (int) $usage[$field] ) );
        }
        if ( ! empty( $response['promptFeedback']['blockReason'] ) && $response['promptFeedback']['blockReason'] !== 'BLOCK_REASON_UNSPECIFIED' ) {
            return new WP_Error( 'content_blocked', 'Prompt blocked: ' . self::reason( $response['promptFeedback']['blockReason'] ) . '. No translation was accepted.' );
        }
        if ( $reason === 'MAX_TOKENS' ) {
            return new WP_Error( 'output_limit', 'Gemini output limit reached (' . $diagnostic . '). No complete answer was received; this does not mean the API key is invalid.' );
        }
        if ( in_array( $reason, [ 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' ], true ) ) {
            return new WP_Error( 'content_blocked', 'Prompt blocked: ' . $reason . '. No translation was accepted.' );
        }
        if ( ! empty( $candidate['finishReason'] ) && ! in_array( $reason, [ 'STOP', 'FINISH_REASON_UNSPECIFIED' ], true ) ) {
            return new WP_Error( 'incomplete_response', 'Gemini did not return a complete answer (' . $diagnostic . ').' );
        }
        $text = '';
        $parts = $candidate['content']['parts'] ?? [];
        foreach ( is_array( $parts ) ? $parts : [] as $part ) {
            if ( ! is_array( $part ) || ! empty( $part['thought'] ) || ! isset( $part['text'] ) || ! is_string( $part['text'] ) ) continue;
            if ( strlen( $text ) + strlen( $part['text'] ) > self::MAX_TEXT_BYTES ) return new WP_Error( 'response_too_large', 'Provider output exceeds the local storage safety limit.' );
            $text .= $part['text'];
        }
        if ( trim( $text ) === '' ) {
            return new WP_Error( 'empty_response', 'Gemini returned no usable final text (' . $diagnostic . '). The connection test is inconclusive, not proof of an invalid API key.' );
        }
        return $text;
    }

    private static function reason( $value ) {
        return in_array( $value, [ 'STOP', 'MAX_TOKENS', 'SAFETY', 'RECITATION', 'LANGUAGE', 'OTHER', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL', 'IMAGE_SAFETY', 'UNEXPECTED_TOOL_CALL', 'TOO_MANY_TOOL_CALLS', 'FINISH_REASON_UNSPECIFIED' ], true ) ? $value : 'UNKNOWN';
    }
}
