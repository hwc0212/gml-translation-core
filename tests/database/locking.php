<?php
/** Atomic cross-process locking and ownership regression. */

require __DIR__ . '/bootstrap.php';
gml_test_product_init();
class_exists( 'GML_Queue_Processor' );
class_exists( 'GML_Content_Crawler' );

$core_src = realpath( getenv( 'GML_TEST_CORE_SRC' ) ?: dirname( __DIR__, 2 ) . '/src' );
gml_db_assert( is_string( $core_src ) && $core_src !== '', 'Core source is available to the lock regression' );

function gml_lock_test_wait_file( $file, $label ) {
	$deadline = microtime( true ) + 10;
	while ( ! is_file( $file ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
	gml_db_assert( is_file( $file ), $label );
}

function gml_lock_test_spawn( $mode, $option, $worker, $sync_dir, $core_src ) {
	$command = implode( ' ', array_map( 'escapeshellarg', [
		PHP_BINARY,
		__DIR__ . '/lock-worker.php',
		$mode,
		$option,
		$worker,
		$sync_dir,
		$core_src,
	] ) );
	$process = proc_open( $command, [
		0 => [ 'file', '/dev/null', 'r' ],
		1 => [ 'file', $sync_dir . '/stdout-' . $worker, 'a' ],
		2 => [ 'file', $sync_dir . '/stderr-' . $worker, 'a' ],
	], $pipes );
	gml_db_assert( is_resource( $process ), 'worker ' . $worker . ' started' );
	return $process;
}

function gml_lock_test_close( $process, $label ) {
	$deadline = microtime( true ) + 10;
	do {
		$status = proc_get_status( $process );
		if ( ! $status['running'] ) {
			$exit = (int) $status['exitcode'];
			proc_close( $process );
			gml_db_assert( $exit === 0, $label );
			return;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	proc_terminate( $process );
	proc_close( $process );
	gml_db_assert( false, $label . ' completed before the deadline' );
}

function gml_lock_test_dir( $label ) {
	$path = sys_get_temp_dir() . '/gml-lock-' . sanitize_key( $label ) . '-' . wp_generate_uuid4();
	gml_db_assert( mkdir( $path, 0700 ), 'created isolated ' . $label . ' process barrier' );
	return $path;
}

function gml_lock_test_cleanup_dir( $path ) {
	foreach ( glob( $path . '/*' ) ?: [] as $file ) {
		unlink( $file );
	}
	rmdir( $path );
}

/*
 * Deterministically reproduce the current read/delete/add takeover race with
 * two real PHP processes. Both workers read the same stale row before either
 * mutates it; B then deletes A's newly acquired lock.
 */
$legacy_option = 'gml_lock_regression_legacy';
$legacy_dir    = gml_lock_test_dir( 'legacy' );
delete_option( $legacy_option );
add_option( $legacy_option, [ 'token' => 'expired-owner', 'expires' => time() - 30 ], '', false );
$legacy_a = gml_lock_test_spawn( 'legacy', $legacy_option, 'a', $legacy_dir, $core_src );
$legacy_b = gml_lock_test_spawn( 'legacy', $legacy_option, 'b', $legacy_dir, $core_src );
gml_lock_test_wait_file( $legacy_dir . '/read-a', 'legacy worker A read the stale lock' );
gml_lock_test_wait_file( $legacy_dir . '/read-b', 'legacy worker B read the same stale lock' );
touch( $legacy_dir . '/delete-a' );
gml_lock_test_wait_file( $legacy_dir . '/deleted-a', 'legacy worker A deleted the stale lock' );
touch( $legacy_dir . '/add-a' );
gml_lock_test_wait_file( $legacy_dir . '/result-a', 'legacy worker A recorded acquisition' );
touch( $legacy_dir . '/delete-b' );
gml_lock_test_wait_file( $legacy_dir . '/deleted-b', 'legacy worker B deleted worker A lock' );
touch( $legacy_dir . '/add-b' );
gml_lock_test_wait_file( $legacy_dir . '/result-b', 'legacy worker B recorded acquisition' );
gml_lock_test_close( $legacy_a, 'legacy worker A completed successfully' );
gml_lock_test_close( $legacy_b, 'legacy worker B completed successfully' );
gml_db_assert(
	trim( (string) file_get_contents( $legacy_dir . '/result-a' ) ) !== ''
		&& trim( (string) file_get_contents( $legacy_dir . '/result-b' ) ) !== '',
	'old stale-lock takeover is proven to grant ownership to both workers'
);
delete_option( $legacy_option );
gml_lock_test_cleanup_dir( $legacy_dir );

$atomic_file = $core_src . '/class-atomic-option-lock.php';
gml_db_assert( is_file( $atomic_file ), 'shared atomic option lock implementation exists' );
if ( ! class_exists( 'GML_Atomic_Option_Lock', false ) ) {
	require_once $atomic_file;
}

function gml_lock_test_competing_takeover( $option, $legacy_value, $core_src, $label ) {
	delete_option( $option );
	add_option( $option, $legacy_value, '', false );
	$dir = gml_lock_test_dir( $label );
	$a   = gml_lock_test_spawn( 'atomic', $option, 'a', $dir, $core_src );
	$b   = gml_lock_test_spawn( 'atomic', $option, 'b', $dir, $core_src );
	gml_lock_test_wait_file( $dir . '/ready-a', $label . ' worker A is ready' );
	gml_lock_test_wait_file( $dir . '/ready-b', $label . ' worker B is ready' );
	touch( $dir . '/go' );
	gml_lock_test_close( $a, $label . ' worker A completed' );
	gml_lock_test_close( $b, $label . ' worker B completed' );
	$tokens = array_values( array_filter( [
		trim( (string) file_get_contents( $dir . '/result-a' ) ),
		trim( (string) file_get_contents( $dir . '/result-b' ) ),
	] ) );
	gml_db_assert( count( $tokens ) === 1, $label . ' expired takeover grants exactly one owner' );
	gml_db_assert( GML_Atomic_Option_Lock::is_owner( $option, $tokens[0] ), $label . ' winner owns the persisted lock' );
	GML_Atomic_Option_Lock::release( $option, $tokens[0] );
	delete_option( $option );
	gml_lock_test_cleanup_dir( $dir );
}

$normal_option = 'gml_lock_regression_normal';
delete_option( $normal_option );
$first = GML_Atomic_Option_Lock::acquire( $normal_option, 30 );
gml_db_assert( $first !== '', 'normal first acquisition succeeds' );
gml_db_assert( GML_Atomic_Option_Lock::acquire( $normal_option, 30 ) === '', 'normal overlapping acquisition fails' );
gml_db_assert( ! GML_Atomic_Option_Lock::release( $normal_option, 'wrong-owner' ), 'wrong owner cannot release an active lock' );
gml_db_assert( GML_Atomic_Option_Lock::is_owner( $normal_option, $first ), 'wrong release leaves the current owner intact' );
gml_db_assert( GML_Atomic_Option_Lock::release( $normal_option, $first ), 'current owner releases its lock' );

$old_owner = GML_Atomic_Option_Lock::acquire( $normal_option, 30 );
gml_db_assert( $old_owner !== '', 'old-owner takeover fixture acquires its first lease' );
update_option( $normal_option, [ 'version' => 1, 'token' => $old_owner, 'expires' => time() - 30 ], false );
$new_owner = GML_Atomic_Option_Lock::acquire( $normal_option, 30 );
gml_db_assert( $new_owner !== '' && ! hash_equals( $old_owner, $new_owner ), 'expired lease is replaced by a different owner token' );
wp_cache_set( $normal_option, [ 'version' => 1, 'token' => $old_owner, 'expires' => time() - 30 ], 'options' );
gml_db_assert( ! GML_Atomic_Option_Lock::release( $normal_option, $old_owner ), 'expired old owner cannot release the newer lease through stale cache state' );
gml_db_assert( GML_Atomic_Option_Lock::is_owner( $normal_option, $new_owner ), 'new owner survives the old shutdown release' );
gml_db_assert( GML_Atomic_Option_Lock::release( $normal_option, $new_owner ), 'new owner can release after stale-cache fencing' );

gml_lock_test_competing_takeover(
	'gml_lock_regression_queue_legacy',
	[ 'token' => 'legacy-queue', 'expires' => time() - 30 ],
	$core_src,
	'queue legacy-array'
);
gml_lock_test_competing_takeover(
	'gml_lock_regression_crawler_legacy',
	time() - 30,
	$core_src,
	'crawler legacy-integer'
);

if ( getenv( 'GML_LOCK_PRIMITIVE_ONLY' ) === '1' ) {
	gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'primitive lock regression makes no external API requests' );
	echo "OK atomic cross-process option lock primitive\n";
	return;
}

delete_option( GML_Queue_Processor::LOCK_OPTION );
delete_option( GML_Translation_Content_Crawler::LOCK_OPTION );

class GML_Database_Lock_Queue extends GML_Queue_Processor {
	public static function acquire_for_test() {
		return parent::acquire_process_lock();
	}
	public static function release_for_test( $token ) {
		return parent::release_process_lock( $token );
	}
	public static function recover_for_test( $token, $table ) {
		global $wpdb;
		return parent::recover_processing_rows( $token, $wpdb, $table );
	}
}

class GML_Database_Lock_Crawler extends GML_Content_Crawler {
	public static function acquire_for_test() {
		return parent::acquire_lock();
	}
	public static function release_for_test( $token ) {
		return parent::release_lock( $token );
	}
}

$queue_token = GML_Database_Lock_Queue::acquire_for_test();
gml_db_assert( is_string( $queue_token ) && $queue_token !== '', 'queue uses the owner-token locking contract' );
gml_db_assert( GML_Database_Lock_Queue::acquire_for_test() === '', 'overlapping queue worker is rejected' );
GML_Database_Lock_Queue::release_for_test( 'wrong-owner' );
gml_db_assert( GML_Atomic_Option_Lock::is_owner( GML_Queue_Processor::LOCK_OPTION, $queue_token ), 'old queue owner cannot release the current owner lock' );

$queue_table = $wpdb->prefix . 'gml_queue';
$source_text = 'Lock recovery fixture ' . wp_generate_uuid4();
$wpdb->insert( $queue_table, [
	'source_hash' => md5( $source_text ),
	'source_text' => $source_text,
	'source_lang' => 'en',
	'target_lang' => 'es',
	'context_type' => 'text',
	'status' => 'processing',
	'attempts' => 0,
	'created_at' => current_time( 'mysql' ),
] );
$queue_id = (int) $wpdb->insert_id;
gml_db_assert( ! GML_Database_Lock_Queue::recover_for_test( 'wrong-owner', $queue_table ), 'non-owner cannot recover processing queue rows' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue_table WHERE id = %d", $queue_id ) ) === 'processing', 'non-owner recovery leaves active queue work unchanged' );
gml_db_assert( GML_Database_Lock_Queue::recover_for_test( $queue_token, $queue_table ), 'current queue owner can recover abandoned processing rows' );
gml_db_assert( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue_table WHERE id = %d", $queue_id ) ) === 'pending', 'owner recovery resets abandoned processing work' );
$wpdb->delete( $queue_table, [ 'id' => $queue_id ] );
GML_Database_Lock_Queue::release_for_test( $queue_token );

$crawler_token = GML_Database_Lock_Crawler::acquire_for_test();
gml_db_assert( is_string( $crawler_token ) && $crawler_token !== '', 'crawler uses the owner-token locking contract' );
gml_db_assert( GML_Database_Lock_Crawler::acquire_for_test() === '', 'overlapping crawler is rejected' );
GML_Database_Lock_Crawler::release_for_test( 'wrong-owner' );
gml_db_assert( GML_Atomic_Option_Lock::is_owner( GML_Translation_Content_Crawler::LOCK_OPTION, $crawler_token ), 'old crawler cannot delete the current owner lock' );
GML_Database_Lock_Crawler::release_for_test( $crawler_token );

foreach ( [ $normal_option, GML_Queue_Processor::LOCK_OPTION, GML_Translation_Content_Crawler::LOCK_OPTION ] as $option ) {
	delete_option( $option );
}
gml_db_assert( $GLOBALS['gml_test_http_calls'] === 0, 'lock regression makes no external API requests' );
echo "OK atomic cross-process queue and crawler locking\n";
