<?php
/**
 * Drives the REST pipeline exactly like the Backups page does (start, then
 * continue until done) and checks the resulting archive and job registration.
 * Also checks that the backup type is honoured.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\FileQueue;

wp_set_current_user( 1 );

function swish_test_rest( string $method, string $path, array $params = array() ): array {
	$req = new WP_REST_Request( $method, '/swish-backup/v1' . $path );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$res = rest_do_request( $req );
	$data = $res->get_data();
	if ( is_wp_error( $data ) ) { return array( '__error' => $data->get_error_message(), '__status' => $res->get_status() ); }
	$data['__status'] = $res->get_status();
	return $data;
}

function swish_test_run_backup( string $type, int $max_requests = 400 ): array {
	$t = microtime( true );
	$start = swish_test_rest( 'POST', '/pipeline/start', array( 'type' => $type ) );
	printf( "  start(%s): %.2fs status=%d phase=%s\n", $type, microtime( true ) - $t, $start['__status'], $start['phase'] ?? '?' );
	swish_assert( 200 === $start['__status'] && ! empty( $start['job_id'] ), "start returns a job id for type {$type}" );
	swish_assert( ( microtime( true ) - $t ) < 5, 'start returns quickly (indexing is deferred to continue)' );
	$job_id = $start['job_id'] ?? '';
	$last = $start; $n = 0; $phases = array();
	while ( $n < $max_requests ) {
		++$n;
		$last = swish_test_rest( 'POST', '/pipeline/continue/' . $job_id );
		if ( isset( $last['__error'] ) ) { echo "  continue error: {$last['__error']}\n"; break; }
		$phases[ $last['phase'] ] = ( $phases[ $last['phase'] ] ?? 0 ) + 1;
		if ( ! empty( $last['completed'] ) || 'failed' === ( $last['phase'] ?? '' ) ) { break; }
	}
	printf( "  %d continue requests, phases=%s, final=%s %s\n", $n, wp_json_encode( $phases ), $last['phase'] ?? '?', $last['error'] ?? '' );
	$last['__job_id'] = $job_id; $last['__requests'] = $n;
	return $last;
}

// --- Database-only backup: manifest + database.sql, nothing else. ---
$r = swish_test_run_backup( 'database' );
swish_assert( ! empty( $r['completed'] ), 'database backup completed' );
$walk = swish_test_walk_archive( $r['file_path'] ?? '' );
swish_assert( $walk['valid'], 'database archive valid: ' . $walk['error'] );
swish_assert_same( array( 'manifest.json', 'database.sql' ), array_column( $walk['entries'], 'path' ), 'database backup contains only manifest.json and database.sql' );
$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT status, type, file_size FROM {$GLOBALS['wpdb']->prefix}swish_backup_jobs WHERE job_id = %s", $r['__job_id'] ), ARRAY_A );
swish_assert( $row && 'completed' === $row['status'] && 'database' === $row['type'] && (int) $row['file_size'] === filesize( $r['file_path'] ), 'database backup registered in the jobs table' );
swish_assert( ! is_dir( dirname( $r['file_path'] ) . '/temp-' . $r['__job_id'] ), 'temp directory cleaned up' );
swish_assert_same( 0, FileQueue::get_total_count( $r['__job_id'] ), 'file queue cleared' );
$opt = get_option( 'swish_pipeline_job_' . $r['__job_id'] );
swish_assert( is_array( $opt ) && 'complete' === $opt['phase'], 'job option records completion' );
$autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", 'swish_pipeline_job_' . $r['__job_id'] ) );
swish_assert( in_array( $autoload, array( 'no', 'off' ), true ), "job option is not autoloaded (autoload={$autoload})" );
if ( ! empty( $r['file_path'] ) ) { @unlink( $r['file_path'] ); }
$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->prefix . 'swish_backup_jobs', array( 'job_id' => $r['__job_id'] ) );

// --- Files-only backup with a small scope: restrict to the themes dir via settings for speed. ---
$settings = get_option( 'swish_backup_settings', array() );
$saved = $settings;
$settings['backup_plugins'] = false; $settings['backup_uploads'] = false; $settings['backup_core_files'] = false; $settings['backup_themes'] = true;
update_option( 'swish_backup_settings', $settings );
$r = swish_test_run_backup( 'files' );
update_option( 'swish_backup_settings', $saved );
swish_assert( ! empty( $r['completed'] ), 'files backup completed' );
$walk = swish_test_walk_archive( $r['file_path'] ?? '' );
swish_assert( $walk['valid'], 'files archive valid: ' . $walk['error'] );
$paths = array_column( $walk['entries'], 'path' );
swish_assert_same( 'manifest.json', $paths[0] ?? '', 'manifest first' );
swish_assert( ! in_array( 'database.sql', $paths, true ), 'files backup contains no database.sql' );
$theme_files = 0; foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( get_theme_root(), FilesystemIterator::SKIP_DOTS ) ) as $f ) { if ( $f->isFile() && ! preg_match( '/\.(log|tmp|swp)$|\.DS_Store$|Thumbs\.db$/', $f->getFilename() ) && ! str_contains( $f->getPathname(), '/node_modules/' ) && ! str_contains( $f->getPathname(), '/.git/' ) ) { ++$theme_files; } }
printf( "  theme files on disk=%d, entries in archive=%d\n", $theme_files, count( $paths ) - 1 );
swish_assert( abs( $theme_files - ( count( $paths ) - 1 ) ) <= 5, 'archive entry count matches theme file count (within exclusion noise)' );
swish_assert( $r['__requests'] >= 3, 'files backup spanned several continue requests' );
if ( ! empty( $r['file_path'] ) ) { @unlink( $r['file_path'] ); }
$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->prefix . 'swish_backup_jobs', array( 'job_id' => $r['__job_id'] ) );

swish_done( 'rest-pipeline-flow' );
