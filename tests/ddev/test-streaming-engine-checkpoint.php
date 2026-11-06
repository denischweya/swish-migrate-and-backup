<?php
/**
 * The scheduled/async engine (BackupManager + StreamingTarBackup) on a site with
 * more than 50,000 files:
 *  - must back up every file (the legacy in-memory file list silently stopped at 50,000);
 *  - must keep its checkpoint small (the file list belongs on disk, not in a JSON
 *    blob replaced in the database every 10 seconds, which exceeds max_allowed_packet
 *    and blows memory on large sites).
 * Uses a generated directory of 60,000 small files via the custom_directories option.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Core\Container;
use SwishMigrateAndBackup\Backup\BackupManager;

global $wpdb;
wp_set_current_user( 1 );

$file_count = 60000;
$dir = sys_get_temp_dir() . '/swish-test-60k';
if ( ! is_dir( $dir ) || count( glob( "$dir/d9/*" ) ) < $file_count / 10 ) {
	swish_test_rrmdir( $dir );
	for ( $d = 0; $d < 10; $d++ ) {
		mkdir( "$dir/d$d", 0777, true );
		for ( $i = 0; $i < $file_count / 10; $i++ ) {
			file_put_contents( "$dir/d$d/f{$d}_{$i}.txt", "file $d $i\n" );
		}
	}
}
echo "  test directory ready: $file_count files\n";
// A 1.5 GB file guarantees the archive needs more than one 10-second slice on any machine.
$bigdir = sys_get_temp_dir() . '/swish-test-60k-big';
if ( ! is_dir( $bigdir ) ) { mkdir( $bigdir, 0777, true ); }
swish_test_big_file( "$bigdir/big.bin", 1536 );

$manager = Container::get_instance()->get( BackupManager::class );
$options = array(
	'type'                 => 'full',
	'backup_database'      => true,
	'backup_plugins'       => false,
	'backup_themes'        => false,
	'backup_uploads'       => false,
	'backup_core_files'    => false,
	'custom_directories'   => array( $dir, $bigdir ),
	'storage_destinations' => array( 'local' ),
);
$start  = $manager->start_async_backup( $options );
$job_id = $start['job_id'];
swish_assert( ! empty( $job_id ), 'async job created' );

// Drive it the way cron would, measuring the checkpoint row after every step.
$t0 = microtime( true );
$manager->process_async_backup( $job_id );
$max_state_bytes = 0; $steps = 1; $status = $manager->get_job_status( $job_id );
while ( $status && in_array( $status['status'], array( 'pending', 'processing' ), true ) && $steps < 400 ) {
	$row_len = (int) $wpdb->get_var( $wpdb->prepare( "SELECT LENGTH(state_value) FROM {$wpdb->prefix}swish_backup_state WHERE job_id = %s AND state_key = 'progress'", $job_id ) );
	$max_state_bytes = max( $max_state_bytes, $row_len );
	$manager->continue_backup( $job_id );
	++$steps;
	$status = $manager->get_job_status( $job_id );
}
printf( "  steps=%d status=%s progress=%s max checkpoint row=%d bytes elapsed=%.0fs message=%s\n", $steps, $status['status'] ?? '?', $status['progress'] ?? '?', $max_state_bytes, microtime( true ) - $t0, $status['message'] ?? '' );
swish_assert( 'completed' === ( $status['status'] ?? '' ), 'async backup completed' );
swish_assert( $steps > 1, 'backup needed more than one request (continuation path exercised)' );
swish_assert( $max_state_bytes > 0 && $max_state_bytes < 65536, "checkpoint row stays small ({$max_state_bytes} bytes) regardless of file count" );

$path = $status['path'] ?? '';
$walk = $path ? swish_test_walk_archive( $path ) : array( 'valid' => false, 'error' => 'no path', 'entries' => array() );
swish_assert( $walk['valid'], 'archive valid: ' . $walk['error'] );
$paths = array_column( $walk['entries'], 'path' );
swish_assert_same( 'manifest.json', $paths[0] ?? '', 'manifest.json is the first entry' );
swish_assert( in_array( 'database.sql', $paths, true ), 'archive contains database.sql' );
$data_entries = count( array_filter( $paths, fn( $p ) => str_ends_with( $p, '.txt' ) ) );
swish_assert_same( $file_count, $data_entries, "all {$file_count} files archived (not truncated at 50,000)" );
swish_assert( in_array( 'big.bin', $paths, true ), 'the 1.5 GB file is in the archive' );
swish_assert_same( count( $paths ), count( array_unique( $paths ) ), 'no duplicate entries' );
swish_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}swish_backup_state WHERE job_id = %s", $job_id ) ), 'state rows cleaned up' );
swish_assert( empty( glob( WP_CONTENT_DIR . '/swish-backups/state/*' . $job_id . '*' ) ), 'state files cleaned up' );

if ( $path ) { @unlink( $path ); }
$wpdb->delete( $wpdb->prefix . 'swish_backup_jobs', array( 'job_id' => $job_id ) );
swish_done( 'streaming-engine-checkpoint' );
