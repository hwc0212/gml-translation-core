<?php
require __DIR__ . '/bootstrap.php';

wp_set_current_user( 1 );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_ai_translation_enabled', true );
update_option( 'gml_source_lang', 'en' );
update_option( 'gml_languages', [
    [ 'code' => 'it', 'enabled' => true, 'paused' => true ],
    [ 'code' => 'pt', 'enabled' => true, 'paused' => true ],
    [ 'code' => 'nl', 'enabled' => true, 'paused' => true ],
] );
GML_Gemini_API::save_api_key( 'failure-recovery-test-only', 'gemini' );
if ( class_exists( 'GML_SEO' ) ) {
    update_option( 'gml_seo', [
        'engine' => 'gemini',
        'gemini_key' => 'failure-recovery-test-only',
        'module_ai_translation_enabled' => 1,
    ] );
    $cache = new ReflectionProperty( GML_SEO::class, 'opt_cache' );
    $cache->setAccessible( true );
    $cache->setValue( null, null );
}

$queue = $wpdb->prefix . 'gml_queue';
$index = $wpdb->prefix . 'gml_index';
$queue_ids = [];
$hashes = [];

class GML_Failure_Recovery_Provider {
    public $mode = 'rate_limited';
    public $calls = 0;
    private $last_error = [];

    public function translate_batch( $texts, $source, $target, $type ) {
        unset( $source, $target, $type );
        $this->calls++;
        if ( $this->mode === 'rate_limited' ) {
            $this->last_error = [
                'code' => 'rate_limited', 'status' => 429, 'retryable' => true,
                'retry_after' => 120, 'message' => 'Google Gemini API HTTP 429',
            ];
            throw new RuntimeException( $this->last_error['message'] );
        }
        if ( $this->mode === 'bad_request' ) {
            $this->last_error = [
                'code' => 'bad_request', 'status' => 400, 'retryable' => false,
                'message' => 'Google Gemini API HTTP 400: invalid request',
            ];
            throw new RuntimeException( $this->last_error['message'] );
        }
        $this->last_error = [];
        return array_map( static function( $text ) { return 'Offline ' . $text; }, $texts );
    }

    public function get_last_error() { return $this->last_error; }
    public function get_engine() { return 'gemini'; }
    public function get_model() { return 'test-model'; }
}

class GML_Failure_Recovery_Worker extends GML_Queue_Processor {
    public static $provider;
    protected function create_api() { return self::$provider; }
}

$provider = new GML_Failure_Recovery_Provider();
GML_Failure_Recovery_Worker::$provider = $provider;
$worker = new GML_Failure_Recovery_Worker();

foreach ( [ GML_Queue_Processor::CIRCUIT_OPTION, GML_Queue_Processor::BACKOFF_OPTION, GML_Queue_Processor::SAMPLE_OPTION, GML_Queue_Processor::LOCK_OPTION ] as $option ) delete_option( $option );
delete_option( GML_Queue_Processor::FAILURE_ACK_OPTION );
delete_option( 'gml_translation_last_batch' );

