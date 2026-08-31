<?php
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
$before = $wpdb->get_results( "CHECKSUM TABLE {$wpdb->prefix}gml_queue, {$wpdb->prefix}gml_index", ARRAY_A );
$state = [ get_option( 'gml_translation_paused' ), get_option( 'gml_languages' ), get_option( 'gml_translation_retry_sample_ids' ), get_option( 'gml_translation_circuit_breaker' ) ];
$_REQUEST = [ 'gml_cache_nonce' => wp_create_nonce( 'gml_cache_action' ) ];
foreach ( [ 'refresh_page_cache', 'clear_all_cache', 'clear_lang_cache' ] as $action ) {
    $invalidated = new ReflectionProperty( GML_Page_Cache::class, 'invalidated' );
    $invalidated->setAccessible( true );
    $invalidated->setValue( null, false );
    foreach ( [ '', 'refresh', 'DELETE', [ 'REFRESH' ] ] as $input ) {
        $generation = GML_Page_Cache::generation();
        $result = GML_Translation_Controls::handle_request( [ 'gml_cache_action' => $action, 'gml_cache_confirmation' => $input ] );
        gml_db_assert( is_wp_error( $result ) && GML_Page_Cache::generation() === $generation, $action . ': missing or incorrect typed confirmation cannot invalidate cache' );
    }
    $generation = GML_Page_Cache::generation();
    gml_db_assert( GML_Translation_Controls::handle_request( [ 'gml_cache_action' => $action, 'gml_cache_confirmation' => 'REFRESH' ] ) === true && GML_Page_Cache::generation() > $generation, $action . ': confirmed refresh invalidates rendered pages' );
}
wp_set_current_user( 0 );
gml_db_assert( is_wp_error( GML_Translation_Controls::refresh_cache( 'REFRESH' ) ), 'typed confirmation does not bypass administrator permissions' );
wp_set_current_user( 1 );
class GML_Cache_Nonce_Error extends RuntimeException {}
$die = static function() { return static function() { throw new GML_Cache_Nonce_Error(); }; };
add_filter( 'wp_die_handler', $die );
$_REQUEST = [ 'gml_cache_nonce' => 'invalid' ];
$rejected = false;
$generation = GML_Page_Cache::generation();
try { GML_Translation_Controls::handle_request( [ 'gml_cache_action' => 'refresh_page_cache', 'gml_cache_confirmation' => 'REFRESH' ] ); }
catch ( GML_Cache_Nonce_Error $e ) { $rejected = true; }
remove_filter( 'wp_die_handler', $die );
gml_db_assert( $rejected && GML_Page_Cache::generation() === $generation, 'correct text does not bypass nonce validation' );
gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE {$wpdb->prefix}gml_queue, {$wpdb->prefix}gml_index", ARRAY_A ), 'all cache commands preserve every queue row and saved translation' );
gml_db_assert( $state === [ get_option( 'gml_translation_paused' ), get_option( 'gml_languages' ), get_option( 'gml_translation_retry_sample_ids' ), get_option( 'gml_translation_circuit_breaker' ) ], 'cache refresh preserves pause, language, sample and safety states' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'cache confirmation never calls an AI provider' );
$_REQUEST = [];
