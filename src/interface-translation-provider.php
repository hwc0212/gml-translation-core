<?php
/**
 * Read-only multilingual data contract used by product adapters.
 *
 * Translation Core owns language and URL relationships. Product adapters own
 * WordPress hooks and final HTML/XML rendering.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface GML_Translation_Provider_Interface {

    /**
     * Return enabled language codes, including the source language.
     *
     * @return string[]
     */
    public function get_languages();

    /**
     * Return index-ready language alternatives keyed by hreflang code.
     *
     * The result may include the special x-default key.
     *
     * @param string $url Source or translated URL for the current resource.
     * @return array<string,string>
     */
    public function get_alternate_urls( $url );

    /**
     * Convert a source or translated URL to a configured language URL.
     *
     * @param string $url  Source or translated URL.
     * @param string $lang Target language code.
     * @return string
     */
    public function get_translated_url( $url, $lang );

    /**
     * Return the current translation status for an object and language.
     *
     * @param int    $object_id WordPress object ID when available.
     * @param string $lang      Language code.
     * @return string complete, incomplete, or disabled.
     */
    public function get_translation_status( $object_id, $lang );

    /** @return string */
    public function get_source_language();

    /** @return string */
    public function get_current_language();

    /** @return bool */
    public function has_indexable_targets();

    /** @return string */
    public function get_hreflang_code( $lang );

    /** @return string */
    public function get_og_locale( $lang );
}
