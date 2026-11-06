<?php
/**
 * End-to-end: full backup of this whole site through the REST pipeline, driven
 * exactly like the Backups page (start, then continue until done). Slow; run on
 * demand:  ddev wp eval-file wp-content/plugins/swish-migrate-and-backup/tests/ddev/e2e-full-site-backup.php
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\FileQueue;

wp_set_current_user( 1 );
global $wpdb;

function e2e_rest( string $method, string $path, array $params = array() ): array {
	$req = new WP_REST_Request( $method, '/swish-backup/v1' . $path );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	if ( is_wp_error( $data ) ) { return array( '__error' => $data->get_error_message() ); }
	return is_array( $data ) ? $data : array();
}

swish_test_big_file( WP_CONTENT_DIR . '/uploads/swish-e2e-large.bin', 1536 );
$t0    = microtime( true );
$start = e2e_rest( 'POST', '/pipeline/start', array( 'type' => 'full' ) );
$job   = $start['job_id'] ?? '';
swish_assert( '' !== $job, 'job started: ' . ( $start['__error'] ?? $job ) );

$n = 0; $last = $start; $phases = array(); $max_req = 0.0; $busy = 0;
while ( $n < 3000 ) {
	++$n;
	$t = microtime( true );
	$last = e2e_rest( 'POST', '/pipeline/continue/' . $job );
	$dur = microtime( true ) - $t; $max_req = max( $max_req, $dur );
	if ( isset( $last['__error'] ) ) { echo "  continue error: {$last['__error']}\n"; break; }
	if ( ! empty( $last['busy'] ) ) { ++$busy; }
	$ph = $last['phase'] ?? '?';
	$phases[ $ph ] = ( $phases[ $ph ] ?? 0 ) + 1;
	if ( 1 === $n || 0 === $n % 20 || in_array( $ph, array( 'preparing', 'finalizing', 'complete', 'failed' ), true ) ) {
		printf( "  #%d %.1fs phase=%s progress=%s %s\n", $n, $dur, $ph, $last['progress'] ?? '?', $last['message'] ?? '' );
	}
	if ( ! empty( $last['completed'] ) || 'failed' === $ph ) { break; }
}
printf( "  total requests=%d busy=%d max request=%.1fs elapsed=%.0fs phases=%s\n", $n, $busy, $max_req, microtime( true ) - $t0, wp_json_encode( $phases ) );
swish_assert( ! empty( $last['completed'] ), 'full backup completed: ' . ( $last['error'] ?? '' ) );
swish_assert( $max_req < 60, sprintf( 'no request exceeded 60s (max %.1fs)', $max_req ) );

$path = $last['file_path'] ?? '';
printf( "  archive=%s size=%s\n", $path, $path ? size_format( filesize( $path ) ) : '-' );
$walk = swish_test_walk_archive( $path );
swish_assert( $walk['valid'], 'archive structurally valid: ' . $walk['error'] );
$paths = array_column( $walk['entries'], 'path' );
swish_assert_same( 'manifest.json', $paths[0] ?? '', 'manifest.json first' );
swish_assert( in_array( 'database.sql', $paths, true ), 'database.sql present' );
// A large upload exercises the mid-file resume at the production time budget.
$big_rel = 'wp-content/uploads/swish-e2e-large.bin';
$idx = array_search( $big_rel, $paths, true );
swish_assert( false !== $idx, 'the 1.5GB upload is in the archive' );
if ( false !== $idx ) {
	swish_assert_same( filesize( ABSPATH . $big_rel ), $walk['entries'][ $idx ]['size'], 'its recorded size matches the file on disk' );
	$e = $walk['entries'][ $idx ]; $h = fopen( $path, 'rb' ); fseek( $h, $e['offset'] ); $ctx = hash_init( 'md5' ); $rem = $e['size'];
	while ( $rem > 0 ) { $c = fread( $h, min( 4194304, $rem ) ); if ( '' === $c || false === $c ) { break; } hash_update( $ctx, $c ); $rem -= strlen( $c ); }
	fclose( $h );
	swish_assert_same( md5_file( ABSPATH . $big_rel ), hash_final( $ctx ), 'its content is byte-identical' );
}
swish_assert( ! in_array( 'wp-content/plugins/swish-migrate-and-backup/swish-migrate-and-backup.php', $paths, true ), 'plugin excluded itself' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, file_size FROM {$wpdb->prefix}swish_backup_jobs WHERE job_id = %s", $job ), ARRAY_A );
swish_assert( $row && 'completed' === $row['status'] && (int) $row['file_size'] === filesize( $path ), 'registered in jobs table' );
swish_assert_same( 0, FileQueue::get_total_count( $job ), 'queue cleared' );
$dup = count( $paths ) - count( array_unique( $paths ) );
swish_assert_same( 0, $dup, 'no duplicate entries' );
printf( "  entries=%d unique=%d\n", count( $paths ), count( array_unique( $paths ) ) );
// Keep the archive for the import e2e; print its path for the next step.
echo "  ARCHIVE_PATH={$path}\n";
swish_done( 'e2e-full-site-backup' );
