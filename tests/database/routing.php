<?php
/** Real WordPress activation, rewrite maintenance and frontend request regression. */
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
$mode = $argv[1] ?? '';
if ( $mode === 'repair' ) define( 'WP_ADMIN', true );
if ( ! defined( 'SAVEQUERIES' ) ) define( 'SAVEQUERIES', true );
$home = getenv( 'GML_TEST_HOME' ) ?: 'http://gml-regression.test';
$fixture_request = $argv[2] ?? '/';
$_SERVER['REQUEST_URI'] = rtrim( parse_url( $home, PHP_URL_PATH ) ?: '', '/' ) . $fixture_request;
$_SERVER['HTTP_HOST'] = parse_url( $home, PHP_URL_HOST );
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = getenv( 'GML_TEST_WP_ROOT' ) . '/index.php';
$_SERVER['PATH_INFO'] = '';
$_SERVER['QUERY_STRING'] = parse_url( $fixture_request, PHP_URL_QUERY ) ?: '';
parse_str( $_SERVER['QUERY_STRING'], $_GET );
require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
if ( strpos( DB_NAME, 'gml_regression' ) !== 0 || $wpdb->prefix !== 'test_' ) exit( 2 );
if ( ! is_blog_installed() ) throw new RuntimeException( 'WordPress test schema is not installed.' );
echo "MARKER wordpress_bootstrap_loaded\n";
require_once ABSPATH . 'wp-admin/includes/plugin.php';
function route_assert( $ok, $label ) {
    if ( ! $ok ) throw new RuntimeException( 'FAIL: ' . $label );
    echo 'PASS: ' . $label . "\n";
}
function language_rules() {
    return array_filter( (array) get_option( 'rewrite_rules', [] ), static function( $rule ) { return strpos( $rule, 'gml_lang=' ) !== false; } );
}
$slug = basename( realpath( getenv( 'GML_TEST_PRODUCT_DIR' ) ) );
if ( ! in_array( $slug, [ 'gml-translate', 'gml-seo' ], true ) ) exit( 2 );
$file = $slug . '/' . $slug . '.php';
if ( $mode === 'prepare' ) {
    if ( get_option( 'active_plugins', [] ) ) exit( 'Deactivate the previous fixture first.' );
    update_option( 'home', $home );
    update_option( 'siteurl', $home );
    route_assert( untrailingslashit( home_url( '/' ) ) === untrailingslashit( $home ), 'fixture uses the requested installation base URL' );
    $front = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Routing Front', 'post_name' => 'routing-front' ] );
    $about = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Routing About', 'post_name' => 'routing-about' ] );
    update_option( 'gml_routing_fixture_ids', [ $front, $about ] );
    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', $front );
    update_option( 'permalink_structure', '/%postname%/' );
    $wp_rewrite->init();
    update_option( 'gml_multilingual_enabled', true );
    update_option( 'gml_translation_enabled', true );
    update_option( 'gml_ai_translation_enabled', false );
    update_option( 'gml_translation_paused', true );
    update_option( 'gml_seo', [ 'module_ai_translation_enabled' => false, 'module_ai_enabled' => false, 'module_monthly_report_enabled' => false ] );
    delete_option( 'gml_api_key_encrypted' );
    delete_option( 'gml_deepseek_api_key_encrypted' );
    update_option( 'gml_languages', [ [ 'code' => 'es', 'enabled' => true ], [ 'code' => 'de', 'enabled' => true ] ] );
    flush_rewrite_rules( false );
    route_assert( language_rules() === [], 'fixture reproduces missing persisted language rules' );
    $result = activate_plugin( $file );
    route_assert( ! is_wp_error( $result ), 'activation after init succeeds with existing languages and no AI key' );
    route_assert( count( language_rules() ) === 2, 'late activation persists both language routes without waiting for init again' );
} elseif ( $mode === 'request' ) {
    $before = get_option( 'rewrite_rules' );
    $GLOBALS['wp']->main();
    $ids = get_option( 'gml_routing_fixture_ids' );
    $kind = $argv[3] ?? 'home';
    if ( $kind === 'missing' ) {
        route_assert( is_404(), 'unknown page/language remains a real 404: ' . $fixture_request );
    } elseif ( $kind === 'search' ) {
        route_assert( is_search(), 'language search keeps query semantics' );
    } else {
        $expected = $kind === 'page' ? $ids[1] : $ids[0];
        route_assert( ! is_404() && get_queried_object_id() === (int) $expected, 'WordPress resolves the correct published object: ' . $fixture_request );
    }
    route_assert( $before === get_option( 'rewrite_rules' ), 'frontend request does not regenerate persisted rewrite rules' );
    route_assert( ! GML_Translation_State::ai_available(), 'language routing works with AI disabled and no credentials' );
} elseif ( $mode === 'break-rules' ) {
    update_option( 'rewrite_rules', array_diff_key( (array) get_option( 'rewrite_rules' ), language_rules() ) );
    delete_option( 'gml_translation_flush_rewrite_rules' );
    route_assert( language_rules() === [], 'simulate a lost rewrite cache without an upgrade flag' );
} elseif ( $mode === 'repair' ) {
    foreach ( [ '_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes' ] as $callback ) remove_action( 'admin_init', $callback );
    $data = $wpdb->get_results( "CHECKSUM TABLE {$wpdb->prefix}gml_queue, {$wpdb->prefix}gml_index", ARRAY_A );
    $other_rules = get_option( 'rewrite_rules' );
    wp_set_current_user( 0 );
    do_action( 'admin_init' );
    route_assert( language_rules() === [], 'unauthorized request cannot repair rules' );
    wp_set_current_user( 1 );
    do_action( 'admin_init' );
    route_assert( count( language_rules() ) === 2, 'authorized admin repairs missing routes even without a legacy flag' );
    route_assert( array_diff_key( get_option( 'rewrite_rules' ), language_rules() ) === $other_rules, 'repair retains every unrelated persisted route' );
    $before_queries = count( $wpdb->queries );
    do_action( 'admin_init' );
    $writes = preg_grep( '/(?:UPDATE|INSERT|DELETE).*rewrite_rules/i', array_column( array_slice( $wpdb->queries, $before_queries ), 0 ) );
    route_assert( ! $writes, 'healthy routing does not trigger repeated rewrite writes' );
    route_assert( $data === $wpdb->get_results( "CHECKSUM TABLE {$wpdb->prefix}gml_queue, {$wpdb->prefix}gml_index", ARRAY_A ), 'route repair preserves every queue item and saved translation' );
    route_assert( get_option( 'gml_translation_paused' ), 'route repair never resumes AI' );
    update_option( 'gml_languages', [ [ 'code' => 'fr', 'enabled' => true ] ] );
    do_action( 'admin_init' );
    route_assert( language_rules() === GML_Translation_Rewrite::rules(), 'changed languages replace obsolete GML patterns' );
    GML_Translation_State::set_multilingual_enabled( false );
    do_action( 'admin_init' );
    route_assert( ! language_rules() && get_option( 'rewrite_rules' ) === $other_rules, 'disabling multilingual removes only GML language routes' );
    GML_Translation_State::set_multilingual_enabled( true );
    update_option( 'gml_languages', [ [ 'code' => 'es', 'enabled' => true ], [ 'code' => 'de', 'enabled' => true ] ] );
    do_action( 'admin_init' );
    route_assert( count( language_rules() ) === 2, 're-enabling multilingual restores routes without an AI key' );
} elseif ( $mode === 'import' ) {
    $before = language_rules();
    delete_option( 'gml_translation_flush_rewrite_rules' );

    // Simulate a bulk importer writing routing configuration in several steps.
    update_option( 'gml_languages', [ [ 'code' => 'fr', 'enabled' => true ] ] );
    update_option( 'gml_languages', [ [ 'code' => 'fr', 'enabled' => true ], [ 'code' => 'it', 'enabled' => true ] ] );
    route_assert( get_option( 'gml_translation_flush_rewrite_rules' ) === '1', 'routing import records one deferred rewrite refresh' );
    route_assert( language_rules() === $before, 'bulk import does not flush rewrite rules per imported item' );

    GML_Translation_Rewrite::maybe_flush_deferred();
    route_assert( ! get_option( 'gml_translation_flush_rewrite_rules' ), 'safe lifecycle clears the deferred marker after attempting refresh' );
    route_assert( language_rules() === GML_Translation_Rewrite::rules(), 'safe lifecycle persists the imported language routes' );

    $after = get_option( 'rewrite_rules' );
    update_option( 'gml_languages', [ [ 'code' => 'fr', 'enabled' => true ], [ 'code' => 'it', 'enabled' => true ] ] );
    route_assert( ! get_option( 'gml_translation_flush_rewrite_rules' ), 're-importing identical routing does not schedule another refresh' );
    GML_Translation_Rewrite::maybe_flush_deferred();
    route_assert( get_option( 'rewrite_rules' ) === $after, 'unchanged routing does not rewrite the persisted rules' );
} elseif ( $mode === 'cleanup' ) {
    deactivate_plugins( $file );
    foreach ( (array) get_option( 'gml_routing_fixture_ids', [] ) as $id ) wp_delete_post( $id, true );
    delete_option( 'gml_routing_fixture_ids' );
    update_option( 'show_on_front', 'posts' );
    update_option( 'page_on_front', 0 );
    update_option( 'gml_multilingual_enabled', false );
}
