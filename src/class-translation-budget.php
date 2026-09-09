<?php
/** Bounded translation budgets. Limits are local safety caps, not model maxima. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Translation_Budget {
    const OUTPUT_CAP = 8192;
    const MAX_CALLS = 12;
    const TOTAL_OUTPUT_CAP = 32768;
    const MAX_SECONDS = 90;

    public static function estimate( array $texts, $target, $type, $engine, $model ) {
        $source = implode( "\n", $texts );
        $count = count( $texts );
        $expansion = in_array( strtolower( substr( $target, 0, 2 ) ), [ 'ru', 'uk', 'ar', 'hi', 'th' ], true ) ? 2.0 : 1.5;
        // Byte-based upper estimate includes markup, placeholders, and escapes.
        $estimated = (int) ceil( strlen( $source ) / 2 * $expansion );
        $overhead = 128 + 24 * $count;
        $floor = $count > 1 || $engine === 'gemini' ? 2048 : 1024;
        $seo = in_array( $type, [ 'seo', 'seo_meta', 'seo_title' ], true );
        if ( $seo ) $overhead += 32 * $count;
        return min( self::OUTPUT_CAP, max( $floor, (int) ceil( ( $estimated + $overhead ) * 1.25 ) ) );
    }

    public static function gemini_thinking( $model ) {
        if ( in_array( $model, [ 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3-flash-preview' ], true ) ) return [ 'thinkingLevel' => 'minimal' ];
        if ( in_array( $model, [ 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-3.1-pro-preview' ], true ) ) return [ 'thinkingLevel' => 'low' ];
        if ( in_array( $model, [ 'gemini-2.5-flash', 'gemini-2.5-flash-lite' ], true ) ) return [ 'thinkingBudget' => 0 ];
        return []; // Unknown models never receive guessed capability parameters.
    }

    public static function deepseek_non_thinking( $model ) {
        return in_array( $model, [ 'deepseek-chat', 'deepseek-v4-flash', 'deepseek-v4-pro' ], true );
    }
}
