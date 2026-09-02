<?php
/** Child process used by the real database lock regression. */

if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) {
	exit( 2 );
}

require __DIR__ . '/bootstrap.php';

$mode     = isset( $argv[1] ) ? (string) $argv[1] : '';
$option   = isset( $argv[2] ) ? (string) $argv[2] : '';
$worker   = isset( $argv[3] ) ? (string) $argv[3] : '';
$sync_dir = isset( $argv[4] ) ? (string) $argv[4] : '';
$core_src = isset( $argv[5] ) ? (string) $argv[5] : '';

if ( $option === '' || $worker === '' || ! is_dir( $sync_dir ) ) {
	exit( 2 );
}

function gml_lock_worker_wait( $file ) {
	$deadline = microtime( true ) + 10;
	while ( ! is_file( $file ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
	if ( ! is_file( $file ) ) {
		exit( 3 );
	}
}

if ( $mode === 'legacy' ) {
	$existing = get_option( $option, [] );
	$expired  = is_array( $existing )
		? (int) ( $existing['expires'] ?? 0 ) < time()
		: (int) $existing < time();
	if ( ! $expired ) {
		exit( 4 );
	}

	touch( $sync_dir . '/read-' . $worker );
	gml_lock_worker_wait( $sync_dir . '/delete-' . $worker );
	delete_option( $option );
	touch( $sync_dir . '/deleted-' . $worker );
	gml_lock_worker_wait( $sync_dir . '/add-' . $worker );

	$token = 'legacy-' . $worker . '-' . wp_generate_uuid4();
	$ok    = add_option(
		$option,
		[ 'token' => $token, 'expires' => time() + 60 ],
		'',
		false
	);
	file_put_contents( $sync_dir . '/result-' . $worker, $ok ? $token : '' );
	exit( $ok ? 0 : 5 );
}

if ( $mode === 'atomic' ) {
	$file = rtrim( $core_src, '/\\' ) . '/class-atomic-option-lock.php';
	if ( ! is_file( $file ) ) {
		exit( 6 );
	}
	if ( ! class_exists( 'GML_Atomic_Option_Lock', false ) ) {
		require_once $file;
	}
	touch( $sync_dir . '/ready-' . $worker );
	gml_lock_worker_wait( $sync_dir . '/go' );
	$token = GML_Atomic_Option_Lock::acquire( $option, 30 );
	file_put_contents( $sync_dir . '/result-' . $worker, $token );
	exit( 0 );
}

exit( 2 );
