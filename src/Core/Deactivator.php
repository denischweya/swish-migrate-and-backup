<?php
/**
 * Plugin deactivator.
 *
 * @package SwishMigrateAndBackup\Core
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin deactivation tasks.
 */
final class Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->clear_scheduled_events();
		$this->cleanup_temp_files();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Clear all scheduled cron events.
	 *
	 * @return void
	 */
	private function clear_scheduled_events(): void {
		$scheduled_hooks = array(
			'swish_backup_scheduled_backup',
			'swish_backup_cleanup',
		);

		foreach ( $scheduled_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		// Clear all scheduled backup events.
		wp_unschedule_hook( 'swish_backup_scheduled_backup' );
	}

	/**
	 * Cleanup temporary files.
	 *
	 * @return void
	 */
	private function cleanup_temp_files(): void {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/swish-backups/temp';

		if ( is_dir( $temp_dir ) ) {
			$this->delete_directory_contents( $temp_dir );
		}
	}

	/**
	 * Recursively delete directory contents.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_directory_contents( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $file->getRealPath() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file->getRealPath() );
			}
		}
	}
}
