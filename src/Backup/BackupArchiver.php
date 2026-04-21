<?php
/**
 * Backup Archiver.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;
use ZipArchive;

/**
 * Handles creating and managing backup archives.
 */
final class BackupArchiver {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Compression level (0-9).
	 *
	 * @var int
	 */
	private int $compression_level = 6;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Set compression level.
	 *
	 * @param int $level Compression level (0-9).
	 * @return self
	 */
	public function set_compression_level( int $level ): self {
		$this->compression_level = max( 0, min( 9, $level ) );
		return $this;
	}

	/**
	 * Create a backup archive from multiple files.
	 *
	 * @param array  $files       Array of file paths to include.
	 * @param string $output_path Output archive path.
	 * @param array  $metadata    Optional metadata to include.
	 * @return bool True if successful.
	 */
	public function create_archive( array $files, string $output_path, array $metadata = array() ): bool {
		$this->logger->info( 'Creating backup archive', array(
			'files' => count( $files ),
			'output' => $output_path,
		) );

		try {
			$zip = new ZipArchive();
			$result = $zip->open( $output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

			if ( true !== $result ) {
				$this->logger->error( 'Failed to create archive', array( 'error_code' => $result ) );
				return false;
			}

			// Set compression method.
			$method = $this->compression_level > 0 ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE;

			// Add files.
			foreach ( $files as $file ) {
				if ( is_array( $file ) ) {
					$path = $file['path'];
					$name = $file['name'] ?? basename( $path );
				} else {
					$path = $file;
					$name = basename( $file );
				}

				if ( ! file_exists( $path ) ) {
					$this->logger->warning( 'File not found for archive', array( 'path' => $path ) );
					continue;
				}

				$file_size = filesize( $path );

				// For large files (>50MB) or already-compressed files, use streaming approach.
				// This avoids memory issues with ZipArchive::addFile() on large files.
				$is_compressed = preg_match( '/\.(gz|zip|tar\.gz|tgz|bz2|xz)$/i', $path );
				$is_large = $file_size > 50 * 1024 * 1024;

				if ( $is_large || $is_compressed ) {
					// Use streaming to add large/compressed files.
					$added = $this->add_large_file_to_zip( $zip, $path, $name, $is_compressed );
					if ( ! $added ) {
						$this->logger->error( 'Failed to add large file to archive', array(
							'path' => $path,
							'size' => $file_size,
						) );
					}
				} else {
					$zip->addFile( $path, $name );
					$zip->setCompressionName( $name, $method, $this->compression_level );
				}
			}

			// Add manifest.
			$manifest = $this->create_manifest( $files, $metadata );
			$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

			$zip->close();

			// Verify archive.
			if ( ! $this->verify_archive( $output_path ) ) {
				$this->logger->error( 'Archive verification failed' );
				return false;
			}

			$this->logger->info( 'Archive created successfully', array(
				'size' => filesize( $output_path ),
				'checksum' => md5_file( $output_path ),
			) );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Archive creation failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Extract an archive.
	 *
	 * @param string $archive_path Path to the archive.
	 * @param string $output_dir   Output directory.
	 * @return bool True if successful.
	 */
	public function extract_archive( string $archive_path, string $output_dir ): bool {
		$this->logger->info( 'Extracting archive', array(
			'archive' => $archive_path,
			'output'  => $output_dir,
		) );

		try {
			$zip = new ZipArchive();
			$result = $zip->open( $archive_path );

			if ( true !== $result ) {
				$this->logger->error( 'Failed to open archive', array( 'error_code' => $result ) );
				return false;
			}

			// Create output directory.
			if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
				$zip->close();
				$this->logger->error( 'Failed to create output directory' );
				return false;
			}

			// Extract all files.
			$result = $zip->extractTo( $output_dir );
			$zip->close();

			if ( ! $result ) {
				$this->logger->error( 'Archive extraction failed' );
				return false;
			}

			$this->logger->info( 'Archive extracted successfully' );
			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Archive extraction failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * List contents of an archive.
	 *
	 * @param string $archive_path Path to the archive.
	 * @return array|null Array of files or null on error.
	 */
	public function list_archive_contents( string $archive_path ): ?array {
		try {
			$zip = new ZipArchive();
			$result = $zip->open( $archive_path, ZipArchive::RDONLY );

			if ( true !== $result ) {
				return null;
			}

			$files = array();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				$files[] = array(
					'name'          => $stat['name'],
					'size'          => $stat['size'],
					'compressed'    => $stat['comp_size'],
					'modified'      => $stat['mtime'],
					'crc'           => $stat['crc'],
				);
			}

			$zip->close();
			return $files;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Get manifest from archive.
	 *
	 * @param string $archive_path Path to the archive.
	 * @return array|null Manifest data or null on error.
	 */
	public function get_manifest( string $archive_path ): ?array {
		try {
			$zip = new ZipArchive();
			$result = $zip->open( $archive_path, ZipArchive::RDONLY );

			if ( true !== $result ) {
				return null;
			}

			$manifest_content = $zip->getFromName( 'manifest.json' );
			$zip->close();

			if ( false === $manifest_content ) {
				return null;
			}

			return json_decode( $manifest_content, true );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Verify archive integrity.
	 *
	 * @param string $archive_path Path to the archive.
	 * @return bool True if valid.
	 */
	public function verify_archive( string $archive_path ): bool {
		if ( ! file_exists( $archive_path ) ) {
			return false;
		}

		try {
			$zip = new ZipArchive();
			$result = $zip->open( $archive_path, ZipArchive::RDONLY );

			if ( true !== $result ) {
				return false;
			}

			// Check that we can read the manifest.
			$has_manifest = false !== $zip->getFromName( 'manifest.json' );

			// Test archive integrity.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false === $stat ) {
					$zip->close();
					return false;
				}
			}

			$zip->close();

			return $has_manifest;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Add a large file to a ZIP archive using streaming.
	 *
	 * This method reads the file in chunks and uses addFromString() to avoid
	 * the memory issues that can occur with addFile() on very large files.
	 * For already-compressed files (like tar.gz), we use CM_STORE to avoid
	 * double-compression.
	 *
	 * @param ZipArchive $zip           The ZipArchive object.
	 * @param string     $file_path     Path to the file to add.
	 * @param string     $name          Name in the archive.
	 * @param bool       $is_compressed Whether the file is already compressed.
	 * @return bool True on success.
	 */
	private function add_large_file_to_zip( ZipArchive $zip, string $file_path, string $name, bool $is_compressed = false ): bool {
		$file_size = filesize( $file_path );

		$this->logger->debug( 'Adding large file to archive using streaming', array(
			'file'          => $name,
			'size'          => $file_size,
			'is_compressed' => $is_compressed,
		) );

		// For files under 100MB, we can still use addFromString with full read.
		// This is more reliable than addFile().
		if ( $file_size < 100 * 1024 * 1024 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file_path );
			if ( false === $content ) {
				$this->logger->error( 'Failed to read file for archive', array( 'path' => $file_path ) );
				return false;
			}

			$zip->addFromString( $name, $content );

			// Use CM_STORE for already-compressed files to avoid double-compression.
			if ( $is_compressed ) {
				$zip->setCompressionName( $name, ZipArchive::CM_STORE );
			} else {
				$zip->setCompressionName( $name, ZipArchive::CM_DEFLATE, $this->compression_level );
			}

			unset( $content );
			return true;
		}

		// For very large files (>100MB), we need a different approach.
		// Close the current zip, use system zip command to add the file, then reopen.

		// First, close the current archive to flush pending writes.
		$archive_path = $zip->filename;
		$zip->close();

		// Use system zip command to add the large file.
		// The -j flag stores just the file without directory path.
		// The -0 flag (if compressed) stores without compression.
		$compression_flag = $is_compressed ? '-0' : '';

		// Create a temp directory for the file with the correct name.
		$temp_dir = dirname( $file_path ) . '/swish-zip-temp-' . uniqid();
		wp_mkdir_p( $temp_dir );

		// Create a hard link or copy with the target name.
		$temp_file = $temp_dir . '/' . $name;
		$temp_file_dir = dirname( $temp_file );
		if ( ! is_dir( $temp_file_dir ) ) {
			wp_mkdir_p( $temp_file_dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.link_link
		if ( ! @link( $file_path, $temp_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $file_path, $temp_file ) ) {
				$this->logger->error( 'Failed to prepare large file for zip', array( 'path' => $file_path ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $temp_dir );
				// Reopen the zip.
				$zip->open( $archive_path, ZipArchive::CREATE );
				return false;
			}
		}

		// Use zip command to add the file.
		$command = sprintf(
			'cd %s && zip %s -r %s %s 2>&1',
			escapeshellarg( $temp_dir ),
			$compression_flag,
			escapeshellarg( $archive_path ),
			escapeshellarg( $name )
		);

		$output = array();
		$return_var = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_var );

		// Clean up temp files.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $temp_file );
		$this->recursive_rmdir( $temp_dir );

		// Reopen the zip archive.
		$result = $zip->open( $archive_path, ZipArchive::CREATE );
		if ( true !== $result ) {
			$this->logger->error( 'Failed to reopen zip after adding large file' );
			return false;
		}

		if ( 0 !== $return_var ) {
			$this->logger->error( 'System zip command failed', array(
				'return_code' => $return_var,
				'output'      => implode( "\n", $output ),
			) );
			return false;
		}

		$this->logger->debug( 'Large file added to archive via system zip' );
		return true;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function recursive_rmdir( string $dir ): void {
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
				@rmdir( $file->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}

	/**
	 * Create a manifest for the backup.
	 *
	 * @param array $files    Files included in backup.
	 * @param array $metadata Additional metadata.
	 * @return array Manifest data.
	 */
	private function create_manifest( array $files, array $metadata = array() ): array {
		global $wpdb;

		$file_list = array();
		foreach ( $files as $file ) {
			if ( is_array( $file ) ) {
				$path = $file['path'];
				$name = $file['name'] ?? basename( $path );
			} else {
				$path = $file;
				$name = basename( $file );
			}

			if ( file_exists( $path ) ) {
				$file_list[] = array(
					'name'     => $name,
					'size'     => filesize( $path ),
					'checksum' => md5_file( $path ),
				);
			}
		}

		return array(
			'version'           => SWISH_BACKUP_VERSION,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'site_url'          => get_site_url(),
			'home_url'          => get_home_url(),
			'table_prefix'      => $wpdb->prefix,
			'multisite'         => is_multisite(),
			'active_theme'      => get_template(),
			'active_plugins'    => get_option( 'active_plugins', array() ),
			'files'             => $file_list,
			'file_count'        => count( $file_list ),
			'metadata'          => $metadata,
		);
	}

	/**
	 * Calculate checksum of an archive.
	 *
	 * @param string $archive_path Path to the archive.
	 * @param string $algorithm    Hash algorithm.
	 * @return string|null Checksum or null on error.
	 */
	public function calculate_checksum( string $archive_path, string $algorithm = 'sha256' ): ?string {
		if ( ! file_exists( $archive_path ) ) {
			return null;
		}

		return hash_file( $algorithm, $archive_path );
	}

	/**
	 * Verify checksum of an archive.
	 *
	 * @param string $archive_path    Path to the archive.
	 * @param string $expected_checksum Expected checksum.
	 * @param string $algorithm       Hash algorithm.
	 * @return bool True if checksum matches.
	 */
	public function verify_checksum(
		string $archive_path,
		string $expected_checksum,
		string $algorithm = 'sha256'
	): bool {
		$actual = $this->calculate_checksum( $archive_path, $algorithm );
		return null !== $actual && hash_equals( $expected_checksum, $actual );
	}

	/**
	 * Split a large archive into parts.
	 *
	 * @param string $archive_path Path to the archive.
	 * @param int    $part_size    Size of each part in bytes.
	 * @return array|null Array of part paths or null on error.
	 */
	public function split_archive( string $archive_path, int $part_size = 104857600 ): ?array {
		if ( ! file_exists( $archive_path ) ) {
			return null;
		}

		$file_size = filesize( $archive_path );
		if ( $file_size <= $part_size ) {
			return array( $archive_path );
		}

		$parts = array();
		$part_num = 1;
		$base_path = preg_replace( '/\.zip$/i', '', $archive_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$source = fopen( $archive_path, 'rb' );
		if ( ! $source ) {
			return null;
		}

		while ( ! feof( $source ) ) {
			$part_path = sprintf( '%s.part%03d', $base_path, $part_num );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$dest = fopen( $part_path, 'wb' );

			if ( ! $dest ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $source );
				return null;
			}

			$bytes_written = 0;
			while ( $bytes_written < $part_size && ! feof( $source ) ) {
				$chunk_size = min( 8192, $part_size - $bytes_written );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $source, $chunk_size );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $dest, $chunk );
				$bytes_written += strlen( $chunk );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dest );
			$parts[] = $part_path;
			++$part_num;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $source );

		return $parts;
	}

	/**
	 * Join archive parts back together.
	 *
	 * @param array  $part_paths  Array of part file paths.
	 * @param string $output_path Output archive path.
	 * @return bool True if successful.
	 */
	public function join_archive_parts( array $part_paths, string $output_path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( $output_path, 'wb' );
		if ( ! $output ) {
			return false;
		}

		// Sort parts by name to ensure correct order.
		sort( $part_paths );

		foreach ( $part_paths as $part_path ) {
			if ( ! file_exists( $part_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $output );
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$part = fopen( $part_path, 'rb' );
			if ( ! $part ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $output );
				return false;
			}

			while ( ! feof( $part ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $part, 8192 );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $output, $chunk );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $part );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );

		return $this->verify_archive( $output_path );
	}
}
