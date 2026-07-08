<?php
/**
 * WP-CLI Commands for Swish Backup.
 *
 * @package SwishMigrateAndBackup\CLI
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\CLI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Import\ImportPipeline;
use SwishMigrateAndBackup\Import\ImportSession;
use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Core\Container;
use WP_CLI;

/**
 * Swish Backup CLI Commands.
 *
 * ## EXAMPLES
 *
 *     # Create a database backup
 *     wp swish backup --type=database
 *
 *     # Create a full backup
 *     wp swish backup --type=full
 *
 *     # Import a backup
 *     wp swish import /path/to/backup.zip --old-url=https://old.com --new-url=https://new.com
 */
class Commands {

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
	 * Create a backup.
	 *
	 * Uses the pipeline approach by default (like AI1WM) which ensures ALL files
	 * are enumerated before archiving begins. This prevents missing files.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Backup type: full, database, or files.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - database
	 *   - files
	 * ---
	 *
	 * [--include-core]
	 * : Include WordPress core files (only for full/files backups).
	 *
	 * [--legacy]
	 * : Use legacy backup method instead of pipeline (not recommended).
	 *
	 * ## EXAMPLES
	 *
	 *     wp swish backup --type=database
	 *     wp swish backup --type=full --include-core
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function backup( array $args, array $assoc_args ): void {
		$type         = $assoc_args['type'] ?? 'full';
		$include_core = isset( $assoc_args['include-core'] );
		$use_pipeline = ! isset( $assoc_args['legacy'] ); // Use pipeline by default.

		WP_CLI::log( "Starting {$type} backup..." );

		// Use pipeline approach for reliable file enumeration (like AI1WM).
		if ( $use_pipeline && 'database' !== $type ) {
			$this->backup_with_pipeline( $type, $include_core );
			return;
		}

		// Legacy approach (only for database-only backups or if --legacy flag is set).
		// Get BackupManager from container.
		$container      = Container::get_instance();
		$backup_manager = $container->get( BackupManager::class );

		// Build options.
		$options = array(
			'backup_database' => true,
			'include_plugins' => true,
			'include_themes'  => true,
			'include_uploads' => true,
			'include_core'    => $include_core,
		);

		try {
			$result = null;

			switch ( $type ) {
				case 'database':
					WP_CLI::log( 'Creating database backup...' );
					$result = $backup_manager->create_database_backup( $options );
					break;

				case 'files':
					WP_CLI::log( 'Creating files backup...' );
					$result = $backup_manager->create_files_backup( $options );
					break;

				case 'full':
				default:
					WP_CLI::log( 'Creating full backup...' );
					$result = $backup_manager->create_full_backup( $options );
					break;
			}

			if ( null === $result ) {
				WP_CLI::error( 'Backup failed - no result returned.' );
				return;
			}

			// Check for chunked/async backup.
			if ( ! empty( $result['chunked'] ) ) {
				$job_id = $result['job_id'];
				WP_CLI::log( "Backup started in chunks (job: {$job_id}). Processing..." );

				// Continue processing until complete.
				$max_iterations = 1000;
				$last_progress  = -1;

				for ( $i = 0; $i < $max_iterations; $i++ ) {
					// Continue the backup (processes one chunk).
					$backup_manager->continue_backup( $job_id );

					// Poll job status.
					$status = $backup_manager->get_job_status( $job_id );

					if ( ! $status ) {
						WP_CLI::error( 'Failed to get backup status.' );
						return;
					}

					$job_status = $status['status'] ?? 'unknown';
					$progress   = (int) ( $status['progress'] ?? 0 );

					// Only log when progress changes.
					if ( $progress !== $last_progress ) {
						WP_CLI::log( sprintf( '[%s] %d%%', $job_status, $progress ) );
						$last_progress = $progress;
					}

					if ( 'complete' === $job_status ) {
						$result = array(
							'backup_file' => $status['file_path'] ?? '',
						);
						break;
					}

					if ( 'failed' === $job_status ) {
						WP_CLI::error( 'Backup failed: ' . ( $status['error_message'] ?? 'Unknown error' ) );
						return;
					}

					// Small delay between iterations.
					usleep( 100000 ); // 100ms.
				}
			}

			// Output result.
			if ( ! empty( $result['backup_file'] ) ) {
				WP_CLI::success( "Backup complete: {$result['backup_file']}" );
			} elseif ( ! empty( $result['file'] ) ) {
				WP_CLI::success( "Backup complete: {$result['file']}" );
			} else {
				WP_CLI::success( 'Backup complete.' );
			}
		} catch ( \Exception $e ) {
			WP_CLI::error( 'Backup failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Create backup using pipeline approach (like AI1WM).
	 *
	 * This ensures ALL files are enumerated before archiving begins.
	 *
	 * @param string $type         Backup type: full or files.
	 * @param bool   $include_core Whether to include WordPress core files.
	 */
	private function backup_with_pipeline( string $type, bool $include_core ): void {
		$settings = get_option( 'swish_backup_settings', array() );

		// Build options matching pipeline expectations.
		// CLI mode uses large batch sizes since there's no HTTP timeout.
		$options = array(
			'type'                 => $type,
			'backup_database'      => 'full' === $type,
			'backup_plugins'       => true,
			'backup_themes'        => true,
			'backup_uploads'       => true,
			'backup_core_files'    => $include_core,
			'exclude_plugins'      => $settings['exclude_plugins'] ?? array(),
			'exclude_themes'       => $settings['exclude_themes'] ?? array(),
			'exclude_uploads'      => $settings['exclude_uploads'] ?? array(),
			'cli_mode'             => true,  // Disables time-based yielding.
			'pipeline_batch_size'  => 500,   // Large batch for CLI (no HTTP timeout).
		);

		// Create backup directory.
		$backup_dir = wp_upload_dir()['basedir'] . '/swish-backups';
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		// Generate archive path.
		$site_name = sanitize_file_name( wp_parse_url( get_site_url(), PHP_URL_HOST ) );
		$timestamp = gmdate( 'Y-m-d-His' );
		$archive_filename = "{$site_name}-{$type}-{$timestamp}.swish";
		$archive_path = $backup_dir . '/' . $archive_filename;

		// Initialize pipeline.
		$pipeline = new \SwishMigrateAndBackup\Backup\BackupPipeline( $this->logger );
		$pipeline->configure( $options );

		// Get directories to backup.
		$directories = $pipeline->get_backup_directories( $options );

		// Ensure file queue table exists.
		\SwishMigrateAndBackup\Backup\FileQueue::create_table();

		// Generate job ID.
		$job_id = 'cli_' . wp_generate_uuid4();

		WP_CLI::log( 'Phase 1: Indexing files...' );

		// Phase 1: Index ALL files (no timeout in CLI).
		$index_result = $pipeline->index_files( $job_id, $directories, 0 );

		// Continue indexing until complete (handles memory pressure yields).
		$offset = $index_result['offset'];
		while ( ! $index_result['completed'] ) {
			$index_result = $pipeline->index_files( $job_id, $directories, $offset );
			$offset = $index_result['offset'];
		}

		$stats = \SwishMigrateAndBackup\Backup\FileQueue::get_job_stats( $job_id );
		WP_CLI::log( sprintf( 'Indexed %d files.', $stats['total'] ) );

		// Prepare archive with manifest and database.
		WP_CLI::log( 'Preparing archive with manifest and database...' );
		$prepare_result = $pipeline->prepare_archive( $job_id, $archive_path, $options );
		if ( ! $prepare_result['success'] ) {
			WP_CLI::error( 'Failed to prepare archive: ' . ( $prepare_result['error'] ?? 'Unknown error' ) );
			return;
		}

		WP_CLI::log( 'Phase 2: Archiving files...' );

		// Phase 2: Archive files in chunks.
		$last_progress = -1;
		$max_iterations = 10000; // Safety limit.

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$result = $pipeline->process_files( $job_id, $archive_path );

			$progress = (int) ( $result['stats']['progress'] ?? 0 );
			if ( $progress !== $last_progress && ( $progress % 5 === 0 || $result['completed'] ) ) {
				WP_CLI::log( sprintf( 'Archiving... %d%% (%d/%d files)', $progress, $result['stats']['completed'] ?? 0, $stats['total'] ) );
				$last_progress = $progress;
			}

			if ( $result['completed'] ) {
				break;
			}
		}

		WP_CLI::log( 'Phase 3: Finalizing...' );

		// Phase 3: Finalize archive.
		$final_result = $pipeline->finalize( $job_id, $archive_path );

		if ( ! $final_result['success'] ) {
			WP_CLI::error( 'Backup failed during finalization: ' . ( $final_result['error'] ?? 'Unknown error' ) );
			return;
		}

		// Register backup so it appears in the admin Backups list.
		$backup_manager = Container::get_instance()->get( BackupManager::class );
		$registered = $backup_manager->register_backup( array(
			'job_id'    => $job_id,
			'type'      => $type,
			'file_path' => $final_result['file_path'],
			'file_size' => $final_result['size'],
			'checksum'  => $final_result['checksum'],
		) );

		if ( ! $registered ) {
			WP_CLI::warning( 'Backup file created but failed to register in database. It may not appear in the admin UI.' );
		}

		WP_CLI::success( sprintf(
			'Backup complete: %s (%s)',
			$final_result['file_path'],
			size_format( $final_result['size'] )
		) );
	}

	/**
	 * Import/migrate from a backup file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the backup file (.zip).
	 *
	 * [--old-url=<url>]
	 * : The old site URL to replace. Auto-detected from backup if not provided.
	 *
	 * [--new-url=<url>]
	 * : The new site URL. Defaults to current site URL.
	 *
	 * [--skip-url-replace]
	 * : Skip URL replacement (restore only, no migration).
	 *
	 * ## EXAMPLES
	 *
	 *     wp swish import /path/to/backup.zip
	 *     wp swish import backup.zip --old-url=https://old.com --new-url=https://new.com
	 *     wp swish import backup.zip --skip-url-replace
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a backup file path.' );
			return;
		}

		$file = $args[0];

		// Resolve relative paths.
		if ( ! file_exists( $file ) ) {
			// Try relative to WordPress root.
			$file = ABSPATH . ltrim( $file, '/' );
		}

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( "Backup file not found: {$args[0]}" );
			return;
		}

		$old_url          = $assoc_args['old-url'] ?? '';
		$new_url          = $assoc_args['new-url'] ?? home_url();
		$skip_url_replace = isset( $assoc_args['skip-url-replace'] );

		WP_CLI::log( "Starting import from: {$file}" );
		WP_CLI::log( "File size: " . size_format( filesize( $file ) ) );

		// Get services from container.
		$container       = Container::get_instance();
		$restore_manager = $container->get( RestoreManager::class );
		$migrator        = $skip_url_replace ? null : $container->get( Migrator::class );

		// Create the import pipeline.
		$pipeline = new ImportPipeline( $this->logger, $restore_manager, $migrator );

		// Initialize the import session.
		$init_result = $this->initialize_import_session( $file, $old_url, $new_url, $skip_url_replace );
		if ( ! $init_result['success'] ) {
			WP_CLI::error( 'Failed to initialize import: ' . ( $init_result['error'] ?? 'Unknown error' ) );
			return;
		}

		$secret_key = $init_result['secret_key'];
		WP_CLI::log( 'Import session initialized.' );

		// Process until complete.
		$iteration      = 0;
		$max_iterations = 50000; // Safety limit - imports can be long.
		$last_phase     = '';

		while ( $iteration < $max_iterations ) {
			++$iteration;

			$result = $pipeline->execute( $secret_key );

			if ( ! ( $result['success'] ?? true ) ) {
				WP_CLI::error( 'Import failed: ' . ( $result['error'] ?? 'Unknown error' ) );
				return;
			}

			// Show progress.
			$phase       = $result['phase'] ?? 'unknown';
			$phase_label = $result['phase_label'] ?? $phase;
			$progress    = $result['progress'] ?? 0;
			$message     = $result['message'] ?? '';
			$detail      = $result['detail'] ?? '';

			// Only log when phase changes or every 10 iterations.
			if ( $phase !== $last_phase || ( $iteration % 10 ) === 0 ) {
				$log_line = sprintf( '[%s] %d%%', $phase_label, $progress );
				if ( $message ) {
					$log_line .= " - {$message}";
				}
				if ( $detail ) {
					$log_line .= " ({$detail})";
				}

				WP_CLI::log( $log_line );
				$last_phase = $phase;
			}

			// Check if complete.
			if ( $result['completed'] ?? false ) {
				break;
			}
		}

		if ( $iteration >= $max_iterations ) {
			WP_CLI::error( 'Import exceeded maximum iterations.' );
			return;
		}

		WP_CLI::success( 'Import complete!' );
	}

	/**
	 * Initialize an import session.
	 *
	 * @param string $file             Path to backup file.
	 * @param string $old_url          Old URL to replace.
	 * @param string $new_url          New URL.
	 * @param bool   $skip_url_replace Whether to skip URL replacement.
	 * @return array Result with 'success' and 'secret_key'.
	 */
	private function initialize_import_session( string $file, string $old_url, string $new_url, bool $skip_url_replace ): array {
		// Generate a secret key for the session using ImportSession.
		$secret_key = ImportSession::generate_secret_key();

		// Determine backup type from extension.
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		// Build options array (matching ImportPipeline expectations).
		$options = array(
			'old_url'          => $old_url,
			'new_url'          => $new_url,
			'restore_database' => true,
			'restore_files'    => $extension !== 'sql',
		);

		// Initialize the session state (matching ImportPipeline structure).
		$state = array(
			'phase'            => 'validate', // Phase name, not priority number.
			'phase_priority'   => 5,          // Priority for phase ordering.
			'backup_path'      => $file,      // Pipeline uses backup_path, not backup_file.
			'backup_type'      => $extension === 'sql' ? 'database' : 'full',
			'options'          => $options,   // Options nested as expected by pipeline.
			'progress'         => 0,
			'started_at'       => gmdate( 'Y-m-d H:i:s' ),
			'last_activity'    => gmdate( 'Y-m-d H:i:s' ),
		);

		// Store the session state.
		ImportSession::save_state( $state );

		return array(
			'success'    => true,
			'secret_key' => $secret_key,
		);
	}

	/**
	 * Show import status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swish status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$state = ImportSession::get_state();

		if ( ! $state ) {
			WP_CLI::log( 'No active import session.' );
			return;
		}

		WP_CLI::log( 'Active import session:' );
		WP_CLI::log( sprintf( '  Phase: %s', $state['phase'] ?? 'unknown' ) );
		WP_CLI::log( sprintf( '  Progress: %d%%', $state['progress'] ?? 0 ) );
		WP_CLI::log( sprintf( '  Backup file: %s', $state['backup_file'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( '  Old URL: %s', $state['old_url'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( '  New URL: %s', $state['new_url'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( '  Last activity: %s', $state['last_activity'] ?? 'N/A' ) );
	}

	/**
	 * Clean up any stale import sessions.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swish cleanup
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		// Clear import session.
		ImportSession::clear_state();
		ImportSession::clear_secret_key();
		WP_CLI::log( 'Import session cleared.' );

		WP_CLI::success( 'Cleanup complete.' );
	}
}
