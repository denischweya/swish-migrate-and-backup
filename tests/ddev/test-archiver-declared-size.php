<?php
/**
 * SwishArchiver::add_file() must write exactly the size recorded in the header,
 * even when the source file is longer or shorter than that size (files change
 * between indexing and archiving: logs, caches, uploads in progress).
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\SwishArchiver;

$dir = sys_get_temp_dir() . '/swish-test-declared';
swish_test_rrmdir( $dir );
mkdir( $dir, 0777, true );
file_put_contents( "$dir/grew.txt", str_repeat( 'G', 3000 ) );   // indexed at 2000, now 3000
file_put_contents( "$dir/shrank.txt", str_repeat( 'S', 1000 ) ); // indexed at 2500, now 1000
file_put_contents( "$dir/same.txt", str_repeat( 'X', 1500 ) );

$archive = "$dir/out.swish";
$a = new SwishArchiver( $archive );
swish_assert( $a->open_for_write(), 'archive opened' );
$b = 0;
swish_assert( $a->add_file( "$dir/grew.txt", 'grew.txt', 0, $b, 0, 2000 ), 'grown file written' );
swish_assert_same( 2000, $b, 'grown file: bytes written capped at declared size' );
swish_assert( $a->add_file( "$dir/shrank.txt", 'shrank.txt', 0, $b, 0, 2500 ), 'shrunk file written' );
swish_assert_same( 2500, $b, 'shrunk file: bytes written padded to declared size' );
swish_assert( $a->add_file( "$dir/same.txt", 'same.txt', 0, $b, 0 ), 'unchanged file written' );
swish_assert_same( 1500, $b, 'unchanged file: bytes written equals actual size' );
$a->close( true );

$walk = swish_test_walk_archive( $archive );
swish_assert( $walk['valid'], 'archive structure valid: ' . $walk['error'] );
swish_assert_same( 3, count( $walk['entries'] ), 'three entries readable' );
swish_assert_same( array( 2000, 2500, 1500 ), array_column( $walk['entries'], 'size' ), 'header sizes match declared sizes' );

// verify_structure() is the production equivalent of the walk above.
swish_assert( method_exists( $a, 'verify_structure' ), 'SwishArchiver::verify_structure exists' );
if ( method_exists( $a, 'verify_structure' ) ) {
	$v = $a->verify_structure();
	swish_assert( ! empty( $v['valid'] ) && 3 === ( $v['entries'] ?? 0 ), 'verify_structure reports valid with 3 entries' );
	// Truncate the archive mid-entry and verify_structure must reject it.
	$h = fopen( $archive, 'r+b' ); ftruncate( $h, 4377 + 1000 ); fclose( $h );
	$v = $a->verify_structure();
	swish_assert( empty( $v['valid'] ), 'verify_structure rejects a truncated archive' );
}
swish_test_rrmdir( $dir );
swish_done( 'archiver-declared-size' );
