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

				if ( file_exists( $path ) ) {
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