$text = 'Transient recovery ' . wp_generate_uuid4();
$hashes[] = md5( $text );
$wpdb->insert( $queue, [
    'source_hash' => end( $hashes ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'it',
    'context_type' => 'text', 'status' => 'pending', 'attempts' => 0, 'created_at' => current_time( 'mysql' ),
] );
$queue_ids[] = (int) $wpdb->insert_id;
GML_Translation_Controls::pause();
gml_db_assert( GML_Translation_Controls::start( 'it' ) === true, 'transient test queue starts for one language' );
$worker->process_batch();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts FROM $queue WHERE id=%d", end( $queue_ids ) ) );
gml_db_assert( $row->status === 'pending' && (int) $row->attempts === 0, 'HTTP 429 returns work to pending without consuming item retries' );
gml_db_assert( GML_Queue_Processor::backoff_is_active() && ! GML_Queue_Processor::circuit_is_open(), 'HTTP 429 opens provider cooldown instead of the configuration breaker' );
gml_db_assert( GML_Translation_Controls::queue_status( 'it', 1 )['state'] === 'waiting', 'provider cooldown is reported as waiting' );
$worker->maybe_schedule_cron();
gml_db_assert( (bool) wp_next_scheduled( 'gml_process_queue' ), 'recurring worker remains scheduled during provider cooldown' );
$worker->process_batch();
gml_db_assert( $provider->calls === 1, 'provider is not called again while cooldown is active' );

$backoff = GML_Queue_Processor::get_backoff();
$backoff['until'] = time() - 1;
update_option( GML_Queue_Processor::BACKOFF_OPTION, $backoff, false );
$provider->mode = 'success';
$worker->process_batch();
gml_db_assert( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue WHERE id=%d", end( $queue_ids ) ) ) === 'completed', 'queue resumes after cooldown and saves the translation' );
gml_db_assert( GML_Queue_Processor::get_backoff() === [], 'successful work clears provider cooldown state' );

GML_Translation_Controls::pause();
$text = 'Configuration failure ' . wp_generate_uuid4();
$hashes[] = md5( $text );
$wpdb->insert( $queue, [
    'source_hash' => end( $hashes ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'pt',
    'context_type' => 'text', 'status' => 'pending', 'attempts' => 0, 'created_at' => current_time( 'mysql' ),
] );
$queue_ids[] = (int) $wpdb->insert_id;
$provider->mode = 'bad_request';
gml_db_assert( GML_Translation_Controls::start( 'pt' ) === true, 'configuration test queue starts independently' );
$worker->process_batch();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts FROM $queue WHERE id=%d", end( $queue_ids ) ) );
$circuit = GML_Queue_Processor::get_circuit_breaker();
gml_db_assert( $row->status === 'pending' && (int) $row->attempts === 0, 'HTTP 400 does not burn retries across pending queue items' );
gml_db_assert( GML_Queue_Processor::circuit_is_open() && ( $circuit['code'] ?? '' ) === 'bad_request', 'HTTP 400 opens a classified configuration breaker' );
gml_db_assert( get_option( 'gml_translation_paused' ) && ! wp_next_scheduled( 'gml_process_queue' ), 'configuration breaker pauses and unschedules AI work' );
GML_Queue_Processor::clear_circuit_breaker();

$classified = GML_Translation_Error::classify( [ 'code' => 'bad_request', 'status' => 400 ], 'Google Gemini API HTTP 400: API key not valid. api_key=secret-value' );
gml_db_assert( $classified['code'] === 'authentication_error' && $classified['category'] === 'configuration', 'invalid-key HTTP 400 receives an actionable authentication classification' );
gml_db_assert( strpos( $classified['message'], 'secret-value' ) === false, 'structured diagnostics redact credentials before storage or display' );
gml_db_assert( GML_Translation_Error::classify( [], 'Google Gemini API returned HTTP 404' )['code'] === 'not_found', 'legacy HTTP 404 is classified without a schema migration' );
gml_db_assert( GML_Translation_Error::classify( [], 'Empty translation result' )['code'] === 'empty_result', 'legacy empty results remain identifiable' );

$failure_messages = [
    'Google Gemini API returned HTTP 400',
    'Google Gemini API returned HTTP 429',
    'Google Gemini API returned HTTP 404',
    'Empty translation result',
];
$failure_ids = [];
foreach ( $failure_messages as $i => $message ) {
    $text = 'Historical failure ' . $i . ' ' . wp_generate_uuid4() . ( $i === 3 ? ' <script>unsafe()</script>' : '' );
    $hashes[] = md5( $text );
    $wpdb->insert( $queue, [
        'source_hash' => end( $hashes ), 'source_text' => $text, 'source_lang' => 'en', 'target_lang' => 'nl',
        'context_type' => $i === 3 ? 'seo_meta' : 'text', 'status' => 'failed', 'attempts' => 3,
        'error_message' => $message, 'created_at' => current_time( 'mysql' ), 'processed_at' => null,
    ] );
    $failure_ids[] = (int) $wpdb->insert_id;
    $queue_ids[] = (int) $wpdb->insert_id;
}
GML_Queue_Processor::clear_circuit_breaker();
$counts = GML_Queue_Processor::get_failure_counts();
gml_db_assert( $counts['total'] >= 4 && $counts['new'] === 0, 'successful connection acknowledgement separates historical failures from current failures' );
$ack = get_option( GML_Queue_Processor::FAILURE_ACK_OPTION, [] );
$wpdb->update( $queue, [ 'processed_at' => date( 'Y-m-d H:i:s', strtotime( $ack['at'] ) + 2 ) ], [ 'id' => $failure_ids[3] ] );
$counts = GML_Queue_Processor::get_failure_counts();
gml_db_assert( $counts['new'] === 1, 'a later failure on an existing row is counted as new' );
$summary = GML_Queue_Processor::get_failure_summary( 'nl', 10 );
$codes = array_column( array_map( static function( $item ) { return (array) $item; }, $summary ), 'error_code' );
gml_db_assert( in_array( 'bad_request', $codes, true ) && in_array( 'rate_limited', $codes, true ) && in_array( 'not_found', $codes, true ) && in_array( 'empty_result', $codes, true ), 'legacy failures are grouped into actionable error classes' );
$details = GML_Queue_Processor::get_failure_details( 'nl', 10 );
gml_db_assert( count( $details ) >= 4 && isset( $details[0]->safe_message ), 'recent failed-row details are available without changing the queue schema' );
$_POST = $_REQUEST = [];
$admin = ( new ReflectionClass( 'GML_Admin_Settings' ) )->newInstanceWithoutConstructor();
$render = new ReflectionMethod( $admin, 'render_translations_tab' );
$render->setAccessible( true );
ob_start();
$render->invoke( $admin );
$failure_html = ob_get_clean();
gml_db_assert( strpos( $failure_html, 'stored failed items' ) !== false && strpos( $failure_html, 'Review the 20 most recent failed items' ) !== false, 'admin queue renders historical failure status and bounded row details' );
gml_db_assert( strpos( $failure_html, '<script>unsafe()</script>' ) === false, 'failed source previews are escaped and cannot inject admin HTML' );

$resolved_id = $failure_ids[0];
$resolved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $queue WHERE id=%d", $resolved_id ) );
$wpdb->insert( $index, [
    'source_hash' => $resolved->source_hash, 'source_text' => $resolved->source_text, 'source_lang' => 'en', 'target_lang' => 'nl',
    'translated_text' => 'Already saved', 'context_type' => 'text', 'status' => 'auto',
    'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
] );
delete_option( GML_Queue_Processor::SAMPLE_OPTION );
delete_option( GML_Queue_Processor::CIRCUIT_OPTION );
GML_Translation_Controls::pause();
$retried = GML_Queue_Processor::retry_failed( 'nl', 1 );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue WHERE id=%d", $resolved_id ) ) === 'completed', 'explicit retry reconciles a historical failure already present in translation memory' );
gml_db_assert( $retried === 1 && count( (array) get_option( GML_Queue_Processor::SAMPLE_OPTION, [] ) ) === 1, 'only unresolved failures enter the bounded retry sample' );

GML_Translation_Controls::pause();
if ( $queue_ids ) $wpdb->query( 'DELETE FROM ' . $queue . ' WHERE id IN (' . implode( ',', array_map( 'intval', $queue_ids ) ) . ')' );
foreach ( array_unique( $hashes ) as $hash ) $wpdb->delete( $index, [ 'source_hash' => $hash ] );
foreach ( [ GML_Queue_Processor::CIRCUIT_OPTION, GML_Queue_Processor::BACKOFF_OPTION, GML_Queue_Processor::SAMPLE_OPTION, GML_Queue_Processor::LOCK_OPTION, GML_Queue_Processor::FAILURE_ACK_OPTION ] as $option ) delete_option( $option );
delete_option( 'gml_translation_last_batch' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'failure recovery regression makes no external API requests' );
