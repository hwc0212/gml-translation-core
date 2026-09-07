<?php
/**
 * Read-only language, URL, and translation-status provider.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-translation-provider.php';
require_once __DIR__ . '/interface-resource-translation-provider.php';

class GML_Translation_Provider implements GML_Translation_Provider_Interface, GML_Resource_Translation_Provider_Interface {

    /** @var array<string,bool> */
    private $readiness = [];

    public function get_languages() {
        if ( ! $this->multilingual_enabled() || ! class_exists( 'GML_Language_Utils' ) ) {
            return [];
        }

        return array_values( array_unique( GML_Language_Utils::configured_codes( true, true ) ) );
    }

    public function get_alternate_urls( $url ) {
        if ( ! $this->is_local_url( $url ) || ! class_exists( 'GML_Public_Eligibility' ) ) return [];
        $resource = $this->resolve_resource( $url );
        if ( ! $resource instanceof GML_Resource_Identity ) return [];

        $cluster = GML_Public_Eligibility::get_cluster( $resource, [ 'entrypoint' => 'hreflang' ] );
        $current = $this->get_current_language();
        if ( empty( $cluster['languages'][ $current ]['public_eligible'] ) ) return [];
        $source = $this->get_source_language();
        $source_url = $cluster['eligible_urls'][ $source ] ?? '';
        if ( $source_url === '' ) return [];

        $alternates = [
            $this->get_hreflang_code( $source ) => $source_url,
            'x-default' => $source_url,
        ];
        foreach ( $cluster['eligible_urls'] as $lang => $translated_url ) {
            if ( $lang !== $source ) $alternates[ $this->get_hreflang_code( $lang ) ] = $translated_url;
        }
        return $alternates;
    }

    public function get_translated_url( $url, $lang ) {
        if ( ! class_exists( 'GML_Language_Utils' ) || ! class_exists( 'GML_URL_Helper' ) ) {
            return '';
        }

        $lang      = GML_Language_Utils::normalize_code( $lang );
        $source    = $this->get_source_language();
        $languages = $this->get_languages();
        if ( ! $lang || ! $source || ! in_array( $lang, $languages, true ) ) {
            return '';
        }

        if ( ! $this->is_local_url( $url ) ) {
            return (string) $url;
        }

        return GML_URL_Helper::get_language_url( $url, $lang, $source, $languages );
    }

    public function get_translation_status( $object_id, $lang ) {
        $lang   = class_exists( 'GML_Language_Utils' ) ? GML_Language_Utils::normalize_code( $lang ) : '';
        $source = $this->get_source_language();
        if ( ! $lang || ! in_array( $lang, $this->get_languages(), true ) ) {
            return 'disabled';
        }
        if ( $lang === $source ) {
            return 'complete';
        }
        if ( (int) $object_id > 0 ) {
            $resource = $this->resolve_resource( (int) $object_id );
            return $resource ? $this->get_resource_status( $resource, $lang ) : 'unknown';
        }
        return $this->is_index_ready( $lang ) ? 'complete' : 'incomplete';
    }

    public function resolve_resource( $subject = null ) {
        return class_exists( 'GML_Resource_Identity' ) ? GML_Resource_Identity::resolve( $subject ) : null;
    }

    public function get_resource_status( $subject, $lang ) {
        $resource = $this->resolve_resource( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return 'unknown';
        if ( ! $resource->is_eligible() ) return 'excluded';
        return class_exists( 'GML_Resource_Readiness' ) ? GML_Resource_Readiness::get_status( $resource, $lang ) : 'unknown';
    }

    public function get_resource_statuses( $subject ) {
        $resource = $this->resolve_resource( $subject );
        if ( ! $resource instanceof GML_Resource_Identity ) return [];
        return class_exists( 'GML_Resource_Readiness' ) ? GML_Resource_Readiness::get_all_statuses( $resource ) : [];
    }

    public function get_resource_statuses_bulk( array $subjects, array $languages = [] ) {
        return class_exists( 'GML_Resource_Readiness' )
            ? GML_Resource_Readiness::get_bulk_statuses( $subjects, $languages ?: $this->get_languages() )
            : [];
    }

    /** Shadow candidates only. Product adapters must not publish these in Phase 2B. */
    public function get_resource_alternate_candidates( $subject ) {
        $resource = $this->resolve_resource( $subject );
        if ( ! $resource instanceof GML_Resource_Identity || ! $resource->is_eligible() ) return [];
        $statuses = $this->get_resource_statuses( $resource );
        $result = [];
        foreach ( $this->get_languages() as $lang ) {
            $url = $this->get_translated_url( $resource->get_source_url(), $lang );
            if ( $url !== '' ) $result[ $lang ] = [ 'url' => $url, 'status' => $statuses[ $lang ] ?? 'unknown' ];
        }
        return $result;
    }

    public function get_resource_alternate_candidates_bulk( array $subjects ) {
        $resources = [];
        foreach ( $subjects as $subject ) {
            $resource = $this->resolve_resource( $subject );
            if ( $resource instanceof GML_Resource_Identity ) $resources[ $resource->get_key() ] = $resource;
        }
        if ( ! $resources || ! class_exists( 'GML_Resource_Readiness' ) ) return [];
        $languages = $this->get_languages();
        $statuses = $this->get_resource_statuses_bulk( array_values( $resources ), $languages );
        $result = [];
        foreach ( $resources as $key => $resource ) {
            foreach ( $languages as $lang ) {
                $url = $this->get_translated_url( $resource->get_source_url(), $lang );
                if ( $url !== '' ) $result[ $key ][ $lang ] = [ 'url' => $url, 'status' => $statuses[ $key ][ $lang ] ?? 'unknown' ];
            }
        }
        return $result;
    }

    public function get_public_status( $subject, $lang, array $context = [] ) {
        return class_exists( 'GML_Public_Eligibility' )
            ? GML_Public_Eligibility::get_status( $subject, $lang, $context )
            : [];
    }

    public function get_public_cluster( $subject, array $context = [] ) {
        return class_exists( 'GML_Public_Eligibility' )
            ? GML_Public_Eligibility::get_cluster( $subject, $context )
            : [];
    }

    public function get_public_clusters_bulk( array $subjects, array $context = [] ) {
        return class_exists( 'GML_Public_Eligibility' )
            ? GML_Public_Eligibility::get_clusters_bulk( $subjects, $context )
            : [];
    }

    public function get_source_language() {
        if ( ! class_exists( 'GML_Language_Utils' ) ) {
            return '';
        }
        return GML_Language_Utils::normalize_code( get_option( 'gml_source_lang', 'en' ) ) ?: 'en';
    }

    public function get_current_language() {
        $source = $this->get_source_language();
        if ( ! class_exists( 'GML_Language_Utils' ) ) {
            return $source;
        }
        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $lang = GML_Language_Utils::detect_prefix_from_path( strtok( $path, '?' ), true );
        return $lang ?: $source;
    }

    public function has_indexable_targets() {
        $source = $this->get_source_language();
        foreach ( $this->get_languages() as $lang ) {
            if ( $lang !== $source && $this->is_index_ready( $lang ) ) {
                return true;
            }
        }
        return false;
    }

    public function get_hreflang_code( $lang ) {
        $lang = strtolower( str_replace( '_', '-', (string) $lang ) );
        $map  = [
            'zh'    => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            'zh-hk' => 'zh-HK',
        ];
        if ( isset( $map[ $lang ] ) ) {
            return $map[ $lang ];
        }
        if ( strpos( $lang, '-' ) !== false ) {
            list( $language, $region ) = explode( '-', $lang, 2 );
            return strtolower( $language ) . '-' . strtoupper( $region );
        }
        return $lang;
    }

    public function get_og_locale( $lang ) {
        $lang = strtolower( str_replace( '_', '-', (string) $lang ) );
        $map  = [
            'en' => 'en_US', 'zh' => 'zh_CN', 'zh-cn' => 'zh_CN', 'zh-tw' => 'zh_TW',
            'ja' => 'ja_JP', 'fr' => 'fr_FR', 'de' => 'de_DE', 'es' => 'es_ES',
            'pt' => 'pt_PT', 'ru' => 'ru_RU', 'ko' => 'ko_KR', 'ar' => 'ar_SA',
            'it' => 'it_IT', 'nl' => 'nl_NL', 'pl' => 'pl_PL', 'tr' => 'tr_TR',
            'vi' => 'vi_VN', 'th' => 'th_TH', 'id' => 'id_ID', 'ms' => 'ms_MY',
            'uk' => 'uk_UA', 'he' => 'he_IL', 'hi' => 'hi_IN', 'sv' => 'sv_SE',
        ];
        if ( isset( $map[ $lang ] ) ) {
            return $map[ $lang ];
        }
        if ( strpos( $lang, '-' ) !== false ) {
            list( $language, $region ) = explode( '-', $lang, 2 );
            return strtolower( $language ) . '_' . strtoupper( $region );
        }
        return $lang ? $lang . '_' . strtoupper( $lang ) : get_locale();
    }

    public function filter_language_attributes( $output, $doctype ) {
        unset( $doctype );
        $current = $this->get_current_language();
        if ( $current && $current !== $this->get_source_language() ) {
            if ( preg_match( '/\blang=(?:"[^"]*"|\'[^\']*\')/', $output ) ) {
                return preg_replace( '/\blang=(?:"[^"]*"|\'[^\']*\')/', 'lang="' . esc_attr( $current ) . '"', $output, 1 );
            }
            $output = trim( $output );
            return 'lang="' . esc_attr( $current ) . '"' . ( $output === '' ? '' : ' ' . $output );
        }
        return $output;
    }

    private function is_index_ready( $lang ) {
        if ( array_key_exists( $lang, $this->readiness ) ) {
            return $this->readiness[ $lang ];
        }
        if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $lang ) ) {
            $this->readiness[ $lang ] = GML_Language_Utils::get_external_site_url( $lang ) !== '';
            return $this->readiness[ $lang ];
        }
        if ( class_exists( 'GML_Queue_Processor' ) && method_exists( 'GML_Queue_Processor', 'language_is_index_ready' ) ) {
            // Product adapters may provide a test double or a more specific
            // storage adapter. Production adapters delegate this to Core.
            $ready = GML_Queue_Processor::language_is_index_ready( $lang );
        } else {
            $ready = class_exists( 'GML_Translation_Readiness' )
                && GML_Translation_Readiness::language_is_index_ready( $lang );
        }
        $this->readiness[ $lang ] = (bool) $ready;
        return $this->readiness[ $lang ];
    }

    private function multilingual_enabled() {
        return class_exists( 'GML_Translation_State' )
            ? GML_Translation_State::multilingual_enabled()
            : (bool) get_option( 'gml_multilingual_enabled', get_option( 'gml_translation_enabled', false ) );
    }

    private function is_local_url( $url ) {
        $host      = wp_parse_url( (string) $url, PHP_URL_HOST );
        $home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        return ! $host || ! $home_host || strtolower( $host ) === strtolower( $home_host );
    }
}
