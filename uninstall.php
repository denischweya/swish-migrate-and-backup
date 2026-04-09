<?php
/**
 * Uninstall script for Swish Migrate and Backup.
 *
 * This file runs when the plugin is deleted from WordPress.
 *
 * @package SwishMigrateAndBackup
 */

// Exit if accessed directly or not uninstalling.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check user permissions.
if ( ! current_user_can( 'delete_plugins' ) ) {
	return;
}

/**
 * Perform plugin uninstall cleanup.
 *
 * @return void
 */
function swish_backup_uninstall_cleanup(): void {
	global $wpdb;

	// Delete plugin options.
	$swish_options_to_delete = array(
		'swish_backup_settings',
		'swish_backup_encryption_key',
		'swish_backup_db_version',
		'swish_backup_directory',
		'swish_backup_storage_local',
		'swish_backup_storage_s3',
		'swish_backup_storage_dropbox',
		'swish_backup_storage_googledrive',
		'swish_backup_job_queue',
	);

	foreach ( $swish_options_to_delete as $swish_option ) {
		delete_option( $swish_option );
	}

	// Delete transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_swish_backup_%' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_swish_backup_%' ) );

	// Drop custom tables.
	$swish_tables_to_drop = array(
		$wpdb->prefix . 'swish_backup_jobs',
		$wpdb->prefix . 'swish_backup_logs',
		$wpdb->prefix . 'swish_backup_schedules',
	);

	foreach ( $swish_tables_to_drop as $swish_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$swish_table}`" );
	}

	// Clear scheduled cron events.
	$swish_cron_hooks = array(
		'swish_backup_scheduled_backup',
		'swish_backup_cleanup',
	);

	foreach ( $swish_cron_hooks as $swish_hook ) {
		wp_clear_scheduled_hook( $swish_hook );
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

// Run the cleanup.
swish_backup_uninstall_cleanup();

// Optionally delete backup files.
// Uncomment the following code to delete all backup files on uninstall.
// WARNING: This will permanently delete all backups!
/*
$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/swish-backups';

if ( is_dir( $backup_dir ) ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			unlink( $file->getRealPath() );
		}
	}

	rmdir( $backup_dir );
}
*/
