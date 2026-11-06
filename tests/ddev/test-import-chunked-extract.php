<?php
/**
 * ImportPipeline must extract a large .swish archive across several bounded
 * requests (never one long request) and place files in wp-content in bounded
 * steps as well. Uses a throwaway archive whose files land under
 * wp-content/uploads/swish-import-test/, then cleans up.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\SwishArchiver;
use SwishMigrateAndBackup\Import\ImportPipeline;
use SwishMigrateAndBackup\Import\ImportSession;
use SwishMigrateAndBackup\Core\Container;
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Logger\Logger;

wp_set_current_user( 1 );
$budget = 1; // seconds per request
add_filter( 'swish_import_time_budget', fn() => $budget );

// Build a test archive: manifest + 1.5GB file + a few small files, all under uploads/swish-import-test/.
$src = sys_get_temp_dir() . '/swish-test-import-src';
if ( ! is_dir( $src ) ) { mkdir( $src, 0777, true ); }
$big = swish_test_big_file( "$src/big.bin", 1536 );
file_put_contents( "$src/small-1.txt", str_repeat( '1', 4000 ) );
file_put_contents( "$src/small-2.txt", str_repeat( '2', 5000 ) );
$manifest = wp_json_encode( array( 'version' => SWISH_BACKUP_VERSION, 'type' => 'files', 'site_url' => get_site_url(), 'format' => 'swish' ) );
file_put_contents( "$src/manifest.json", $manifest );
$archive = WP_CONTENT_DIR . '/swish-backups/imports/swish-import-test.swish';
wp_mkdir_p( dirname( $archive ) ); @unlink( $archive );
$a = new SwishArchiver( $archive ); $a->open_for_write(); $b = 0;
$a->add_file( "$src/manifest.json", 'manifest.json', 0, $b, 0 );
$a->add_file( "$src/small-1.txt", 'wp-content/uploads/swish-import-test/small-1.txt', 0, $b, 0 );
$a->add_file( $big, 'wp-content/uploads/swish-import-test/big.bin', 0, $b, 0 );
$a->add_file( "$src/small-2.txt", 'wp-content/uploads/swish-import-test/sub/small-2.txt', 0, $b, 0 );
$a->close( true );

$dest = WP_CONTENT_DIR . '/uploads/swish-import-test';
swish_test_rrmdir( $dest );
ImportSession::force_clear();
$session = ImportSession::create_session( $archive, array( 'restore_database' => false, 'restore_files' => true ) );
$key = $session['secret_key'];
$container = Container::get_instance();

$max_call = 0; $calls = 0; $phases = array(); $phase_max = array(); $done = false; $last = array();
for ( $i = 0; $i < 300; $i++ ) {
	$p = new ImportPipeline( new Logger(), $container->get( RestoreManager::class ), $container->get( Migrator::class ) );
	$t = microtime( true );
	$last = $p->execute( $key );
	$dur = microtime( true ) - $t; $max_call = max( $max_call, $dur ); ++$calls;
	$phases[ $last['phase'] ?? '?' ] = ( $phases[ $last['phase'] ?? '?' ] ?? 0 ) + 1;
	$phase_max[ $last['phase'] ?? '?' ] = max( $phase_max[ $last['phase'] ?? '?' ] ?? 0, round( $dur, 2 ) );
	if ( ! empty( $last['completed'] ) ) { $done = ! empty( $last['success'] ); break; }
}
printf( "  calls=%d max_call=%.2fs phases=%s phase_max=%s last=%s\n", $calls, $max_call, wp_json_encode( $phases ), wp_json_encode( $phase_max ), $last['error'] ?? ( $last['message'] ?? '' ) );
swish_assert( $done, 'import completed successfully' );
swish_assert( ( $phases['extract'] ?? 0 ) >= 2, 'extraction spanned at least two requests' );
swish_assert( $max_call < $budget + 6, sprintf( 'no single request ran much longer than the budget (max %.1fs)', $max_call ) );
swish_assert( file_exists( "$dest/big.bin" ) && md5_file( "$dest/big.bin" ) === md5_file( $big ), 'large file restored byte-identical' );
swish_assert( file_exists( "$dest/small-1.txt" ) && file_exists( "$dest/sub/small-2.txt" ), 'small files restored in place' );
swish_assert( ! file_exists( ABSPATH . 'manifest.json' ) && ! file_exists( WP_CONTENT_DIR . '/manifest.json' ), 'manifest.json not dropped into the site' );
$leftover = glob( WP_CONTENT_DIR . '/swish-backups/temp/import-*' );
swish_assert( empty( $leftover ), 'temp extraction directories cleaned up' );

swish_test_rrmdir( $dest ); @unlink( $archive ); ImportSession::force_clear();
swish_done( 'import-chunked-extract' );
