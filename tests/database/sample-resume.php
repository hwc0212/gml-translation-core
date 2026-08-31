<?php
$fixture_only = ( $argv[1] ?? '' ) === 'fixture';
if ( $fixture_only ) {
    if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
    require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
    if ( DB_NAME !== 'gml_regression' || $wpdb->prefix !== 'test_' || WP_HOME !== 'http://127.0.0.1:8941' ) exit( 2 );
} else {
    require __DIR__ . '/bootstrap.php';
}
wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_translation_engine', 'gemini' );
GML_Gemini_API::save_api_key( 'synthetic-resume-test-only', 'gemini' );
update_option( 'gml_translation_paused', true );
update_option( 'gml_languages', [ [ 'code' => 'es', 'enabled' => true, 'paused' => false ], [ 'code' => 'de', 'enabled' => true, 'paused' => true ] ] );
delete_option( 'gml_translation_circuit_breaker' );
delete_option( 'gml_translation_process_lock' );
delete_option( 'gml_translation_retry_sample_ids' );
wp_clear_scheduled_hook( 'gml_process_queue' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'gemini_key' => 'synthetic-resume-test-only', 'module_ai_translation_enabled' => 1 ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}
$table = $wpdb->prefix . 'gml_queue';
$ids = [];
for ( $i = 0; $i < 26; $i++ ) {
    $text = 'Resume fixture ' . wp_generate_uuid4();
    $wpdb->insert( $table, [ 'source_hash' => md5( $text ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'es', 'context_type' => 'text', 'status' => 'pending', 'priority' => 5, 'attempts' => 0, 'created_at' => current_time( 'mysql' ) ] );
    $ids[] = (int) $wpdb->insert_id;
}
$sample = array_slice( $ids, 0, 25 );
update_option( 'gml_translation_retry_sample_ids', $sample );
update_option( 'gml_translation_failure_ack', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='failed'" ) );
if ( $fixture_only ) exit( "Paused 25-item sample ready for local browser testing.\n" );

$before = $wpdb->get_results( "CHECKSUM TABLE $table, {$wpdb->prefix}gml_index", ARRAY_A );
$admin = (new ReflectionClass( 'GML_Admin_Settings' ))->newInstanceWithoutConstructor();
$render = new ReflectionMethod( $admin, 'render_translations_tab' );
$render->setAccessible( true );
$_POST = $_REQUEST = [];
ob_start();
$render->invoke( $admin );
$html = ob_get_clean();
$dom = new DOMDocument();
@$dom->loadHTML( $html );
$xpath = new DOMXPath( $dom );
gml_db_assert( $xpath->query( '//button[@value="resume_sample" and not(@disabled)]' )->length > 0, 'paused sample exposes an enabled Resume Sample command' );
gml_db_assert( $xpath->query( '//button[@id="gml-crawl-start" and @disabled]' )->length === 1, 'content scan remains blocked during a limited sample' );
gml_db_assert( is_wp_error( GML_Translation_Controls::start() ), 'full queue start still cannot bypass sample scope' );

$reject = static function() { return false; };
add_filter( 'pre_schedule_event', $reject );
$result = GML_Translation_Controls::resume_sample();
remove_filter( 'pre_schedule_event', $reject );
gml_db_assert( is_wp_error( $result ) && get_option( 'gml_translation_paused' ), 'schedule failure leaves the sample paused' );
gml_db_assert( get_option( 'gml_translation_retry_sample_ids' ) === $sample, 'schedule failure retains exactly the approved sample IDs' );

wp_set_current_user( 0 );
gml_db_assert( is_wp_error( GML_Translation_Controls::resume_sample() ), 'unauthorized user cannot resume a sample' );
wp_set_current_user( 1 );
class GML_Sample_Invalid_Nonce extends RuntimeException {}
$die = static function() { return static function() { throw new GML_Sample_Invalid_Nonce(); }; };
add_filter( 'wp_die_handler', $die );
$_REQUEST = [ 'gml_translation_nonce' => 'invalid' ];
$rejected = false;
try { GML_Translation_Controls::handle_request( [ 'gml_global_action' => 'resume_sample' ] ); }
catch ( GML_Sample_Invalid_Nonce $error ) { $rejected = true; }
remove_filter( 'wp_die_handler', $die );
gml_db_assert( $rejected && get_option( 'gml_translation_paused' ), 'invalid nonce cannot resume the sample' );
update_option( 'gml_translation_circuit_breaker', [ 'message' => 'Synthetic breaker' ] );
gml_db_assert( is_wp_error( GML_Translation_Controls::resume_sample() ), 'sample resume cannot bypass the provider circuit breaker' );
delete_option( 'gml_translation_circuit_breaker' );
update_option( 'gml_multilingual_enabled', false );
gml_db_assert( is_wp_error( GML_Translation_Controls::resume_sample() ), 'disabled multilingual cannot be bypassed by resuming a sample' );
update_option( 'gml_multilingual_enabled', true );
$saved_secrets = [ get_option( 'gml_api_key_encrypted' ), get_option( 'gml_seo' ) ];
delete_option( 'gml_api_key_encrypted' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [ 'engine' => 'gemini', 'module_ai_translation_enabled' => 1 ] );
    $cache->setValue( null, null );
}
gml_db_assert( is_wp_error( GML_Translation_Controls::resume_sample() ), 'missing provider key cannot be bypassed by resuming a sample' );
update_option( 'gml_api_key_encrypted', $saved_secrets[0] );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', $saved_secrets[1] );
    $cache->setValue( null, null );
}
update_option( 'gml_translation_process_lock', [ 'token' => 'finishing-test', 'expires' => time() + 60 ] );
gml_db_assert( is_wp_error( GML_Translation_Controls::resume_sample() ), 'resume waits for an in-flight batch to finish' );
delete_option( 'gml_translation_process_lock' );
update_option( 'gml_translation_retry_sample_ids', $ids );
gml_db_assert( is_wp_error( GML_Translation_Controls::resume_sample() ), 'oversized sample scope cannot be resumed' );
update_option( 'gml_translation_retry_sample_ids', $sample );
gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE $table, {$wpdb->prefix}gml_index", ARRAY_A ), 'rejected resume attempts never rewrite queue rows or saved translations' );

