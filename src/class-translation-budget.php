<?php
/** Bounded translation budgets. Limits are local safety caps, not model maxima. */
if ( ! defined( 'ABSPATH' ) ) exit;

/** A local yield is not a provider/content failure and consumes no item attempt. */
class GML_Translation_Worker_Yield extends RuntimeException {}

class GML_Translation_Budget {
    const OUTPUT_CAP = 8192;
    const MAX_CALLS = 12;
    const TOTAL_OUTPUT_CAP = 32768;
    const MAX_SECONDS = 90;
    private static $worker = null;

    public static function begin_worker($seconds, $guard) {
        self::$worker = ['deadline'=>microtime(true)+$seconds, 'calls'=>0, 'output'=>0, 'input'=>0, 'guard'=>$guard];
    }
    public static function end_worker() { self::$worker = null; }
    public static function reserve_worker_request($input_bytes, $output_tokens) {
        if (self::$worker === null) return;
        $w =& self::$worker;
        $reason=call_user_func($w['guard']);
        if ($reason !== '') throw new GML_Translation_Worker_Yield($reason);
        if (microtime(true) >= $w['deadline']-7) throw new GML_Translation_Worker_Yield('time_budget');
        if ($w['calls'] >= 8 || $w['output']+$output_tokens > self::TOTAL_OUTPUT_CAP || $w['input']+$input_bytes > 262144)
            throw new GML_Translation_Worker_Yield('request_budget');
        // Sites can enforce an existing daily allowance before any paid request.
        if (!apply_filters('gml_translation_worker_can_request', true, $input_bytes, $output_tokens))
            throw new GML_Translation_Worker_Yield('site_budget');
        $w['calls']++; $w['output']+=$output_tokens; $w['input']+=$input_bytes;
    }
    public static function worker_timeout($timeout) {
        return self::$worker === null ? $timeout : min($timeout,max(1,(int)floor(self::$worker['deadline']-microtime(true)-2)));
    }

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
