<?php
/** Fail before WordPress can render a database error page with exit code zero. */
$host = getenv( 'GML_TEST_DB_HOST' ) ?: '127.0.0.1:3306';
$user = getenv( 'GML_TEST_DB_USER' ) ?: 'root';
$password = getenv( 'GML_TEST_DB_PASSWORD' ) ?: 'gml-regression-only';
$database = getenv( 'GML_TEST_DB_NAME' ) ?: 'gml_regression';
$prefix = getenv( 'GML_TEST_DB_PREFIX' ) ?: 'test_';
$port = 3306;

if ( preg_match( '/^(.+):(\d+)$/', $host, $matches ) ) {
    $host = $matches[1];
    $port = (int) $matches[2];
}

$connection = mysqli_init();
if ( ! $connection || ! @mysqli_real_connect( $connection, $host, $user, $password, $database, $port ) ) {
    fwrite( STDERR, "Database preflight failed. Start the disposable MariaDB service and verify GML_TEST_DB_* values.\n" );
    exit( 1 );
}

$result = mysqli_query( $connection, 'SELECT 1' );
if ( ! $result ) {
    fwrite( STDERR, "Database preflight query failed.\n" );
    exit( 1 );
}

mysqli_free_result( $result );
$options = $prefix . 'options';
$table = mysqli_query( $connection, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . mysqli_real_escape_string( $connection, $database ) . "' AND TABLE_NAME='" . mysqli_real_escape_string( $connection, $options ) . "'" );
if ( ! $table || mysqli_num_rows( $table ) !== 1 ) {
    fwrite( STDERR, "Database preflight failed: WordPress schema is not installed. Run tests/database/setup.php first.\n" );
    exit( 1 );
}
mysqli_free_result( $table );
$installed = mysqli_query( $connection, "SELECT option_value FROM `" . str_replace( '`', '``', $options ) . "` WHERE option_name='siteurl' LIMIT 1" );
if ( ! $installed || mysqli_num_rows( $installed ) !== 1 ) {
    fwrite( STDERR, "Database preflight failed: WordPress siteurl is missing. Run tests/database/setup.php first.\n" );
    exit( 1 );
}
$siteurl = mysqli_fetch_row( $installed );
if ( trim( (string) ( $siteurl[0] ?? '' ) ) === '' ) {
    fwrite( STDERR, "Database preflight failed: WordPress siteurl is empty. Run tests/database/setup.php with an authoritative HTTP_HOST.\n" );
    exit( 1 );
}
mysqli_free_result( $installed );
mysqli_close( $connection );
echo "MARKER database_schema_ready\n";
echo "OK database and installed WordPress schema preflight\n";
