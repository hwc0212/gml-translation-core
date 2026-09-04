<?php
/** Fail before WordPress can render a database error page with exit code zero. */
$host = getenv( 'GML_TEST_DB_HOST' ) ?: '127.0.0.1:3306';
$user = getenv( 'GML_TEST_DB_USER' ) ?: 'root';
$password = getenv( 'GML_TEST_DB_PASSWORD' ) ?: 'gml-regression-only';
$database = getenv( 'GML_TEST_DB_NAME' ) ?: 'gml_regression';
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
mysqli_close( $connection );
echo "OK database preflight\n";
