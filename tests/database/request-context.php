<?php
$context = $argv[1] ?? '';
if ( $context === 'ajax' ) define( 'DOING_AJAX', true );
if ( $context === 'cron' ) define( 'DOING_CRON', true );
require __DIR__ . '/bootstrap.php';
update_option( 'gml_db_version', '2.4.0' );
update_option( 'gml_translation_paused', true );
update_option( 'gml_multilingual_enabled', false );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'dashboard' );
wp_set_current_user( 1 );
gml_test_product_init();
// wp-cron.php never dispatches admin_init; AJAX does.
if ( $context === 'ajax' ) do_action( 'admin_init' );
else GML_Installer::maybe_upgrade();
gml_db_assert( get_option( 'gml_db_version' ) === '2.4.0', $context . ' requests do not run database upgrades' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, $context . ' requests make no external API call' );
