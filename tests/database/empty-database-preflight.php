<?php
/** Prove an empty database cannot produce a green database-suite preflight. */
$host = getenv( 'GML_TEST_DB_HOST' ) ?: '127.0.0.1:3306';
$user = getenv( 'GML_TEST_DB_USER' ) ?: 'root';
$password = getenv( 'GML_TEST_DB_PASSWORD' ) ?: 'gml-regression-only';
$port = 3306;
if ( preg_match( '/^(.+):(\d+)$/', $host, $matches ) ) {
    $host = $matches[1];
    $port = (int) $matches[2];
}
$database = 'gml_regression_empty_preflight';
$connection = mysqli_init();
if ( ! $connection || ! @mysqli_real_connect( $connection, $host, $user, $password, '', $port ) ) exit( "Unable to prepare empty preflight database.\n" );
mysqli_query( $connection, "DROP DATABASE IF EXISTS `$database`" );
if ( ! mysqli_query( $connection, "CREATE DATABASE `$database`" ) ) exit( "Unable to create empty preflight database.\n" );

$environment = array_merge( $_ENV, [
    'GML_TEST_DB_HOST' => getenv( 'GML_TEST_DB_HOST' ) ?: '127.0.0.1:3306',
    'GML_TEST_DB_USER' => $user,
    'GML_TEST_DB_PASSWORD' => $password,
    'GML_TEST_DB_NAME' => $database,
] );
$pipes = [];
$process = proc_open( [ PHP_BINARY, __DIR__ . '/preflight.php' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $environment );
if ( ! is_resource( $process ) ) exit( "Unable to execute empty database preflight.\n" );
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$exit_code = proc_close( $process );
mysqli_query( $connection, "DROP DATABASE IF EXISTS `$database`" );
mysqli_close( $connection );
if ( $exit_code === 0 || strpos( $stderr, 'WordPress schema is not installed' ) === false ) {
    fwrite( STDERR, "Empty database incorrectly passed preflight.\n$stdout$stderr" );
    exit( 1 );
}
echo "OK empty database fails the WordPress schema preflight\n";
