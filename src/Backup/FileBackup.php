<?php
/**
 * File Backup Handler.
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
use SwishMigrateAndBackup\Backup\TarArchiver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Generator;

/**
 * Handles file backup operations with chunked processing.
 *
 * Optimized for managed hosting environments like WP Engine with:
 * - Generator-based file scanning (low memory)
 * - Immediate file reading (no queuing)
 * - Frequent checkpoint saves
 * - Aggressive timeout detection
 */
final class FileBackup {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Backup state manager.
	 *
	 * @var BackupState|null
	 */
	private ?BackupState $state = null;

	/**
	 * Tar archiver instance.
	 *
	 * @var TarArchiver|null
	 */
	private ?TarArchiver $tar_archiver = null;

	/**
	 * Files/patterns to exclude.
	 *
	 * @var array
	 */
	private array $exclude_patterns = array();

	/**
	 * Maximum files per batch.
	 *
	 * @var int
	 */
	private int $files_per_batch = 50;

	/**
	 * Whether to include WordPress core files.
	 *
	 * @var bool
	 */
	private bool $include_core = false;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;

		// Set default exclusions.
		$this->exclude_patterns = array(
			'*.log',
			'*.tmp',
			'*.swp',
			'.git',
			'.svn',
			'node_modules',
			'wp-content/cache',
			'swish-backups',
			'wp-content/debug.log',
			'error_log',
		);
	}

	/**
	 * Get or create the backup state manager.
	 *
	 * @return BackupState
	 */
	private function get_state(): BackupState {
		if ( null === $this->state ) {
			$this->state = new BackupState();
		}
		return $this->state;
	}

	/**
	 * Get or create the tar archiver.
	 *
	 * @return TarArchiver
	 */
	private function get_tar_archiver(): TarArchiver {
		if ( null === $this->tar_archiver ) {
			$this->tar_archiver = new TarArchiver( $this->logger );
		}
		return $this->tar_archiver;
	}

	/**
	 * WordPress core files and directories to exclude.
	 *
	 * @return array
	 */
	private function get_wp_core_patterns(): array {
		return array(
			// Core directories.
			'wp-admin',
			'wp-includes',
			// Core root files.
			'index.php',
			'license.txt',
			'readme.html',
			'wp-activate.php',
			'wp-blog-header.php',
			'wp-comments-post.php',
			'wp-config-sample.php',
			'wp-cron.php',
			'wp-links-opml.php',
			'wp-load.php',
			'wp-login.php',
			'wp-mail.php',
			'wp-settings.php',
			'wp-signup.php',
			'wp-trackback.php',
			'xmlrpc.php',
		);
	}

	/**
	 * Set whether to include WordPress core files.
	 *
	 * @param bool $include Whether to include core files.
	 * @return self
	 */
	public function set_include_core( bool $include ): self {
		$this->include_core = $include;
		return $this;
	}

	/**
	 * Get whether WordPress core files are included.
	 *
	 * @return bool
	 */
	public function get_include_core(): bool {
		return $this->include_core;
	}

	/**
	 * Set exclude patterns.
	 *
	 * @param array $patterns Patterns to exclude.
	 * @return self
	 */
	public function set_exclude_patterns( array $patterns ): self {
		$this->exclude_patterns = array_merge( $this->exclude_patterns, $patterns );
		return $this;
	}

	/**
	 * Set the number of files per batch.
	 *
	 * @param int $files_per_batch Files per batch (10-500).
	 * @return self
	 */
	public function set_files_per_batch( int $files_per_batch ): self {
		$this->files_per_batch = max( 10, min( 500, $files_per_batch ) );
		return $this;
	}

	/**
	 * Get the current files per batch setting.
	 *
	 * @return int
	 */
	public function get_files_per_batch(): int {
		return $this->files_per_batch;
	}

	/**
	 * Generator-based file scanning.
	 *
	 * Yields files one at a time instead of loading all into memory.
	 * Checks for timeout during scanning.
	 *
	 * @param array $directories Directories to scan.
	 * @return Generator Yields file data arrays.
	 */
	public function scan_files_generator( array $directories ): Generator {
		$file_count = 0;
		$check_interval = max( 500, ServerLimits::get_timeout_check_interval() * 10 );

		foreach ( $directories as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator(
						$directory,
						RecursiveDirectoryIterator::SKIP_DOTS
					),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $file ) {
					$path = $file->getPathname();

					if ( $this->should_exclude( $path ) ) {
						continue;
					}

					if ( $file->isFile() && $file->isReadable() ) {
						yield array(
							'path'     => $path,
							'relative' => $this->get_relative_path( $path ),
							'size'     => $file->getSize(),
						);

						++$file_count;

						// Periodic timeout check during scanning.
						if ( 0 === $file_count % $check_interval ) {
							// Check timeout with a larger threshold during scanning.
							if ( ServerLimits::is_approaching_time_limit( ServerLimits::get_safe_timeout_threshold() + 10 ) ) {
								$this->logger->warning( 'Approaching timeout during file scan', array(
									'files_found' => $file_count,
								) );
								return; // Stop scanning.
							}

							// Periodic memory cleanup.
							if ( function_exists( 'gc_collect_cycles' ) ) {
								gc_collect_cycles();
							}
						}
					}
				}
			} catch ( \Exception $e ) {
				$this->logger->warning( 'Error scanning directory: ' . $e->getMessage(), array(
					'directory' => $directory,
				) );
			}
		}
	}

	/**
	 * Scan files and write directly to state file.
	 *
	 * This avoids loading the entire file list into memory.
	 *
	 * @param string $job_id      Job ID.
	 * @param array  $directories Directories to scan.
	 * @return array Result with count, timeout status.
	 */
	public function scan_files_to_state( string $job_id, array $directories ): array {
		ServerLimits::init_timing();
		$state = $this->get_state();

		$this->logger->info( 'Starting file scan', array(
			'directories'   => count( $directories ),
			'server_limits' => ServerLimits::get_debug_info(),
		) );

		$file_count = 0;
		$batch = array();
		$batch_size = 500; // Write to disk every 500 files.
		$timed_out = false;
		$check_interval = max( 200, ServerLimits::get_timeout_check_interval() * 5 );

		foreach ( $directories as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator(
						$directory,
						RecursiveDirectoryIterator::SKIP_DOTS
					),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $file ) {
					$path = $file->getPathname();

					if ( $this->should_exclude( $path ) ) {
						continue;
					}

					if ( $file->isFile() && $file->isReadable() ) {
						$batch[] = array(
							'path'     => $path,
							'relative' => $this->get_relative_path( $path ),
							'size'     => $file->getSize(),
						);

						++$file_count;

						// Write batch to disk periodically.
						if ( count( $batch ) >= $batch_size ) {
							if ( 0 === $file_count - count( $batch ) ) {
								// First batch - create new file.
								$state->save_file_list( $job_id, $batch );
							} else {
								// Append to existing file.
								$state->append_to_file_list( $job_id, $batch );
							}
							$batch = array();

							// Garbage collection.
							if ( function_exists( 'gc_collect_cycles' ) ) {
								gc_collect_cycles();
							}
						}

						// Check timeout periodically.
						if ( 0 === $file_count % $check_interval ) {
							if ( ServerLimits::is_approaching_time_limit( ServerLimits::get_safe_timeout_threshold() + 5 ) ) {
								$timed_out = true;
								$this->logger->warning( 'Timeout during file scan', array(
									'files_found' => $file_count,
								) );
								break 2; // Exit both loops.
							}
						}
					}
				}
			} catch ( \Exception $e ) {
				$this->logger->warning( 'Error scanning directory: ' . $e->getMessage(), array(
					'directory' => $directory,
				) );
			}
		}

		// Write remaining batch.
		if ( ! empty( $batch ) ) {
			if ( 0 === $file_count - count( $batch ) ) {
				$state->save_file_list( $job_id, $batch );
			} else {
				$state->append_to_file_list( $job_id, $batch );
			}
		}

		$this->logger->info( 'File scan completed', array(
			'file_count' => $file_count,
			'timed_out'  => $timed_out,
		) );

		return array(
			'count'     => $file_count,
			'timed_out' => $timed_out,
		);
	}

	/**
	 * Get list of files to backup (legacy method for compatibility).
	 *
	 * @param array $directories Directories to scan.
	 * @return array List of file paths.
	 */
	public function get_file_list( array $directories ): array {
		$files = array();
		$file_count = 0;

		foreach ( $this->scan_files_generator( $directories ) as $file ) {
			$files[] = $file;
			++$file_count;

			// Safety limit - don't load too many files into memory.
			if ( $file_count >= 50000 ) {
				$this->logger->warning( 'File list truncated at 50000 files - use scan_files_to_state for large sites' );
				break;
			}
		}

		return $files;
	}

	/**
	 * Get default directories to backup.
	 *
	 * @param array $options Backup options.
	 * @return array Directories to backup.
	 */
	public function get_backup_directories( array $options = array() ): array {
		$directories = array();

		// WordPress core files - excluded by default.
		$this->include_core = $options['backup_core_files'] ?? false;

		if ( $this->include_core ) {
			$directories[] = ABSPATH;
		}

		// Plugins.
		if ( $options['backup_plugins'] ?? true ) {
			$directories[] = WP_PLUGIN_DIR;
		}

		// Themes.
		if ( $options['backup_themes'] ?? true ) {
			$directories[] = get_theme_root();
		}

		// Uploads.
		if ( $options['backup_uploads'] ?? true ) {
			$upload_dir = wp_upload_dir();
			$directories[] = $upload_dir['basedir'];
		}

		// Custom directories.
		if ( ! empty( $options['custom_directories'] ) ) {
			$directories = array_merge( $directories, $options['custom_directories'] );
		}

		return array_unique( array_filter( $directories, 'is_dir' ) );
	}

	/**
	 * Create a backup of specified files.
	 *
	 * Uses tar.gz when available (faster, bypasses PHP memory limits).
	 * Falls back to batch ZIP approach for compatibility.
	 *
	 * @param array         $files             Files to backup.
	 * @param string        $output_path       Output zip file path.
	 * @param callable|null $progress_callback Progress callback.
	 * @return bool|array True/parts array if successful, false or timeout array on failure.
	 */
	public function backup( array $files, string $output_path, ?callable $progress_callback = null ) {
		// Initialize server limits tracking.
		ServerLimits::init_timing();
		$limits_debug = ServerLimits::get_debug_info();

		$total_files = count( $files );

		// Check if we should use tar.gz instead of ZIP.
		if ( ServerLimits::should_use_tar() ) {
			$this->logger->info( 'Using tar.gz for file backup (faster, bypasses PHP limits)', array(
				'file_count'    => $total_files,
				'server_limits' => $limits_debug,
			) );

			return $this->backup_with_tar( $files, $output_path, $progress_callback );
		}

		$this->logger->info( 'Starting file backup (batch ZIP mode)', array(
			'file_count'    => $total_files,
			'server_limits' => $limits_debug,
		) );

		try {
			$start_time = microtime( true );
			$output_dir = dirname( $output_path );
			$base_name = pathinfo( $output_path, PATHINFO_FILENAME );

			// Batch settings - create smaller ZIPs to avoid reopen overhead.
			// Each batch is created fresh and closed, never reopened.
			$files_per_batch = $this->get_optimal_batch_size( $total_files );
			$timeout_check_interval = ServerLimits::get_timeout_check_interval();
			$timeout_threshold = ServerLimits::get_safe_timeout_threshold();

			// For small file counts, skip batching entirely.
			$use_batching = $total_files > $files_per_batch;

			$this->logger->debug( 'Batch backup settings', array(
				'files_per_batch' => $files_per_batch,
				'use_batching'    => $use_batching,
				'timeout_check'   => $timeout_check_interval,
				'timeout_threshold' => $timeout_threshold,
			) );

			$processed = 0;
			$part_num = 1;
			$batch_files = array();
			$created_parts = array();
			$skipped_files = array();
			$last_progress_time = $start_time;

			foreach ( $files as $index => $file ) {
				$batch_files[] = $file;
				++$processed;

				// Check if batch is full OR this is the last file.
				$batch_full = count( $batch_files ) >= $files_per_batch;
				$is_last_file = $processed === $total_files;

				if ( $batch_full || $is_last_file ) {
					// Determine the path for this batch.
					if ( ! $use_batching ) {
						// Single batch - use output path directly.
						$part_path = $output_path;
					} else {
						// Multiple batches - use numbered part files.
						$part_path = $output_dir . '/' . $base_name . '-part-' . sprintf( '%03d', $part_num ) . '.zip';
					}

					$batch_result = $this->create_batch_zip( $part_path, $batch_files );

					if ( ! $batch_result['success'] ) {
						$this->logger->error( 'Failed to create batch ZIP', array(
							'part'  => $part_num,
							'error' => $batch_result['error'] ?? 'Unknown error',
						) );
						return false;
					}

					$created_parts[] = $part_path;
					$skipped_files = array_merge( $skipped_files, $batch_result['skipped'] ?? array() );

					// Clear batch for next round.
					$batch_files = array();
					++$part_num;

					// Progress callback after each batch.
					$now = microtime( true );
					if ( $progress_callback ) {
						$progress = (int) ( ( $processed / $total_files ) * 100 );
						$progress_callback( $progress, "Batch " . ( $part_num - 1 ) . " completed", $processed, $total_files, 0 );
						$last_progress_time = $now;
					}

					// Garbage collection between batches.
					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}

					// Check timeout AFTER completing a batch (clean breakpoint).
					if ( ! $is_last_file && ServerLimits::is_approaching_time_limit( $timeout_threshold ) ) {
						$this->logger->warning( 'Backup approaching time limit, saving progress', array(
							'processed'      => $processed,
							'total'          => $total_files,
							'parts_created'  => count( $created_parts ),
							'elapsed'        => ServerLimits::get_elapsed_time(),
						) );

						// Return timeout state for resumable processing.
						return array(
							'timeout'         => true,
							'processed'       => $processed,
							'total'           => $total_files,
							'output_path'     => $output_path,
							'created_parts'   => $created_parts,
							'remaining_files' => array_slice( $files, $processed ),
						);
					}
				} else {
					// Progress update within batch (every 50 files or 500ms).
					$now = microtime( true );
					if ( $progress_callback && ( 0 === $processed % 50 || $now - $last_progress_time >= 0.5 ) ) {
						$progress = (int) ( ( $processed / $total_files ) * 100 );
						$progress_callback( $progress, $file['relative'], $processed, $total_files, 0 );
						$last_progress_time = $now;
					}
				}
			}

			$elapsed = microtime( true ) - $start_time;

			$this->logger->info( 'File backup completed', array(
				'files'         => $total_files,
				'skipped'       => count( $skipped_files ),
				'parts'         => count( $created_parts ),
				'use_batching'  => $use_batching,
				'elapsed_time'  => round( $elapsed, 2 ) . 's',
				'files_per_sec' => round( $total_files / max( $elapsed, 0.001 ), 1 ),
			) );

			// Return the created parts for BackupManager to include in final archive.
			// For single part, just return true (backward compatible).
			// For multiple parts, return array with parts list.
			if ( ! $use_batching ) {
				return true;
			}

			return array(
				'success' => true,
				'parts'   => $created_parts,
				'total'   => $total_files,
			);
		} catch ( \Exception $e ) {
			$this->logger->error( 'File backup failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Create a backup using tar.gz format.
	 *
	 * Uses system tar command which bypasses PHP memory limits and
	 * provides much better performance for large backups.
	 *
	 * @param array         $files             Files to backup.
	 * @param string        $output_path       Output path (will be changed to .tar.gz).
	 * @param callable|null $progress_callback Progress callback.
	 * @return bool|array True if successful, false on failure.
	 */
	private function backup_with_tar( array $files, string $output_path, ?callable $progress_callback = null ) {
		$tar_archiver = $this->get_tar_archiver();
		$start_time = microtime( true );
		$total_files = count( $files );

		// Change output extension to .tar.gz.
		$output_dir = dirname( $output_path );
		$base_name = pathinfo( $output_path, PATHINFO_FILENAME );
		$tar_output = $output_dir . '/' . $base_name . $tar_archiver->get_archive_extension();

		// Report starting.
		if ( $progress_callback ) {
			$progress_callback( 0, 'Starting tar.gz archive creation...', 0, $total_files, 0 );
		}

		// Use directory-based backup if all files are from common directories.
		// This is more efficient as tar can handle entire directories at once.
		$directories = $this->detect_common_directories( $files );

		if ( ! empty( $directories ) && count( $directories ) <= 5 ) {
			// Create archive from directories.
			$this->logger->debug( 'Using directory-based tar archive', array(
				'directories' => $directories,
			) );

			// Create a temporary directory structure.
			$temp_base = $output_dir . '/swish-tar-temp-' . uniqid();
			if ( ! wp_mkdir_p( $temp_base ) ) {
				$this->logger->error( 'Failed to create temp staging directory', array(
					'path' => $temp_base,
				) );
				return false;
			}

			// Create symlinks or copy files to temp structure.
			// This ensures proper relative paths in the archive.
			$files_linked = 0;
			$files_failed = 0;
			foreach ( $files as $file ) {
				$relative = $file['relative'];
				$source = $file['path'];

				if ( ! file_exists( $source ) ) {
					++$files_failed;
					continue;
				}

				$dest_path = $temp_base . '/' . $relative;
				$dest_dir = dirname( $dest_path );

				if ( ! is_dir( $dest_dir ) ) {
					if ( ! wp_mkdir_p( $dest_dir ) ) {
						++$files_failed;
						continue;
					}
				}

				// Create hard link or copy (hard links are faster).
				$staged = false;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.link_link
				if ( @link( $source, $dest_path ) ) {
					$staged = true;
				} else {
					// Fallback to copy if link fails (cross-device).
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
					if ( @copy( $source, $dest_path ) ) {
						$staged = true;
					}
				}

				if ( $staged ) {
					++$files_linked;
				} else {
					++$files_failed;
					if ( $files_failed <= 3 ) {
						$this->logger->warning( 'Failed to stage file', array(
							'source' => $source,
							'dest'   => $dest_path,
						) );
					}
				}

				// Progress update every 100 files.
				if ( $progress_callback && 0 === $files_linked % 100 ) {
					$progress = (int) ( ( $files_linked / $total_files ) * 50 ); // 0-50% for file linking.
					$progress_callback( $progress, 'Preparing files...', $files_linked, $total_files, 0 );
				}
			}

			// Verify files were staged.
			if ( 0 === $files_linked ) {
				$this->logger->error( 'No files were staged for tar archive', array(
					'total_files'  => $total_files,
					'files_failed' => $files_failed,
				) );
				$this->recursive_delete( $temp_base );
				return false;
			}

			$this->logger->info( 'Files staged for tar archive', array(
				'staged' => $files_linked,
				'failed' => $files_failed,
			) );

			// Report progress.
			if ( $progress_callback ) {
				$progress_callback( 50, 'Creating tar.gz archive...', $files_linked, $total_files, 0 );
			}

			// Create the tar.gz archive from the temp directory.
			// Note: Don't pass exclude patterns - files are already filtered and
			// patterns like 'wp-content/cache' would incorrectly exclude staged files.
			$result = $tar_archiver->create_archive(
				$tar_output,
				$temp_base,
				array(), // No exclude patterns for staging directory
				function ( $percent, $message ) use ( $progress_callback, $total_files ) {
					if ( $progress_callback ) {
						// Map 0-100 from tar to 50-100 overall.
						$overall_progress = 50 + (int) ( $percent / 2 );
						$progress_callback( $overall_progress, $message, $total_files, $total_files, 0 );
					}
				}
			);

			// Clean up temp directory.
			$this->recursive_delete( $temp_base );

			if ( ! $result['success'] ) {
				$this->logger->error( 'Tar archive creation failed', array(
					'error' => $result['error'] ?? 'Unknown error',
				) );
				return false;
			}

			$elapsed = microtime( true ) - $start_time;

			$this->logger->info( 'Tar.gz archive created successfully', array(
				'path'         => $tar_output,
				'size'         => ServerLimits::format_bytes( $result['size'] ?? filesize( $tar_output ) ),
				'files'        => $total_files,
				'elapsed_time' => round( $elapsed, 2 ) . 's',
				'files_per_sec' => round( $total_files / max( $elapsed, 0.001 ), 1 ),
			) );

			// Report completion.
			if ( $progress_callback ) {
				$progress_callback( 100, 'Archive created', $total_files, $total_files, 0 );
			}

			// Return result with tar path.
			return array(
				'success' => true,
				'path'    => $tar_output,
				'format'  => 'tar.gz',
				'size'    => filesize( $tar_output ),
				'total'   => $total_files,
			);
		}

		// Fallback: use file list approach for scattered files.
		$this->logger->debug( 'Using file list-based tar archive' );

		// Report progress.
		if ( $progress_callback ) {
			$progress_callback( 10, 'Creating tar.gz archive from file list...', 0, $total_files, 0 );
		}

		$result = $tar_archiver->create_archive_from_list(
			$tar_output,
			$files,
			ABSPATH,
			function ( $percent, $message ) use ( $progress_callback, $total_files ) {
				if ( $progress_callback ) {
					$progress_callback( $percent, $message, $total_files, $total_files, 0 );
				}
			}
		);

		if ( ! $result['success'] ) {
			$this->logger->error( 'Tar archive creation failed', array(
				'error' => $result['error'] ?? 'Unknown error',
			) );
			return false;
		}

		$elapsed = microtime( true ) - $start_time;

		$this->logger->info( 'Tar.gz archive created successfully (file list mode)', array(
			'path'         => $tar_output,
			'size'         => ServerLimits::format_bytes( $result['size'] ?? filesize( $tar_output ) ),
			'files'        => $result['file_count'] ?? $total_files,
			'elapsed_time' => round( $elapsed, 2 ) . 's',
		) );

		// Report completion.
		if ( $progress_callback ) {
			$progress_callback( 100, 'Archive created', $total_files, $total_files, 0 );
		}

		return array(
			'success' => true,
			'path'    => $tar_output,
			'format'  => 'tar.gz',
			'size'    => filesize( $tar_output ),
			'total'   => $result['file_count'] ?? $total_files,
		);
	}

	/**
	 * Detect common directories from a list of files.
	 *
	 * @param array $files File list.
	 * @return array Common directories.
	 */
	private function detect_common_directories( array $files ): array {
		$directories = array();

		foreach ( $files as $file ) {
			$path = $file['path'] ?? '';
			if ( empty( $path ) ) {
				continue;
			}

			$dir = dirname( $path );

			// Track top-level directories.
			$relative = str_replace( ABSPATH, '', $dir );
			$parts = explode( '/', trim( $relative, '/' ) );

			if ( ! empty( $parts[0] ) ) {
				$top_dir = ABSPATH . $parts[0];
				if ( is_dir( $top_dir ) ) {
					$directories[ $top_dir ] = true;
				}
			}
		}

		return array_keys( $directories );
	}

	/**
	 * Get optimal batch size based on total file count.
	 *
	 * Uses small batches to avoid overwhelming the server when ZipArchive::close()
	 * processes all queued files at once.
	 *
	 * @param int $total_files Total number of files.
	 * @return int Optimal files per batch.
	 */
	private function get_optimal_batch_size( int $total_files ): int {
		// Use small batches to prevent server crashes during close().
		// Each batch of 50 files is processed quickly and reliably.
		// This creates more batch files but prevents OOM/timeout issues.
		return 50;
	}

	/**
	 * Get batch ZIP file path.
	 *
	 * @param string $output_dir  Output directory.
	 * @param string $base_name   Base filename.
	 * @param int    $part_num    Part number.
	 * @param int    $total_files Total file count (for single-part optimization).
	 * @return string Batch file path.
	 */
	private function get_batch_path( string $output_dir, string $base_name, int $part_num, int $total_files ): string {
		// If only one batch will be created, use the final output name directly.
		if ( $total_files <= $this->get_optimal_batch_size( $total_files ) ) {
			return $output_dir . '/' . $base_name . '.zip';
		}

		return $output_dir . '/' . $base_name . '-part-' . sprintf( '%03d', $part_num ) . '.zip';
	}

	/**
	 * Create a single batch ZIP file.
	 *
	 * Processes files one at a time to avoid overwhelming the server.
	 * Uses addFromString() for immediate writes and periodic flushes.
	 *
	 * @param string $path  Output path for this batch.
	 * @param array  $files Files to include in this batch.
	 * @return array Result with 'success', 'skipped' keys.
	 */
	private function create_batch_zip( string $path, array $files ): array {
		$zip = new \ZipArchive();
		$result = $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		if ( true !== $result ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create ZIP archive: ' . $result,
				'skipped' => array(),
			);
		}

		$skipped = array();
		$processed = 0;
		$bytes_added = 0;
		$flush_threshold = 20 * 1024 * 1024; // Flush every 20MB.
		$files_since_flush = 0;
		$flush_file_threshold = 10; // Also flush every 10 files.

		foreach ( $files as $file ) {
			$file_path = $file['path'];

			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				$skipped[] = $file_path;
				continue;
			}

			$file_size = filesize( $file_path );

			// For very large files (>50MB), skip them to avoid memory issues.
			// They should be handled separately or excluded.
			if ( $file_size > 50 * 1024 * 1024 ) {
				$this->logger->warning( 'Skipping very large file in batch', array(
					'file' => $file_path,
					'size' => $file_size,
				) );
				$skipped[] = $file_path;
				continue;
			}

			// Read file and add immediately.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = @file_get_contents( $file_path );

			if ( false === $content ) {
				$skipped[] = $file_path;
				continue;
			}

			$zip->addFromString( $file['relative'], $content );
			$bytes_added += strlen( $content );
			++$processed;
			++$files_since_flush;

			// Free memory immediately.
			unset( $content );

			// Flush to disk periodically to prevent memory buildup.
			// Close and reopen the ZIP to write buffered data.
			$should_flush = ( $bytes_added >= $flush_threshold ) ||
				( $files_since_flush >= $flush_file_threshold );

			if ( $should_flush && $processed < count( $files ) ) {
				$zip->close();

				// Reopen in append mode.
				if ( true !== $zip->open( $path, \ZipArchive::CREATE ) ) {
					return array(
						'success' => false,
						'error'   => 'Failed to reopen ZIP during flush',
						'skipped' => $skipped,
					);
				}

				$bytes_added = 0;
				$files_since_flush = 0;

				// Force garbage collection.
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}

		$zip->close();

		// Final garbage collection.
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		return array(
			'success' => true,
			'skipped' => $skipped,
		);
	}

	/**
	 * Combine multiple batch ZIP files into final output.
	 *
	 * Uses streaming extraction approach for efficiency - extracts all parts
	 * to a temp directory, then creates final ZIP using addFile().
	 *
	 * @param array  $parts       Array of batch ZIP paths.
	 * @param string $output_path Final output path.
	 * @return bool True on success.
	 */
	private function combine_batch_zips( array $parts, string $output_path ): bool {
		if ( empty( $parts ) ) {
			return false;
		}

		// If only one part, just rename it (or it's already the output).
		if ( 1 === count( $parts ) ) {
			$single_part = $parts[0];
			if ( $single_part === $output_path ) {
				return true;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return rename( $single_part, $output_path );
		}

		$this->logger->info( 'Combining batch ZIPs', array( 'parts' => count( $parts ) ) );

		// Extract all parts to a temporary directory.
		$temp_dir = dirname( $output_path ) . '/swish-combine-' . uniqid();
		wp_mkdir_p( $temp_dir );

		foreach ( $parts as $part_path ) {
			if ( ! file_exists( $part_path ) ) {
				continue;
			}

			$part_zip = new \ZipArchive();
			if ( true !== $part_zip->open( $part_path, \ZipArchive::RDONLY ) ) {
				$this->logger->warning( 'Failed to open batch ZIP', array( 'path' => $part_path ) );
				continue;
			}

			// Extract to temp directory.
			$part_zip->extractTo( $temp_dir );
			$part_zip->close();
		}

		// Create final ZIP from extracted files using addFile() (memory efficient).
		$final_zip = new \ZipArchive();
		$result = $final_zip->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		if ( true !== $result ) {
			$this->logger->error( 'Failed to create final combined ZIP' );
			$this->recursive_delete( $temp_dir );
			return false;
		}

		// Iterate through extracted files and add to final ZIP.
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $temp_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$file_count = 0;
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				continue;
			}

			$file_path = $file->getPathname();
			$relative = str_replace( $temp_dir . '/', '', $file_path );
			$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

			// Use addFile - memory efficient, reads on close.
			$final_zip->addFile( $file_path, $relative );
			++$file_count;

			// Close and reopen periodically to write data and prevent memory buildup.
			if ( 0 === $file_count % 500 ) {
				$final_zip->close();
				if ( true !== $final_zip->open( $output_path, \ZipArchive::CREATE ) ) {
					$this->logger->error( 'Failed to reopen final ZIP during combine' );
					$this->recursive_delete( $temp_dir );
					return false;
				}

				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}

		$final_zip->close();

		// Clean up temp directory.
		$this->recursive_delete( $temp_dir );

		$this->logger->info( 'Batch ZIPs combined successfully', array( 'files' => $file_count ) );

		return file_exists( $output_path ) && filesize( $output_path ) > 0;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to delete.
	 * @return bool True on success.
	 */
	private function recursive_delete( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return @rmdir( $dir );
	}

	/**
	 * Backup files from state file (resumable).
	 *
	 * Reads files from the state file in chunks and backs them up.
	 * Designed for very large sites with many files.
	 *
	 * @param string        $job_id            Job ID.
	 * @param string        $output_path       Output zip file path.
	 * @param int           $start_offset      Starting file offset.
	 * @param callable|null $progress_callback Progress callback.
	 * @return array Result with processed count, timeout status.
	 */
	public function backup_from_state(
		string $job_id,
		string $output_path,
		int $start_offset = 0,
		?callable $progress_callback = null
	): array {
		ServerLimits::init_timing();
		$state = $this->get_state();

		// Get total file count.
		$total_files = $state->count_file_list( $job_id );

		$this->logger->info( 'Starting backup from state', array(
			'job_id'       => $job_id,
			'total_files'  => $total_files,
			'start_offset' => $start_offset,
		) );

		// Open or create ZIP archive.
		$zip = new \ZipArchive();
		$mode = 0 === $start_offset
			? \ZipArchive::CREATE | \ZipArchive::OVERWRITE
			: \ZipArchive::CREATE;

		$result = $zip->open( $output_path, $mode );
		if ( true !== $result ) {
			return array(
				'error'     => 'Failed to open zip archive',
				'processed' => $start_offset,
				'total'     => $total_files,
			);
		}

		// Get adaptive settings.
		$batch_size = ServerLimits::get_adaptive_file_batch_size();
		$flush_interval = ServerLimits::get_zip_flush_interval();
		$timeout_check_interval = ServerLimits::get_timeout_check_interval();
		$timeout_threshold = ServerLimits::get_safe_timeout_threshold();
		$bytes_flush_threshold = 50 * 1024 * 1024;

		$processed = $start_offset;
		$files_in_batch = 0;
		$bytes_since_flush = 0;
		$timed_out = false;
		$last_progress_time = microtime( true );

		$this->logger->debug( 'Backup from state settings', array(
			'batch_size'            => $batch_size,
			'flush_interval'        => $flush_interval,
			'timeout_check'         => $timeout_check_interval,
			'bytes_flush_threshold' => $bytes_flush_threshold,
		) );

		// Read and process files in batches.
		while ( $processed < $total_files ) {
			// Check timeout before reading next batch.
			if ( ServerLimits::is_approaching_time_limit( $timeout_threshold ) ) {
				$timed_out = true;
				$this->logger->debug( 'Timeout approaching, stopping', array(
					'processed' => $processed,
					'elapsed'   => ServerLimits::get_elapsed_time(),
				) );
				break;
			}

			// Read next batch of files from state.
			$files = $state->read_file_list( $job_id, $processed, $batch_size );

			if ( empty( $files ) ) {
				break;
			}

			foreach ( $files as $file ) {
				// Check timeout frequently.
				if ( 0 === $files_in_batch % $timeout_check_interval && $files_in_batch > 0 ) {
					if ( ServerLimits::is_approaching_time_limit( $timeout_threshold ) ) {
						$timed_out = true;
						break 2;
					}
				}

				// Add file to ZIP.
				$file_path = $file['path'];
				if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
					$file_size = filesize( $file_path );

					if ( $file_size < 10 * 1024 * 1024 ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						$content = file_get_contents( $file_path );
						if ( false !== $content ) {
							$zip->addFromString( $file['relative'], $content );
							$bytes_since_flush += strlen( $content );
							unset( $content );
						}
					} else {
						// Large file - add and flush immediately.
						$zip->addFile( $file_path, $file['relative'] );
						$bytes_since_flush += $file_size;
						$zip->close();
						// Reopen in append mode.
						if ( true !== $zip->open( $output_path, \ZipArchive::CREATE ) ) {
							$this->logger->error( 'Failed to reopen zip archive after large file' );
							return array(
								'error'     => 'Failed to reopen zip archive',
								'processed' => $processed - $start_offset,
								'total'     => $total_files,
								'timeout'   => false,
							);
						}
						$bytes_since_flush = 0;

						if ( function_exists( 'gc_collect_cycles' ) ) {
							gc_collect_cycles();
						}
					}
				}

				++$processed;
				++$files_in_batch;

				// Progress callback - more frequently for better UI feedback.
				$now = microtime( true );
				$should_update = ( 0 === $files_in_batch % 5 ) ||
					( $now - $last_progress_time >= 0.5 );

				if ( $progress_callback && $should_update ) {
					$progress = (int) ( ( $processed / $total_files ) * 100 );
					$progress_callback( $progress, $file['relative'], $processed, $total_files, 0 );
					$last_progress_time = $now;
				}

				// Flush ZIP based on file count OR bytes processed OR memory pressure.
				$should_flush = ( 0 === $files_in_batch % $flush_interval ) ||
					( $bytes_since_flush >= $bytes_flush_threshold ) ||
					ServerLimits::is_memory_low();

				if ( $should_flush && $processed < $total_files ) {
					$this->logger->debug( 'Flushing ZIP archive (state backup)', array(
						'processed'         => $processed,
						'bytes_since_flush' => $bytes_since_flush,
						'memory_usage'      => ServerLimits::format_bytes( memory_get_usage( true ) ),
					) );

					$zip->close();
					// Reopen in append mode (CREATE without OVERWRITE).
					if ( true !== $zip->open( $output_path, \ZipArchive::CREATE ) ) {
						$this->logger->error( 'Failed to reopen zip archive during flush' );
						return array(
							'error'     => 'Failed to reopen zip archive',
							'processed' => $processed - $start_offset,
							'total'     => $total_files,
							'timeout'   => false,
						);
					}
					$bytes_since_flush = 0;

					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}

					// Reduce thresholds if memory is low.
					if ( ServerLimits::is_memory_low() ) {
						$flush_interval = max( 10, (int) ( $flush_interval / 2 ) );
						$bytes_flush_threshold = max( 10 * 1024 * 1024, (int) ( $bytes_flush_threshold / 2 ) );
					}
				}
			}
		}

		$zip->close();

		// Calculate files processed in this run.
		$files_processed_this_run = $processed - $start_offset;

		// Save progress.
		$state->save_progress( $job_id, $processed, $total_files, 'backup', array(
			'output_path' => $output_path,
		) );

		$this->logger->info( 'Backup from state completed', array(
			'processed_this_run' => $files_processed_this_run,
			'total_processed'    => $processed,
			'total_files'        => $total_files,
			'timed_out'          => $timed_out,
		) );

		// Return result compatible with BackupManager.
		// 'processed' is the number of files processed in THIS run (not cumulative).
		// 'timeout' is used by BackupManager to check if we timed out.
		return array(
			'processed' => $files_processed_this_run,
			'total'     => $total_files,
			'timeout'   => $timed_out,
			'remaining' => $total_files - $processed,
			'completed' => $processed >= $total_files,
		);
	}

	/**
	 * Backup files in chunks for resumable processing.
	 *
	 * @param array  $files       Files to backup.
	 * @param string $output_path Output zip file path.
	 * @param int    $chunk_index Current chunk index.
	 * @param int    $chunk_size  Number of files per chunk.
	 * @return array Result with 'completed', 'next_chunk', 'total_chunks'.
	 */
	public function backup_chunk(
		array $files,
		string $output_path,
		int $chunk_index = 0,
		int $chunk_size = 100
	): array {
		$total_files = count( $files );
		$total_chunks = (int) ceil( $total_files / $chunk_size );
		$start = $chunk_index * $chunk_size;
		$chunk_files = array_slice( $files, $start, $chunk_size );

		if ( empty( $chunk_files ) ) {
			return array(
				'completed'    => true,
				'next_chunk'   => null,
				'total_chunks' => $total_chunks,
				'processed'    => $total_files,
			);
		}

		try {
			$zip = new \ZipArchive();

			// First chunk creates new archive, subsequent chunks append.
			$mode = 0 === $chunk_index
				? \ZipArchive::CREATE | \ZipArchive::OVERWRITE
				: \ZipArchive::CREATE;

			$result = $zip->open( $output_path, $mode );

			if ( true !== $result ) {
				throw new \RuntimeException( 'Failed to open zip archive' );
			}

			$files_processed = 0;
			$bytes_since_flush = 0;
			$flush_interval = 50;
			$bytes_flush_threshold = 25 * 1024 * 1024;

			foreach ( $chunk_files as $file ) {
				// Use addFromString for predictable timing.
				$file_path = $file['path'];
				if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
					$file_size = filesize( $file_path );

					if ( $file_size < 10 * 1024 * 1024 ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						$content = file_get_contents( $file_path );
						if ( false !== $content ) {
							$zip->addFromString( $file['relative'], $content );
							$bytes_since_flush += strlen( $content );
							unset( $content );
						}
					} else {
						// Large file handling.
						$zip->addFile( $file_path, $file['relative'] );
						$bytes_since_flush += $file_size;
						$zip->close();
						if ( true !== $zip->open( $output_path, \ZipArchive::CREATE ) ) {
							throw new \RuntimeException( 'Failed to reopen zip archive' );
						}
						$bytes_since_flush = 0;
					}
				}

				++$files_processed;

				// Flush periodically within chunk.
				if ( ( 0 === $files_processed % $flush_interval || $bytes_since_flush >= $bytes_flush_threshold ) &&
					$files_processed < count( $chunk_files ) ) {
					$zip->close();
					if ( true !== $zip->open( $output_path, \ZipArchive::CREATE ) ) {
						throw new \RuntimeException( 'Failed to reopen zip archive during flush' );
					}
					$bytes_since_flush = 0;

					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}
				}
			}

			$zip->close();

			// Garbage collection after chunk.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			$next_chunk = $chunk_index + 1;
			$is_completed = $next_chunk >= $total_chunks;

			return array(
				'completed'    => $is_completed,
				'next_chunk'   => $is_completed ? null : $next_chunk,
				'total_chunks' => $total_chunks,
				'processed'    => min( $start + $chunk_size, $total_files ),
			);
		} catch ( \Exception $e ) {
			$this->logger->error( 'Chunk backup failed: ' . $e->getMessage() );
			return array(
				'completed'    => false,
				'error'        => $e->getMessage(),
				'next_chunk'   => $chunk_index,
				'total_chunks' => $total_chunks,
				'processed'    => $start,
			);
		}
	}

	/**
	 * Get file list for backup with state tracking.
	 *
	 * @param array $options Backup options.
	 * @return array File list with metadata.
	 */
	public function prepare_file_list( array $options = array() ): array {
		$directories = $this->get_backup_directories( $options );

		if ( ! empty( $options['exclude_files'] ) ) {
			$this->set_exclude_patterns( $options['exclude_files'] );
		}

		$files = $this->get_file_list( $directories );
		$total_size = array_sum( array_column( $files, 'size' ) );

		return array(
			'files'       => $files,
			'count'       => count( $files ),
			'total_size'  => $total_size,
			'directories' => $directories,
		);
	}

	/**
	 * Check if a path should be excluded (public wrapper).
	 *
	 * @param string $path File path.
	 * @return bool True if should be excluded.
	 */
	public function is_excluded( string $path ): bool {
		return $this->should_exclude( $path );
	}

	/**
	 * Check if a path should be excluded.
	 *
	 * @param string $path File path.
	 * @return bool True if should be excluded.
	 */
	private function should_exclude( string $path ): bool {
		$normalized_path = str_replace( '\\', '/', $path );
		$abspath = str_replace( '\\', '/', ABSPATH );

		// Exclude WordPress core files/directories unless explicitly included.
		if ( ! $this->include_core ) {
			foreach ( $this->get_wp_core_patterns() as $core_pattern ) {
				$core_path = $abspath . $core_pattern;

				// Check if path is the core file/directory or inside a core directory.
				if ( strpos( $normalized_path, $core_path ) === 0 ) {
					return true;
				}
			}
		}

		foreach ( $this->exclude_patterns as $pattern ) {
			// Check if it's a glob pattern.
			if ( strpos( $pattern, '*' ) !== false ) {
				if ( fnmatch( $pattern, basename( $path ) ) ) {
					return true;
				}
				if ( fnmatch( '*/' . $pattern, $normalized_path ) ) {
					return true;
				}
			} else {
				// Exact match or path contains pattern.
				if ( strpos( $normalized_path, '/' . $pattern . '/' ) !== false ) {
					return true;
				}
				if ( str_ends_with( $normalized_path, '/' . $pattern ) ) {
					return true;
				}
				// Check if basename matches (for directory names like 'swish-backups').
				if ( basename( $normalized_path ) === $pattern ) {
					return true;
				}
			}
		}

		// Exclude files larger than 500MB by default (Pro version can override).
		$max_file_size = apply_filters( 'swish_backup_max_file_size', 500 * 1024 * 1024 );
		if ( is_file( $path ) && $max_file_size > 0 && filesize( $path ) > $max_file_size ) {
			$this->logger->warning( 'Skipping large file', array( 'path' => $path, 'size' => filesize( $path ) ) );
			return true;
		}

		return false;
	}

	/**
	 * Get relative path from WordPress root.
	 *
	 * @param string $path Absolute path.
	 * @return string Relative path.
	 */
	private function get_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$root = str_replace( '\\', '/', ABSPATH );

		if ( strpos( $path, $root ) === 0 ) {
			return substr( $path, strlen( $root ) );
		}

		return basename( $path );
	}

	/**
	 * Calculate total size of files to backup.
	 *
	 * @param array $files File list.
	 * @return int Total size in bytes.
	 */
	public function calculate_total_size( array $files ): int {
		return array_sum( array_column( $files, 'size' ) );
	}

	/**
	 * Get backup directory sizes.
	 *
	 * @return array Directory => size map.
	 */
	public function get_directory_sizes(): array {
		$sizes = array();
		$directories = array(
			'core'    => ABSPATH,
			'plugins' => WP_PLUGIN_DIR,
			'themes'  => get_theme_root(),
			'uploads' => wp_upload_dir()['basedir'],
		);

		foreach ( $directories as $name => $path ) {
			$sizes[ $name ] = $this->calculate_directory_size( $path );
		}

		return $sizes;
	}

	/**
	 * Calculate directory size recursively.
	 *
	 * @param string $directory Directory path.
	 * @return int Size in bytes.
	 */
	private function calculate_directory_size( string $directory ): int {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$size = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! $this->should_exclude( $file->getPathname() ) ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Backup special WordPress files.
	 *
	 * @param string $output_dir Output directory.
	 * @return array List of backed up files.
	 */
	public function backup_wp_config( string $output_dir ): array {
		$files = array();

		// wp-config.php (sanitized).
		$wp_config = ABSPATH . 'wp-config.php';
		if ( file_exists( $wp_config ) ) {
			$sanitized = $this->sanitize_wp_config( $wp_config );
			$output_path = $output_dir . '/wp-config.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $output_path, $sanitized );
			$files[] = $output_path;
		}

		// .htaccess.
		$htaccess = ABSPATH . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			$output_path = $output_dir . '/.htaccess';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $htaccess, $output_path );
			$files[] = $output_path;
		}

		// robots.txt.
		$robots = ABSPATH . 'robots.txt';
		if ( file_exists( $robots ) ) {
			$output_path = $output_dir . '/robots.txt';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $robots, $output_path );
			$files[] = $output_path;
		}

		return $files;
	}

	/**
	 * Sanitize wp-config.php content.
	 *
	 * Remove sensitive credentials for security.
	 *
	 * @param string $file_path Path to wp-config.php.
	 * @return string Sanitized content.
	 */
	private function sanitize_wp_config( string $file_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		// Add warning comment.
		$warning = "<?php\n/**\n * BACKUP NOTICE: Sensitive credentials have been removed.\n";
		$warning .= " * You will need to update database credentials after restore.\n */\n\n";

		// Remove sensitive constants.
		$sensitive_constants = array(
			'DB_NAME',
			'DB_USER',
			'DB_PASSWORD',
			'DB_HOST',
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		foreach ( $sensitive_constants as $constant ) {
			$content = preg_replace(
				"/define\s*\(\s*['\"]" . $constant . "['\"]\s*,\s*['\"].*?['\"]\s*\);/s",
				"define( '{$constant}', 'REPLACE_ME' );",
				$content
			);
		}

		// Remove opening PHP tag if present (we'll add our own with warning).
		$content = preg_replace( '/^<\?php\s*/i', '', $content );

		return $warning . $content;
	}
}
