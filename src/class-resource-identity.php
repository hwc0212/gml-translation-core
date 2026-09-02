<?php
/** Immutable, Core-owned identity for a translatable WordPress resource. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Resource_Identity {
    private $key;
    private $type;
    private $object_id;
    private $taxonomy;
    private $variant;
    private $source_url;
    private $source_url_hash;
    private $source_revision;
    private $eligible;
    private $exclusion_reason;

    private function __construct( array $data ) {
        $this->key              = (string) $data['key'];
        $this->type             = (string) $data['type'];
        $this->object_id        = (int) $data['object_id'];
        $this->taxonomy         = (string) $data['taxonomy'];
        $this->variant          = (string) $data['variant'];
        $this->source_url       = (string) $data['source_url'];
        $this->source_url_hash  = (string) $data['source_url_hash'];
        $this->source_revision  = (string) $data['source_revision'];
        $this->eligible         = (bool) $data['eligible'];
        $this->exclusion_reason = (string) $data['exclusion_reason'];
    }

    public static function from_parts( $type, $object_id, $taxonomy, $variant, $source_url, $source_revision = '', $eligible = true, $exclusion_reason = '' ) {
        $type      = sanitize_key( $type );
        $object_id = max( 0, (int) $object_id );
        $taxonomy  = sanitize_key( $taxonomy );
        $variant   = sanitize_key( $variant );
        $source_url = self::canonical_source_url( $source_url );

        if ( ! in_array( $type, [ 'post', 'term', 'role', 'archive', 'excluded' ], true ) ) return null;
        if ( $type !== 'excluded' && $source_url === '' ) return null;
        if ( $type === 'post' && ( $object_id < 1 || $variant === '' ) ) return null;
        if ( $type === 'term' && ( $object_id < 1 || $taxonomy === '' ) ) return null;
        if ( in_array( $type, [ 'role', 'archive' ], true ) && $variant === '' ) return null;

        if ( $type === 'post' ) $key = 'post:' . $variant . ':' . $object_id;
        elseif ( $type === 'term' ) $key = 'term:' . $taxonomy . ':' . $object_id;
        elseif ( $type === 'role' ) $key = 'role:' . $variant . ':' . $object_id;
        elseif ( $type === 'archive' ) $key = 'archive:' . $variant;
        else $key = 'excluded:' . ( sanitize_key( $exclusion_reason ) ?: 'request' );

        return new self( [
            'key'              => substr( $key, 0, 191 ),
            'type'             => $type,
            'object_id'        => $object_id,
            'taxonomy'         => substr( $taxonomy, 0, 64 ),
            'variant'          => substr( $variant, 0, 64 ),
            'source_url'       => $source_url,
            'source_url_hash'  => $source_url === '' ? '' : hash( 'sha256', $source_url ),
            'source_revision'  => substr( (string) $source_revision, 0, 191 ),
            'eligible'         => (bool) $eligible,
            'exclusion_reason' => substr( sanitize_key( $exclusion_reason ), 0, 64 ),
        ] );
    }

    public static function excluded( $reason = 'request' ) {
        return self::from_parts( 'excluded', 0, '', '', '', '', false, $reason );
    }

    public static function for_post( $post ) {
        $post = $post instanceof WP_Post ? $post : get_post( (int) $post );
        if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_type === 'attachment' ) return null;
        $type = get_post_type_object( $post->post_type );
        if ( ! $type || empty( $type->public ) ) return null;

        $front_id = (int) get_option( 'page_on_front', 0 );
        $posts_id = (int) get_option( 'page_for_posts', 0 );
        if ( $post->post_type === 'page' && $front_id === (int) $post->ID && get_option( 'show_on_front' ) === 'page' ) {
            $resource_type = 'role';
            $variant = 'front_page';
            $url = home_url( '/' );
        } elseif ( $post->post_type === 'page' && $posts_id === (int) $post->ID ) {
            $resource_type = 'role';
            $variant = 'posts_page';
            $url = get_permalink( $post );
        } else {
            $resource_type = 'post';
            $variant = $post->post_type;
            $url = get_permalink( $post );
        }

        $revision = hash( 'sha256', implode( '|', [
            (string) $post->ID,
            (string) $post->post_type,
            (string) $post->post_status,
            (string) $post->post_name,
            (string) $post->post_modified_gmt,
            (string) $post->post_modified,
        ] ) );
        return self::from_parts( $resource_type, $post->ID, '', $variant, $url, $revision );
    }

    public static function front_page() {
        if ( get_option( 'show_on_front' ) === 'page' && (int) get_option( 'page_on_front', 0 ) > 0 ) {
            return self::for_post( (int) get_option( 'page_on_front', 0 ) );
        }
        return self::from_parts( 'role', 0, '', 'front_page', home_url( '/' ), self::site_revision( 'front_page' ) );
    }

    public static function posts_page() {
        $post_id = (int) get_option( 'page_for_posts', 0 );
        if ( $post_id > 0 ) return self::for_post( $post_id );
        return self::from_parts( 'role', 0, '', 'posts_page', home_url( '/' ), self::site_revision( 'posts_page' ) );
    }

    public static function for_term( $term, $taxonomy = '' ) {
        $term = $term instanceof WP_Term ? $term : get_term( (int) $term, sanitize_key( $taxonomy ) );
        if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) return null;
        $taxonomy = sanitize_key( $term->taxonomy );
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax || empty( $tax->public ) ) return null;
        $url = get_term_link( $term );
        if ( is_wp_error( $url ) ) return null;
        $revision = hash( 'sha256', implode( '|', [
            (string) $term->term_id,
            (string) $term->term_taxonomy_id,
            (string) $term->name,
            (string) $term->slug,
            (string) $term->description,
            (string) $term->parent,
            (string) $term->count,
        ] ) );
        return self::from_parts( 'term', $term->term_id, $taxonomy, '', $url, $revision );
    }

    public static function for_archive( $post_type ) {
        $post_type = sanitize_key( $post_type );
        $object = get_post_type_object( $post_type );
        if ( ! $object || empty( $object->public ) || empty( $object->has_archive ) ) return null;
        if ( ! apply_filters( 'gml_resource_support_post_type_archive', true, $post_type, $object ) ) return null;
        $url = get_post_type_archive_link( $post_type );
        if ( ! $url ) return null;
        return self::from_parts( 'archive', 0, '', $post_type, $url, self::site_revision( 'archive:' . $post_type ) );
    }

    public static function resolve( $subject = null ) {
        if ( $subject instanceof self ) return $subject;
        if ( $subject instanceof WP_Post ) return self::for_post( $subject );
        if ( $subject instanceof WP_Term ) return self::for_term( $subject );
        if ( is_int( $subject ) || ( is_string( $subject ) && ctype_digit( $subject ) ) ) return self::for_post( $subject );
        if ( is_array( $subject ) && ! empty( $subject['type'] ) ) {
            return self::from_parts(
                $subject['type'], $subject['object_id'] ?? 0, $subject['taxonomy'] ?? '', $subject['variant'] ?? '',
                $subject['source_url'] ?? '', $subject['source_revision'] ?? '', $subject['eligible'] ?? true,
                $subject['exclusion_reason'] ?? ''
            );
        }
        if ( is_string( $subject ) && preg_match( '/^post:([a-z0-9_-]+):(\d+)$/', $subject, $m ) ) return self::for_post( (int) $m[2] );
        if ( is_string( $subject ) && preg_match( '/^term:([a-z0-9_-]+):(\d+)$/', $subject, $m ) ) return self::for_term( (int) $m[2], $m[1] );
        if ( is_string( $subject ) && preg_match( '/^archive:([a-z0-9_-]+)$/', $subject, $m ) ) return self::for_archive( $m[1] );
        if ( is_string( $subject ) && preg_match( '#^https?://#i', $subject ) ) {
            $post_id = url_to_postid( $subject );
            return $post_id ? self::for_post( $post_id ) : null;
        }
        return $subject === null ? self::current() : null;
    }

    public static function current() {
        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) return self::excluded( 'rest_ajax' );
        if ( function_exists( 'is_feed' ) && is_feed() ) return self::excluded( 'feed' );
        if ( is_search() || is_404() || is_author() || is_date() ) return self::excluded( 'non_indexable_archive' );
        if ( is_user_logged_in() ) return self::excluded( 'personalized' );
        foreach ( [ 'is_cart' => 'cart', 'is_checkout' => 'checkout', 'is_account_page' => 'account' ] as $function => $reason ) {
            if ( function_exists( $function ) && call_user_func( $function ) ) return self::excluded( $reason );
        }
        if ( is_front_page() ) return self::front_page();
        if ( is_home() ) return self::posts_page();
        if ( is_singular() ) return self::for_post( get_queried_object_id() );
        if ( is_category() || is_tag() || is_tax() ) return self::for_term( get_queried_object() );
        if ( is_post_type_archive() ) return self::for_archive( get_query_var( 'post_type' ) );
        return self::excluded( 'unsupported_request' );
    }

    public static function refresh( self $resource ) {
        if ( $resource->type === 'post' || ( $resource->type === 'role' && $resource->object_id > 0 ) ) {
            return self::for_post( $resource->object_id ) ?: $resource;
        }
        if ( $resource->type === 'role' ) {
            return $resource->variant === 'posts_page' ? self::posts_page() : self::front_page();
        }
        if ( $resource->type === 'term' ) return self::for_term( $resource->object_id, $resource->taxonomy ) ?: $resource;
        if ( $resource->type === 'archive' ) return self::for_archive( $resource->variant ) ?: $resource;
        return $resource;
    }

    private static function canonical_source_url( $url ) {
        $url = esc_url_raw( (string) $url, [ 'http', 'https' ] );
        if ( $url === '' ) return '';
        if ( class_exists( 'GML_URL_Helper' ) && GML_URL_Helper::internal_absolute_path( $url ) === null ) return '';
        if ( class_exists( 'GML_URL_Helper' ) && class_exists( 'GML_Language_Utils' ) ) {
            $source = GML_Language_Utils::normalize_code( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
            $url = GML_URL_Helper::get_language_url( $url, $source, $source, GML_Language_Utils::configured_codes( true, true ) );
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
        $path = function_exists( 'user_trailingslashit' ) ? user_trailingslashit( $path ) : trailingslashit( $path );
        return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . $port . $path;
    }

    private static function site_revision( $variant ) {
        return hash( 'sha256', $variant . '|' . get_bloginfo( 'name' ) . '|' . get_bloginfo( 'description' ) );
    }

    public function get_key() { return $this->key; }
    public function get_type() { return $this->type; }
    public function get_object_id() { return $this->object_id; }
    public function get_taxonomy() { return $this->taxonomy; }
    public function get_variant() { return $this->variant; }
    public function get_source_url() { return $this->source_url; }
    public function get_source_url_hash() { return $this->source_url_hash; }
    public function get_source_revision() { return $this->source_revision; }
    public function is_eligible() { return $this->eligible; }
    public function get_exclusion_reason() { return $this->exclusion_reason; }

    public function to_array() {
        return [
            'key' => $this->key, 'type' => $this->type, 'object_id' => $this->object_id,
            'taxonomy' => $this->taxonomy, 'variant' => $this->variant,
            'source_url' => $this->source_url, 'source_url_hash' => $this->source_url_hash,
            'source_revision' => $this->source_revision, 'eligible' => $this->eligible,
            'exclusion_reason' => $this->exclusion_reason,
        ];
    }
}
