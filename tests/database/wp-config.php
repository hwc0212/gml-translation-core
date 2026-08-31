<?php
/** Only for a disposable database created by the regression runner. */
$database = getenv( 'GML_TEST_DB_NAME' ) ?: 'gml_regression';
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' || strpos( $database, 'gml_regression' ) !== 0 ) {
    exit( "Refusing to configure a non-test database.\n" );
}
define( 'DB_NAME', $database );
define( 'DB_USER', getenv( 'GML_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'GML_TEST_DB_PASSWORD' ) ?: 'gml-regression-only' );
define( 'DB_HOST', getenv( 'GML_TEST_DB_HOST' ) ?: '127.0.0.1:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'gml-disposable-test-auth' );
define( 'SECURE_AUTH_KEY', 'gml-disposable-test-secure' );
define( 'LOGGED_IN_KEY', 'gml-disposable-test-login' );
define( 'NONCE_KEY', 'gml-disposable-test-nonce' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'SAVEQUERIES', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://gml-regression.test' );
define( 'WP_SITEURL', 'http://gml-regression.test' );
$table_prefix = 'test_';
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-settings.php';
