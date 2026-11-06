<?php
/**
 * End-to-end: import the archive produced by e2e-full-site-backup.php back into
 * this same site through the REST import pipeline (start, then continue with the
 * secret key, exactly like the Migration page). Take a database snapshot first:
 *   ddev export-db --file=/tmp/before-import.sql.gz
 * Usage:
 *   ddev wp eval-file .../e2e-self-import.php <archive path>
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Import\ImportSession;

wp_set_current_user( 1 );
global $wpdb;

$archive = $args[0] ?? '';
swish_assert( $archive && file_exists( $archive ), "archive exists: {$archive}" );

$posts_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
$options_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
$siteurl_before = get_option( 'siteurl' );
$active_before  = get_option( 'active_plugins' );
$sample_upload  = null;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR . '/uploads', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( $f->isFile() && preg_match( '/\.(jpe?g|png)$/i', $f->getFilename() ) && $f->getSize() > 10000 ) { $sample_upload = $f->getPathname(); break; }
}
$sample_md5 = $sample_upload ? md5_file( $sample_upload ) : '';
printf( "  before: posts=%d options=%d siteurl=%s sample=%s\n", $posts_before, $options_before, $siteurl_before, $sample_upload ? basename( $sample_upload ) : '-' );

function e2e_import_rest( string $path, array $params ): array {
	$req = new WP_REST_Request( 'POST', '/swish-backup/v1' . $path );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	if ( is_wp_error( $data ) ) { return array( '__error' => $data->get_error_message() . ' [' . $data->get_error_code() . ']' ); }
	return is_array( $data ) ? $data : array();
}

ImportSession::force_clear();
$t0    = microtime( true );
$start = e2e_import_rest( '/import/start', array(
	'backup_path'      => $archive,
	'old_url'          => get_site_url(),
	'new_url'          => get_site_url(),
	'restore_database' => true,
	'restore_files'    => true,
	'force'            => true,
) );
swish_assert( ! empty( $start['secret_key'] ), 'import started: ' . ( $start['__error'] ?? ( $start['message'] ?? '' ) ) );
$key = $start['secret_key'] ?? '';

$n = 0; $last = $start; $phases = array(); $max_req = 0.0; $busy = 0;
while ( $key && $n < 5000 ) {
	++$n;
	$t = microtime( true );
	$last = e2e_import_rest( '/import/continue', array( 'secret_key' => $key ) );
	$dur = microtime( true ) - $t; $max_req = max( $max_req, $dur );
	if ( isset( $last['__error'] ) ) { echo "  continue error: {$last['__error']}\n"; break; }
	if ( ! empty( $last['busy'] ) ) { ++$busy; }
	$ph = $last['phase'] ?? '?';
	$phases[ $ph ] = ( $phases[ $ph ] ?? 0 ) + 1;
	static $last_ph = ''; if ( $ph !== $last_ph || 0 === $n % 25 ) { printf( "  #%d %.1fs phase=%s progress=%s %s | %s\n", $n, $dur, $ph, $last['progress'] ?? '?', $last['message'] ?? '', $last['detail'] ?? '' ); $last_ph = $ph; }
	if ( ! empty( $last['completed'] ) ) { break; }
}
printf( "  total requests=%d busy=%d max request=%.1fs elapsed=%.0fs phases=%s\n", $n, $busy, $max_req, microtime( true ) - $t0, wp_json_encode( $phases ) );
swish_assert( ! empty( $last['completed'] ) && ! empty( $last['success'] ), 'import completed successfully: ' . ( $last['error'] ?? '' ) );
swish_assert( $max_req < 60, sprintf( 'no request exceeded 60s (max %.1fs)', $max_req ) );

// Site must be intact afterwards (same site imported over itself).
wp_cache_flush();
$posts_after   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
$siteurl_after = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'siteurl' ) );
$active_after  = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) ) );
printf( "  after: posts=%d siteurl=%s\n", $posts_after, $siteurl_after );
swish_assert_same( $posts_before, $posts_after, 'post count unchanged' );
swish_assert_same( $siteurl_before, $siteurl_after, 'siteurl unchanged' );
swish_assert( is_array( $active_after ) && in_array( 'swish-migrate-and-backup/swish-migrate-and-backup.php', $active_after, true ), 'plugin still active' );
if ( $sample_upload ) { swish_assert( file_exists( $sample_upload ) && md5_file( $sample_upload ) === $sample_md5, 'sample upload intact after restore' ); }
swish_assert( empty( glob( WP_CONTENT_DIR . '/swish-backups/temp/import-*' ) ), 'temp extraction directory removed' );
swish_assert( ! ImportSession::is_import_active(), 'import session closed' );
swish_done( 'e2e-self-import' );
