<?php
/**
 * Tar Archiver for file backups.
 *
 * Uses system tar command to create compressed archives, bypassing
 * PHP memory limits and providing better performance for large backups.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Core\ServerLimits;
use SwishMigrateAndBackup\Logger\Logger;

/**
 * Creates tar.gz archives using system tar command.
 */
final class TarArchiver {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Check if tar archiver can be used.
	 *
	 * @return bool True if tar is available.
	 */
	public function is_available(): bool {
		return ServerLimits::is_tar_available();
	}

	/**
	 * Create a tar.gz archive from a directory.
	 *
	 * This method creates the archive in a streaming fashion,
	 * writing files directly to the archive without loading
	 * them into PHP memory.
	 *
	 * @param string        $archive_path     Path for the output archive.
	 * @param string        $source_dir       Directory to archive.
	 * @param array         $exclude_patterns Patterns to exclude (e.g., ['*.log', 'cache/*']).
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, error?: string, file_count?: int}
	 */
	public function create_archive(
		string $archive_path,
		string $source_dir,
		array $exclude_patterns = array(),
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		// Ensure source directory exists.
		if ( ! is_dir( $source_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Source directory does not exist: ' . $source_dir,
			);
		}

		// Ensure output directory exists.
		$output_dir = dirname( $archive_path );
		if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create output directory: ' . $output_dir,
			);
		}

		// Build exclude arguments.
		$exclude_args = $this->build_exclude_args( $exclude_patterns );

		// Determine compression flag.
		$compress_flag = ServerLimits::tar_supports_gzip() ? 'z' : '';

		// Build the tar command.
		// Using -C to change directory ensures paths in archive are relative.
		// Use nice to lower CPU priority and prevent server overload.
		$nice_prefix = $this->get_nice_prefix();
		$gzip_env = $compress_flag ? 'GZIP=-1 ' : '';

		$command = sprintf(
			'%s%star -c%sf %s %s -C %s .',
			$gzip_env,
			$nice_prefix,
			$compress_flag,
			escapeshellarg( $archive_path ),
			$exclude_args,
			escapeshellarg( $source_dir )
		);

		$this->logger->debug( 'Running tar command', array(
			'command'     => $command,
			'source'      => $source_dir,
			'destination' => $archive_path,
		) );

		// Execute the command.
		$output = array();
		$return_var = 0;

		// Report progress starting.
		if ( $progress_callback ) {
			$progress_callback( 0, 'Starting archive creation...' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command . ' 2>&1', $output, $return_var );

		if ( 0 !== $return_var ) {
			$error_message = implode( "\n", $output );
			$this->logger->error( 'Tar command failed', array(
				'return_code' => $return_var,
				'output'      => $error_message,
			) );

			return array(
				'success' => false,
				'error'   => 'Tar command failed: ' . $error_message,
			);
		}

		// Verify archive was created.
		if ( ! file_exists( $archive_path ) ) {
			$this->logger->error( 'Archive file was not created', array(
				'archive_path' => $archive_path,
				'source_dir'   => $source_dir,
				'command'      => $command,
			) );

			return array(
				'success' => false,
				'error'   => 'Archive file was not created.',
			);
		}

		$archive_size = filesize( $archive_path );

		// Verify archive has content (minimum valid tar.gz is ~20 bytes for empty).
		if ( $archive_size < 20 ) {
			$this->logger->error( 'Archive is empty or too small', array(
				'archive_path' => $archive_path,
				'size'         => $archive_size,
				'source_dir'   => $source_dir,
				'command'      => $command,
				'output'       => implode( "\n", $output ),
			) );

			// Clean up the empty file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $archive_path );

			return array(
				'success' => false,
				'error'   => sprintf( 'Archive is empty or too small (%d bytes). Source directory may be empty.', $archive_size ),
			);
		}

		// Report completion.
		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created successfully.' );
		}

		$this->logger->info( 'Tar archive created', array(
			'path' => $archive_path,
			'size' => ServerLimits::format_bytes( $archive_size ),
		) );

		return array(
			'success' => true,
			'path'    => $archive_path,
			'size'    => $archive_size,
		);
	}

	/**
	 * Create a tar.gz archive from a list of files.
	 *
	 * This method is more flexible than create_archive() as it allows
	 * specifying individual files rather than a whole directory.
	 *
	 * @param string        $archive_path     Path for the output archive.
	 * @param array         $files            Array of files with 'path' and 'name' keys.
	 * @param string        $base_dir         Base directory for relative paths.
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, error?: string, file_count?: int}
	 */
	public function create_archive_from_list(
		string $archive_path,
		array $files,
		string $base_dir,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		if ( empty( $files ) ) {
			return array(
				'success' => false,
				'error'   => 'No files provided for archiving.',
			);
		}

		// Ensure output directory exists.
		$output_dir = dirname( $archive_path );
		if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create output directory: ' . $output_dir,
			);
		}

		// Create a temporary file list.
		$temp_list = wp_tempnam( 'tar_files_' );
		if ( ! $temp_list ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create temporary file list.',
			);
		}

		// Write relative paths to the file list.
		$file_list_content = '';
		$file_count = 0;
		$base_dir_len = strlen( rtrim( $base_dir, '/\\' ) ) + 1;

		foreach ( $files as $file ) {
			$full_path = $file['path'] ?? $file;

			if ( ! file_exists( $full_path ) ) {
				continue;
			}

			// Get relative path from base directory.
			if ( 0 === strpos( $full_path, $base_dir ) ) {
				$relative_path = substr( $full_path, $base_dir_len );
			} else {
				$relative_path = basename( $full_path );
			}

			$file_list_content .= $relative_path . "\n";
			++$file_count;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_list, $file_list_content );

		// Determine compression flag.
		$compress_flag = ServerLimits::tar_supports_gzip() ? 'z' : '';

		// Build the tar command using the file list.
		// Use nice to lower CPU priority.
		$nice_prefix = $this->get_nice_prefix();
		$gzip_env = $compress_flag ? 'GZIP=-1 ' : '';

		$command = sprintf(
			'%s%star -c%sf %s -C %s -T %s',
			$gzip_env,
			$nice_prefix,
			$compress_flag,
			escapeshellarg( $archive_path ),
			escapeshellarg( $base_dir ),
			escapeshellarg( $temp_list )
		);

		$this->logger->debug( 'Running tar command with file list', array(
			'command'    => $command,
			'file_count' => $file_count,
			'base_dir'   => $base_dir,
		) );

		// Report progress.
		if ( $progress_callback ) {
			$progress_callback( 10, sprintf( 'Archiving %d files...', $file_count ) );
		}

		// Execute the command.
		$output = array();
		$return_var = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command . ' 2>&1', $output, $return_var );

		// Clean up temp file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $temp_list );

		if ( 0 !== $return_var ) {
			$error_message = implode( "\n", $output );
			$this->logger->error( 'Tar command failed', array(
				'return_code' => $return_var,
				'output'      => $error_message,
			) );

			return array(
				'success' => false,
				'error'   => 'Tar command failed: ' . $error_message,
			);
		}

		// Verify archive was created.
		if ( ! file_exists( $archive_path ) ) {
			$this->logger->error( 'Archive file was not created (file list mode)', array(
				'archive_path' => $archive_path,
				'command'      => $command,
			) );

			return array(
				'success' => false,
				'error'   => 'Archive file was not created.',
			);
		}

		$archive_size = filesize( $archive_path );

		// Verify archive has content.
		if ( $archive_size < 20 ) {
			$this->logger->error( 'Archive is empty or too small (file list mode)', array(
				'archive_path' => $archive_path,
				'size'         => $archive_size,
				'output'       => implode( "\n", $output ),
			) );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $archive_path );

			return array(
				'success' => false,
				'error'   => sprintf( 'Archive is empty (%d bytes).', $archive_size ),
			);
		}

		// Report completion.
		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created successfully.' );
		}

		$this->logger->info( 'Tar archive created from file list', array(
			'path'       => $archive_path,
			'size'       => ServerLimits::format_bytes( $archive_size ),
			'file_count' => $file_count,
		) );

		return array(
			'success'    => true,
			'path'       => $archive_path,
			'size'       => $archive_size,
			'file_count' => $file_count,
		);
	}

	/**
	 * Extract a tar.gz archive.
	 *
	 * @param string        $archive_path     Path to the archive.
	 * @param string        $destination_dir  Directory to extract to.
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, error?: string}
	 */
	public function extract_archive(
		string $archive_path,
		string $destination_dir,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file does not exist: ' . $archive_path,
			);
		}

		// Create destination directory.
		if ( ! is_dir( $destination_dir ) && ! wp_mkdir_p( $destination_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create destination directory: ' . $destination_dir,
			);
		}

		// Determine decompression flag based on file extension.
		$compress_flag = '';
		if ( preg_match( '/\.(gz|tgz)$/i', $archive_path ) ) {
			$compress_flag = 'z';
		} elseif ( preg_match( '/\.bz2$/i', $archive_path ) ) {
			$compress_flag = 'j';
		} elseif ( preg_match( '/\.xz$/i', $archive_path ) ) {
			$compress_flag = 'J';
		}

		// Build the tar extract command.
		$command = sprintf(
			'tar -x%sf %s -C %s',
			$compress_flag,
			escapeshellarg( $archive_path ),
			escapeshellarg( $destination_dir )
		);

		$this->logger->debug( 'Running tar extract command', array(
			'command'     => $command,
			'archive'     => $archive_path,
			'destination' => $destination_dir,
		) );

		// Report progress.
		if ( $progress_callback ) {
			$progress_callback( 0, 'Extracting archive...' );
		}

		// Execute the command.
		$output = array();
		$return_var = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command . ' 2>&1', $output, $return_var );

		if ( 0 !== $return_var ) {
			$error_message = implode( "\n", $output );
			$this->logger->error( 'Tar extract failed', array(
				'return_code' => $return_var,
				'output'      => $error_message,
			) );

			return array(
				'success' => false,
				'error'   => 'Tar extract failed: ' . $error_message,
			);
		}

		// Report completion.
		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive extracted successfully.' );
		}

		$this->logger->info( 'Tar archive extracted', array(
			'archive'     => $archive_path,
			'destination' => $destination_dir,
		) );

		return array(
			'success' => true,
		);
	}

	/**
	 * List contents of a tar.gz archive.
	 *
	 * @param string $archive_path Path to the archive.
	 * @return array{success: bool, files?: array, error?: string}
	 */
	public function list_contents( string $archive_path ): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file does not exist: ' . $archive_path,
			);
		}

		// Determine decompression flag.
		$compress_flag = '';
		if ( preg_match( '/\.(gz|tgz)$/i', $archive_path ) ) {
			$compress_flag = 'z';
		}

		// Build the tar list command.
		$command = sprintf(
			'tar -t%sf %s',
			$compress_flag,
			escapeshellarg( $archive_path )
		);

		// Execute the command.
		$output = array();
		$return_var = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command . ' 2>&1', $output, $return_var );

		if ( 0 !== $return_var ) {
			return array(
				'success' => false,
				'error'   => 'Failed to list archive contents.',
			);
		}

		return array(
			'success' => true,
			'files'   => $output,
		);
	}

	/**
	 * Get nice command prefix for lower CPU priority.
	 *
	 * This prevents tar from overwhelming the server, especially
	 * in containerized environments like ddev/Docker.
	 *
	 * @return string Nice prefix or empty string.
	 */
	private function get_nice_prefix(): string {
		// Check if nice is available.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$nice_path = @shell_exec( 'which nice 2>/dev/null' );

		if ( empty( trim( $nice_path ?? '' ) ) ) {
			return '';
		}

		// Use nice level 19 (lowest priority) to prevent server overload.
		// Also try ionice if available for I/O throttling.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$ionice_path = @shell_exec( 'which ionice 2>/dev/null' );

		if ( ! empty( trim( $ionice_path ?? '' ) ) ) {
			// ionice -c3 = idle class (only runs when nothing else needs I/O)
			return 'nice -n 19 ionice -c3 ';
		}

		return 'nice -n 19 ';
	}

	/**
	 * Build exclude arguments for tar command.
	 *
	 * @param array $patterns Array of exclude patterns.
	 * @return string Exclude arguments string.
	 */
	private function build_exclude_args( array $patterns ): string {
		if ( empty( $patterns ) ) {
			return '';
		}

		$args = array();
		foreach ( $patterns as $pattern ) {
			$args[] = '--exclude=' . escapeshellarg( $pattern );
		}

		return implode( ' ', $args );
	}

	/**
	 * Get the appropriate file extension for tar archives.
	 *
	 * @return string File extension (e.g., '.tar.gz' or '.tar').
	 */
	public function get_archive_extension(): string {
		return ServerLimits::tar_supports_gzip() ? '.tar.gz' : '.tar';
	}

	/**
	 * Create a backup archive directly from source files using tar with file list.
	 *
	 * This is the most efficient method for large sites because it:
	 * 1. Writes file paths to a temp file (no memory usage for file list)
	 * 2. Uses tar --files-from to read paths directly
	 * 3. Uses --transform to set proper archive paths
	 * 4. Streams directly to output (no staging)
	 *
	 * @param string        $archive_path      Path for the output archive.
	 * @param array         $files             Array of files with 'path' and 'relative' keys.
	 * @param string        $temp_dir          Temp directory with database.sql, manifest.json, etc.
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, size?: int, error?: string}
	 */
	public function create_backup_from_files(
		string $archive_path,
		array $files,
		string $temp_dir,
		?callable $progress_callback = null
	): array {
		// For large file lists, use the streaming method
		if ( count( $files ) > 1000 ) {
			return $this->create_backup_streaming( $archive_path, $files, $temp_dir, $progress_callback );
		}

		// For smaller file lists, use the original symlink method
		return $this->create_backup_with_symlinks( $archive_path, $files, $temp_dir, $progress_callback );
	}

	/**
	 * Create backup using streaming tar (for large sites).
	 *
	 * Uses tar --files-from to avoid loading file list into memory.
	 * Creates archive in a single pass without staging.
	 *
	 * @param string        $archive_path      Path for the output archive.
	 * @param array         $files             Array of files with 'path' and 'relative' keys.
	 * @param string        $temp_dir          Temp directory with database.sql, manifest.json, etc.
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, size?: int, error?: string}
	 */
	private function create_backup_streaming(
		string $archive_path,
		array $files,
		string $temp_dir,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		$this->logger->info( 'Creating backup using streaming tar method', array(
			'file_count' => count( $files ),
		) );

		if ( $progress_callback ) {
			$progress_callback( 0, 'Preparing file list...' );
		}

		// Step 1: Write file list to temp file (very fast, low memory)
		$file_list_path = $temp_dir . '/tar_file_list.txt';
		$file_count = count( $files );
		$handle = fopen( $file_list_path, 'w' );

		if ( ! $handle ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create file list.',
			);
		}

		// Write each file path on a line - use null separator for safety with special chars
		$written = 0;
		foreach ( $files as $file ) {
			$path = $file['path'] ?? '';
			if ( ! empty( $path ) && file_exists( $path ) ) {
				fwrite( $handle, $path . "\0" );
				++$written;
			}
		}
		fclose( $handle );

		if ( 0 === $written ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $file_list_path );
			return array(
				'success' => false,
				'error'   => 'No valid files to archive.',
			);
		}

		$this->logger->debug( 'File list created', array(
			'path'  => $file_list_path,
			'files' => $written,
		) );

		if ( $progress_callback ) {
			$progress_callback( 5, sprintf( 'Prepared %d files...', $written ) );
		}

		// Step 2: Determine compression and nice settings
		$compress_flag = ServerLimits::tar_supports_gzip() ? 'z' : '';
		$nice_prefix = $this->get_nice_prefix();
		$gzip_env = $compress_flag ? 'GZIP=-1 ' : '';

		// Step 3: Build tar command
		// Use --null to read null-separated file list
		// Use --transform to strip ABSPATH prefix and add 'files/' prefix
		$abspath = rtrim( ABSPATH, '/' );
		$transform_pattern = 's|^' . preg_quote( $abspath, '|' ) . '/|files/|';

		// First create archive with temp_dir content (small files)
		$temp_files = array();
		$temp_iterator = new \DirectoryIterator( $temp_dir );
		foreach ( $temp_iterator as $item ) {
			if ( $item->isFile() && $item->getFilename() !== 'tar_file_list.txt' ) {
				$temp_files[] = $item->getFilename();
			}
		}

		if ( $progress_callback ) {
			$progress_callback( 10, 'Creating archive with metadata...' );
		}

		// Create initial archive with metadata files from temp_dir
		if ( ! empty( $temp_files ) ) {
			$temp_file_args = implode( ' ', array_map( 'escapeshellarg', $temp_files ) );
			$init_command = sprintf(
				'%scd %s && %star -c%sf %s %s',
				$gzip_env,
				escapeshellarg( $temp_dir ),
				$nice_prefix,
				$compress_flag,
				escapeshellarg( $archive_path ),
				$temp_file_args
			);

			$output = array();
			$return_var = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( $init_command . ' 2>&1', $output, $return_var );

			if ( 0 !== $return_var ) {
				$this->logger->error( 'Failed to create initial archive', array(
					'command' => $init_command,
					'output'  => implode( "\n", $output ),
				) );
				// Continue anyway - we'll create the archive with just files
			}
		}

		if ( $progress_callback ) {
			$progress_callback( 15, sprintf( 'Archiving %d files...', $written ) );
		}

		// Step 4: Append WordPress files to archive
		// Note: Can't append to compressed archives, so we need to create uncompressed first
		// then compress, OR create everything in one go

		// For simplicity and reliability, create a fresh archive with everything
		// Remove the initial archive and create complete one

		if ( file_exists( $archive_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $archive_path );
		}

		// Create the complete archive in one tar command using multiple sources
		// 1. Metadata files from temp_dir (at root level)
		// 2. WordPress files from file list (under files/ directory)

		$temp_file_args = '';
		if ( ! empty( $temp_files ) ) {
			$temp_file_args = implode( ' ', array_map( 'escapeshellarg', $temp_files ) );
		}

		// Build the combined command
		// This creates an archive with:
		// - database.sql, manifest.json, wp-config.php at root
		// - All WordPress files under files/ directory
		$command = sprintf(
			'%s%star -c%sf %s -C %s %s --null --files-from=%s --transform=%s 2>&1',
			$gzip_env,
			$nice_prefix,
			$compress_flag,
			escapeshellarg( $archive_path ),
			escapeshellarg( $temp_dir ),
			$temp_file_args,
			escapeshellarg( $file_list_path ),
			escapeshellarg( $transform_pattern )
		);

		$this->logger->debug( 'Running streaming tar command', array(
			'command' => $command,
		) );

		// Execute tar command
		$output = array();
		$return_var = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_var );

		// Clean up file list
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file_list_path );

		// Check result
		if ( 0 !== $return_var ) {
			$error_msg = implode( "\n", $output );
			$this->logger->error( 'Streaming tar command failed', array(
				'return_code' => $return_var,
				'output'      => $error_msg,
			) );

			// Try fallback method
			$this->logger->info( 'Falling back to symlink-based method' );
			return $this->create_backup_with_symlinks( $archive_path, $files, $temp_dir, $progress_callback );
		}

		// Verify archive
		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file was not created.',
			);
		}

		$archive_size = filesize( $archive_path );

		if ( $archive_size < 100 ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Archive is too small (%d bytes).', $archive_size ),
			);
		}

		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created successfully.' );
		}

		$this->logger->info( 'Streaming backup archive created', array(
			'path'       => $archive_path,
			'size'       => ServerLimits::format_bytes( $archive_size ),
			'file_count' => $written,
		) );

		return array(
			'success'    => true,
			'path'       => $archive_path,
			'size'       => $archive_size,
			'file_count' => $written,
		);
	}

	/**
	 * Create backup using symlink staging (original method, for smaller sites).
	 *
	 * @param string        $archive_path      Path for the output archive.
	 * @param array         $files             Array of files with 'path' and 'relative' keys.
	 * @param string        $temp_dir          Temp directory with database.sql, manifest.json, etc.
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, size?: int, error?: string}
	 */
	private function create_backup_with_symlinks(
		string $archive_path,
		array $files,
		string $temp_dir,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		// Ensure output directory exists.
		$output_dir = dirname( $archive_path );
		if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create output directory: ' . $output_dir,
			);
		}

		if ( $progress_callback ) {
			$progress_callback( 0, 'Preparing file list...' );
		}

		// Create a temp file list with full paths and the archive names.
		$file_list_path = $temp_dir . '/tar_files.txt';
		$transform_file = $temp_dir . '/tar_transform.txt';
		$file_count = count( $files );

		// For reliability, we'll create a script that builds the archive in stages.
		// Stage 1: Create archive with temp dir contents (database.sql, manifest.json, etc.)
		// Stage 2: Append WordPress files using transform rules.

		// Determine compression flag.
		$compress_flag = ServerLimits::tar_supports_gzip() ? 'z' : '';

		// Stage 1: Archive temp dir contents (small files).
		$this->logger->debug( 'Stage 1: Creating archive with metadata files', array(
			'temp_dir' => $temp_dir,
		) );

		if ( $progress_callback ) {
			$progress_callback( 5, 'Archiving metadata files...' );
		}

		// Find all files in temp dir excluding our helper files.
		$temp_files = array();
		$temp_iterator = new \DirectoryIterator( $temp_dir );
		foreach ( $temp_iterator as $item ) {
			if ( $item->isFile() && ! in_array( $item->getFilename(), array( 'tar_files.txt', 'tar_transform.txt' ), true ) ) {
				$temp_files[] = $item->getFilename();
			}
		}

		if ( empty( $temp_files ) ) {
			// No metadata files - create empty manifest.
			$manifest_path = $temp_dir . '/manifest.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $manifest_path, '{}' );
			$temp_files[] = 'manifest.json';
		}

		// Create initial archive with temp dir contents.
		$temp_file_args = implode( ' ', array_map( 'escapeshellarg', $temp_files ) );
		$stage1_command = sprintf(
			'cd %s && tar -c%sf %s %s',
			escapeshellarg( $temp_dir ),
			$compress_flag,
			escapeshellarg( $archive_path ),
			$temp_file_args
		);

		$output = array();
		$return_var = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $stage1_command . ' 2>&1', $output, $return_var );

		if ( 0 !== $return_var ) {
			$this->logger->error( 'Stage 1 tar failed', array(
				'command' => $stage1_command,
				'output'  => implode( "\n", $output ),
			) );
			return array(
				'success' => false,
				'error'   => 'Failed to create initial archive: ' . implode( "\n", $output ),
			);
		}

		// Stage 2: Append WordPress files to the archive.
		// We need to use --append (-r) but that doesn't work with compressed archives.
		// So instead, we'll create the full archive in one go using a file list.

		// Actually, we can't append to compressed archives. Let's use a different approach:
		// Create the file list and use tar with transform to place files correctly.

		// Write file list for WordPress files.
		$file_list_content = '';
		$abspath = rtrim( ABSPATH, '/\\' );
		$abspath_len = strlen( $abspath ) + 1;

		foreach ( $files as $file ) {
			$full_path = $file['path'];
			if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
				$file_list_content .= $full_path . "\n";
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file_list_path, $file_list_content );

		$this->logger->debug( 'Stage 2: Appending WordPress files', array(
			'file_count' => $file_count,
		) );

		if ( $progress_callback ) {
			$progress_callback( 15, sprintf( 'Archiving %d files...', $file_count ) );
		}

		// For the main files, we need to recreate the full archive including everything.
		// The approach: create one big tar with all content.

		// First, remove the stage 1 archive since we'll recreate it properly.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $archive_path );

		// Create combined archive: temp files + WordPress files with proper paths.
		// We'll use tar with -C to change directories and include both sets of files.

		// Build the final command.
		// This creates archive with:
		// 1. Contents of temp_dir (database.sql, manifest.json, etc.) at root
		// 2. WordPress files under 'files/' directory with relative paths.

		// Create a wrapper staging approach that's more efficient:
		// Instead of copying files, create symlinks in a structure (faster).
		$staging_dir = $temp_dir . '/archive_staging';
		wp_mkdir_p( $staging_dir . '/files' );

		// Move temp files to staging root.
		foreach ( $temp_files as $tf ) {
			$src = $temp_dir . '/' . $tf;
			$dst = $staging_dir . '/' . $tf;
			if ( file_exists( $src ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				@rename( $src, $dst );
			}
		}

		// For WordPress files, create symlinks in staging/files/ (much faster than copying).
		$symlink_count = 0;
		$symlink_failed = 0;
		$batch_size = 500;
		$processed = 0;
		$last_progress_update = microtime( true );

		foreach ( $files as $file ) {
			$full_path = $file['path'];
			$relative = $file['relative'] ?? '';

			if ( empty( $relative ) || ! file_exists( $full_path ) ) {
				continue;
			}

			$link_path = $staging_dir . '/files/' . $relative;
			$link_dir = dirname( $link_path );

			if ( ! is_dir( $link_dir ) ) {
				wp_mkdir_p( $link_dir );
			}

			// Create symlink (much faster than copy/hard link).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.symlink_symlink
			if ( @symlink( $full_path, $link_path ) ) {
				++$symlink_count;
			} else {
				++$symlink_failed;
			}

			++$processed;

			// Progress update every batch or 500ms.
			$now = microtime( true );
			if ( $processed % $batch_size === 0 || $now - $last_progress_update >= 0.5 ) {
				$progress = 15 + (int) ( ( $processed / $file_count ) * 50 ); // 15-65%
				if ( $progress_callback ) {
					$progress_callback( $progress, sprintf( 'Preparing files... %d/%d', $processed, $file_count ) );
				}
				$last_progress_update = $now;
			}

			// Check for timeout.
			if ( $processed % 1000 === 0 && ServerLimits::is_approaching_time_limit( 15 ) ) {
				$this->logger->warning( 'Approaching time limit during symlink creation', array(
					'processed' => $processed,
					'total'     => $file_count,
				) );
				// Clean up and return error - let the backup manager handle chunking.
				$this->cleanup_staging( $staging_dir );
				return array(
					'success' => false,
					'error'   => 'Timeout approaching during archive preparation. Backup will continue.',
					'timeout' => true,
				);
			}
		}

		$this->logger->debug( 'Symlinks created', array(
			'success' => $symlink_count,
			'failed'  => $symlink_failed,
		) );

		// Now create the final tar from staging directory.
		if ( $progress_callback ) {
			$progress_callback( 70, 'Compressing archive...' );
		}

		// Use tar with -h to dereference symlinks (follow them).
		// Use nice to lower CPU priority and prevent overwhelming the server.
		// Use lower compression level (gzip -1) for speed over size.
		$nice_prefix = $this->get_nice_prefix();
		$gzip_env = $compress_flag ? 'GZIP=-1 ' : '';

		$final_command = sprintf(
			'%scd %s && %star -c%shf %s .',
			$gzip_env,
			escapeshellarg( $staging_dir ),
			$nice_prefix,
			$compress_flag,
			escapeshellarg( $archive_path )
		);

		$this->logger->debug( 'Running final tar command', array(
			'command' => $final_command,
		) );

		$output = array();
		$return_var = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $final_command . ' 2>&1', $output, $return_var );

		// Cleanup staging directory.
		$this->cleanup_staging( $staging_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file_list_path );

		if ( 0 !== $return_var ) {
			$error_message = implode( "\n", $output );
			$this->logger->error( 'Final tar command failed', array(
				'return_code' => $return_var,
				'output'      => $error_message,
			) );

			return array(
				'success' => false,
				'error'   => 'Tar command failed: ' . $error_message,
			);
		}

		// Verify archive.
		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file was not created.',
			);
		}

		$archive_size = filesize( $archive_path );

		if ( $archive_size < 100 ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Archive is too small (%d bytes).', $archive_size ),
			);
		}

		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created successfully.' );
		}

		$this->logger->info( 'Backup archive created', array(
			'path'       => $archive_path,
			'size'       => ServerLimits::format_bytes( $archive_size ),
			'file_count' => $symlink_count,
		) );

		return array(
			'success'    => true,
			'path'       => $archive_path,
			'size'       => $archive_size,
			'file_count' => $symlink_count,
		);
	}

	/**
	 * Clean up staging directory with symlinks.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function cleanup_staging( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $item->getPathname() );
			} elseif ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}

	/**
	 * Create backup from a file list on disk (most memory efficient).
	 *
	 * This method reads file paths from a disk file and creates the tar archive
	 * in a single streaming operation. No file list is held in memory.
	 *
	 * @param string        $archive_path      Path for the output archive.
	 * @param string        $file_list_path    Path to file containing paths (null-separated).
	 * @param string        $temp_dir          Temp directory with database.sql, manifest.json, etc.
	 * @param int           $total_files       Total file count (for progress).
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, size?: int, error?: string}
	 */
	public function create_backup_from_file_list(
		string $archive_path,
		string $file_list_path,
		string $temp_dir,
		int $total_files,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		if ( ! file_exists( $file_list_path ) ) {
			return array(
				'success' => false,
				'error'   => 'File list does not exist.',
			);
		}

		$this->logger->info( 'Creating backup from file list', array(
			'file_list'   => $file_list_path,
			'total_files' => $total_files,
		) );

		if ( $progress_callback ) {
			$progress_callback( 0, 'Starting archive creation...' );
		}

		// Collect metadata files from temp_dir.
		$temp_files = array();
		$temp_iterator = new \DirectoryIterator( $temp_dir );
		foreach ( $temp_iterator as $item ) {
			if ( $item->isFile() && $item->getFilename() !== 'file_list.txt' ) {
				$temp_files[] = $item->getFilename();
			}
		}

		// Determine compression and nice settings.
		$compress_flag = ServerLimits::tar_supports_gzip() ? 'z' : '';
		$nice_prefix = $this->get_nice_prefix();
		$gzip_env = $compress_flag ? 'GZIP=-1 ' : '';

		// Build transform pattern to put WordPress files under files/ directory.
		$abspath = rtrim( ABSPATH, '/' );
		$transform_pattern = 's|^' . preg_quote( $abspath, '|' ) . '/|files/|';

		// Build the tar command.
		// This creates archive with:
		// - Metadata files (database.sql, manifest.json, wp-config.php) at root
		// - WordPress files under files/ directory
		$temp_file_args = '';
		if ( ! empty( $temp_files ) ) {
			$temp_file_args = implode( ' ', array_map( 'escapeshellarg', $temp_files ) );
		}

		if ( $progress_callback ) {
			$progress_callback( 10, sprintf( 'Compressing %d files...', $total_files ) );
		}

		$command = sprintf(
			'%s%star -c%sf %s -C %s %s --null --files-from=%s --transform=%s 2>&1',
			$gzip_env,
			$nice_prefix,
			$compress_flag,
			escapeshellarg( $archive_path ),
			escapeshellarg( $temp_dir ),
			$temp_file_args,
			escapeshellarg( $file_list_path ),
			escapeshellarg( $transform_pattern )
		);

		$this->logger->debug( 'Running tar command from file list', array(
			'command' => $command,
		) );

		// Execute tar command.
		$output = array();
		$return_var = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_var );

		// Check result.
		if ( 0 !== $return_var ) {
			$error_msg = implode( "\n", $output );
			$this->logger->error( 'Tar command failed', array(
				'return_code' => $return_var,
				'output'      => $error_msg,
			) );

			return array(
				'success' => false,
				'error'   => 'Tar command failed: ' . $error_msg,
			);
		}

		// Verify archive.
		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file was not created.',
			);
		}

		$archive_size = filesize( $archive_path );

		if ( $archive_size < 100 ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Archive is too small (%d bytes).', $archive_size ),
			);
		}

		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created successfully.' );
		}

		$this->logger->info( 'Backup archive created from file list', array(
			'path'       => $archive_path,
			'size'       => ServerLimits::format_bytes( $archive_size ),
			'file_count' => $total_files,
		) );

		return array(
			'success'    => true,
			'path'       => $archive_path,
			'size'       => $archive_size,
			'file_count' => $total_files,
		);
	}

	/**
	 * Create a complete backup archive with all components.
	 *
	 * Creates a tar.gz that includes:
	 * - All backup files from the staging directory
	 * - Database SQL file
	 * - Manifest JSON
	 * - Any additional files (wp-config, .htaccess, etc.)
	 *
	 * This is more efficient than creating separate archives and combining them.
	 *
	 * @param string        $archive_path      Path for the output archive.
	 * @param string        $staging_dir       Directory containing all backup content.
	 * @param callable|null $progress_callback Optional callback for progress updates.
	 * @return array{success: bool, path?: string, size?: int, error?: string}
	 */
	public function create_complete_backup(
		string $archive_path,
		string $staging_dir,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'error'   => 'Tar command is not available on this system.',
			);
		}

		// Ensure staging directory exists.
		if ( ! is_dir( $staging_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Staging directory does not exist: ' . $staging_dir,
			);
		}

		// Ensure output directory exists.
		$output_dir = dirname( $archive_path );
		if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create output directory: ' . $output_dir,
			);
		}

		// Report starting.
		if ( $progress_callback ) {
			$progress_callback( 0, 'Creating backup archive...' );
		}

		// Count files for progress reporting.
		$file_count = $this->count_files_in_directory( $staging_dir );

		// Validate staging directory has content.
		if ( 0 === $file_count ) {
			$this->logger->error( 'Staging directory is empty', array(
				'staging_dir' => $staging_dir,
			) );

			return array(
				'success' => false,
				'error'   => 'Staging directory is empty - no files to archive.',
			);
		}

		$this->logger->info( 'Creating complete backup archive', array(
			'staging_dir' => $staging_dir,
			'output'      => $archive_path,
			'file_count'  => $file_count,
		) );

		// Determine compression flag.
		$compress_flag = ServerLimits::tar_supports_gzip() ? 'z' : '';

		// Build the tar command.
		// Note: Using simple exec() for reliability instead of proc_open.
		// Use nice to lower CPU priority and prevent overwhelming the server.
		$nice_prefix = $this->get_nice_prefix();
		$gzip_env = $compress_flag ? 'GZIP=-1 ' : '';

		$command = sprintf(
			'%scd %s && %star -c%sf %s .',
			$gzip_env,
			escapeshellarg( $staging_dir ),
			$nice_prefix,
			$compress_flag,
			escapeshellarg( $archive_path )
		);

		$this->logger->debug( 'Running tar command', array(
			'command' => $command,
		) );

		// Report progress.
		if ( $progress_callback ) {
			$progress_callback( 10, sprintf( 'Compressing %d files...', $file_count ) );
		}

		// Execute the command.
		$output = array();
		$return_var = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command . ' 2>&1', $output, $return_var );

		// Check for errors.
		if ( 0 !== $return_var ) {
			$error_message = implode( "\n", $output );
			$this->logger->error( 'Tar command failed', array(
				'return_code' => $return_var,
				'output'      => $error_message,
				'command'     => $command,
			) );

			return array(
				'success' => false,
				'error'   => 'Tar command failed (code ' . $return_var . '): ' . $error_message,
			);
		}

		// Verify archive was created and has content.
		if ( ! file_exists( $archive_path ) ) {
			$this->logger->error( 'Archive file was not created', array(
				'archive_path' => $archive_path,
				'output'       => implode( "\n", $output ),
			) );

			return array(
				'success' => false,
				'error'   => 'Archive file was not created.',
			);
		}

		$archive_size = filesize( $archive_path );

		// Verify archive is not empty.
		if ( $archive_size < 100 ) {
			$this->logger->error( 'Archive is too small or empty', array(
				'archive_path' => $archive_path,
				'size'         => $archive_size,
				'output'       => implode( "\n", $output ),
			) );

			return array(
				'success' => false,
				'error'   => sprintf( 'Archive is too small (%d bytes) - tar may have failed.', $archive_size ),
			);
		}

		// Report completion.
		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created successfully.' );
		}

		$this->logger->info( 'Complete backup archive created', array(
			'path'       => $archive_path,
			'size'       => ServerLimits::format_bytes( $archive_size ),
			'file_count' => $file_count,
		) );

		return array(
			'success'    => true,
			'path'       => $archive_path,
			'size'       => $archive_size,
			'file_count' => $file_count,
		);
	}

	/**
	 * Count files in a directory recursively.
	 *
	 * @param string $directory Directory path.
	 * @return int Number of files.
	 */
	private function count_files_in_directory( string $directory ): int {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$count = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				++$count;
			}
		}

		return $count;
	}
}
