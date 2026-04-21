<?php
/**
 * Backup Pipeline - Queue-based chunked backup processing.
 *
 * This implements a proper pipeline architecture that:
 * - Separates phases: index → queue → process → finalize
 * - Enforces hard time budgets per request
 * - Implements backpressure controls (memory, CPU, IO)
 * - Supports retry/recovery for failed files
 * - Uses streaming writes for minimal memory usage
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Core\ServerLimits;
use SwishMigrateAndBackup\Logger\Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Queue-based backup pipeline with chunked processing.
 */
final class BackupPipeline {

	/**
	 * Pipeline phases.
	 */
	public const PHASE_INIT       = 'init';
	public const PHASE_INDEXING   = 'indexing';
	public const PHASE_PROCESSING = 'processing';
	public const PHASE_FINALIZING = 'finalizing';
	public const PHASE_COMPLETE   = 'complete';
	public const PHASE_FAILED     = 'failed';

	/**
	 * Default time budget per request in seconds.
	 */
	private const DEFAULT_TIME_BUDGET = 15;

	/**
	 * Default files to index per request.
	 */
	private const DEFAULT_INDEX_BATCH_SIZE = 500;

	/**
	 * Default files to process per request.
	 * Can be overridden via settings for different server capabilities.
	 */
	private const DEFAULT_PROCESS_BATCH_SIZE = 50;

	/**
	 * Memory threshold (percentage) before yielding.
	 */
	private const MEMORY_THRESHOLD = 0.75;

	/**
	 * Actual process batch size (configurable).
	 *
	 * @var int
	 */
	private int $process_batch_size = self::DEFAULT_PROCESS_BATCH_SIZE;

	/**
	 * Actual index batch size (configurable).
	 *
	 * @var int
	 */
	private int $index_batch_size = self::DEFAULT_INDEX_BATCH_SIZE;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * SwishArchiver instance.
	 *
	 * @var SwishArchiver|null
	 */
	private ?SwishArchiver $archiver = null;

	/**
	 * Files/patterns to exclude.
	 *
	 * @var array
	 */
	private array $exclude_patterns = array();

	/**
	 * Excluded plugins.
	 *
	 * @var array
	 */
	private array $exclude_plugins = array();

	/**
	 * Excluded themes.
	 *
	 * @var array
	 */
	private array $exclude_themes = array();

	/**
	 * Excluded upload folders.
	 *
	 * @var array
	 */
	private array $exclude_uploads = array();

	/**
	 * Whether to include WordPress core files.
	 *
	 * @var bool
	 */
	private bool $include_core = false;

	/**
	 * Request start time.
	 *
	 * @var float
	 */
	private float $start_time;

	/**
	 * Time budget for this request.
	 *
	 * @var int
	 */
	private int $time_budget;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
		$this->start_time = microtime( true );
		$this->time_budget = self::DEFAULT_TIME_BUDGET;

