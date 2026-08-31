<?php
require __DIR__ . '/bootstrap.php';

$queue = $wpdb->prefix . 'gml_queue';
$index = $wpdb->prefix . 'gml_index';
// These destructive fixtures are guarded by bootstrap's disposable database check.
$wpdb->query( "DROP TABLE IF EXISTS $queue, $index" );
delete_option( 'gml_db_version' );
gml_db_assert( GML_Installer::activate() === true, 'fresh installation succeeds' );
gml_db_assert( $wpdb->get_var( "SHOW INDEX FROM $queue WHERE Key_name = 'queue_hash_lang'" ) !== null, 'fresh queue has a unique key' );
$wpdb->query( "ALTER TABLE $queue DROP INDEX queue_hash_lang" );
$wpdb->query( "INSERT INTO $queue (source_hash, source_text, source_lang, target_lang, context_type, status, created_at)
    SELECT MD5(CONCAT('old-', MOD(seq, 65000))), CONCAT('Legacy ', seq), 'en', 'de', 'text', 'pending', NOW()
    FROM seq_1_to_130000" );
$wpdb->query( "INSERT INTO $index (source_hash, source_text, source_lang, target_lang, translated_text, context_type, status, created_at, updated_at)
    SELECT MD5(CONCAT('memory-', seq)), CONCAT('Memory ', seq), 'en', 'de', CONCAT('Saved ', seq), '', 'manual', NOW(), NOW()
    FROM seq_1_to_52000" );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue" ) === 130000, 'legacy fixture contains 130000 queue rows, including duplicates' );
gml_db_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $index" ) === 52000, 'legacy fixture contains 52000 manual translations' );
$before = $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A );
$definition = $wpdb->get_row( "SHOW CREATE TABLE $queue", ARRAY_N );
update_option( 'gml_multilingual_enabled', false );
update_option( 'gml_db_version', '2.4.0' );
$wpdb->queries = [];
gml_test_product_init();
gml_db_assert( get_option( 'gml_db_version' ) === '2.4.0', 'real product frontend bootstrap does not upgrade the database' );
gml_db_assert( ! preg_grep( '/(?:ALTER |CREATE |DELETE .*gml_)/i', array_column( $wpdb->queries, 0 ) ), 'frontend bootstrap executes no migration DDL or data cleanup' );
gml_db_assert( GML_Installer::maybe_upgrade() === false, 'direct frontend upgrade is refused' );

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'dashboard' );
wp_set_current_user( 0 );
gml_db_assert( GML_Installer::maybe_upgrade() === false, 'unauthorized admin cannot run setup' );
wp_set_current_user( 1 );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', GML_Installer::lock_name() ) );
$start = microtime( true );
$busy = GML_Installer::activate();
gml_db_assert( is_wp_error( $busy ) && $busy->get_error_code() === 'gml_install_busy' && microtime( true ) - $start < 1, 'competing real connection returns immediately, without waiting for a migration lock' );
gml_db_assert( get_option( 'gml_db_version' ) === '2.4.0', 'contended setup does not advance version' );
$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', GML_Installer::lock_name() ) );

// A queue in use must not force setup to acquire an exclusive metadata lock.
$other->query( 'START TRANSACTION' );
$other->get_var( "SELECT id FROM $queue LIMIT 1" );
$wpdb->queries = [];
$start = microtime( true );
gml_db_assert( GML_Installer::maybe_upgrade() === true, 'authorized admin upgrades while another connection reads the legacy queue' );
$elapsed = microtime( true ) - $start;
$other->query( 'ROLLBACK' );
gml_db_assert( $elapsed < 2, 'large legacy upgrade finishes in ' . round( $elapsed, 3 ) . ' seconds' );
gml_db_assert( ! preg_grep( '/(?:ALTER |CREATE |DELETE .*gml_(?:queue|index))/i', array_column( $wpdb->queries, 0 ) ), 'existing tables receive no ALTER, CREATE or DELETE' );
gml_db_assert( $definition === $wpdb->get_row( "SHOW CREATE TABLE $queue", ARRAY_N ), 'legacy queue schema is unchanged' );
gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A ), 'all legacy queue and manual translation bytes survive unchanged' );
update_option( 'gml_translation_paused', true );
update_option( 'gml_glossary_rules', [ [ 'source' => 'Acme', 'target' => 'Acme' ] ], false );
update_option( 'gml_db_version', '2.5.1' );
gml_db_assert( GML_Installer::maybe_upgrade() === true, 'upgrade also accepts the previous shared Core schema version' );
gml_db_assert( get_option( 'gml_translation_paused' ) && get_option( 'gml_glossary_rules' ) === [ [ 'source' => 'Acme', 'target' => 'Acme' ] ], 'upgrade preserves pause and glossary options' );
gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A ), '2.5.1 upgrade preserves all translation data' );

// A real SQL permission error must fail closed without marking setup complete.
$wpdb->query( "CREATE USER IF NOT EXISTS 'gml_regression_limited'@'localhost' IDENTIFIED BY 'test-only'" );
$wpdb->query( "CREATE USER IF NOT EXISTS 'gml_regression_limited'@'%' IDENTIFIED BY 'test-only'" );
foreach ( [ 'localhost', '%' ] as $host ) {
    $wpdb->query( "GRANT SELECT, INSERT, UPDATE, DELETE ON `" . DB_NAME . "`.* TO 'gml_regression_limited'@'$host'" );
}
$wpdb->query( "RENAME TABLE $queue TO {$queue}_saved" );
update_option( 'gml_db_version', '2.4.0' );
$admin_db = $wpdb;
$limited = new wpdb( 'gml_regression_limited', 'test-only', DB_NAME, DB_HOST );
$limited->set_prefix( 'test_' );
$limited->suppress_errors( true );
$GLOBALS['wpdb'] = $limited;
$failure = GML_Installer::activate();
$GLOBALS['wpdb'] = $admin_db;
gml_db_assert( is_wp_error( $failure ) && $failure->get_error_code() === 'gml_install_failed', 'real CREATE permission error is reported safely' );
gml_db_assert( get_option( 'gml_db_version' ) === '2.4.0', 'failed setup does not mark the database upgraded' );
gml_db_assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', GML_Installer::lock_name() ) ) === 1, 'failed setup releases its named lock' );
gml_db_assert( GML_Installer::maybe_upgrade() === false, 'failure cooldown prevents repeated setup on every admin request' );
$wpdb->query( "RENAME TABLE {$queue}_saved TO $queue" );
delete_option( GML_Installer::ERROR_OPTION );
gml_db_assert( GML_Installer::maybe_upgrade() === true, 'setup recovers after database permissions are restored' );
gml_db_assert( $before === $wpdb->get_results( "CHECKSUM TABLE $queue, $index", ARRAY_A ), 'failed setup and retry preserve old data' );
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'upgrade makes zero external API requests' );
