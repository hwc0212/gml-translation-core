<?php
/** Legacy-compatible credential storage shared by both translation adapters. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Translation_Credentials {
    public static function option_name( $engine ) {
        $options = [ 'gemini' => 'gml_api_key_encrypted', 'deepseek' => 'gml_deepseek_api_key_encrypted' ];
        return is_string( $engine ) ? ( $options[ $engine ] ?? '' ) : '';
    }

    public static function valid_input( $key ) {
        return is_string( $key ) && $key !== '' && strlen( $key ) <= 512
            && ! preg_match( '/[^\x21-\x7e]/', $key ) && ! preg_match( '/^\*+$/', $key );
    }

    public static function read( $engine ) {
        $option = self::option_name( $engine );
        return $option ? self::decode( get_option( $option, '' ) ) : '';
    }

    public static function status( $engine ) {
        $option = self::option_name( $engine );
        $stored = $option ? get_option( $option, '' ) : '';
        return $stored === '' ? 'missing' : ( self::decode( $stored ) !== '' ? 'readable' : 'unreadable' );
    }

    public static function error_message() {
        return __( 'The saved API key cannot be decrypted on this site. Re-enter and save the key. No API request was sent; existing translations are unaffected.', 'gml-translate' );
    }

    public static function decode( $stored ) {
        if ( ! is_string( $stored ) || $stored === '' || strlen( $stored ) > 1024 ) return '';
        $raw = base64_decode( $stored, true );
        if ( is_string( $raw ) && strlen( $raw ) >= 32 && strlen( $raw ) % 16 === 0 ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) return '';
            // Historical releases used the raw auth salt and IV + CBC ciphertext.
            $key = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', wp_salt( 'auth' ), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
            return self::valid_input( $key ) ? $key : '';
        }
        // Only the two historical plaintext formats are eligible for fallback.
        // New opaque provider keys are accepted through encrypted storage above.
        return self::valid_input( $stored ) && preg_match( '/^(?:AIza|sk-)[A-Za-z0-9_-]+$/D', $stored ) ? $stored : '';
    }

    public static function save( $key, $engine ) {
        $option = self::option_name( $engine );
        $key = is_string( $key ) ? trim( $key ) : '';
        if ( ! $option || ! self::valid_input( $key ) || ! function_exists( 'openssl_encrypt' ) ) return false;
        try {
            $iv = random_bytes( 16 );
            $cipher = openssl_encrypt( $key, 'AES-256-CBC', wp_salt( 'auth' ), OPENSSL_RAW_DATA, $iv );
        } catch ( Throwable $error ) {
            return false;
        }
        if ( ! is_string( $cipher ) ) return false;
        $payload = base64_encode( $iv . $cipher );
        if ( ! update_option( $option, $payload, false ) ) return false;
        $saved = get_option( $option, '' );
        return is_string( $saved ) && hash_equals( $payload, $saved ) && hash_equals( $key, self::decode( $saved ) );
    }
}
