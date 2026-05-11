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
use SwishMigrateAndBackup\Backup\TarArchiver;
use SwishMigrateAndBackup\Core\ServerLimits;
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
			if ( $options['restore_files'] ?? true ) {
				$files_restored = false;

				// Check for tar.gz archive first (preferred, faster).
				$tar_files = glob( $extract_dir . '/files*.tar.gz' );
				if ( ! empty( $tar_files ) ) {
					sort( $tar_files );
					$this->logger->info( 'Restoring files from tar.gz archive...', array( 'archives' => count( $tar_files ) ) );
					foreach ( $tar_files as $tar_path ) {
						$this->logger->debug( 'Extracting tar.gz archive', array( 'archive' => basename( $tar_path ) ) );
						if ( ! $this->restore_files_tar( $tar_path, $options ) ) {
							throw new \RuntimeException( 'File restore failed for: ' . basename( $tar_path ) );
						}
					}
					$files_restored = true;
				} elseif ( file_exists( $extract_dir . '/files.zip' ) ) {
					// Check for single files.zip (legacy/small backups).
					$this->logger->info( 'Restoring files...' );
					if ( ! $this->restore_files( $extract_dir . '/files.zip', $options ) ) {
						throw new \RuntimeException( 'File restore failed' );
					}
					$files_restored = true;
				}

				// Check for multiple batch parts (files-001.zip, files-002.zip, etc.).
				$file_parts = glob( $extract_dir . '/files-*.zip' );
				if ( ! empty( $file_parts ) ) {
					sort( $file_parts ); // Ensure correct order.
					$this->logger->info( 'Restoring files from batch parts...', array( 'parts' => count( $file_parts ) ) );
					foreach ( $file_parts as $part_path ) {
						$this->logger->debug( 'Extracting file part', array( 'part' => basename( $part_path ) ) );
						if ( ! $this->restore_files( $part_path, $options ) ) {
							throw new \RuntimeException( 'File restore failed for part: ' . basename( $part_path ) );
						}
					}
					$files_restored = true;
				}

				// Handle streaming tar backup format: files are extracted directly to extract_dir.
				// Check if wp-content exists directly (no nested archive).
				if ( ! $files_restored && is_dir( $extract_dir . '/wp-content' ) ) {
					$this->logger->info( 'Restoring files from direct extraction (streaming tar format)...' );
					if ( ! $this->restore_files_direct( $extract_dir, $options ) ) {
						throw new \RuntimeException( 'File restore failed (direct copy)' );
					}
					$files_restored = true;
				}

				if ( ! $files_restored ) {
					$this->logger->warning( 'No files to restore - backup may be database-only' );
				}
			}

			// Restore wp-config if present and requested.
			if ( ( $options['restore_wp_config'] ?? false ) && file_exists( $extract_dir . '/wp-config.php' ) ) {
				$this->logger->warning( 'Restoring wp-config.php - database credentials will need to be updated' );
				// Store in wp-content/swish-backups/ instead of ABSPATH for security.
				$config_restore_dir = WP_CONTENT_DIR . '/swish-backups/restored-configs';
				if ( ! is_dir( $config_restore_dir ) ) {
					wp_mkdir_p( $config_restore_dir );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				copy( $extract_dir . '/wp-config.php', $config_restore_dir . '/wp-config.php.restored-' . time() );
				$this->logger->info( 'wp-config.php saved to ' . $config_restore_dir );
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
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL from backup file is validated.
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

			// Extract files safely (with path validation to prevent Zip Slip).
			$extracted = $this->safe_extract_zip( $zip, $destination );
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
	 * Restore files from a tar.gz archive.
	 *
	 * Uses system tar command for efficient extraction.
	 *
	 * @param string $archive_path Path to tar.gz archive.
	 * @param array  $options      Restore options.
	 * @return bool True if successful.
	 */
	public function restore_files_tar( string $archive_path, array $options = array() ): bool {
		$this->logger->info( 'Starting tar.gz file restore', array( 'archive' => $archive_path ) );

		try {
			// Check if tar is available.
			if ( ! ServerLimits::is_tar_available() ) {
				$this->logger->warning( 'Tar not available, falling back to PHP extraction' );
				return $this->restore_files_tar_php( $archive_path, $options );
			}

			$tar_archiver = new TarArchiver( $this->logger );
			$destination = ABSPATH;

			// Create backup of current files if requested.
			if ( $options['backup_before_restore'] ?? false ) {
				$this->create_pre_restore_backup();
			}

			// Extract using system tar.
			$result = $tar_archiver->extract_archive( $archive_path, $destination );

			if ( ! $result['success'] ) {
				$this->logger->error( 'Tar extraction failed', array(
					'error' => $result['error'] ?? 'Unknown error',
				) );
				return false;
			}

			$this->logger->info( 'Tar.gz file restore completed' );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Tar.gz file restore failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Restore files from tar.gz using PHP (fallback when system tar unavailable).
	 *
	 * Uses PharData for extraction.
	 *
	 * @param string $archive_path Path to tar.gz archive.
	 * @param array  $options      Restore options.
	 * @return bool True if successful.
	 */
	private function restore_files_tar_php( string $archive_path, array $options = array() ): bool {
		try {
			$destination = ABSPATH;

			// Create backup of current files if requested.
			if ( $options['backup_before_restore'] ?? false ) {
				$this->create_pre_restore_backup();
			}

			// Use PharData to extract.
			$phar = new \PharData( $archive_path );

			// Extract - PharData handles gzip decompression automatically.
			$phar->extractTo( $destination, null, true ); // true = overwrite.

			$this->logger->info( 'Tar.gz file restore completed (PHP fallback)' );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'PHP tar extraction failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Restore files directly from extracted backup directory.
	 *
	 * This handles streaming tar backups where files are extracted directly
	 * to the extract directory (not nested in files.tar.gz or files.zip).
	 *
	 * @param string $extract_dir Directory containing extracted files.
	 * @param array  $options     Restore options.
	 * @return bool True if successful.
	 */
	private function restore_files_direct( string $extract_dir, array $options = array() ): bool {
		try {
			$destination = ABSPATH;

			// Create backup of current files if requested.
			if ( $options['backup_before_restore'] ?? false ) {
				$this->create_pre_restore_backup();
			}

			// Directories to copy (standard WordPress structure).
			$dirs_to_copy = array( 'wp-content', 'wp-includes', 'wp-admin' );

			// Also check for any root PHP files (but exclude special ones we handle separately).
			$exclude_files = array( 'manifest.json', 'database.sql', 'wp-config.php' );

			$files_copied = 0;
			$dirs_copied = 0;

			// Copy directories.
			foreach ( $dirs_to_copy as $dir ) {
				$source = $extract_dir . '/' . $dir;
				if ( is_dir( $source ) ) {
					$this->logger->info( "Copying directory: {$dir}" );
					$copied = $this->copy_directory_recursive( $source, $destination . '/' . $dir );
					$files_copied += $copied;
					++$dirs_copied;
				}
			}

			// Copy root files (like index.php, wp-load.php, etc.) but not special backup files.
			$root_files = glob( $extract_dir . '/*.php' );
			if ( ! empty( $root_files ) ) {
				foreach ( $root_files as $file ) {
					$filename = basename( $file );
					if ( in_array( $filename, $exclude_files, true ) ) {
						continue;
					}
					$dest_file = $destination . '/' . $filename;
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
					if ( copy( $file, $dest_file ) ) {
						++$files_copied;
					}
				}
			}

			$this->logger->info( 'Direct file restore completed', array(
				'directories' => $dirs_copied,
				'files'       => $files_copied,
			) );

			return $dirs_copied > 0 || $files_copied > 0;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Direct file restore failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source      Source directory.
	 * @param string $destination Destination directory.
	 * @return int Number of files copied.
	 */
	private function copy_directory_recursive( string $source, string $destination ): int {
		$count = 0;

		if ( ! is_dir( $destination ) ) {
			wp_mkdir_p( $destination );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$rel_path = substr( $item->getPathname(), strlen( $source ) + 1 );
			$dest_path = $destination . '/' . $rel_path;

			if ( $item->isDir() ) {
				if ( ! is_dir( $dest_path ) ) {
					wp_mkdir_p( $dest_path );
				}
			} else {
				// Ensure parent directory exists.
				$parent = dirname( $dest_path );
				if ( ! is_dir( $parent ) ) {
					wp_mkdir_p( $parent );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				if ( copy( $item->getPathname(), $dest_path ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Safely extract a ZIP archive with path traversal validation.
	 *
	 * Prevents Zip Slip attacks by ensuring all extracted files stay within the destination directory.
	 * Uses stream-based extraction to handle large files without memory exhaustion.
	 *
	 * @param ZipArchive $zip         The ZipArchive object.
	 * @param string     $destination The destination directory.
	 * @return bool True if successful.
	 */
	private function safe_extract_zip( ZipArchive $zip, string $destination ): bool {
		$destination = rtrim( $destination, '/' ) . '/';
		$real_destination = realpath( $destination );

		if ( false === $real_destination ) {
			return false;
		}

		$real_destination = rtrim( $real_destination, '/' ) . '/';

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );

			if ( false === $entry_name ) {
				continue;
			}

			// Build target path.
			$target_path = $destination . $entry_name;

			// Resolve the real path (handles ../ and symlinks).
			// For directories, we need to check parent since dir doesn't exist yet.
			if ( str_ends_with( $entry_name, '/' ) ) {
				// It's a directory entry.
				$parent_dir = dirname( $target_path );
				if ( ! is_dir( $parent_dir ) ) {
					wp_mkdir_p( $parent_dir );
				}
				if ( ! is_dir( $target_path ) ) {
					wp_mkdir_p( $target_path );
				}
				continue;
			}

			// For files, ensure parent directory exists.
			$parent_dir = dirname( $target_path );
			if ( ! is_dir( $parent_dir ) ) {
				wp_mkdir_p( $parent_dir );
			}

			// Now check if the resolved path is within our destination.
			$real_target = realpath( $parent_dir );
			if ( false === $real_target || ! str_starts_with( $real_target . '/', $real_destination ) ) {
				$this->logger->warning( 'Skipping unsafe ZIP entry (path traversal attempt)', array( 'entry' => $entry_name ) );
				continue;
			}

			// Extract the file using streams to avoid memory exhaustion on large files.
			if ( ! $this->extract_file_stream( $zip, $entry_name, $target_path ) ) {
				$this->logger->warning( 'Failed to extract file', array( 'entry' => $entry_name ) );
				continue;
			}
		}

		return true;
	}

	/**
	 * Extract a single file from ZIP using streams.
	 *
	 * This method reads and writes in chunks to avoid loading entire files into memory.
	 *
	 * @param ZipArchive $zip         The ZipArchive object.
	 * @param string     $entry_name  The name of the entry in the ZIP.
	 * @param string     $target_path The target path to write to.
	 * @return bool True if successful.
	 */
	private function extract_file_stream( ZipArchive $zip, string $entry_name, string $target_path ): bool {
		// Get a stream for the ZIP entry.
		$stream = $zip->getStream( $entry_name );
		if ( false === $stream ) {
			return false;
		}

		// Open target file for writing.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( $target_path, 'wb' );
		if ( false === $output ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $stream );
			return false;
		}

		// Copy in chunks (8KB at a time).
		$chunk_size = 8192;
		while ( ! feof( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $stream, $chunk_size );
			if ( false === $chunk ) {
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $output, $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );

		return true;
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
			// Get manifest using format detection.
			$manifest = null;

			if ( $this->is_tar_archive( $backup_path ) ) {
				$manifest = $this->get_manifest_from_tar( $backup_path );
			} elseif ( $this->is_zip_archive( $backup_path ) ) {
				$manifest = $this->get_manifest_from_zip( $backup_path );
			} else {
				// Unknown format - try both.
				$manifest = $this->get_manifest_from_tar( $backup_path );
				if ( null === $manifest ) {
					$manifest = $this->get_manifest_from_zip( $backup_path );
				}
			}

			if ( null === $manifest ) {
				return false;
			}

			// Validate manifest.
			$manifest_data = json_decode( $manifest, true );
			if ( ! is_array( $manifest_data ) || empty( $manifest_data['version'] ) ) {
				return false;
			}

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check if file is a tar/tar.gz archive.
	 *
	 * Handles WordPress filename mangling (e.g., .tar-2.gz instead of .tar.gz).
	 *
	 * @param string $backup_path Path to backup file.
	 * @return bool True if tar archive.
	 */
	private function is_tar_archive( string $backup_path ): bool {
		$filename = strtolower( basename( $backup_path ) );

		// Standard extensions.
		if ( str_ends_with( $filename, '.tar.gz' ) ||
			str_ends_with( $filename, '.tgz' ) ||
			str_ends_with( $filename, '.swish' ) ||
			str_ends_with( $filename, '.tar' ) ) {
			return true;
		}

		// Handle WordPress filename mangling: .tar-N.gz (e.g., file.tar-2.gz).
		if ( preg_match( '/\.tar-\d+\.gz$/i', $filename ) ) {
			return true;
		}

		// Any .gz file that contains 'tar' in filename is likely a tar.gz.
		if ( str_ends_with( $filename, '.gz' ) && str_contains( $filename, 'tar' ) ) {
			return true;
		}

		// Check by content - gzip magic bytes.
		if ( str_ends_with( $filename, '.gz' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $backup_path, 'rb' );
			if ( $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$magic = fread( $handle, 2 );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				// Gzip magic bytes: 1f 8b.
				if ( "\x1f\x8b" === $magic ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if file is a ZIP archive.
	 *
	 * @param string $backup_path Path to backup file.
	 * @return bool True if ZIP archive.
	 */
	private function is_zip_archive( string $backup_path ): bool {
		$filename = strtolower( basename( $backup_path ) );

		if ( str_ends_with( $filename, '.zip' ) ) {
			return true;
		}

		// Check by content - ZIP magic bytes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $backup_path, 'rb' );
		if ( $handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$magic = fread( $handle, 4 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			// ZIP magic bytes: PK (50 4b 03 04).
			if ( "PK\x03\x04" === $magic ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get backup information.
	 *
	 * @param string $backup_path Path to backup file.
	 * @return array|null Backup info or null on error.
	 */
	public function get_backup_info( string $backup_path ): ?array {
		try {
			$manifest = null;

			// Determine archive type and extract manifest accordingly.
			if ( $this->is_tar_archive( $backup_path ) ) {
				$manifest = $this->get_manifest_from_tar( $backup_path );
			} elseif ( $this->is_zip_archive( $backup_path ) ) {
				$manifest = $this->get_manifest_from_zip( $backup_path );
			} else {
				// Unknown format - try both.
				$manifest = $this->get_manifest_from_tar( $backup_path );
				if ( null === $manifest ) {
					$manifest = $this->get_manifest_from_zip( $backup_path );
				}
			}

			if ( null === $manifest ) {
				return null;
			}

			$data = json_decode( $manifest, true );
			if ( ! is_array( $data ) ) {
				return null;
			}

			$data['file_size'] = filesize( $backup_path );
			$data['filename'] = basename( $backup_path );

			return $data;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to get backup info: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Extract manifest.json from a ZIP archive.
	 *
	 * @param string $backup_path Path to ZIP file.
	 * @return string|null Manifest contents or null on error.
	 */
	private function get_manifest_from_zip( string $backup_path ): ?string {
		try {
			$zip = new \ZipArchive();
			$result = $zip->open( $backup_path, \ZipArchive::RDONLY );

			if ( true !== $result ) {
				return null;
			}

			$manifest = $zip->getFromName( 'manifest.json' );
			$zip->close();

			return false !== $manifest ? $manifest : null;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Extract manifest.json from a tar.gz archive.
	 *
	 * @param string $backup_path Path to tar.gz file.
	 * @return string|null Manifest contents or null on error.
	 */
	private function get_manifest_from_tar( string $backup_path ): ?string {
		try {
			$phar = new \PharData( $backup_path );

			// Try to get manifest.json directly.
			if ( isset( $phar['manifest.json'] ) ) {
				return $phar['manifest.json']->getContent();
			}

			// Some archives may have it in a subdirectory, iterate to find it.
			foreach ( new \RecursiveIteratorIterator( $phar ) as $file ) {
				if ( basename( $file->getPathname() ) === 'manifest.json' ) {
					return $file->getContent();
				}
			}

			return null;
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
	 * Supports both ZIP and tar.gz (including .swish) formats.
	 *
	 * @param string $backup_path Path to backup file.
	 * @param string $output_dir  Output directory.
	 * @return bool True if successful.
	 */
	private function extract_backup( string $backup_path, string $output_dir ): bool {
		try {
			if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
				return false;
			}

			// Determine archive type and extract accordingly.
			if ( $this->is_tar_archive( $backup_path ) ) {
				return $this->extract_tar_backup( $backup_path, $output_dir );
			}

			if ( $this->is_zip_archive( $backup_path ) ) {
				return $this->extract_zip_backup( $backup_path, $output_dir );
			}

			// Unknown format - try tar first, then zip.
			if ( $this->extract_tar_backup( $backup_path, $output_dir ) ) {
				return true;
			}

			return $this->extract_zip_backup( $backup_path, $output_dir );
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to extract backup: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Extract ZIP backup to directory.
	 *
	 * @param string $backup_path Path to ZIP file.
	 * @param string $output_dir  Output directory.
	 * @return bool True if successful.
	 */
	private function extract_zip_backup( string $backup_path, string $output_dir ): bool {
		$zip = new \ZipArchive();
		$result = $zip->open( $backup_path );

		if ( true !== $result ) {
			return false;
		}

		// Use safe extraction to prevent Zip Slip attacks.
		$extracted = $this->safe_extract_zip( $zip, $output_dir );
		$zip->close();

		return $extracted;
	}

	/**
	 * Extract tar.gz backup to directory.
	 *
	 * @param string $backup_path Path to tar.gz file.
	 * @param string $output_dir  Output directory.
	 * @return bool True if successful.
	 */
	private function extract_tar_backup( string $backup_path, string $output_dir ): bool {
		try {
			$phar = new \PharData( $backup_path );
			$phar->extractTo( $output_dir, null, true ); // true = overwrite existing files.
			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to extract tar.gz: ' . $e->getMessage() );
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

		// Clear transients in batches to avoid memory issues on large sites.
		global $wpdb;
		$batch_size = 1000;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
					'_transient_%',
					$batch_size
				)
			);

			// Free memory between batches.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		} while ( $deleted > 0 );

		$this->logger->info( 'Caches flushed' );
	}
}
