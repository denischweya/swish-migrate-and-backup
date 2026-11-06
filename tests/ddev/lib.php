<?php
/**
 * Minimal assertion helpers for the DDEV integration tests.
 *
 * Run a test with:  ddev wp eval-file wp-content/plugins/swish-migrate-and-backup/tests/ddev/<test>.php
 * Run them all with: bash wp-content/plugins/swish-migrate-and-backup/tests/ddev/run.sh
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['swish_test_failures'] = 0;
$GLOBALS['swish_test_passes']   = 0;

function swish_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		++$GLOBALS['swish_test_passes'];
		echo "  ok   - {$message}\n";
	} else {
		++$GLOBALS['swish_test_failures'];
		echo "  FAIL - {$message}\n";
	}
}

function swish_assert_same( $expected, $actual, string $message ): void {
	$ok = ( $expected === $actual );
	swish_assert( $ok, $message . ( $ok ? '' : sprintf( ' (expected %s, got %s)', var_export( $expected, true ), var_export( $actual, true ) ) ) );
}

function swish_done( string $name ): void {
	$f = $GLOBALS['swish_test_failures'];
	$p = $GLOBALS['swish_test_passes'];
	echo sprintf( "%s: %d passed, %d failed\n", $name, $p, $f );
	if ( $f > 0 ) {
		exit( 1 );
	}
}

/** Create a file of $mb megabytes filled with pseudo-random data (cached between runs). */
function swish_test_big_file( string $path, int $mb ): string {
	if ( ! file_exists( $path ) || filesize( $path ) !== $mb * 1048576 ) {
		$h     = fopen( $path, 'wb' );
		$chunk = random_bytes( 1048576 );
		for ( $i = 0; $i < $mb; $i++ ) {
			fwrite( $h, $chunk );
		}
		fclose( $h );
	}
	return $path;
}

/** Walk a .swish archive header by header; returns [entries => [...], valid => bool, error => string]. */
function swish_test_walk_archive( string $path ): array {
	$entries = array();
	$size    = filesize( $path );
	$h       = fopen( $path, 'rb' );
	$eof     = str_repeat( "\0", 4377 );
	$valid   = false;
	$error   = '';
	while ( true ) {
		$pos    = ftell( $h );
		$header = fread( $h, 4377 );
		if ( strlen( $header ) < 4377 ) {
			$error = "archive ends without EOF marker at offset {$pos}";
			break;
		}
		if ( $header === $eof ) {
			$valid = ( $pos + 4377 === $size );
			$error = $valid ? '' : 'bytes after EOF marker';
			break;
		}
		$name   = rtrim( substr( $header, 0, 255 ), "\0" );
		$esize  = (int) rtrim( substr( $header, 255, 14 ), "\0" );
		$prefix = rtrim( substr( $header, 281, 4096 ), "\0" );
		$full   = $prefix ? $prefix . '/' . $name : $name;
		if ( '' === $name || $esize < 0 || $pos + 4377 + $esize > $size ) {
			$error = "corrupt header at offset {$pos} (name='{$name}' size={$esize})";
			break;
		}
		$entries[] = array( 'path' => $full, 'size' => $esize, 'offset' => $pos + 4377 );
		fseek( $h, $esize, SEEK_CUR );
	}
	fclose( $h );
	return array( 'entries' => $entries, 'valid' => $valid, 'error' => $error );
}

function swish_test_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}
