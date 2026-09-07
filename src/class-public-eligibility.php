<?php
/** Derived publication eligibility for exact resource translation snapshots. */
if ( ! defined( 'ABSPATH' ) ) exit;

final class GML_Public_Eligibility {
    const BATCH_SIZE = 500;

    /** Evaluate one resource/language pair without persisting publication state. */
    public static function get_status( $subject, $lang, array $context = [] ) {
        $resource = self::resolve_resource( $subject );
        $lang = self::normalize_language( $lang );
        if ( ! $resource instanceof GML_Resource_Identity || $lang === '' ) {
            return self::empty_status( $resource, $lang, 'invalid_resource' );
        }
        if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $lang ) ) {
            $status = self::empty_status( $resource, $lang, 'external_unverified' );
            $status['machine_status'] = 'external_unverified';
            return $status;
        }

        $clusters = self::get_clusters_bulk( [ $resource ], $context );
        return $clusters[ $resource->get_key() ]['languages'][ $lang ]
            ?? self::empty_status( $resource, $lang, 'language_disabled' );
    }

    /** Return source plus every public-eligible local target for one resource. */
    public static function get_cluster( $subject, array $context = [] ) {
        $resource = self::resolve_resource( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return self::empty_cluster();
        $clusters = self::get_clusters_bulk( [ $resource ], $context );
        return $clusters[ $resource->get_key() ] ?? self::empty_cluster( $resource );
    }

    /**
     * Derive public clusters in bounded reads. Human Review is loaded once per
     * resource chunk, and indexability is evaluated once per resource rather
     * than once per URL/language pair.
     */
    public static function get_clusters_bulk( array $subjects, array $context = [] ) {
        $resources = self::resolve_resources( $subjects );
        if ( ! $resources ) return [];

        $source = self::source_language();
        $targets = self::local_target_languages();
        $review_statuses = class_exists( 'GML_Resource_Approval' )
            ? GML_Resource_Approval::get_statuses_bulk( array_values( $resources ), $targets )
            : [];
        $indexable = self::indexability_map( $resources, $context );
        $clusters = [];

        foreach ( $resources as $key => $resource ) {
            $source_route = self::route( $resource, $source, $source );
            $source_public = ! empty( $indexable[ $key ] ) && $source_route['valid'];
            $languages = [
                $source => self::status_row(
                    $resource,
                    $source,
                    $source_route,
                    $source_public,
                    $source_public ? 'source' : ( empty( $indexable[ $key ] ) ? 'resource_noindex' : 'route_invalid' ),
                    'complete',
                    'approved',
                    true
                ),
            ];

            foreach ( $targets as $lang ) {
                $review = $review_statuses[ $key ][ $lang ] ?? [];
                $machine = sanitize_key( $review['machine_status'] ?? 'unknown' ) ?: 'unknown';
                $human = sanitize_key( $review['review_status'] ?? 'blocked' ) ?: 'blocked';
                $snapshot_matches = ! empty( $review['snapshot_matches'] );
                $route = self::route( $resource, $lang, $source );
                $public = $source_public
                    && $route['valid']
                    && $machine === 'complete'
                    && $human === 'approved'
                    && $snapshot_matches;
                $reason = self::target_reason( $source_public, ! empty( $indexable[ $key ] ), $route['valid'], $machine, $human, $snapshot_matches );
                $languages[ $lang ] = self::status_row(
                    $resource,
                    $lang,
                    $route,
                    $public,
                    $reason,
                    $machine,
                    $human,
                    $snapshot_matches,
                    $review
                );
            }

            $eligible = [];
            foreach ( $languages as $lang => $status ) {
                if ( ! empty( $status['public_eligible'] ) ) $eligible[ $lang ] = $status['url'];
            }
            $clusters[ $key ] = [
                'resource_key' => $key,
                'resource' => $resource,
                'source_lang' => $source,
                'source_url' => $resource->get_source_url(),
                'resource_indexable' => ! empty( $indexable[ $key ] ),
                'languages' => $languages,
                'eligible_urls' => $eligible,
            ];
        }
        return $clusters;
    }

    public static function get_public_urls( $subject, array $context = [] ) {
        $cluster = self::get_cluster( $subject, $context );
        return $cluster['eligible_urls'] ?? [];
    }

    public static function is_eligible( $subject, $lang, array $context = [] ) {
        $status = self::get_status( $subject, $lang, $context );
        return ! empty( $status['public_eligible'] );
    }

    private static function resolve_resources( array $subjects ) {
        $resources = [];
        foreach ( array_slice( $subjects, 0, 5000 ) as $subject ) {
            $resource = self::resolve_resource( $subject );
            if ( $resource instanceof GML_Resource_Identity ) $resources[ $resource->get_key() ] = $resource;
        }
        return $resources;
    }

    private static function resolve_resource( $subject ) {
        if ( $subject instanceof GML_Resource_Identity ) return $subject;
        return class_exists( 'GML_Resource_Identity' ) ? GML_Resource_Identity::resolve( $subject ) : null;
    }

    private static function indexability_map( array $resources, array $context ) {
        $indexable = [];
        $site_public = (string) get_option( 'blog_public', '1' ) !== '0';
        foreach ( $resources as $key => $resource ) {
            $indexable[ $key ] = $site_public && $resource->is_eligible();
        }
        $filtered = apply_filters( 'gml_translation_resources_indexable', $indexable, $resources, $context );
        if ( is_array( $filtered ) ) {
            foreach ( $indexable as $key => $value ) {
                if ( array_key_exists( $key, $filtered ) ) $indexable[ $key ] = (bool) $filtered[ $key ];
            }
        }
        foreach ( $resources as $key => $resource ) {
            $indexable[ $key ] = (bool) apply_filters(
                'gml_translation_resource_indexable',
                ! empty( $indexable[ $key ] ),
                $resource,
                $context
            );
        }
        return $indexable;
    }

    private static function route( GML_Resource_Identity $resource, $lang, $source ) {
        $source_url = $resource->get_source_url();
        $url = '';
        $valid = false;
        if ( $source_url !== '' && class_exists( 'GML_URL_Helper' ) ) {
            $languages = array_merge( [ $source ], self::local_target_languages() );
            $url = GML_URL_Helper::get_language_url( $source_url, $lang, $source, $languages );
            $round_trip = GML_URL_Helper::get_language_url( $url, $source, $source, $languages );
            $valid = $url !== ''
                && GML_URL_Helper::internal_absolute_path( $url ) !== null
                && hash_equals(
                    GML_Resource_Identity::source_url_hash( $source_url ),
                    GML_Resource_Identity::source_url_hash( $round_trip )
                );
        }
        $valid = (bool) apply_filters( 'gml_translation_route_valid', $valid, $resource, $lang, $url );
        return [ 'url' => $valid ? $url : '', 'valid' => $valid ];
    }

    private static function target_reason( $source_public, $indexable, $route_valid, $machine, $human, $snapshot_matches ) {
        if ( ! $indexable ) return 'resource_noindex';
        if ( ! $source_public ) return 'source_ineligible';
        if ( ! $route_valid ) return 'route_invalid';
        if ( $machine !== 'complete' ) return $machine;
        if ( ! $snapshot_matches && $human !== 'unreviewed' ) return 'stale';
        if ( $human !== 'approved' ) return $human;
        return 'eligible';
    }

    private static function status_row( GML_Resource_Identity $resource, $lang, array $route, $public, $reason, $machine, $human, $snapshot_matches, array $review = [] ) {
        return [
            'resource_key' => $resource->get_key(),
            'target_lang' => $lang,
            'url' => $route['url'],
            'route_valid' => (bool) $route['valid'],
            'machine_status' => $machine,
            'review_status' => $human,
            'snapshot_matches' => (bool) $snapshot_matches,
            'public_eligible' => (bool) $public,
            'reason' => sanitize_key( $reason ) ?: 'unknown',
            'review_revision' => (int) ( $review['review_revision'] ?? 0 ),
            'manifest_generation' => (int) ( $review['manifest_generation'] ?? 0 ),
            'global_generation' => (int) ( $review['global_generation'] ?? 0 ),
            'translation_generation' => (int) ( $review['translation_generation'] ?? 0 ),
        ];
    }

    private static function empty_status( $resource, $lang, $reason ) {
        $key = $resource instanceof GML_Resource_Identity ? $resource->get_key() : '';
        return [
            'resource_key' => $key,
            'target_lang' => (string) $lang,
            'url' => '',
            'route_valid' => false,
            'machine_status' => 'unknown',
            'review_status' => 'blocked',
            'snapshot_matches' => false,
            'public_eligible' => false,
            'reason' => sanitize_key( $reason ) ?: 'unknown',
            'review_revision' => 0,
            'manifest_generation' => 0,
            'global_generation' => 0,
            'translation_generation' => 0,
        ];
    }

    private static function empty_cluster( $resource = null ) {
        return [
            'resource_key' => $resource instanceof GML_Resource_Identity ? $resource->get_key() : '',
            'resource' => $resource,
            'source_lang' => self::source_language(),
            'source_url' => $resource instanceof GML_Resource_Identity ? $resource->get_source_url() : '',
            'resource_indexable' => false,
            'languages' => [],
            'eligible_urls' => [],
        ];
    }

    private static function source_language() {
        return self::normalize_language( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
    }

    private static function local_target_languages() {
        if ( ! class_exists( 'GML_Translation_State' ) || ! GML_Translation_State::multilingual_enabled() ) return [];
        return class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::enabled_local_target_codes() : [];
    }

    private static function normalize_language( $lang ) {
        return class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $lang ) : sanitize_key( $lang );
    }
}
