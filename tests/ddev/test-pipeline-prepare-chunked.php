<?php
/**
 * BackupPipeline::prepare_archive() must dump the database in time slices across
 * calls (checkpoint in/out) and must never load database.sql into memory.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\BackupPipeline;
use SwishMigrateAndBackup\Backup\FileQueue;
use SwishMigrateAndBackup\Backup\DatabaseBackup;
use SwishMigrateAndBackup\Logger\Logger;

$dir = sys_get_temp_dir() . '/swish-test-prepare';
swish_test_rrmdir( $dir ); mkdir( $dir, 0777, true );
$job = 'test_prepare_' . time();
FileQueue::create_table(); FileQueue::clear_job( $job );
$archive = "$dir/out.swish";
$options = array( 'type' => 'full', 'backup_database' => true );

$checkpoint = array(); $calls = 0; $completed = false; $result = array();
for ( $i = 0; $i < 50; $i++ ) {
	$p = new BackupPipeline( new Logger() );
	$p->configure( $options );
	// Tiny time slice (1s) forces at least one checkpoint round-trip on any real database.
	$result = $p->prepare_archive( $job, $archive, $options, $checkpoint, 1 );
	++$calls;
	if ( empty( $result['success'] ) ) { break; }
	$checkpoint = $result['checkpoint'] ?? array();
	if ( ! empty( $result['completed'] ) ) { $completed = true; break; }
}
swish_assert( ! empty( $result['success'] ), 'prepare_archive succeeded: ' . ( $result['error'] ?? '' ) );
swish_assert( $completed, "prepare_archive completed (calls={$calls})" );
swish_assert( isset( $result['archive_offset'] ) && $result['archive_offset'] === filesize( $archive ), 'archive_offset returned equals archive size' );

$walk = swish_test_walk_archive( $archive );
swish_assert_same( 'manifest.json', $walk['entries'][0]['path'] ?? '', 'manifest.json is the first entry' );
swish_assert_same( 1, count( $walk['entries'] ), 'archive contains only the manifest after prepare (database.sql is streamed later)' );

// database.sql must be queued as a regular file so process_files() streams it with resume support.
$rows = FileQueue::get_pending_batch( $job, 10 );
swish_assert_same( 1, count( $rows ), 'exactly one queued row after prepare' );
swish_assert_same( 'database.sql', $rows[0]['relative_path'] ?? '', 'queued row is database.sql' );
$sql = $rows[0]['file_path'] ?? '';
swish_assert( $sql && file_exists( $sql ) && (int) $rows[0]['file_size'] === filesize( $sql ), 'queued database.sql exists with correct size' );
$tables = ( new DatabaseBackup( new Logger() ) )->get_tables();
$content = $sql ? file_get_contents( $sql ) : '';
swish_assert_same( count( $tables ), preg_match_all( '/^CREATE TABLE/m', $content ), 'dump contains every table' );
swish_assert( str_contains( substr( $content, -80 ), 'COMMIT' ), 'dump ends with COMMIT' );

FileQueue::clear_job( $job ); swish_test_rrmdir( $dir );
$temp = dirname( $archive ) . '/temp-' . $job; swish_test_rrmdir( $temp );
swish_done( 'pipeline-prepare-chunked' );
