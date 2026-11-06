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
use SwishMigrateAndBackup\Backup\DatabaseBackup;
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
	private const DEFAULT_PROCESS_BATCH_SIZE = 100;

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
	 * CLI mode - disables time-based yielding during processing.
	 *
	 * @var bool
	 */
	private bool $cli_mode = false;

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
		// Note: 'vendor' is NOT excluded because many plugins (Yoast, WooCommerce, etc.)
		// include vendor/ directories with Composer autoloaders that are required to function.
		$this->exclude_patterns = array(
			'*.log',
			'*.tmp',
			'*.swp',
			'.git',
			'.svn',
			'node_modules',
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

		// CLI mode: disables time-based yielding, allows much larger batches.
		if ( isset( $options['cli_mode'] ) && $options['cli_mode'] ) {
			$this->cli_mode = true;
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

		$directories = array_values( array_unique( array_filter( $directories, 'is_dir' ) ) );

		// A directory inside another one in the list would be indexed twice (the
		// site root already contains wp-content on a standard install). Keep only
		// the outermost directories.
		$real = array();
		foreach ( $directories as $dir ) {
			$resolved     = realpath( $dir );
			$real[ $dir ] = $resolved ? rtrim( str_replace( '\\', '/', $resolved ), '/' ) . '/' : null;
		}

		$outermost = array();
		foreach ( $directories as $dir ) {
			$nested = false;
			foreach ( $directories as $other ) {
				if ( $other !== $dir && $real[ $dir ] && $real[ $other ] && 0 === strpos( $real[ $dir ], $real[ $other ] ) ) {
					$nested = true;
					break;
				}
			}
			if ( ! $nested ) {
				$outermost[] = $dir;
			}
		}

		return $outermost;
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

		// IMPORTANT: File indexing should complete fully without yielding.
		// This is just directory listing (no file content reading), so it's fast.
		// Like AI1WM, we enumerate ALL files first, then archive in chunks.
		// Only yield if we're critically low on memory.

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

							// Only check memory pressure during indexing - NOT time.
							// File listing is fast and must complete to avoid missing files.
							if ( $this->is_memory_pressure() ) {
								$completed = false;
								$this->logger->debug( 'Yielding during indexing due to memory', array(
									'indexed' => $indexed,
									'offset'  => $current_offset,
								) );
								break 2; // Exit both loops.
							}

							// Garbage collection to free memory.
							if ( function_exists( 'gc_collect_cycles' ) ) {
								gc_collect_cycles();
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
	 * Prepare the archive: write the manifest, dump the database in time slices
	 * and queue database.sql for streaming.
	 *
	 * Call repeatedly, passing back the returned 'checkpoint', until 'completed'
	 * is true. The manifest is written on the first call. The database dump is
	 * resumed from the checkpoint on every call and, when it is complete, the
	 * dump file is added to the file queue so process_files() streams it into
	 * the archive with the same resume support as any other file (it is never
	 * read into memory). Must complete before process_files() is called.
	 *
	 * @param string $job_id       Job ID.
	 * @param string $archive_path Path to the archive file.
	 * @param array  $options      Backup options.
	 * @param array  $checkpoint   Checkpoint returned by the previous call (empty to start).
	 * @param int    $time_budget  Seconds the database dump may run in this call (0 = pipeline budget).
	 * @return array Result with 'success', 'completed', 'checkpoint', 'archive_offset', 'progress', 'error'.
	 */
	public function prepare_archive( string $job_id, string $archive_path, array $options = array(), array $checkpoint = array(), int $time_budget = 0 ): array {
		// Create temp directory for manifest and database.
		$temp_dir = dirname( $archive_path ) . '/temp-' . $job_id;
		if ( ! is_dir( $temp_dir ) && ! wp_mkdir_p( $temp_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create temp directory',
			);
		}

		$manifest = null;

		if ( empty( $checkpoint['manifest_done'] ) ) {
			$this->logger->info( 'Preparing archive with manifest', array(
				'job_id'  => $job_id,
				'archive' => $archive_path,
			) );

			$stats = FileQueue::get_job_stats( $job_id );
			$manifest = $this->create_manifest( $job_id, $options, $stats );
			$manifest_path = $temp_dir . '/manifest.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

			$archiver = new SwishArchiver( $archive_path );

			if ( ! $archiver->open_for_write() ) {
				return array(
					'success' => false,
					'error'   => 'Failed to create archive file',
				);
			}

			// A retry after a crashed first attempt may find a half-written archive: start clean.
			$archiver->truncate_to( 0 );

			$bytes = 0;
			if ( ! $archiver->add_file( $manifest_path, 'manifest.json', 0, $bytes, 0 ) ) {
				$archiver->close( false );
				return array(
					'success' => false,
					'error'   => 'Failed to write manifest to archive',
				);
			}

			// Close without finalizing - process_files will append more files.
			$archiver->close( false );
			unlink( $manifest_path );

			$checkpoint['manifest_done'] = true;
		}

		$type = $options['type'] ?? 'full';
		$backup_database = $options['backup_database'] ?? ( 'files' !== $type );

		if ( $backup_database && empty( $checkpoint['database_done'] ) ) {
			$db_backup = new DatabaseBackup( $this->logger );
			$db_path   = $temp_dir . '/database.sql';

			// CLI has no request limit; browser requests get the pipeline time budget.
			$time_slice = $this->cli_mode ? 3600 : max( 5, $time_budget > 0 ? $time_budget : $this->time_budget );

			$db_result = $db_backup->backup_chunked( $db_path, $checkpoint['db'] ?? array(), null, $time_slice );

			if ( isset( $db_result['error'] ) ) {
				return array(
					'success' => false,
					'error'   => 'Database backup failed: ' . $db_result['error'],
				);
			}

			if ( empty( $db_result['completed'] ) ) {
				$checkpoint['db'] = $db_result['checkpoint'];

				return array(
					'success'        => true,
					'completed'      => false,
					'checkpoint'     => $checkpoint,
					'archive_offset' => (int) filesize( $archive_path ),
					'progress'       => $db_result['progress'] ?? array(),
				);
			}

			// Queue the dump as a regular file: process_files() streams it with resume support.
			// (A retry after a crash between queueing and saving the checkpoint must not queue it twice.)
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				FileQueue::get_table_name(),
				array(
					'job_id'        => $job_id,
					'relative_path' => 'database.sql',
				),
				array( '%s', '%s' )
			);
			FileQueue::add_files_batch( $job_id, array(
				array(
					'path'     => $db_path,
					'relative' => 'database.sql',
					'size'     => (int) filesize( $db_path ),
				),
			) );

			$this->logger->info( 'Database backup queued for archiving', array(
				'size'   => filesize( $db_path ),
				'tables' => $db_result['tables'] ?? 0,
			) );

			$checkpoint['database_done'] = true;
		}

		$this->logger->info( 'Archive prepared successfully' );

		return array(
			'success'        => true,
			'completed'      => true,
			'checkpoint'     => $checkpoint,
			'archive_offset' => (int) filesize( $archive_path ),
			'manifest'       => $manifest,
		);
	}

	/**
	 * Create backup manifest.
	 *
	 * @param string $job_id  Job ID.
	 * @param array  $options Backup options.
	 * @param array  $stats   File queue stats.
	 * @return array Manifest data.
	 */
	private function create_manifest( string $job_id, array $options, array $stats ): array {
		global $wpdb;

		return array(
			'version'           => defined( 'SWISH_BACKUP_VERSION' ) ? SWISH_BACKUP_VERSION : '1.0.0',
			'job_id'            => $job_id,
			'type'              => $options['type'] ?? 'full',
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'site_url'          => get_site_url(),
			'home_url'          => get_home_url(),
			'table_prefix'      => $wpdb->prefix,
			'multisite'         => is_multisite(),
			'active_theme'      => get_template(),
			'active_plugins'    => $this->get_active_plugins(),
			'file_count'        => $stats['total'] ?? 0,
			'options'           => $options,
			'format'            => 'swish',
		);
	}

	/**
	 * Get active plugins list.
	 *
	 * @return array Active plugins.
	 */
	private function get_active_plugins(): array {
		$active = get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			$active = array_merge( $active, array_keys( $network_active ) );
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * Execute processing phase - archive queued files.
	 *
	 * This phase processes files from the queue and writes them to the archive.
	 * NOTE: prepare_archive() MUST be called first to add manifest and database.
	 *
	 * The cursor records where the archive is known to be good. 'archive_offset'
	 * is the end of the last fully written entry and 'partial' describes a
	 * trailing entry that ran out of time ({file_id, entry_offset, bytes_written}).
	 * Pass the cursor returned by the previous call back in: a large file is then
	 * resumed where it stopped instead of being appended again from the start,
	 * and anything a crashed request appended without recording is cut off.
	 *
	 * @param string $job_id       Job ID.
	 * @param string $archive_path Path to the archive file.
	 * @param array  $cursor       Cursor from the previous call (empty on the first call).
	 * @return array Result with 'completed', 'processed', 'stats', 'cursor'.
	 */
	public function process_files( string $job_id, string $archive_path, array $cursor = array() ): array {
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
				'cursor'    => $cursor,
			);
		}

		// Make the archive tail agree with the cursor before appending anything.
		$tail = $this->reconcile_archive_tail( $cursor );
		if ( isset( $tail['error'] ) ) {
			$this->archiver->close();
			return array(
				'completed' => false,
				'processed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'error'     => $tail['error'],
				'stats'     => FileQueue::get_job_stats( $job_id ),
				'cursor'    => $cursor,
			);
		}
		$partial   = $tail['partial'];
		$committed = $this->archiver->get_end_position();
		if ( null !== $partial ) {
			$committed = (int) $partial['entry_offset'];
		}

		// Get batch of pending files.
		$batch = FileQueue::get_pending_batch( $job_id, $this->process_batch_size );

		// The partially written file must be the next one up; if the queue disagrees
		// (it was reset or re-indexed), drop the partial entry and start it over.
		if ( null !== $partial && ( empty( $batch ) || (int) $batch[0]['id'] !== (int) $partial['file_id'] ) ) {
			$this->archiver->truncate_to( $committed );
			FileQueue::update_progress( (int) $partial['file_id'], 0, 0 );
			$partial   = null;
			$committed = $this->archiver->get_end_position();
		}

		// Rows reset to pending by reset_stale_processing() may carry a stale
		// bytes_written from a crashed request; only the cursor's partial is trusted.
		foreach ( $batch as $i => $row ) {
			if ( (int) ( $row['bytes_written'] ?? 0 ) > 0 && ( null === $partial || (int) $row['id'] !== (int) $partial['file_id'] ) ) {
				FileQueue::update_progress( (int) $row['id'], 0, 0 );
				$batch[ $i ]['bytes_written'] = 0;
			}
		}

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
			// Size captured at indexing time: the header is written with it and exactly
			// that many bytes follow, even if the file changed since (see SwishArchiver::add_file).
			$file_size = (int) $file_record['file_size'];

			$resuming      = null !== $partial && (int) $partial['file_id'] === $file_id;
			$resume_offset = $resuming ? (int) $partial['bytes_written'] : 0;
			$entry_offset  = $resuming ? (int) $partial['entry_offset'] : $this->archiver->get_end_position();

			// Mark as processing (a resumed file is the same attempt, not a new one).
			FileQueue::mark_processing( $file_id, ! $resuming );

			if ( ! $resuming ) {
				// Check if file still exists.
				if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
					FileQueue::mark_skipped( $file_id, 'File not found or not readable' );
					++$skipped;
					continue;
				}

				// Check file size - no per-file limit by default; filterable for constrained hosts.
				$actual_size = filesize( $file_path );
				$max_file_size = apply_filters( 'swish_backup_max_file_size', PHP_INT_MAX );

				if ( $actual_size > $max_file_size ) {
					FileQueue::mark_skipped( $file_id, 'File too large: ' . $this->format_bytes( $actual_size ) );
					$this->logger->warning( 'Skipping large file', array(
						'path' => $file_path,
						'size' => $this->format_bytes( $actual_size ),
					) );
					++$skipped;
					continue;
				}
			}

			// Add file to archive.
			try {
				$bytes = 0;
				// CLI mode: no timeout (0), otherwise use remaining time budget.
				$file_timeout = $this->cli_mode ? 0 : $this->get_remaining_time();
				$this->logger->debug( 'Adding file to archive', array(
					'file_id'       => $file_id,
					'path'          => $relative_path,
					'size'          => $this->format_bytes( $file_size ),
					'resume_offset' => $resume_offset,
					'file_timeout'  => $file_timeout,
				) );

				$result = $this->archiver->add_file(
					$file_path,
					$relative_path,
					$resume_offset,
					$bytes,
					$file_timeout,
					$file_size
				);

				if ( $result ) {
					FileQueue::mark_completed( $file_id, $resume_offset + $bytes );
					++$processed;
					$partial   = null;
					$committed = $this->archiver->get_end_position();
					$this->logger->debug( 'File completed', array(
						'file_id' => $file_id,
						'path'    => $relative_path,
						'bytes'   => $resume_offset + $bytes,
					) );
				} elseif ( 0 === $resume_offset + $bytes ) {
					// Out of time before the first chunk: only the header is in the
					// archive. Resuming at offset 0 would write a second header, so cut
					// the entry and start it over next request.
					$this->archiver->truncate_to( $entry_offset );
					FileQueue::update_progress( $file_id, 0, 0 );
					FileQueue::reset_to_pending( $file_id );
					$partial   = null;
					$committed = $entry_offset;
					break; // Yield to next request.
				} else {
					// Out of time mid-file: record how far we got and continue from
					// there next request. The header already carries the full size, so
					// appending the remaining bytes later keeps the archive consistent.
					$written_so_far = $resume_offset + $bytes;
					FileQueue::update_progress( $file_id, $written_so_far, 0 );
					FileQueue::reset_to_pending( $file_id );
					$partial   = array(
						'file_id'       => $file_id,
						'entry_offset'  => $entry_offset,
						'bytes_written' => $written_so_far,
					);
					$committed = $entry_offset;
					$this->logger->info( 'File partially written, will resume next request', array(
						'file_id'        => $file_id,
						'path'           => $relative_path,
						'bytes_written'  => $written_so_far,
						'file_size'      => $file_size,
						'bytes_this_try' => $bytes,
					) );
					break; // Yield to next request.
				}
			} catch ( ArchiveWriteException $e ) {
				// The archive itself cannot be written (disk full, quota): retrying
				// per file would spin forever. Cut back to the entry start, release
				// the row and fail the step with the real reason.
				$this->archiver->truncate_to( $entry_offset );
				FileQueue::update_progress( $file_id, 0, 0 );
				FileQueue::reset_to_pending( $file_id );
				$this->archiver->close();
				$this->logger->error( 'Archive write failed', array( 'error' => $e->getMessage() ) );

				return array(
					'completed' => false,
					'processed' => $processed,
					'failed'    => $failed,
					'skipped'   => $skipped,
					'error'     => $e->getMessage(),
					'stats'     => FileQueue::get_job_stats( $job_id ),
					'cursor'    => array(
						'archive_offset' => $entry_offset,
						'partial'        => null,
					),
				);
			} catch ( \Exception $e ) {
				// Whatever this entry wrote is unusable: cut the archive back to its start.
				$this->archiver->truncate_to( $entry_offset );
				$partial   = null;
				$committed = $entry_offset;
				FileQueue::update_progress( $file_id, 0, 0 );
				FileQueue::mark_failed( $file_id, $e->getMessage(), 0 );
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
			'cursor'    => array(
				'archive_offset' => $committed,
				'partial'        => $partial,
			),
		);
	}

	/**
	 * Make the archive tail match the cursor from the previous request.
	 *
	 * The cursor is saved once per request while queue rows are completed per
	 * file, so after a hard kill the archive may hold complete entries the
	 * cursor does not know about (their rows already say "completed" and will
	 * never be rewritten). Those entries are kept: the walk from the cursor
	 * advances over every structurally complete entry and cuts only the
	 * trailing incomplete one. An entry whose row was not yet marked completed
	 * is simply written again later (a harmless duplicate).
	 *
	 * Situations handled:
	 * - the previous request ended mid-file and recorded it: if the entry is
	 *   still incomplete keep exactly the recorded bytes and resume it; if it
	 *   turned out complete (killed after finishing it), treat it as committed;
	 * - the previous request died without recording what it appended: keep the
	 *   complete entries, cut the incomplete tail;
	 * - the archive is shorter than the committed size: it was modified or
	 *   deleted underneath us and cannot be continued.
	 *
	 * @param array $cursor Cursor from the previous call.
	 * @return array{partial: array|null, error?: string}
	 */
	private function reconcile_archive_tail( array $cursor ): array {
		if ( ! isset( $cursor['archive_offset'] ) ) {
			return array( 'partial' => null ); // No cursor (CLI/legacy caller): nothing recorded to reconcile.
		}

		$committed = (int) $cursor['archive_offset'];
		$partial   = $cursor['partial'] ?? null;
		$actual    = $this->archiver->get_end_position();

		if ( $actual < $committed ) {
			$this->logger->error( 'Archive is shorter than the committed size', array(
				'committed' => $committed,
				'actual'    => $actual,
			) );
			return array(
				'partial' => null,
				'error'   => 'The backup archive is shorter than expected; it was modified or deleted while the backup was running.',
			);
		}

		if ( $actual === $committed ) {
			return array( 'partial' => null ); // Nothing after the last committed entry.
		}

		// Keep every complete entry after the committed offset.
		$kept = $this->archiver->find_last_complete_entry_end( $committed );

		$has_partial = is_array( $partial ) && isset( $partial['file_id'], $partial['bytes_written'] ) && (int) $partial['bytes_written'] > 0;

		if ( $has_partial && $kept === $committed ) {
			// The recorded partial entry is still incomplete: resume it at the recorded byte.
			$expected = $committed + SwishArchiver::HEADER_SIZE + (int) $partial['bytes_written'];

			if ( $actual >= $expected ) {
				if ( $actual > $expected ) {
					$this->logger->warning( 'Cutting unrecorded bytes after partial entry', array(
						'expected' => $expected,
						'actual'   => $actual,
					) );
					$this->archiver->truncate_to( $expected );
				}
				$partial['entry_offset'] = $committed;
				return array( 'partial' => $partial );
			}

			// Archive shorter than the recorded partial: restart that entry from scratch.
			$this->archiver->truncate_to( $committed );
			FileQueue::update_progress( (int) $partial['file_id'], 0, 0 );
			return array( 'partial' => null );
		}

		if ( $has_partial ) {
			// The partial entry was finished by a request that died before saving
			// the cursor; its row is completed or will be rewritten. Either way the
			// recorded offset is stale.
			FileQueue::update_progress( (int) $partial['file_id'], 0, 0 );
		}

		if ( $actual > $kept ) {
			$this->logger->warning( 'Cutting incomplete entry appended by a request that did not finish', array(
				'committed' => $committed,
				'kept'      => $kept,
				'actual'    => $actual,
			) );
			$this->archiver->truncate_to( $kept );
		}

		return array( 'partial' => null );
	}

	/**
	 * Finalize the archive: write the EOF marker, verify every entry, compute
	 * the checksum, register-ready.
	 *
	 * Verification and hashing read the whole archive, which on a multi-gigabyte
	 * backup takes longer than a request may run. Both are therefore resumable:
	 * call again with the returned 'checkpoint' until 'completed' is true. The
	 * time budget is the pipeline's (none in CLI mode).
	 *
	 * @param string $job_id       Job ID.
	 * @param string $archive_path Path to the archive file.
	 * @param array  $checkpoint   Checkpoint from the previous call (empty to start).
	 * @return array Result with 'success', 'completed', 'checkpoint', and when completed 'size', 'checksum', 'stats'.
	 */
	public function finalize( string $job_id, string $archive_path, array $checkpoint = array() ): array {
		// Verify archive exists.
		if ( ! file_exists( $archive_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Archive file not found',
			);
		}

		$budget    = $this->cli_mode ? 0 : max( 1, $this->time_budget - ( microtime( true ) - $this->start_time ) );
		$finalizer = new SwishArchiver( $archive_path );

		if ( empty( $checkpoint['verify_started'] ) ) {
			$this->logger->info( 'Finalizing archive', array(
				'job_id' => $job_id,
				'path'   => $archive_path,
			) );
			$checkpoint['verify_started'] = true;
		}

		// Walk the archive header by header. Never register an archive the extractor
		// could not read back: a structurally broken archive is deleted (it is useless
		// and would otherwise sit unseen on disk). The walk also decides whether the
		// EOF marker still has to be written: an archive that ends exactly on an entry
		// boundary gets one, an archive that already ends with the marker (a previous
		// finalize attempt) is left alone. Comparing the last block to null bytes is
		// not enough, as an entry's content may end in null bytes itself.
		if ( empty( $checkpoint['verified'] ) ) {
			$check = $finalizer->verify_structure(
				(int) ( $checkpoint['verify_pos'] ?? 0 ),
				(int) ( $checkpoint['verify_entries'] ?? 0 ),
				$budget
			);

			if ( ! $check['completed'] ) {
				$checkpoint['verify_pos']     = $check['pos'];
				$checkpoint['verify_entries'] = $check['entries'];

				return array(
					'success'    => true,
					'completed'  => false,
					'checkpoint' => $checkpoint,
					'message'    => sprintf( 'Verifying archive: %s entries checked', number_format_i18n( $check['entries'] ) ),
				);
			}

			$needs_eof = ! $check['valid']
				&& 0 === strpos( (string) $check['error'], 'Archive ends without an EOF marker' )
				&& (int) $check['pos'] === (int) filesize( $archive_path );

			if ( $needs_eof ) {
				if ( ! $finalizer->open_for_write() ) {
					return array(
						'success' => false,
						'error'   => 'Failed to open archive to write EOF marker',
					);
				}
				$finalizer->close( true );
			} elseif ( ! $check['valid'] ) {
				$this->logger->error( 'Archive failed structural verification', array(
					'path'    => $archive_path,
					'entries' => $check['entries'],
					'error'   => $check['error'],
				) );
				wp_delete_file( $archive_path );

				return array(
					'success' => false,
					'error'   => sprintf(
						'Backup archive failed verification (%s) and was discarded. Please run the backup again.',
						$check['error']
					),
				);
			}

			$checkpoint['verified'] = true;
			$checkpoint['entries']  = $check['entries'];
			$budget                 = $this->cli_mode ? 0 : max( 1, $this->time_budget - ( microtime( true ) - $this->start_time ) );
		}

		// Calculate checksum (resumable).
		if ( empty( $checkpoint['checksum'] ) ) {
			$hash = $finalizer->checksum_chunked(
				(int) ( $checkpoint['hash_offset'] ?? 0 ),
				(string) ( $checkpoint['hash_context'] ?? '' ),
				$budget
			);

			if ( '' !== $hash['error'] ) {
				return array(
					'success' => false,
					'error'   => 'Failed to checksum archive: ' . $hash['error'],
				);
			}

			if ( ! $hash['completed'] ) {
				$checkpoint['hash_offset']  = $hash['offset'];
				$checkpoint['hash_context'] = $hash['context'];

				return array(
					'success'    => true,
					'completed'  => false,
					'checkpoint' => $checkpoint,
					'message'    => sprintf( 'Computing checksum: %s of %s', size_format( $hash['offset'] ), size_format( (int) filesize( $archive_path ) ) ),
				);
			}

			$checkpoint['checksum'] = $hash['checksum'];
			unset( $checkpoint['hash_context'] );
		}

		$checksum = $checkpoint['checksum'];
		$size = filesize( $archive_path );

		// Get final stats.
		$stats = FileQueue::get_job_stats( $job_id );
		$stats['entries'] = (int) ( $checkpoint['entries'] ?? 0 );

		// Clean up queue.
		FileQueue::clear_job( $job_id );

		// Clean up the temp directory used for manifest.json / database.sql.
		$this->remove_temp_directory( dirname( $archive_path ) . '/temp-' . $job_id );

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
			'success'    => true,
			'completed'  => true,
			'checkpoint' => $checkpoint,
			'file_path'  => $archive_path,
			'size'       => $size,
			'checksum'   => $checksum,
			'stats'      => $stats,
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
		// CLI mode: only yield on memory pressure, not time.
		if ( $this->cli_mode ) {
			return $this->is_memory_pressure();
		}

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
		$plugins_dir = str_replace( '\\', '/', WP_PLUGIN_DIR ) . '/';
		if ( strpos( $normalized_path, $plugins_dir ) === 0 ) {
			$relative_to_plugins = substr( $normalized_path, strlen( $plugins_dir ) );
			$plugin_folder = explode( '/', $relative_to_plugins )[0];

			// Never archive this plugin itself (same rule as FileBackup): restoring it
			// over the copy that is running the import breaks the import mid-way.
			if ( in_array( $plugin_folder, array( 'swish-migrate-and-backup', 'swish-migrate-and-backup-pro' ), true ) ) {
				return true;
			}

			if ( ! empty( $this->exclude_plugins ) && in_array( $plugin_folder, $this->exclude_plugins, true ) ) {
				return true;
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
	 * Uses multiple strategies to handle symlinks, Docker paths, etc.
	 *
	 * @param string $path Absolute path.
	 * @return string Relative path.
	 */
	private function get_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );

		// Try matching against ABSPATH first.
		$root = str_replace( '\\', '/', rtrim( ABSPATH, '/' ) ) . '/';
		if ( strpos( $path, $root ) === 0 ) {
			return substr( $path, strlen( $root ) );
		}

		// Try matching against WP_CONTENT_DIR (handles symlinks/Docker mounts).
		$wp_content = str_replace( '\\', '/', rtrim( WP_CONTENT_DIR, '/' ) );
		if ( strpos( $path, $wp_content . '/' ) === 0 ) {
			return 'wp-content' . substr( $path, strlen( $wp_content ) );
		}

		// Try matching against plugin directory.
		$plugins_dir = str_replace( '\\', '/', rtrim( WP_PLUGIN_DIR, '/' ) );
		if ( strpos( $path, $plugins_dir . '/' ) === 0 ) {
			return 'wp-content/plugins' . substr( $path, strlen( $plugins_dir ) );
		}

		// Try matching against theme directory.
		$themes_dir = str_replace( '\\', '/', rtrim( get_theme_root(), '/' ) );
		if ( strpos( $path, $themes_dir . '/' ) === 0 ) {
			return 'wp-content/themes' . substr( $path, strlen( $themes_dir ) );
		}

		// Try matching against uploads directory.
		$upload_dir = wp_upload_dir();
		$uploads_base = str_replace( '\\', '/', rtrim( $upload_dir['basedir'], '/' ) );
		if ( strpos( $path, $uploads_base . '/' ) === 0 ) {
			return 'wp-content/uploads' . substr( $path, strlen( $uploads_base ) );
		}

		// Try realpath matching for all known directories.
		// This handles symlinks where the iterator returns resolved paths.
		$real_path = realpath( $path );
		if ( $real_path ) {
			$real_path = str_replace( '\\', '/', $real_path );

			// Try plugins directory with realpath.
			$real_plugins = realpath( WP_PLUGIN_DIR );
			if ( $real_plugins ) {
				$real_plugins = str_replace( '\\', '/', rtrim( $real_plugins, '/' ) );
				if ( strpos( $real_path, $real_plugins . '/' ) === 0 ) {
					return 'wp-content/plugins' . substr( $real_path, strlen( $real_plugins ) );
				}
			}

			// Try themes directory with realpath.
			$real_themes = realpath( get_theme_root() );
			if ( $real_themes ) {
				$real_themes = str_replace( '\\', '/', rtrim( $real_themes, '/' ) );
				if ( strpos( $real_path, $real_themes . '/' ) === 0 ) {
					return 'wp-content/themes' . substr( $real_path, strlen( $real_themes ) );
				}
			}

			// Try uploads directory with realpath.
			$real_uploads = realpath( $upload_dir['basedir'] );
			if ( $real_uploads ) {
				$real_uploads = str_replace( '\\', '/', rtrim( $real_uploads, '/' ) );
				if ( strpos( $real_path, $real_uploads . '/' ) === 0 ) {
					return 'wp-content/uploads' . substr( $real_path, strlen( $real_uploads ) );
				}
			}

			// Try WP_CONTENT_DIR with realpath.
			$real_content = realpath( WP_CONTENT_DIR );
			if ( $real_content ) {
				$real_content = str_replace( '\\', '/', rtrim( $real_content, '/' ) );
				if ( strpos( $real_path, $real_content . '/' ) === 0 ) {
					return 'wp-content' . substr( $real_path, strlen( $real_content ) );
				}
			}

			// Try ABSPATH with realpath.
			$real_root = realpath( ABSPATH );
			if ( $real_root ) {
				$real_root = str_replace( '\\', '/', rtrim( $real_root, '/' ) ) . '/';
				if ( strpos( $real_path, $real_root ) === 0 ) {
					return substr( $real_path, strlen( $real_root ) );
				}
			}
		}

		// Look for wp-content in path and preserve structure from there.
		$wp_content_pos = strpos( $path, '/wp-content/' );
		if ( false !== $wp_content_pos ) {
			return substr( $path, $wp_content_pos + 1 ); // +1 to skip leading slash.
		}

		// Also check realpath for wp-content pattern.
		if ( $real_path ) {
			$wp_content_pos = strpos( $real_path, '/wp-content/' );
			if ( false !== $wp_content_pos ) {
				return substr( $real_path, $wp_content_pos + 1 );
			}
		}

		// Try to extract path structure from common WordPress patterns.
		// Match patterns like /plugins/plugin-name/... or /themes/theme-name/...
		if ( preg_match( '#/plugins/([^/]+/.+)$#', $path, $matches ) ) {
			return 'wp-content/plugins/' . $matches[1];
		}
		if ( preg_match( '#/themes/([^/]+/.+)$#', $path, $matches ) ) {
			return 'wp-content/themes/' . $matches[1];
		}
		if ( preg_match( '#/uploads/(\d{4}/\d{2}/.+)$#', $path, $matches ) ) {
			return 'wp-content/uploads/' . $matches[1];
		}
		if ( preg_match( '#/uploads/([^/]+/.+)$#', $path, $matches ) ) {
			return 'wp-content/uploads/' . $matches[1];
		}

		// Fallback to basename (logs warning as this loses structure).
		$this->logger->warning( 'Could not determine relative path, using basename', array(
			'path'      => $path,
			'real_path' => $real_path ?? 'N/A',
			'abspath'   => ABSPATH,
			'plugins'   => WP_PLUGIN_DIR,
			'themes'    => get_theme_root(),
			'uploads'   => $upload_dir['basedir'],
		) );
		return basename( $path );
	}

	/**
	 * Remove what a job left on disk besides the archive: the temp directory
	 * holding manifest.json / database.sql. Called when a job fails.
	 *
	 * @param string $job_id       Job ID.
	 * @param string $archive_path Archive path (its directory hosts the temp dir).
	 * @return void
	 */
	public function cleanup_job_files( string $job_id, string $archive_path ): void {
		$this->remove_temp_directory( dirname( $archive_path ) . '/temp-' . $job_id );
	}

	/**
	 * Remove a job temp directory and everything in it.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function remove_temp_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $file->getPathname() );
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
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