		// Set default exclusions.
		$this->exclude_patterns = array(
			'*.log',
			'*.tmp',
			'*.swp',
			'.git',
			'.svn',
			'node_modules',
			'vendor',
			'wp-content/cache',
			'wp-content/debug.log',
			'wp-content/upgrade',
			'wp-content/swish-backups',
			'swish-backups',
			'error_log',
			'.DS_Store',
			'Thumbs.db',
		);
	}

	/**
	 * Configure exclusions from options.
	 *
	 * @param array $options Backup options.
	 * @return self
	 */
	public function configure( array $options ): self {
		$this->include_core = $options['backup_core_files'] ?? false;
		$this->exclude_plugins = $options['exclude_plugins'] ?? array();
		$this->exclude_themes = $options['exclude_themes'] ?? array();
		$this->exclude_uploads = $options['exclude_uploads'] ?? array();

		if ( ! empty( $options['exclude_files'] ) ) {
			$this->exclude_patterns = array_merge( $this->exclude_patterns, $options['exclude_files'] );
		}

		if ( isset( $options['time_budget'] ) ) {
			$this->time_budget = (int) $options['time_budget'];
		}

		// Configurable batch sizes for different server capabilities.
		if ( isset( $options['pipeline_batch_size'] ) ) {
			$this->process_batch_size = max( 10, min( 500, (int) $options['pipeline_batch_size'] ) );
		}

		if ( isset( $options['index_batch_size'] ) ) {
			$this->index_batch_size = max( 100, min( 2000, (int) $options['index_batch_size'] ) );
		}

		return $this;
	}

	/**
	 * Get directories to backup based on options.
	 *
	 * @param array $options Backup options.
	 * @return array Directories to backup.
	 */
	public function get_backup_directories( array $options ): array {
		$directories = array();

		if ( $this->include_core ) {
			$directories[] = ABSPATH;
		}

		if ( $options['backup_plugins'] ?? true ) {
			$directories[] = WP_PLUGIN_DIR;
		}

		if ( $options['backup_themes'] ?? true ) {
			$directories[] = get_theme_root();
		}

		if ( $options['backup_uploads'] ?? true ) {
			$upload_dir = wp_upload_dir();
			$directories[] = $upload_dir['basedir'];
		}

		return array_unique( array_filter( $directories, 'is_dir' ) );
	}

	/**
	 * Execute indexing phase - scan files and queue them.
	 *
	 * This phase ONLY scans and queues files. No archiving happens here.
	 *
	 * @param string $job_id      Job ID.
	 * @param array  $directories Directories to scan.
	 * @param int    $offset      Offset to resume from.
	 * @return array Result with 'completed', 'indexed', 'offset'.
	 */
	public function index_files( string $job_id, array $directories, int $offset = 0 ): array {
		$this->start_time = microtime( true );
		$indexed = 0;
		$current_offset = $offset;
		$batch = array();
		$completed = true;

		$this->logger->info( 'Starting file indexing phase', array(
			'job_id'      => $job_id,
			'directories' => count( $directories ),
			'offset'      => $offset,
		) );

		// Track which directory and file we're at.
		$dir_index = 0;
		$file_index = 0;
		$global_index = 0;

		foreach ( $directories as $directory ) {
			if ( ! is_dir( $directory ) ) {
				++$dir_index;
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
					// Skip if we haven't reached the offset yet.
					if ( $global_index < $offset ) {
						++$global_index;
						continue;
					}

					$path = $file->getPathname();

					// Check exclusions.
					if ( $this->should_exclude( $path ) ) {
						++$global_index;
						continue;
					}

					// Only process files, not directories.
					if ( $file->isFile() && $file->isReadable() ) {
						$batch[] = array(
							'path'     => $path,
							'relative' => $this->get_relative_path( $path ),
							'size'     => $file->getSize(),
						);

						++$indexed;
						++$global_index;

						// Write batch to database when full.
						if ( count( $batch ) >= $this->index_batch_size ) {
							FileQueue::add_files_batch( $job_id, $batch );
							$batch = array();
							$current_offset = $global_index;

							// Check backpressure.
							if ( $this->should_yield() ) {
								$completed = false;
								$this->logger->debug( 'Yielding during indexing', array(
									'indexed' => $indexed,
									'offset'  => $current_offset,
									'reason'  => $this->get_yield_reason(),
								) );
								break 2; // Exit both loops.
							}
						}
					} else {
						++$global_index;
					}
				}
			} catch ( \Exception $e ) {
				$this->logger->warning( 'Error scanning directory', array(
					'directory' => $directory,
					'error'     => $e->getMessage(),
				) );
			}

			++$dir_index;
		}

		// Write remaining batch.
		if ( ! empty( $batch ) ) {
			FileQueue::add_files_batch( $job_id, $batch );
			$current_offset = $global_index;
		}

		$this->logger->info( 'Indexing phase result', array(
			'indexed'   => $indexed,
			'offset'    => $current_offset,
			'completed' => $completed,
			'elapsed'   => round( microtime( true ) - $this->start_time, 2 ),
		) );

		return array(
			'completed' => $completed,
			'indexed'   => $indexed,
			'offset'    => $current_offset,
		);
	}

	/**
	 * Execute processing phase - archive queued files.
	 *
	 * This phase processes files from the queue and writes them to the archive.
	 *
	 * @param string $job_id       Job ID.
	 * @param string $archive_path Path to the archive file.
	 * @return array Result with 'completed', 'processed', 'stats'.
	 */
	public function process_files( string $job_id, string $archive_path ): array {
		$this->start_time = microtime( true );
		$processed = 0;
		$failed = 0;
		$skipped = 0;

		// Reset any stale processing files (reduced from 300s to 30s for faster recovery).
		$reset = FileQueue::reset_stale_processing( $job_id, 30 );
		if ( $reset > 0 ) {
			$this->logger->info( 'Reset stale processing files', array( 'count' => $reset ) );
		}

		// Initialize archiver.
		$this->archiver = new SwishArchiver( $archive_path );

		// Open archive for writing.
		if ( ! $this->archiver->open_for_write() ) {
			$this->logger->error( 'Failed to open archive for writing', array(
				'path' => $archive_path,
			) );
			return array(
				'completed' => false,
				'processed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'error'     => 'Failed to open archive',
				'stats'     => FileQueue::get_job_stats( $job_id ),
			);
		}

		// Get batch of pending files.
		$batch = FileQueue::get_pending_batch( $job_id, $this->process_batch_size );

		$this->logger->info( 'Processing batch info', array(
			'pending_count' => count( $batch ),
			'job_id'        => $job_id,
			'archive'       => $archive_path,
			'time_budget'   => $this->time_budget,
		) );

		if ( empty( $batch ) ) {
			// Check for retryable failures.
			$batch = FileQueue::get_retryable( $job_id, 3, $this->process_batch_size );

			if ( empty( $batch ) ) {
				// Log status breakdown to understand why no files are available.
				$stats = FileQueue::get_job_stats( $job_id );
				$this->logger->info( 'No pending files available', array(
					'total'      => $stats['total'],
					'pending'    => $stats['pending'],
					'processing' => $stats['processing'],
					'completed'  => $stats['completed'],
					'failed'     => $stats['failed'],
					'skipped'    => $stats['skipped'],
				) );
			}
		}

		$this->logger->debug( 'Processing batch', array(
			'batch_size' => count( $batch ),
			'job_id'     => $job_id,
		) );

		foreach ( $batch as $file_record ) {
			// Check backpressure before each file.
			if ( $this->should_yield() ) {
				$this->logger->info( 'Yielding during processing', array(
					'processed' => $processed,
					'reason'    => $this->get_yield_reason(),
					'elapsed'   => round( microtime( true ) - $this->start_time, 3 ),
				) );
				break;
			}

			$file_id = (int) $file_record['id'];
			$file_path = $file_record['file_path'];
			$relative_path = $file_record['relative_path'];
			$file_size = (int) $file_record['file_size'];
			$bytes_written = (int) ( $file_record['bytes_written'] ?? 0 );

			// Mark as processing.
			FileQueue::mark_processing( $file_id );

			// Check if file still exists.
			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				FileQueue::mark_skipped( $file_id, 'File not found or not readable' );
				++$skipped;
				continue;
			}

			// Check file size - skip very large files.
			$actual_size = filesize( $file_path );
			$max_file_size = apply_filters( 'swish_backup_max_file_size', 500 * 1024 * 1024 );

			if ( $actual_size > $max_file_size ) {
				FileQueue::mark_skipped( $file_id, 'File too large: ' . $this->format_bytes( $actual_size ) );
				$this->logger->warning( 'Skipping large file', array(
					'path' => $file_path,
					'size' => $this->format_bytes( $actual_size ),
				) );
				++$skipped;
				continue;
			}

			// Add file to archive.
			try {
				$bytes = 0;
				$remaining_time = $this->get_remaining_time();
				$this->logger->info( 'Adding file to archive', array(
					'file_id'        => $file_id,
					'path'           => $relative_path,
					'size'           => $this->format_bytes( $actual_size ),
					'remaining_time' => $remaining_time,
				) );

				$result = $this->archiver->add_file(
					$file_path,
					$relative_path,
					$bytes_written,
					$bytes,
					$remaining_time
				);

				if ( $result ) {
					FileQueue::mark_completed( $file_id, $bytes );
					++$processed;
					$this->logger->info( 'File completed', array(
						'file_id' => $file_id,
						'path'    => $relative_path,
						'bytes'   => $bytes,
					) );
				} else {
					// Partial write - reset to pending and restart from scratch.
					// Note: Resuming partial files doesn't work correctly with append mode,
					// so we restart the file from the beginning on next attempt.
					FileQueue::update_progress( $file_id, 0, 0 );
					FileQueue::reset_to_pending( $file_id );
					$this->logger->info( 'File timeout, reset to pending for fresh retry', array(
						'file_id'         => $file_id,
						'path'            => $relative_path,
						'bytes_this_try'  => $bytes,
						'file_size'       => $actual_size,
					) );
					break; // Yield to next request.
				}
			} catch ( \Exception $e ) {
				FileQueue::mark_failed( $file_id, $e->getMessage(), $bytes_written );
				$this->logger->error( 'Failed to archive file', array(
					'path'  => $file_path,
					'error' => $e->getMessage(),
				) );
				++$failed;
			}
		}

		// Close archiver.
		$this->archiver->close();

		// Get updated stats.
		$stats = FileQueue::get_job_stats( $job_id );
		$completed = FileQueue::is_job_complete( $job_id );

		$this->logger->info( 'Processing phase result', array(
			'processed' => $processed,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'completed' => $completed,
			'stats'     => $stats,
			'elapsed'   => round( microtime( true ) - $this->start_time, 2 ),
		) );

		return array(
			'completed' => $completed,
			'processed' => $processed,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'stats'     => $stats,
		);
	}

	/**
	 * Finalize the archive.
	 *
	 * @param string $job_id       Job ID.
	 * @param string $archive_path Path to the archive file.
	 * @return array Result with 'success', 'size', 'checksum'.
	 */
	public function finalize( string $job_id, string $archive_path ): array {
		$this->logger->info( 'Finalizing archive', array(
			'job_id' => $job_id,
			'path'   => $archive_path,
		) );

		// Verify archive exists.
		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file not found',
			);
		}

		// Calculate checksum.
		$checksum = md5_file( $archive_path );
		$size = filesize( $archive_path );

		// Get final stats.
		$stats = FileQueue::get_job_stats( $job_id );

		// Clean up queue.
		FileQueue::clear_job( $job_id );

		// Clean up state file.
		$state_file = dirname( $archive_path ) . '/' . pathinfo( $archive_path, PATHINFO_FILENAME ) . '.state';
		if ( file_exists( $state_file ) ) {
			unlink( $state_file );
		}

		$this->logger->info( 'Archive finalized', array(
			'size'     => $this->format_bytes( $size ),
			'checksum' => $checksum,
			'stats'    => $stats,
		) );

		return array(
			'success'  => true,
			'size'     => $size,
			'checksum' => $checksum,
			'stats'    => $stats,
		);
	}

	/**
	 * Get the current pipeline state for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array State with 'phase', 'progress', 'stats'.
	 */
	public function get_state( string $job_id ): array {
		$stats = FileQueue::get_job_stats( $job_id );

		$phase = self::PHASE_INIT;
		if ( $stats['total'] > 0 ) {
			if ( $stats['pending'] > 0 || $stats['processing'] > 0 ) {
				$phase = self::PHASE_PROCESSING;
			} elseif ( $stats['completed'] + $stats['skipped'] === $stats['total'] ) {
				$phase = self::PHASE_FINALIZING;
			}
		}

		return array(
			'phase'    => $phase,
			'progress' => $stats['progress'],
			'stats'    => $stats,
		);
	}

	/**
	 * Check if we should yield control to avoid timeout/memory issues.
	 *
	 * @return bool True if we should yield.
	 */
	private function should_yield(): bool {
		// Time budget check.
		$elapsed = microtime( true ) - $this->start_time;
		if ( $elapsed >= $this->time_budget ) {
			return true;
		}

		// Also check against server limits.
		if ( ServerLimits::is_approaching_time_limit( 5 ) ) {
			return true;
		}

		// Memory check.
		if ( $this->is_memory_pressure() ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the reason for yielding.
	 *
	 * @return string Yield reason.
	 */
	private function get_yield_reason(): string {
		$elapsed = microtime( true ) - $this->start_time;

		if ( $elapsed >= $this->time_budget ) {
			return 'time_budget_exceeded';
		}

		if ( ServerLimits::is_approaching_time_limit( 5 ) ) {
			return 'server_time_limit';
		}

		if ( $this->is_memory_pressure() ) {
			return 'memory_pressure';
		}

		return 'unknown';
	}

	/**
	 * Get remaining time in the budget.
	 *
	 * @return int Remaining seconds.
	 */
	private function get_remaining_time(): int {
		$elapsed = microtime( true ) - $this->start_time;
		$remaining = $this->time_budget - $elapsed;
		return max( 1, (int) $remaining );
	}

	/**
	 * Check if under memory pressure.
	 *
	 * @return bool True if memory pressure detected.
	 */
	private function is_memory_pressure(): bool {
		$memory_limit = $this->get_memory_limit();
		if ( $memory_limit <= 0 ) {
			return false;
		}

		$current_usage = memory_get_usage( true );
		$threshold = (int) ( $memory_limit * self::MEMORY_THRESHOLD );

		return $current_usage >= $threshold;
	}

	/**
	 * Get PHP memory limit in bytes.
	 *
	 * @return int Memory limit in bytes.
	 */
	private function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );

		if ( '-1' === $limit ) {
			return 0; // Unlimited.
		}

		$value = (int) $limit;
		$unit = strtolower( substr( $limit, -1 ) );

		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
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
			$core_patterns = array(
				'wp-admin',
				'wp-includes',
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

			foreach ( $core_patterns as $core_pattern ) {
				$core_path = $abspath . $core_pattern;
				if ( strpos( $normalized_path, $core_path ) === 0 ) {
					return true;
				}
			}
		}

		// Check excluded plugins.
		if ( ! empty( $this->exclude_plugins ) ) {
			$plugins_dir = str_replace( '\\', '/', WP_PLUGIN_DIR ) . '/';
			if ( strpos( $normalized_path, $plugins_dir ) === 0 ) {
				$relative_to_plugins = substr( $normalized_path, strlen( $plugins_dir ) );
				$plugin_folder = explode( '/', $relative_to_plugins )[0];
				if ( in_array( $plugin_folder, $this->exclude_plugins, true ) ) {
					return true;
				}
			}
		}

		// Check excluded themes.
		if ( ! empty( $this->exclude_themes ) ) {
			$themes_dir = str_replace( '\\', '/', get_theme_root() ) . '/';
			if ( strpos( $normalized_path, $themes_dir ) === 0 ) {
				$relative_to_themes = substr( $normalized_path, strlen( $themes_dir ) );
				$theme_folder = explode( '/', $relative_to_themes )[0];
				if ( in_array( $theme_folder, $this->exclude_themes, true ) ) {
					return true;
				}
			}
		}

		// Check excluded upload folders.
		if ( ! empty( $this->exclude_uploads ) ) {
			$upload_dir = wp_upload_dir();
			$uploads_base = str_replace( '\\', '/', $upload_dir['basedir'] ) . '/';
			if ( strpos( $normalized_path, $uploads_base ) === 0 ) {
				$relative_to_uploads = substr( $normalized_path, strlen( $uploads_base ) );
				foreach ( $this->exclude_uploads as $excluded_folder ) {
					$excluded_normalized = trim( str_replace( '\\', '/', $excluded_folder ), '/' );
					if ( strpos( $relative_to_uploads, $excluded_normalized . '/' ) === 0 ||
						$relative_to_uploads === $excluded_normalized ) {
						return true;
					}
				}
			}
		}

		// Check pattern exclusions.
		foreach ( $this->exclude_patterns as $pattern ) {
			if ( strpos( $pattern, '*' ) !== false ) {
				if ( fnmatch( $pattern, basename( $path ) ) ) {
					return true;
				}
				if ( fnmatch( '*/' . $pattern, $normalized_path ) ) {
					return true;
				}
			} else {
				if ( strpos( $normalized_path, '/' . $pattern . '/' ) !== false ) {
					return true;
				}
				if ( str_ends_with( $normalized_path, '/' . $pattern ) ) {
					return true;
				}
				if ( basename( $normalized_path ) === $pattern ) {
					return true;
				}
			}
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
	 * Format bytes to human-readable string.
	 *
	 * @param int $bytes Bytes.
	 * @return string Formatted string.
	 */
	private function format_bytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$unit = 0;

		while ( $bytes >= 1024 && $unit < count( $units ) - 1 ) {
			$bytes /= 1024;
			++$unit;
		}

		return round( $bytes, 2 ) . ' ' . $units[ $unit ];
	}
}
