<?php
/** External language sites must remain outside local routing and AI work. */
require __DIR__ . '/bootstrap.php';

$saved = [
    'gml_source_lang' => get_option( 'gml_source_lang', 'en' ),
    'gml_languages' => get_option( 'gml_languages', [] ),
    'gml_multilingual_enabled' => get_option( 'gml_multilingual_enabled', false ),
];

update_option( 'gml_source_lang', 'en' );
update_option( 'gml_multilingual_enabled', true );
update_option( 'gml_languages', [
    [ 'code' => 'es', 'enabled' => true, 'site_mode' => 'local' ],
    [
        'code' => 'zh',
        'enabled' => true,
        'site_mode' => 'external',
        'external_url' => 'https://cnxhe.cn/',
        'external_path_mode' => 'same_path',
    ],
    [
        'code' => 'de',
        'enabled' => true,
        'site_mode' => 'external',
        'external_url' => 'https://de.example.test/',
        'external_path_mode' => 'homepage',
    ],
] );

$all_targets = GML_Language_Utils::configured_codes( false, true );
sort( $all_targets );
gml_db_assert( $all_targets === [ 'de', 'es', 'zh' ], 'public language inventory includes external sites' );
gml_db_assert( GML_Language_Utils::enabled_local_target_codes() === [ 'es' ], 'local route inventory excludes external sites' );

$rules = GML_Translation_Rewrite::rules();
gml_db_assert( count( $rules ) === 2 && strpos( implode( ' ', array_keys( $rules ) ), 'es' ) !== false, 'rewrite rules retain the local language' );
gml_db_assert( strpos( implode( ' ', array_keys( $rules ) ), 'zh' ) === false && strpos( implode( ' ', array_keys( $rules ) ), 'de' ) === false, 'rewrite rules never claim external language paths' );

$source_url = home_url( '/products/example/?utm_source=language-test' );
gml_db_assert( GML_URL_Helper::get_external_language_url( $source_url, 'zh' ) === 'https://cnxhe.cn/products/example/', 'same-path external mapping drops cross-domain query data' );
gml_db_assert( GML_URL_Helper::get_external_language_url( $source_url, 'de' ) === 'https://de.example.test/', 'homepage external mapping uses only the configured base URL' );
gml_db_assert( GML_URL_Helper::get_external_hreflang_url( $source_url, 'de' ) === '', 'homepage-only mapping is not emitted as an inner-page hreflang equivalent' );

$provider = new GML_Translation_Provider();
$alternates = $provider->get_alternate_urls( home_url( '/products/example/' ) );
gml_db_assert( ( $alternates['zh-CN'] ?? '' ) === 'https://cnxhe.cn/products/example/', 'provider exposes a same-path external hreflang URL' );
gml_db_assert( ! isset( $alternates['de'] ), 'provider omits a homepage-only external language from inner-page hreflang' );
$home_alternates = $provider->get_alternate_urls( home_url( '/' ) );
gml_db_assert( ( $home_alternates['de'] ?? '' ) === 'https://de.example.test/', 'provider exposes homepage-only hreflang on the source homepage' );
gml_db_assert( GML_Translation_Queue_Scope::enabled_languages() === [ 'es' ], 'AI queue scope excludes external language sites' );

gml_db_assert( GML_Language_Utils::sanitize_external_site_url( 'http://cnxhe.cn/' ) === '', 'external site configuration rejects HTTP' );
gml_db_assert( GML_Language_Utils::sanitize_external_site_url( 'https://user:pass@cnxhe.cn/' ) === '', 'external site configuration rejects URL credentials' );
gml_db_assert( GML_Language_Utils::sanitize_external_site_url( 'https://cnxhe.cn/?token=secret' ) === '', 'external site configuration rejects query strings' );
gml_db_assert( GML_Language_Utils::sanitize_external_site_url( home_url( '/zh/' ) ) === '', 'external mode rejects the current WordPress host' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'external URL mapping performs no network requests' );

foreach ( $saved as $option => $value ) update_option( $option, $value );
echo "OK external language site isolation and URL mapping\n";
