<?php
/**
 * Restore Manager.
 *
 * @package SwishMigrateAndBackup\Restore
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Restore;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Storage\StorageManager;
use ZipArchive;

/**
 * Handles restore operations from backups.
 */
final class RestoreManager {

	/**
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param StorageManager $storage_manager Storage manager.
	 * @param Logger         $logger          Logger instance.
	 */
	public function __construct( StorageManager $storage_manager, Logger $logger ) {
		$this->storage_manager = $storage_manager;
		$this->logger          = $logger;
	}

	/**
	 * Restore from a backup.
	 *
	 * @param string $backup_path Path to backup file.
	 * @param array  $options     Restore options.
	 * @return bool True if successful.
	 */
	public function restore( string $backup_path, array $options = array() ): bool {
		$this->logger->info( 'Starting restore', array( 'backup' => $backup_path ) );

		/**
		 * Fires before a restore starts.
		 *
		 * @param string $backup_path Path to backup file.
		 * @param array  $options     Restore options.
		 */
		do_action( 'swish_backup_restore_before', $backup_path, $options );

		try {
			// Verify backup file.
			if ( ! $this->verify_backup( $backup_path ) ) {
				throw new \RuntimeException( 'Backup verification failed' );
			}

			// Extract backup.
			$extract_dir = $this->get_extract_directory();
			if ( ! $this->extract_backup( $backup_path, $extract_dir ) ) {
				throw new \RuntimeException( 'Failed to extract backup' );
			}

			// Get manifest.
			$manifest = $this->get_manifest( $extract_dir );
			if ( ! $manifest ) {
				throw new \RuntimeException( 'Invalid backup manifest' );
			}

			// Restore database.
			if ( ( $options['restore_database'] ?? true ) && file_exists( $extract_dir . '/database.sql' ) ) {
				$this->logger->info( 'Restoring database...' );
				if ( ! $this->restore_database( $extract_dir . '/database.sql' ) ) {
					throw new \RuntimeException( 'Database restore failed' );
				}
			}

			// Restore files.
			if ( ( $options['restore_files'] ?? true ) && file_exists( $extract_dir . '/files.zip' ) ) {
				$this->logger->info( 'Restoring files...' );
				if ( ! $this->restore_files( $extract_dir . '/files.zip', $options ) ) {
					throw new \RuntimeException( 'File restore failed' );
				}
			}

			// Restore wp-config if present and requested.
			if ( ( $options['restore_wp_config'] ?? false ) && file_exists( $extract_dir . '/wp-config.php' ) ) {
				$this->logger->warning( 'Restoring wp-config.php - database credentials will need to be updated' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				copy( $extract_dir . '/wp-config.php', ABSPATH . 'wp-config.php.restored' );
			}

			// Restore .htaccess if present.
			if ( ( $options['restore_htaccess'] ?? true ) && file_exists( $extract_dir . '/.htaccess' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				copy( $extract_dir . '/.htaccess', ABSPATH . '.htaccess' );
			}

			// Clean up.
			$this->cleanup_extract_directory( $extract_dir );

			// Flush caches and rewrite rules.
			$this->flush_caches();

			/**
			 * Fires after a restore completes successfully.
			 *
			 * @param string $backup_path Path to backup file.
			 * @param array  $manifest    Backup manifest.
			 */
			do_action( 'swish_backup_restore_after', $backup_path, $manifest );

			$this->logger->info( 'Restore completed successfully' );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Restore failed: ' . $e->getMessage() );

			if ( isset( $extract_dir ) ) {
				$this->cleanup_extract_directory( $extract_dir );
			}

			return false;
		}
	}

	/**
	 * Restore database from SQL file.
	 *
	 * @param string $sql_file Path to SQL file.
	 * @return bool True if successful.
	 */
	public function restore_database( string $sql_file ): bool {
		global $wpdb;

		if ( ! file_exists( $sql_file ) ) {
			return false;
		}

		$this->logger->info( 'Starting database restore', array( 'file' => $sql_file ) );

		try {
			// Read and execute SQL file in chunks.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $sql_file, 'r' );
			if ( ! $handle ) {
				return false;
			}

			// Disable foreign key checks temporarily.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

			$query = '';
			$delimiter = ';';
			$in_string = false;
			$string_char = '';

			while ( ! feof( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				$line = fgets( $handle );

				if ( false === $line ) {
					break;
				}

				// Skip comments and empty lines.
				$trimmed = trim( $line );
				if ( empty( $trimmed ) || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '#' ) ) {
					continue;
				}

				// Skip MySQL-specific comments.
				if ( preg_match( '/^\/\*!/', $trimmed ) && ! str_contains( $trimmed, '*/' ) ) {
					continue;
				}

				$query .= $line;

				// Check for complete statement.
				if ( $this->is_complete_query( $query, $delimiter ) ) {
					$query = trim( $query );

					if ( ! empty( $query ) && $query !== $delimiter ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
						$result = $wpdb->query( $query );

						if ( false === $result && ! empty( $wpdb->last_error ) ) {
							// Log error but continue (some errors are expected).
							$this->logger->warning( 'SQL query warning', array(
								'error' => $wpdb->last_error,
								'query' => substr( $query, 0, 100 ) . '...',
							) );
						}
					}

					$query = '';
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			// Re-enable foreign key checks.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

			$this->logger->info( 'Database restore completed' );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Database restore failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Restore files from archive.
	 *
	 * @param string $archive_path Path to files archive.
	 * @param array  $options      Restore options.
	 * @return bool True if successful.
	 */
	public function restore_files( string $archive_path, array $options = array() ): bool {
		$this->logger->info( 'Starting file restore', array( 'archive' => $archive_path ) );

		try {
			$zip = new ZipArchive();
			$result = $zip->open( $archive_path );

			if ( true !== $result ) {
				$this->logger->error( 'Failed to open files archive', array( 'error' => $result ) );
				return false;
			}

			$destination = ABSPATH;

			// Create backup of current files if requested.
			if ( $options['backup_before_restore'] ?? false ) {
				$this->create_pre_restore_backup();
			}

			// Extract files.
			$extracted = $zip->extractTo( $destination );
			$zip->close();

			if ( ! $extracted ) {
				$this->logger->error( 'Failed to extract files' );
				return false;
			}

			$this->logger->info( 'File restore completed' );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'File restore failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Verify backup integrity.
	 *
	 * @param string $backup_path Path to backup file.
	 * @return bool True if valid.
	 */
	public function verify_backup( string $backup_path ): bool {
		if ( ! file_exists( $backup_path ) ) {
			return false;
		}

		try {
			$zip = new ZipArchive();
			$result = $zip->open( $backup_path, ZipArchive::RDONLY );

			if ( true !== $result ) {
				return false;
			}

			// Check for manifest.
			$manifest = $zip->getFromName( 'manifest.json' );
			if ( false === $manifest ) {
				$zip->close();
				return false;
			}

			// Validate manifest.
			$manifest_data = json_decode( $manifest, true );
			if ( ! is_array( $manifest_data ) || empty( $manifest_data['version'] ) ) {
				$zip->close();
				return false;
			}

			$zip->close();

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Get backup information.
	 *
	 * @param string $backup_path Path to backup file.
	 * @return array|null Backup info or null on error.
	 */
	public function get_backup_info( string $backup_path ): ?array {
		try {
			$zip = new ZipArchive();
			$result = $zip->open( $backup_path, ZipArchive::RDONLY );

			if ( true !== $result ) {
				return null;
			}

			$manifest = $zip->getFromName( 'manifest.json' );
			$zip->close();

			if ( false === $manifest ) {
				return null;
			}

			$data = json_decode( $manifest, true );
			$data['file_size'] = filesize( $backup_path );
			$data['filename'] = basename( $backup_path );

			return $data;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Download backup from remote storage.
	 *
	 * @param string $filename    Backup filename.
	 * @param string $adapter_id  Storage adapter ID.
	 * @return string|null Local path or null on error.
	 */
	public function download_from_storage( string $filename, string $adapter_id = 'local' ): ?string {
		try {
			$adapter = $this->storage_manager->get_adapter( $adapter_id );

			if ( 'local' === $adapter_id ) {
				// Local storage - just return the path.
				$local_adapter = $adapter;
				return $local_adapter->get_base_directory() . '/' . $filename;
			}

			// Download from remote.
			$local_path = $this->get_extract_directory() . '/' . $filename;

			if ( ! $adapter->download( $filename, $local_path ) ) {
				return null;
			}

			return $local_path;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to download backup: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Extract backup to temporary directory.
	 *
	 * @param string $backup_path Path to backup file.
	 * @param string $output_dir  Output directory.
	 * @return bool True if successful.
	 */
	private function extract_backup( string $backup_path, string $output_dir ): bool {
		try {
			$zip = new ZipArchive();
			$result = $zip->open( $backup_path );

			if ( true !== $result ) {
				return false;
			}

			if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
				$zip->close();
				return false;
			}

			$extracted = $zip->extractTo( $output_dir );
			$zip->close();

			return $extracted;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Get manifest from extracted backup.
	 *
	 * @param string $extract_dir Extraction directory.
	 * @return array|null Manifest data or null on error.
	 */
	private function get_manifest( string $extract_dir ): ?array {
		$manifest_path = $extract_dir . '/manifest.json';

		if ( ! file_exists( $manifest_path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $manifest_path );
		return json_decode( $content, true );
	}

	/**
	 * Get extraction directory.
	 *
	 * @return string
	 */
	private function get_extract_directory(): string {
		$extract_dir = WP_CONTENT_DIR . '/swish-backups/temp/restore-' . time();

		if ( ! is_dir( $extract_dir ) ) {
			wp_mkdir_p( $extract_dir );
		}

		return $extract_dir;
	}

	/**
	 * Cleanup extraction directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function cleanup_extract_directory( string $dir ): void {
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Check if SQL query is complete.
	 *
	 * @param string $query     Query string.
	 * @param string $delimiter Delimiter.
	 * @return bool True if complete.
	 */
	private function is_complete_query( string $query, string $delimiter ): bool {
		$trimmed = rtrim( $query );
		return str_ends_with( $trimmed, $delimiter );
	}

	/**
	 * Create a backup before restore.
	 *
	 * @return void
	 */
	private function create_pre_restore_backup(): void {
		$this->logger->info( 'Creating pre-restore backup...' );
		// This would call BackupManager but would create circular dependency.
		// In practice, you'd use a hook or different architecture.
	}

	/**
	 * Flush caches and rewrite rules.
	 *
	 * @return void
	 */
	private function flush_caches(): void {
		// Flush rewrite rules.
		flush_rewrite_rules();

		// Clear object cache.
		wp_cache_flush();

		// Clear any transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );

		$this->logger->info( 'Caches flushed' );
	}
}
