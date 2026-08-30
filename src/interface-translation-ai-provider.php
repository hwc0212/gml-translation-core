<?php
/**
 * Contract required by the shared translation queue.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-ai-provider.php';

interface GML_Translation_AI_Provider_Interface extends GML_AI_Provider_Interface {

    public function get_engine();

    public function translate( $text, $source_lang, $target_lang );

    public function translate_seo( $text, $source_lang, $target_lang );

    public function translate_batch( array $texts, $source_lang, $target_lang, $type = 'text' );
}
