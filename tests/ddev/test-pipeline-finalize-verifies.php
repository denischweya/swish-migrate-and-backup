<?php
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\BackupPipeline;
use SwishMigrateAndBackup\Backup\FileQueue;
use SwishMigrateAndBackup\Backup\SwishArchiver;
use SwishMigrateAndBackup\Logger\Logger;

$dir = sys_get_temp_dir() . '/swish-test-finalize';
swish_test_rrmdir( $dir ); mkdir( $dir, 0777, true );
file_put_contents( "$dir/a.txt", str_repeat( 'A', 5000 ) );
$job = 'test_finalize_' . time();
FileQueue::create_table(); FileQueue::clear_job( $job );

// Good archive.
$good = "$dir/good.swish";
$a = new SwishArchiver( $good ); $a->open_for_write(); $b = 0; $a->add_file( "$dir/a.txt", 'a.txt', 0, $b, 0 ); $a->close( false );
$r = ( new BackupPipeline( new Logger() ) )->finalize( $job, $good );
swish_assert( ! empty( $r['success'] ), 'finalize accepts a well-formed archive' );
swish_assert( ( new SwishArchiver( $good ) )->is_valid(), 'finalized archive has EOF marker' );

// Corrupt archive: header claims 5000 bytes but only 1000 follow (the pre-fix partial-write pattern).
$bad = "$dir/bad.swish";
$a = new SwishArchiver( $bad ); $a->open_for_write(); $b = 0; $a->add_file( "$dir/a.txt", 'a.txt', 0, $b, 0 ); $a->close( false );
$h = fopen( $bad, 'r+b' ); ftruncate( $h, 4377 + 1000 ); fclose( $h );
$a = new SwishArchiver( $bad ); $a->open_for_write(); $a->add_file( "$dir/a.txt", 'a.txt', 0, $b, 0 ); $a->close( false );
$r = ( new BackupPipeline( new Logger() ) )->finalize( $job, $bad );
swish_assert( empty( $r['success'] ), 'finalize rejects a corrupt archive' );
swish_assert( ! empty( $r['error'] ), 'finalize reports an error message: ' . ( $r['error'] ?? '' ) );

FileQueue::clear_job( $job ); swish_test_rrmdir( $dir );
swish_done( 'pipeline-finalize-verifies' );
