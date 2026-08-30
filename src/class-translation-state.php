<?php
/**
 * Translation feature state shared by the standalone and bundled adapters.
 *
 * Vendored from GML Translation Core. Do not add product UI here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GML_Translation_State {
	const MULTILINGUAL_OPTION = 'gml_multilingual_enabled';
	const AI_OPTION           = 'gml_ai_translation_enabled';
	const LEGACY_OPTION       = 'gml_translation_enabled';

	public static function multilingual_enabled() {
		$missing = new stdClass();
		$value   = get_option( self::MULTILINGUAL_OPTION, $missing );
		if ( $value !== $missing ) {
			return (bool) $value;
		}
		return (bool) get_option( self::LEGACY_OPTION, false );
	}

	public static function set_multilingual_enabled( $enabled ) {
		$enabled = (bool) $enabled;
		$changed = self::multilingual_enabled() !== $enabled;
		update_option( self::MULTILINGUAL_OPTION, $enabled, false );
		// Keep the historical option readable by older releases during rollback.
		update_option( self::LEGACY_OPTION, $enabled, false );
		return $changed;
	}

	public static function ai_translation_enabled() {
		if ( self::is_seo_hosted() && class_exists( 'GML_SEO' ) && method_exists( 'GML_SEO', 'module_enabled' ) ) {
			return GML_SEO::module_enabled( 'ai_translation' );
		}

		$missing = new stdClass();
		$value   = get_option( self::AI_OPTION, $missing );
		if ( $value !== $missing ) {
			return (bool) $value;
		}
		return (bool) get_option( self::LEGACY_OPTION, false );
	}

	public static function set_ai_translation_enabled( $enabled ) {
		update_option( self::AI_OPTION, (bool) $enabled, false );
	}

	public static function has_api_key() {
		if ( self::is_seo_hosted() && class_exists( 'GML_SEO' ) && method_exists( 'GML_SEO', 'has_ai_key' ) ) {
			return GML_SEO::has_ai_key();
		}
		return ! empty( get_option( 'gml_api_key_encrypted' ) )
			|| ! empty( get_option( 'gml_deepseek_api_key_encrypted' ) );
	}

	public static function ai_available() {
		return self::ai_translation_enabled() && self::has_api_key();
	}

	public static function work_enabled() {
		return self::multilingual_enabled()
			&& self::ai_available()
			&& ! get_option( 'gml_translation_paused', false );
	}

	private static function is_seo_hosted() {
		return defined( 'GML_TRANSLATION_HOST' ) && GML_TRANSLATION_HOST === 'gml-seo';
	}
}
