<?php
/**
 * BackupPipeline::process_files() with a time budget too small to write a large
 * file in one request must resume the file on the next request and eventually
 * produce a valid archive with byte-identical content.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\BackupPipeline;
use SwishMigrateAndBackup\Backup\FileQueue;
use SwishMigrateAndBackup\Backup\SwishArchiver;
use SwishMigrateAndBackup\Logger\Logger;

$dir = sys_get_temp_dir() . '/swish-test-resume';
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
file_put_contents( "$dir/a.txt", str_repeat( 'A', 1000 ) );
$big = swish_test_big_file( "$dir/big.bin", 1536 );
file_put_contents( "$dir/c.txt", str_repeat( 'C', 3000 ) );

$job = 'test_resume_' . time();
FileQueue::create_table();
FileQueue::clear_job( $job );
FileQueue::add_files_batch( $job, array(
	array( 'path' => "$dir/a.txt", 'relative' => 't/a.txt', 'size' => 1000 ),
	array( 'path' => $big, 'relative' => 't/big.bin', 'size' => filesize( $big ) ),
	array( 'path' => "$dir/c.txt", 'relative' => 't/c.txt', 'size' => 3000 ),
) );
$archive = "$dir/out.swish";
@unlink( $archive );

$logger  = new Logger();
$cursor  = array();
$done    = false;
$requests = 0;
$last_written = 0;
$progressed = true;
for ( $req = 1; $req <= 120; $req++ ) {
	$p = new BackupPipeline( $logger );
	$p->configure( array( 'time_budget' => 1, 'pipeline_batch_size' => 50 ) );
	$r = $p->process_files( $job, $archive, $cursor );
	$cursor = $r['cursor'] ?? array();
	$requests = $req;
	$size = filesize( $archive );
	printf( "  request %d: completed=%s pending=%d archive=%s partial=%s\n", $req, var_export( $r['completed'], true ), $r['stats']['pending'], size_format( $size ), isset( $cursor['partial'] ) ? $cursor['partial']['bytes_written'] : '-' );
	if ( $r['completed'] ) { $done = true; break; }
	if ( $size > 2 * filesize( $big ) ) { echo "  archive is growing past 2x the payload - restarting instead of resuming\n"; break; }
}
swish_assert( $done, "job completed within 40 one-second requests (took {$requests})" );
swish_assert( $requests > 1, 'large file genuinely needed more than one request' );

$f = new SwishArchiver( $archive ); $f->open_for_write(); $f->close( true );
$walk = swish_test_walk_archive( $archive );
swish_assert( $walk['valid'], 'archive structure valid: ' . $walk['error'] );
swish_assert_same( array( 't/a.txt', 't/big.bin', 't/c.txt' ), array_column( $walk['entries'], 'path' ), 'exactly the three queued files, once each' );
$expected = 4377 * 4 + 1000 + filesize( $big ) + 3000;
swish_assert_same( $expected, filesize( $archive ), 'archive size equals headers + payload + EOF' );

// Content of the big file must be byte-identical.
if ( $walk['valid'] && count( $walk['entries'] ) === 3 ) {
	$e = $walk['entries'][1];
	$h = fopen( $archive, 'rb' ); fseek( $h, $e['offset'] );
	$ctx = hash_init( 'md5' ); $rem = $e['size'];
	while ( $rem > 0 ) { $c = fread( $h, min( 1048576, $rem ) ); hash_update( $ctx, $c ); $rem -= strlen( $c ); }
	fclose( $h );
	swish_assert_same( md5_file( $big ), hash_final( $ctx ), 'big file content is byte-identical after resume' );
}
FileQueue::clear_job( $job );
@unlink( $archive );
swish_done( 'pipeline-resume-partial' );