GML_Translation_Controls::pause( 'es' );
$_REQUEST = [ 'gml_translation_nonce' => wp_create_nonce( 'gml_translation_action' ) ];
gml_db_assert( GML_Translation_Controls::handle_request( [ 'gml_global_action' => 'resume_sample' ] ) === true, 'nonce-protected sample resume succeeds after global and language pause' );
$languages = get_option( 'gml_languages' );
gml_db_assert( ! get_option( 'gml_translation_paused' ) && ! $languages[0]['paused'] && $languages[1]['paused'], 'resume enables only the selected sample language and preserves other pauses' );
gml_db_assert( get_option( 'gml_translation_retry_sample_ids' ) === $sample && wp_next_scheduled( 'gml_process_queue' ), 'resume keeps sample scope and schedules before unpausing' );
class GML_Sample_Worker extends GML_Queue_Processor {
    const BATCH_SIZE = 10;
    public static $calls = 0;
    protected function create_api() {
        return new class {
            public function translate_batch( $texts, $source, $target, $type ) {
                GML_Sample_Worker::$calls++;
                return array_map( static function( $text ) { return 'Translated ' . $text; }, $texts );
            }
        };
    }
}
$worker = new GML_Sample_Worker();
$worker->process_batch();
GML_Translation_Controls::pause();
gml_db_assert( GML_Translation_Controls::sample_status()['remaining'] === 15, 'partially processed sample retains its remaining work after pause' );
gml_db_assert( GML_Translation_Controls::resume_sample() === true, 'the same sample can be paused and resumed repeatedly' );
$worker->process_batch();
$worker->process_batch();
gml_db_assert( GML_Sample_Worker::$calls === 3, 'resuming does not repeat completed sample items' );
gml_db_assert( ! get_option( 'gml_translation_retry_sample_ids' ) && get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( 'gml_process_queue' ), 'sample completion clears its marker and automatically pauses the queue' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE id=%d", $ids[25] ) ) === 'pending', 'work outside the approved sample never runs' );
$worker->process_batch();
gml_db_assert( GML_Sample_Worker::$calls === 3 && $GLOBALS['gml_test_http_calls'] === 0, 'no automatic full-queue follow-up or real API request occurs' );
$wpdb->query( "DELETE FROM $table WHERE id IN (" . implode( ',', $ids ) . ')' );
$_REQUEST = [];
