<?php
/**
 * Crash windows in BackupPipeline::process_files():
 *  1. a request dies after marking files completed but before the job state
 *     (cursor) is saved: the next request must keep those complete entries;
 *  2. a partial entry recorded with 0 content bytes must be restarted, never
 *     resumed (resuming at offset 0 would write a second header);
 *  3. finalize() must add the EOF marker even when the last entry's content
 *     ends in 4377 null bytes, and must not delete such an archive as corrupt;
 *  4. a database dump slice that died after writing rows must not leave those
 *     rows in front of the resumed slice.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\BackupPipeline;
use SwishMigrateAndBackup\Backup\FileQueue;
use SwishMigrateAndBackup\Backup\SwishArchiver;
use SwishMigrateAndBackup\Backup\DatabaseBackup;
use SwishMigrateAndBackup\Logger\Logger;

$dir = sys_get_temp_dir() . '/swish-test-crash';
swish_test_rrmdir( $dir ); mkdir( $dir, 0777, true );
for ( $i = 1; $i <= 5; $i++ ) { file_put_contents( "$dir/f$i.txt", str_repeat( chr( 64 + $i ), 1000 * $i ) ); }
FileQueue::create_table();

// --- 1. Stale cursor after a kill: completed entries must survive. ---
$job = 'test_crash1_' . time(); FileQueue::clear_job( $job );
$rows = array(); for ( $i = 1; $i <= 5; $i++ ) { $rows[] = array( 'path' => "$dir/f$i.txt", 'relative' => "c/f$i.txt", 'size' => 1000 * $i ); }
FileQueue::add_files_batch( $job, $rows );
$archive = "$dir/crash1.swish"; @unlink( $archive );
$p = new BackupPipeline( new Logger() ); $p->configure( array( 'time_budget' => 25, 'pipeline_batch_size' => 2 ) );
$r1 = $p->process_files( $job, $archive, array( 'archive_offset' => 0, 'partial' => null ) ); // writes f1, f2; cursor -> end
$size_after_1 = filesize( $archive );
$p = new BackupPipeline( new Logger() ); $p->configure( array( 'time_budget' => 25, 'pipeline_batch_size' => 2 ) );
$p->process_files( $job, $archive, $r1['cursor'] ); // writes f3, f4 and marks them completed...
// ...but pretend this request died before its cursor was saved: replay with the OLD cursor.
$p = new BackupPipeline( new Logger() ); $p->configure( array( 'time_budget' => 25, 'pipeline_batch_size' => 2 ) );
$r3 = $p->process_files( $job, $archive, $r1['cursor'] ); // writes f5
swish_assert( ! empty( $r3['completed'] ), 'job completes after the replay' );
$f = new SwishArchiver( $archive ); $f->open_for_write(); $f->close( true );
$walk = swish_test_walk_archive( $archive );
swish_assert( $walk['valid'], 'archive valid after stale-cursor replay: ' . $walk['error'] );
swish_assert_same( array( 'c/f1.txt', 'c/f2.txt', 'c/f3.txt', 'c/f4.txt', 'c/f5.txt' ), array_column( $walk['entries'], 'path' ), 'entries written before the crash are kept, none lost, none duplicated' );
FileQueue::clear_job( $job );

// --- 1b. Same, with an incomplete trailing entry from the killed request. ---
$job = 'test_crash1b_' . time(); FileQueue::clear_job( $job );
FileQueue::add_files_batch( $job, $rows );
$archive = "$dir/crash1b.swish"; @unlink( $archive );
$p = new BackupPipeline( new Logger() ); $p->configure( array( 'time_budget' => 25, 'pipeline_batch_size' => 3 ) );
$r1 = $p->process_files( $job, $archive, array( 'archive_offset' => 0, 'partial' => null ) ); // f1..f3 completed
// Simulate a killed request that appended a header + half of f4 without recording anything.
$h = fopen( $archive, 'ab' ); fwrite( $h, pack( 'a255', 'f4.txt' ) . pack( 'a14', '4000' ) . pack( 'a12', '1' ) . pack( 'a4096', 'c' ) . str_repeat( 'D', 1500 ) ); fclose( $h );
$p = new BackupPipeline( new Logger() ); $p->configure( array( 'time_budget' => 25, 'pipeline_batch_size' => 3 ) );
$r2 = $p->process_files( $job, $archive, $r1['cursor'] );
swish_assert( ! empty( $r2['completed'] ), 'job completes after an incomplete trailing entry' );
$f = new SwishArchiver( $archive ); $f->open_for_write(); $f->close( true );
$walk = swish_test_walk_archive( $archive );
swish_assert( $walk['valid'], 'archive valid: ' . $walk['error'] );
swish_assert_same( array( 'c/f1.txt', 'c/f2.txt', 'c/f3.txt', 'c/f4.txt', 'c/f5.txt' ), array_column( $walk['entries'], 'path' ), 'incomplete tail was cut and f4 written once' );
FileQueue::clear_job( $job );

// --- 2. Partial recorded with 0 content bytes must restart, not resume. ---
$job = 'test_crash2_' . time(); FileQueue::clear_job( $job );
FileQueue::add_files_batch( $job, array( $rows[0], $rows[1] ) );
$archive = "$dir/crash2.swish"; @unlink( $archive );
$a = new SwishArchiver( $archive ); $a->open_for_write(); $b = 0; $a->add_file( "$dir/f1.txt", 'c/f1.txt', 0, $b, 0 ); $a->close( false );
$entry2 = filesize( $archive );
// Header of f2 written, no content, then the request ended (bytes_written 0 recorded).
$h = fopen( $archive, 'ab' ); fwrite( $h, pack( 'a255', 'f2.txt' ) . pack( 'a14', '2000' ) . pack( 'a12', '1' ) . pack( 'a4096', 'c' ) ); fclose( $h );
$ids = FileQueue::get_pending_batch( $job, 10 ); FileQueue::mark_completed( (int) $ids[0]['id'], 1000 );
$cursor = array( 'archive_offset' => $entry2, 'partial' => array( 'file_id' => (int) $ids[1]['id'], 'entry_offset' => $entry2, 'bytes_written' => 0 ) );
$p = new BackupPipeline( new Logger() ); $p->configure( array( 'time_budget' => 25, 'pipeline_batch_size' => 10 ) );
$r = $p->process_files( $job, $archive, $cursor );
$f = new SwishArchiver( $archive ); $f->open_for_write(); $f->close( true );
$walk = swish_test_walk_archive( $archive );
swish_assert( ! empty( $r['completed'] ) && $walk['valid'], 'zero-byte partial restarted cleanly: ' . $walk['error'] );
swish_assert_same( array( 1000, 2000 ), array_column( $walk['entries'], 'size' ), 'no doubled header' );
FileQueue::clear_job( $job );

// --- 3. Last entry ends with 4377 null bytes: finalize must still add the EOF marker. ---
$job = 'test_crash3_' . time();
file_put_contents( "$dir/nulls.bin", 'x' . str_repeat( "\0", 4377 ) );
$archive = "$dir/crash3.swish"; @unlink( $archive );
$a = new SwishArchiver( $archive ); $a->open_for_write(); $b = 0; $a->add_file( "$dir/nulls.bin", 'c/nulls.bin', 0, $b, 0 ); $a->close( false );
$r = ( new BackupPipeline( new Logger() ) )->finalize( $job, $archive );
swish_assert( ! empty( $r['success'] ) && ! empty( $r['completed'] ), 'finalize succeeds on an archive whose last entry ends in null bytes: ' . ( $r['error'] ?? '' ) );
swish_assert( file_exists( $archive ) && filesize( $archive ) === 4377 + 4378 + 4377, 'EOF marker appended exactly once' );
// Running finalize again (retry after a crash) must not append a second marker.
$r = ( new BackupPipeline( new Logger() ) )->finalize( $job, $archive );
swish_assert( ! empty( $r['success'] ) && filesize( $archive ) === 4377 + 4378 + 4377, 'finalize is idempotent' );

// --- 4. Database dump slice killed after writing rows: resume must truncate first. ---
global $wpdb;
$bulk = $wpdb->prefix . 'swish_test_bulk';
$wpdb->query( "DROP TABLE IF EXISTS `{$bulk}`" );
$wpdb->query( "CREATE TABLE `{$bulk}` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, payload VARCHAR(120) NOT NULL)" );
// MariaDB sequence engine: 400k rows in one statement (~45 MB dumped), enough for several 3-second slices.
$wpdb->query( "INSERT INTO `{$bulk}` (id, payload) SELECT seq, REPEAT('x', 100) FROM seq_1_to_400000" );
$bulk_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$bulk}`" );
swish_assert( $bulk_rows === 400000, "bulk table created ({$bulk_rows} rows)" );

$db = new DatabaseBackup( new Logger() ); $db->set_rows_per_batch( 500 );
$expected_tables = count( $db->get_tables() );
$out = "$dir/dump.sql"; @unlink( $out );
$res = $db->backup_chunked( $out, array(), null, 3 );
$slices = 1;
while ( empty( $res['completed'] ) && $slices < 100 ) {
	// Simulate: the next slice wrote some rows, then died before saving its checkpoint.
	file_put_contents( $out, "INSERT INTO `ghost` VALUES ('from a killed slice');\n", FILE_APPEND );
	$res = $db->backup_chunked( $out, $res['checkpoint'], null, 3 ); ++$slices;
}
$wpdb->query( "DROP TABLE IF EXISTS `{$bulk}`" );
$content = file_get_contents( $out );
swish_assert( ! empty( $res['completed'] ), "dump completed in {$slices} slices" );
swish_assert( $slices > 1, 'dump genuinely needed several slices' );
swish_assert_same( 0, substr_count( $content, 'ghost' ), 'rows written by a killed slice are not kept' );
swish_assert_same( 0, substr_count( $content, "\0" ), 'no null gaps from a mis-tracked offset' );
swish_assert_same( $expected_tables, preg_match_all( '/^CREATE TABLE/m', $content ), 'every table present exactly once' );
swish_assert_same( 400000, substr_count( $content, "'" . str_repeat( 'x', 100 ) . "'" ), 'all bulk rows present exactly once' );

swish_test_rrmdir( $dir );
swish_done( 'pipeline-crash-recovery' );
