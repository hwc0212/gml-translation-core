<?php
/** Terminal recovery must survive another worker tick and local write failure. */
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
gml_db_assert( GML_Installer::activate() === true, 'queue budget schema available' );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [ [ 'code' => 'it', 'enabled' => true, 'paused' => false ] ] );
GML_Gemini_API::save_api_key( 'queue-budget-fixture', 'gemini' );

class GML_Budget_Queue_Provider {
    public $calls = 0;
    public $single_failure = false;
    public function translate_batch( $texts, $source, $target, $type ) { $this->calls++; throw new RuntimeException( 'Bounded output recovery stopped (incomplete_response)' ); }
    public function get_last_error() { return [ 'code' => $this->single_failure && $this->calls === 1 ? 'incomplete_response' : 'output_limit', 'retryable' => false, 'message' => 'Bounded recovery exhausted' ]; }
}
class GML_Budget_Queue_Worker extends GML_Queue_Processor {
    public static $provider;
    protected function create_api() { return self::$provider; }
}
$queue = $wpdb->prefix . 'gml_queue';
$index = $wpdb->prefix . 'gml_index';
foreach ( [ 'normal', 'write_failure', 'single_write_failure' ] as $case ) {
    $break_write = $case !== 'normal';
    foreach ( [ GML_Queue_Processor::CIRCUIT_OPTION, GML_Queue_Processor::BACKOFF_OPTION, GML_Queue_Processor::SAMPLE_OPTION, GML_Queue_Processor::LOCK_OPTION ] as $key ) delete_option( $key );
    GML_Queue_Processor::clear_circuit_breaker();
    $text = 'Budget queue fixture ' . wp_generate_uuid4();
    $hash = md5( $text );
    $wpdb->insert( $queue, [ 'source_hash' => $hash, 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'it', 'context_type' => 'text', 'status' => 'pending', 'created_at' => current_time( 'mysql' ) ] );
    $id = (int) $wpdb->insert_id;
    update_option( GML_Queue_Processor::SAMPLE_OPTION, [ $id ] );
    update_option( GML_Translation_Queue_Scope::SAMPLE_PAUSED_OPTION, false );
    update_option( GML_Translation_Queue_Scope::NORMAL_OPTION, false );
    update_option( 'gml_translation_paused', false );
    $provider = new GML_Budget_Queue_Provider();
    $provider->single_failure = $case === 'single_write_failure';
    GML_Budget_Queue_Worker::$provider = $provider;
    $worker = new GML_Budget_Queue_Worker();
    $filter = static function( $sql ) use ( $queue ) {
        if ( strpos( $sql, 'UPDATE `' . $queue . '`' ) === 0 && strpos( $sql, '[output_limit]' ) !== false ) return 'UPDATE missing_budget_table SET id=1';
        return $sql;
    };
    $suppress = $wpdb->suppress_errors( true );
    if ( $break_write ) add_filter( 'query', $filter );
    wp_set_current_user( 0 );
    try { $worker->process_batch(); } catch ( RuntimeException $e ) { if ( ! $break_write ) throw $e; }
    remove_filter( 'query', $filter );
    $wpdb->suppress_errors( $suppress );
    ( new GML_Budget_Queue_Worker() )->process_batch();
    gml_db_assert( $provider->calls === ( $provider->single_failure ? 2 : 1 ), 'terminal recovery never reopens fallback or repeats in new worker; calls=' . $provider->calls );
    gml_db_assert( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $index WHERE source_hash=%s", $hash ) ), 'terminal failure never saves translation memory' );
    if ( $break_write ) gml_db_assert( get_option( 'gml_translation_paused' ) && GML_Queue_Processor::circuit_is_open(), 'failed terminal persistence pauses queue and opens local circuit' );
    else gml_db_assert( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue WHERE id=%d", $id ) ) === 'failed', 'first exhausted recovery is durably failed' );
    $wpdb->delete( $queue, [ 'id' => $id ] );
}
wp_set_current_user( 1 );
GML_Translation_Controls::pause();
GML_Queue_Processor::clear_circuit_breaker();
delete_option( GML_Queue_Processor::SAMPLE_OPTION );
echo "OK queue budget regression\n";
