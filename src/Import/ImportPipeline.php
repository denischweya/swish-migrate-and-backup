<?php
/**
 * Import Pipeline.
 *
 * Handles chunked import/migration operations that can span multiple HTTP requests.
 * Uses a priority-based phase system similar to All-in-One WP Migration.
 *
 * @package SwishMigrateAndBackup\Import
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Import;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Migration\SearchReplace;

/**
 * Orchestrates chunked import operations.
 */
final class ImportPipeline {

	/**
	 * Time budget per request in seconds.
	 * Higher value = fewer chunks needed, more reliable for browser imports.
	 */
	private const TIME_BUDGET = 25;

	/**
	 * Import phases in priority order.
	 *
	 * Order is critical:
	 * 1. Files FIRST - so plugins/themes are available even if DB fails
	 * 2. Database SECOND - main tables (posts, terms, etc.)
	 * 3. Critical tables LAST - options/usermeta to avoid session loss
	 */
	private const PHASES = array(
		5   => 'validate',         // Validate backup file.
		10  => 'extract',          // Extract archive to temp dir.
		50  => 'enumerate',        // Count files/queries for progress.
		100 => 'confirm',          // Ready for user confirmation (if needed).
		150 => 'preserve',         // Preserve critical options to file.
		200 => 'content',          // Restore files FIRST (chunked).
		250 => 'database',         // Restore database tables (chunked).
		275 => 'database_critical', // Restore critical tables last (options, users).
		300 => 'url_replace',      // Search/replace URLs.
		350 => 'finalize',         // Activate plugins, flush caches.
		400 => 'cleanup',          // Remove temp files.
	);

	/**
	 * Human-readable phase labels for progress display.
	 */
	private const PHASE_LABELS = array(
		'validate'          => 'Validating Backup',
		'extract'           => 'Extracting Archive',
		'enumerate'         => 'Analyzing Contents',
		'confirm'           => 'Preparing Migration',
		'preserve'          => 'Preserving Settings',
		'content'           => 'Restoring Files',
		'database'          => 'Restoring Database',
		'database_critical' => 'Restoring Settings',
		'url_replace'       => 'Updating URLs',
		'finalize'          => 'Finalizing',
		'cleanup'           => 'Cleaning Up',
	);

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Restore manager.
	 *
	 * @var RestoreManager
	 */
	private RestoreManager $restore_manager;

	/**
	 * Migrator instance.
	 *
	 * @var Migrator|null
	 */
	private ?Migrator $migrator;

	/**
	 * Start time of current request.
	 *
	 * @var float
	 */
	private float $start_time;

	/**
	 * Constructor.
	 *
	 * @param Logger         $logger          Logger instance.
	 * @param RestoreManager $restore_manager Restore manager.
	 * @param Migrator|null  $migrator        Migrator instance.
	 */
	public function __construct( Logger $logger, RestoreManager $restore_manager, ?Migrator $migrator = null ) {
		$this->logger          = $logger;
		$this->restore_manager = $restore_manager;
		$this->migrator        = $migrator;
		$this->start_time      = microtime( true );
	}

	/**
	 * Check if we have time remaining in the budget.
	 *
	 * @return bool True if we can continue.
	 */
	private function has_time_remaining(): bool {
		$elapsed = microtime( true ) - $this->start_time;
		return $elapsed < self::TIME_BUDGET;
	}

	/**
	 * Get remaining time in seconds.
	 *
	 * @return float Remaining time.
	 */
	private function get_remaining_time(): float {
		return max( 0, self::TIME_BUDGET - ( microtime( true ) - $this->start_time ) );
	}

	/**
	 * Execute the next chunk of the import pipeline.
	 *
	 * @param string $secret_key The secret key for authentication.
	 * @return array Result with 'completed', 'phase', 'progress', etc.
	 */
	public function execute( string $secret_key ): array {
		$this->start_time = microtime( true );

		// Verify secret key.
		if ( ! ImportSession::verify_secret_key( $secret_key ) ) {
			return array(
				'success'   => false,
				'error'     => 'Invalid or expired import session.',
				'completed' => true,
			);
		}

		// Get current state.
		$state = ImportSession::get_state();
		if ( ! $state ) {
			return array(
				'success'   => false,
				'error'     => 'No active import session.',
				'completed' => true,
			);
		}

		// Set WP_IMPORTING constant to prevent certain hooks from running.
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		try {
			// Execute current phase.
			$result = $this->execute_phase( $state );

			// Update state with result.
			if ( ! $result['completed'] ) {
				// More work to do in current phase.
				ImportSession::save_state( array_merge( $state, $result['state_updates'] ?? array() ) );
			} elseif ( $result['success'] ) {
				// Move to next phase.
				$next_phase = $this->get_next_phase( $state['phase_priority'] );
				if ( $next_phase ) {
					ImportSession::update_phase(
						$next_phase['name'],
						$next_phase['priority'],
						$result['state_updates'] ?? array()
					);
					$result['completed'] = false; // More phases to run.
				} else {
					// All phases complete.
					ImportSession::complete( true, $result );
				}
			}

			return $result;

		} catch ( \Exception $e ) {
			$this->logger->error( 'Import pipeline error: ' . $e->getMessage() );
			ImportSession::complete( false, array( 'error' => $e->getMessage() ) );

			return array(
				'success'   => false,
				'error'     => $e->getMessage(),
				'completed' => true,
			);
		}
	}

	/**
	 * Execute the current phase.
	 *
	 * @param array $state Current import state.
	 * @return array Phase execution result.
	 */
	private function execute_phase( array $state ): array {
		$phase = $state['phase'] ?? 'validate';

		$this->logger->info( 'Executing import phase', array( 'phase' => $phase ) );

		switch ( $phase ) {
			case 'initialized':
			case 'validate':
				return $this->phase_validate( $state );

			case 'extract':
				return $this->phase_extract( $state );

			case 'enumerate':
				return $this->phase_enumerate( $state );

			case 'confirm':
				return $this->phase_confirm( $state );

			case 'preserve':
				return $this->phase_preserve( $state );

			case 'content':
				return $this->phase_content( $state );

			case 'database':
				return $this->phase_database( $state );

			case 'database_critical':
				return $this->phase_database_critical( $state );

			case 'url_replace':
				return $this->phase_url_replace( $state );

			case 'finalize':
				return $this->phase_finalize( $state );

			case 'cleanup':
				return $this->phase_cleanup( $state );

			default:
				return array(
					'success'   => false,
					'error'     => 'Unknown phase: ' . $phase,
					'completed' => true,
				);
		}
	}

	/**
	 * Get the next phase after the current priority.
	 *
	 * @param int $current_priority Current phase priority.
	 * @return array|null Next phase info or null if done.
	 */
	private function get_next_phase( int $current_priority ): ?array {
		foreach ( self::PHASES as $priority => $name ) {
			if ( $priority > $current_priority ) {
				return array(
					'name'     => $name,
					'priority' => $priority,
				);
			}
		}
		return null;
	}

	/**
	 * Calculate overall progress percentage.
	 *
	 * @param int   $phase_priority Current phase priority.
	 * @param float $phase_progress Progress within current phase (0-100).
	 * @return int Overall progress (0-100).
	 */
	private function calculate_progress( int $phase_priority, float $phase_progress = 0 ): int {
		$total_phases = count( self::PHASES );
		$current_index = 0;
		$i = 0;

		foreach ( self::PHASES as $priority => $name ) {
			if ( $priority === $phase_priority ) {
				$current_index = $i;
				break;
			}
			++$i;
		}

		// Each phase contributes equally to overall progress.
		$phase_weight = 100 / $total_phases;
		$base_progress = $current_index * $phase_weight;
		$phase_contribution = ( $phase_progress / 100 ) * $phase_weight;

		return min( 100, (int) ( $base_progress + $phase_contribution ) );
	}

	/**
	 * Phase: Validate backup file.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_validate( array $state ): array {
		$backup_path = $state['backup_path'];
		$filename = basename( $backup_path );

		if ( ! file_exists( $backup_path ) ) {
			return array(
				'success'     => false,
				'error'       => 'Backup file not found: ' . $backup_path,
				'completed'   => true,
				'phase'       => 'validate',
				'phase_label' => self::PHASE_LABELS['validate'],
			);
		}

		$is_valid = $this->restore_manager->verify_backup( $backup_path );
		if ( ! $is_valid ) {
			return array(
				'success'     => false,
				'error'       => 'Invalid backup file. The file may be corrupted or not a valid Swish backup.',
				'completed'   => true,
				'phase'       => 'validate',
				'phase_label' => self::PHASE_LABELS['validate'],
			);
		}

		$file_size = filesize( $backup_path );

		return array(
			'success'     => true,
			'completed'   => true,
			'phase'       => 'validate',
			'phase_label' => self::PHASE_LABELS['validate'],
			'message'     => sprintf( 'Validated: %s (%s)', $filename, size_format( $file_size ) ),
			'detail'      => 'Backup file structure verified',
			'progress'    => $this->calculate_progress( 5, 100 ),
		);
	}

	/**
	 * Phase: Extract archive to temp directory.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_extract( array $state ): array {
		$backup_path = $state['backup_path'];
		$extract_dir = $state['extract_dir'] ?? null;

		// Create extract directory if not exists.
		if ( ! $extract_dir ) {
			$extract_dir = WP_CONTENT_DIR . '/swish-backups/temp/import-' . time();
			if ( ! wp_mkdir_p( $extract_dir ) ) {
				return array(
					'success'     => false,
					'error'       => 'Failed to create extraction directory',
					'completed'   => true,
					'phase'       => 'extract',
					'phase_label' => self::PHASE_LABELS['extract'],
				);
			}
		}

		// For now, extract the full archive.
		// TODO: Implement chunked extraction for very large archives.
		$extracted = $this->restore_manager->extract_backup_public( $backup_path, $extract_dir );

		if ( ! $extracted ) {
			return array(
				'success'     => false,
				'error'       => 'Failed to extract backup archive',
				'completed'   => true,
				'phase'       => 'extract',
				'phase_label' => self::PHASE_LABELS['extract'],
			);
		}

		// Calculate extracted size.
		$extracted_size = 0;
		$file_count = 0;
		if ( is_dir( $extract_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $extract_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					++$file_count;
					$extracted_size += $file->getSize();
				}
			}
		}

		return array(
			'success'       => true,
			'completed'     => true,
			'phase'         => 'extract',
			'phase_label'   => self::PHASE_LABELS['extract'],
			'message'       => sprintf( 'Extracted %d files (%s)', $file_count, size_format( $extracted_size ) ),
			'detail'        => 'Archive unpacked to temporary directory',
			'progress'      => $this->calculate_progress( 10, 100 ),
			'state_updates' => array(
				'extract_dir' => $extract_dir,
			),
		);
	}

	/**
	 * Phase: Enumerate files and queries for progress tracking.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_enumerate( array $state ): array {
		$extract_dir = $state['extract_dir'];

		// Count SQL file size for database progress.
		$sql_file = $extract_dir . '/database.sql';
		$total_queries_size = 0;
		$table_count = 0;
		if ( file_exists( $sql_file ) ) {
			$total_queries_size = filesize( $sql_file );
			// Count tables in SQL file.
			$table_count = $this->count_tables_in_sql( $sql_file );
		}

		// Count files for content restore progress.
		$total_files_count = 0;
		$total_files_size = 0;

		// Check for file archives.
		$tar_files = glob( $extract_dir . '/files*.tar.gz' );
		$zip_file = $extract_dir . '/files.zip';
		$wp_content = $extract_dir . '/wp-content';

		if ( ! empty( $tar_files ) || file_exists( $zip_file ) || is_dir( $wp_content ) ) {
			// Estimate based on archive size for now.
			foreach ( $tar_files as $tar_file ) {
				$total_files_size += filesize( $tar_file );
			}
			if ( file_exists( $zip_file ) ) {
				$total_files_size += filesize( $zip_file );
			}
			if ( is_dir( $wp_content ) ) {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $wp_content, \RecursiveDirectoryIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file ) {
					if ( $file->isFile() ) {
						++$total_files_count;
						$total_files_size += $file->getSize();
					}
				}
			}
		}

		// Build detailed message.
		$details = array();
		if ( $total_queries_size > 0 ) {
			$details[] = sprintf( 'Database: %s (%d tables)', size_format( $total_queries_size ), $table_count );
		}
		if ( $total_files_count > 0 || $total_files_size > 0 ) {
			$details[] = sprintf( 'Files: %s', size_format( $total_files_size ) );
			if ( $total_files_count > 0 ) {
				$details[ count( $details ) - 1 ] .= sprintf( ' (%d files)', $total_files_count );
			}
		}

		return array(
			'success'       => true,
			'completed'     => true,
			'phase'         => 'enumerate',
			'phase_label'   => self::PHASE_LABELS['enumerate'],
			'message'       => 'Backup contents analyzed',
			'detail'        => implode( ' | ', $details ),
			'progress'      => $this->calculate_progress( 50, 100 ),
			'state_updates' => array(
				'total_queries_size' => $total_queries_size,
				'total_files_count'  => $total_files_count,
				'total_files_size'   => $total_files_size,
				'table_count'        => $table_count,
			),
		);
	}

	/**
	 * Count tables in SQL file by scanning for CREATE TABLE statements.
	 *
	 * @param string $sql_file Path to SQL file.
	 * @return int Number of tables.
	 */
	private function count_tables_in_sql( string $sql_file ): int {
		$handle = fopen( $sql_file, 'r' );
		if ( ! $handle ) {
			return 0;
		}

		$count = 0;
		while ( ( $line = fgets( $handle ) ) !== false ) {
			if ( preg_match( '/^CREATE TABLE/i', trim( $line ) ) ) {
				++$count;
			}
		}

		fclose( $handle );
		return $count;
	}

	/**
	 * Phase: Confirm (placeholder for user confirmation if needed).
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_confirm( array $state ): array {
		// Auto-confirm for now. In future, this could pause for user confirmation.
		return array(
			'success'     => true,
			'completed'   => true,
			'phase'       => 'confirm',
			'phase_label' => self::PHASE_LABELS['confirm'],
			'message'     => 'Migration ready to begin',
			'detail'      => 'All pre-checks passed',
			'progress'    => $this->calculate_progress( 100, 100 ),
		);
	}

	/**
	 * Phase: Preserve critical options before database restore.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_preserve( array $state ): array {
		$preserved = ImportSession::preserve_critical_options();

		$this->logger->info( 'Preserved critical options', array(
			'count' => count( $preserved ),
		) );

		$option_names = array_keys( $preserved );
		$detail = count( $option_names ) > 3
			? sprintf( '%s and %d more', implode( ', ', array_slice( $option_names, 0, 3 ) ), count( $option_names ) - 3 )
			: implode( ', ', $option_names );

		return array(
			'success'       => true,
			'completed'     => true,
			'phase'         => 'preserve',
			'phase_label'   => self::PHASE_LABELS['preserve'],
			'message'       => sprintf( 'Preserved %d critical settings', count( $preserved ) ),
			'detail'        => $detail,
			'progress'      => $this->calculate_progress( 150, 100 ),
			'state_updates' => array(
				'options_preserved' => true,
			),
		);
	}

	/**
	 * Phase: Restore database (chunked).
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_database( array $state ): array {
		$extract_dir = $state['extract_dir'];
		$sql_file = $extract_dir . '/database.sql';

		// Check if database restore is requested.
		$options = $state['options'] ?? array();
		if ( ! ( $options['restore_database'] ?? true ) ) {
			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'database',
				'phase_label' => self::PHASE_LABELS['database'],
				'message'     => 'Database restore skipped (user option)',
				'detail'      => 'Database restoration was disabled in import options',
				'progress'    => $this->calculate_progress( 200, 100 ),
			);
		}

		if ( ! file_exists( $sql_file ) ) {
			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'database',
				'phase_label' => self::PHASE_LABELS['database'],
				'message'     => 'No database in backup',
				'detail'      => 'Backup does not contain database.sql',
				'progress'    => $this->calculate_progress( 200, 100 ),
			);
		}

		// Get current offset.
		$query_offset = $state['query_offset'] ?? 0;
		$total_size = $state['total_queries_size'] ?? filesize( $sql_file );

		// Detect table prefix from backup (only on first chunk).
		$old_prefix = $state['old_table_prefix'] ?? null;
		if ( null === $old_prefix && 0 === $query_offset ) {
			$old_prefix = $this->detect_table_prefix( $sql_file );
			$this->logger->info( 'Detected table prefix in backup', array( 'prefix' => $old_prefix ) );
		}

		// Get current site's table prefix.
		global $wpdb;
		$new_prefix = $wpdb->prefix;

		// Execute chunked database restore, skipping critical tables.
		// Critical tables (options, usermeta, users) are imported in the next phase.
		$result = $this->restore_database_chunk( $sql_file, $query_offset, $total_size, $old_prefix, $new_prefix, 'skip_critical' );

		$phase_progress = $total_size > 0 ? ( $result['offset'] / $total_size ) * 100 : 100;

		// Initialize state updates.
		$state_updates = array(
			'query_offset' => $result['offset'],
		);

		// Store detected prefix for subsequent chunks.
		if ( null !== $old_prefix ) {
			$state_updates['old_table_prefix'] = $old_prefix;
		}

		// Build detailed message.
		$table_count = $state['table_count'] ?? 0;
		$queries_executed = $result['queries_executed'] ?? 0;

		if ( $result['completed'] ) {
			$message = 'Main database tables restored';
			$detail = sprintf( '%s imported (critical tables pending)', size_format( $total_size ) );
		} else {
			$message = sprintf( 'Restoring database: %d%%', (int) $phase_progress );
			$detail = sprintf( '%s of %s processed', size_format( $result['offset'] ), size_format( $total_size ) );
			if ( $queries_executed > 0 ) {
				$detail .= sprintf( ' (%d queries this chunk)', $queries_executed );
			}
		}

		return array(
			'success'       => $result['success'],
			'completed'     => $result['completed'],
			'phase'         => 'database',
			'phase_label'   => self::PHASE_LABELS['database'],
			'message'       => $message,
			'detail'        => $detail,
			'progress'      => $this->calculate_progress( 250, $phase_progress ),
			'bytes_done'    => $result['offset'],
			'bytes_total'   => $total_size,
			'state_updates' => $state_updates,
		);
	}

	/**
	 * Phase: Restore critical database tables (options, usermeta, users).
	 *
	 * These tables are imported LAST to avoid losing the import session
	 * when wp_options is overwritten.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_database_critical( array $state ): array {
		$extract_dir = $state['extract_dir'];
		$sql_file = $extract_dir . '/database.sql';

		// Skip if database restore was disabled.
		$options = $state['options'] ?? array();
		if ( ! ( $options['restore_database'] ?? true ) ) {
			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'database_critical',
				'phase_label' => self::PHASE_LABELS['database_critical'],
				'message'     => 'Critical tables skipped',
				'detail'      => 'Database restoration was disabled',
				'progress'    => $this->calculate_progress( 275, 100 ),
			);
		}

		if ( ! file_exists( $sql_file ) ) {
			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'database_critical',
				'phase_label' => self::PHASE_LABELS['database_critical'],
				'message'     => 'No database in backup',
				'detail'      => 'Backup does not contain database.sql',
				'progress'    => $this->calculate_progress( 275, 100 ),
			);
		}

		// Use a separate offset for critical tables phase.
		$critical_offset = $state['critical_query_offset'] ?? 0;
		$total_size = $state['total_queries_size'] ?? filesize( $sql_file );

		// Get prefixes from previous phase.
		$old_prefix = $state['old_table_prefix'] ?? null;
		global $wpdb;
		$new_prefix = $wpdb->prefix;

		// Execute chunked restore for ONLY critical tables.
		$result = $this->restore_database_chunk( $sql_file, $critical_offset, $total_size, $old_prefix, $new_prefix, 'only_critical' );

		$phase_progress = $total_size > 0 ? ( $result['offset'] / $total_size ) * 100 : 100;

		// State updates.
		$state_updates = array(
			'critical_query_offset' => $result['offset'],
		);

		// Restore critical options after critical tables are fully imported.
		if ( $result['completed'] ) {
			// Verify table prefix is correct after restore.
			$verified_prefix = $this->verify_table_prefix_after_restore( $new_prefix );
			if ( $verified_prefix && $verified_prefix !== $new_prefix ) {
				$this->logger->warning( 'Table prefix mismatch detected after restore', array(
					'expected'   => $new_prefix,
					'actual'     => $verified_prefix,
				) );
				$state_updates['actual_table_prefix'] = $verified_prefix;
			}

			// NOW restore critical options (siteurl, home, swish plugin active, etc.).
			ImportSession::restore_critical_options();

			$this->logger->info( 'Critical database tables restored and options preserved' );
		}

		// Build message.
		if ( $result['completed'] ) {
			$message = 'Database fully restored';
			$detail = 'All tables imported, settings preserved';
		} else {
			$message = sprintf( 'Restoring settings: %d%%', (int) $phase_progress );
			$detail = 'Importing critical tables (options, users)';
		}

		return array(
			'success'       => $result['success'],
			'completed'     => $result['completed'],
			'phase'         => 'database_critical',
			'phase_label'   => self::PHASE_LABELS['database_critical'],
			'message'       => $message,
			'detail'        => $detail,
			'progress'      => $this->calculate_progress( 275, $phase_progress ),
			'state_updates' => $state_updates,
		);
	}

	/**
	 * Detect table prefix from SQL dump file.
	 *
	 * @param string $sql_file Path to SQL file.
	 * @return string|null Detected prefix or null.
	 */
	private function detect_table_prefix( string $sql_file ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $sql_file, 'r' );
		if ( ! $handle ) {
			return null;
		}

		$prefix = null;
		$lines_read = 0;
		$max_lines = 100; // Header should be in first 100 lines.

		// First, look for the Swish header comment with explicit prefix.
		while ( ( $line = fgets( $handle ) ) !== false && $lines_read < $max_lines ) {
			++$lines_read;

			// Look for "-- Table Prefix: wp_xyz_" header comment (Swish format).
			if ( preg_match( '/^--\s*Table Prefix:\s*([a-zA-Z0-9_]+)$/i', trim( $line ), $matches ) ) {
				$prefix = $matches[1];
				$this->logger->info( 'Table prefix from header comment', array( 'prefix' => $prefix ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return $prefix;
			}

			// Stop looking for header after first SQL statement.
			if ( preg_match( '/^(DROP|CREATE|INSERT|SET|START)/i', trim( $line ) ) ) {
				break;
			}
		}

		// Fall back to detecting from table names.
		// Reset to scan more of the file for table patterns.
		rewind( $handle );
		$lines_read = 0;
		$max_lines = 500;

		while ( ( $line = fgets( $handle ) ) !== false && $lines_read < $max_lines ) {
			++$lines_read;

			// Look for any DROP TABLE or CREATE TABLE statement with a prefix pattern.
			// Match: `prefix_tablename` where prefix ends with underscore.
			if ( preg_match( '/(?:DROP TABLE|CREATE TABLE)[^`]*`([a-zA-Z0-9]+_[a-zA-Z0-9]*_)[a-zA-Z]+`/i', $line, $matches ) ) {
				$prefix = $matches[1];
				break;
			}

			// Simpler pattern: look for backtick-quoted table name starting with wp_.
			if ( preg_match( '/`(wp_[a-zA-Z0-9]*_)[a-zA-Z]/i', $line, $matches ) ) {
				$prefix = $matches[1];
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		$this->logger->info( 'Table prefix detection', array(
			'lines_scanned' => $lines_read,
			'detected'      => $prefix,
		) );

		return $prefix;
	}

	/**
	 * Verify table prefix after database restore.
	 *
	 * If prefix detection failed or was incorrect, the restored tables may have
	 * a different prefix than expected. This method checks if we can access the
	 * options table and finds the actual prefix if needed.
	 *
	 * @param string $expected_prefix The expected table prefix.
	 * @return string|null The actual prefix if different, or null if expected is correct.
	 */
	private function verify_table_prefix_after_restore( string $expected_prefix ): ?string {
		global $wpdb;

		// First, check if we can query the expected options table.
		$expected_options_table = $expected_prefix . 'options';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$test = $wpdb->get_var( "SELECT COUNT(*) FROM `{$expected_options_table}` LIMIT 1" );

		if ( null !== $test && '' !== $wpdb->last_error ) {
			// Expected table exists and is accessible.
			return null;
		}

		// Expected table doesn't exist - scan for actual options table.
		$this->logger->warning( 'Expected options table not accessible, scanning for actual table', array(
			'expected_table' => $expected_options_table,
			'error'          => $wpdb->last_error,
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( empty( $tables ) ) {
			$this->logger->error( 'No tables found in database after restore' );
			return null;
		}

		// Look for an options table.
		foreach ( $tables as $table ) {
			if ( preg_match( '/^(.+)options$/', $table, $matches ) ) {
				$actual_prefix = $matches[1];

				// Verify this looks like a WordPress options table.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$has_siteurl = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1",
						'siteurl'
					)
				);

				if ( null !== $has_siteurl ) {
					$this->logger->info( 'Found actual options table with different prefix', array(
						'actual_table'  => $table,
						'actual_prefix' => $actual_prefix,
					) );
					return $actual_prefix;
				}
			}
		}

		$this->logger->error( 'Could not find a valid options table after restore' );
		return null;
	}

	/**
	 * Critical tables that should be imported LAST to avoid session loss.
	 * These tables contain session/user data that gets overwritten during import.
	 */
	private const CRITICAL_TABLES = array(
		'options',
		'usermeta',
		'users',
		'user_meta', // Some plugins use this.
	);

	/**
	 * Restore database in chunks with offset tracking.
	 *
	 * @param string      $sql_file   Path to SQL file.
	 * @param int         $offset     Current byte offset.
	 * @param int         $total_size Total file size.
	 * @param string|null $old_prefix Old table prefix (from backup).
	 * @param string|null $new_prefix New table prefix (current site).
	 * @param string      $table_mode Table filtering mode: 'all', 'skip_critical', 'only_critical'.
	 * @return array Result with 'success', 'completed', 'offset'.
	 */
	private function restore_database_chunk( string $sql_file, int $offset, int $total_size, ?string $old_prefix = null, ?string $new_prefix = null, string $table_mode = 'all' ): array {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $sql_file, 'r' );
		if ( ! $handle ) {
			return array(
				'success'   => false,
				'completed' => true,
				'offset'    => $offset,
			);
		}

		// Seek to offset.
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		} else {
			// Disable foreign key checks and autocommit at start.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET autocommit = 0' );
		}

		// Start transaction for this chunk.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		$query = '';
		$queries_executed = 0;
		// Track offset of the last COMPLETE query, not current read position.
		$safe_offset = $offset;
		$query_start_offset = $offset;

		// Atomic query tracking - don't interrupt during critical operations.
		$in_atomic_operation = false;

		// Check if table prefix replacement is needed.
		$replace_prefix = ( null !== $old_prefix && null !== $new_prefix && $old_prefix !== $new_prefix );

		// Track current table for filtering.
		$current_table = '';
		$skip_current_table = false;

		$this->logger->info( 'Database chunk starting', array(
			'offset'         => $offset,
			'total_size'     => $total_size,
			'old_prefix'     => $old_prefix,
			'new_prefix'     => $new_prefix,
			'replace_prefix' => $replace_prefix,
			'table_mode'     => $table_mode,
		) );

		while ( ! feof( $handle ) && ( $this->has_time_remaining() || $in_atomic_operation ) ) {
			// Remember where this line starts.
			$line_start = ftell( $handle );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$trimmed = trim( $line );

			// Skip comments and empty lines (but update safe_offset since these are "complete").
			if ( empty( $trimmed ) || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '#' ) ) {
				if ( empty( $query ) ) {
					$safe_offset = ftell( $handle );
					$query_start_offset = $safe_offset;
				}
				continue;
			}

			// Skip MySQL-specific version comments but process the content.
			if ( preg_match( '/^\/\*!\d+\s/', $trimmed ) ) {
				// Remove version comment wrapper but keep content.
				$trimmed = preg_replace( '/^\/\*!\d+\s/', '', $trimmed );
				$trimmed = preg_replace( '/\s*\*\/$/', '', $trimmed );
				$line = $trimmed . "\n";
			}

			// If this is the start of a new query, remember where it starts.
			if ( empty( $query ) ) {
				$query_start_offset = $line_start;
			}

			$query .= $line;

			// Check for complete statement (ends with semicolon, not inside string).
			if ( preg_match( '/;\s*$/', $query ) ) {
				$query = trim( $query );

				if ( ! empty( $query ) && ';' !== $query ) {
					// Detect table changes from DROP/CREATE/INSERT statements.
					if ( preg_match( '/(?:DROP TABLE|CREATE TABLE|INSERT INTO)[^`]*`([^`]+)`/i', $query, $matches ) ) {
						$detected_table = $matches[1];
						if ( $detected_table !== $current_table ) {
							$current_table = $detected_table;
							// Check if this table should be skipped based on mode.
							$is_critical = $this->is_critical_table( $current_table );
							if ( 'skip_critical' === $table_mode ) {
								$skip_current_table = $is_critical;
							} elseif ( 'only_critical' === $table_mode ) {
								$skip_current_table = ! $is_critical;
							} else {
								$skip_current_table = false;
							}
						}
					}

					// Skip query if current table should be skipped.
					if ( $skip_current_table ) {
						// Still update offset but don't execute.
						$safe_offset = ftell( $handle );
						$query = '';
						$query_start_offset = $safe_offset;
						continue;
					}

					// Check if this is an atomic operation (DDL or critical table).
					$in_atomic_operation = $this->is_atomic_query( $query );

					// Replace table prefix if needed.
					if ( $replace_prefix ) {
						$query = $this->replace_table_prefix( $query, $old_prefix, $new_prefix );
					}

					// Execute the query with connection recovery.
					$result = $this->execute_query_with_retry( $query );

					if ( false === $result && ! empty( $wpdb->last_error ) ) {
						// Log but continue (some errors are expected).
						$this->logger->warning( 'SQL query warning', array(
							'error' => $wpdb->last_error,
							'query' => substr( $query, 0, 100 ) . '...',
						) );
					}

					++$queries_executed;

					// Reset atomic flag after DDL completes.
					if ( $in_atomic_operation && ! $this->is_partial_table_operation( $query ) ) {
						$in_atomic_operation = false;
					}
				}

				// Query complete - update safe_offset to AFTER this query.
				$safe_offset = ftell( $handle );
				$query = '';
				$query_start_offset = $safe_offset;
			}
		}

		$completed = feof( $handle );

		// Commit the transaction.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( $completed ) {
			// Re-enable foreign key checks and autocommit.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET autocommit = 1' );

			$this->logger->info( 'Database restore completed', array(
				'queries_executed' => $queries_executed,
			) );
		} else {
			$this->logger->info( 'Database chunk completed', array(
				'queries_executed' => $queries_executed,
				'safe_offset'      => $safe_offset,
				'progress'         => $total_size > 0 ? round( ( $safe_offset / $total_size ) * 100, 1 ) : 0,
			) );
		}

		return array(
			'success'   => true,
			'completed' => $completed,
			'offset'    => $safe_offset,
		);
	}

	/**
	 * Check if query is atomic (should not be interrupted).
	 *
	 * @param string $query SQL query.
	 * @return bool True if atomic.
	 */
	private function is_atomic_query( string $query ): bool {
		$query_upper = strtoupper( substr( $query, 0, 50 ) );

		// DDL statements are atomic.
		if ( str_starts_with( $query_upper, 'DROP TABLE' ) ||
			str_starts_with( $query_upper, 'CREATE TABLE' ) ||
			str_starts_with( $query_upper, 'ALTER TABLE' ) ||
			str_starts_with( $query_upper, 'TRUNCATE' ) ) {
			return true;
		}

		// Queries for critical tables (options, usermeta) are atomic.
		if ( preg_match( '/INSERT\s+INTO\s+[`"\']?(\w+_)?(options|usermeta)[`"\']?/i', $query ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if this is a partial table operation (needs more queries to complete).
	 *
	 * @param string $query SQL query.
	 * @return bool True if partial.
	 */
	private function is_partial_table_operation( string $query ): bool {
		// CREATE TABLE or INSERT are self-contained.
		// DROP TABLE followed by CREATE TABLE should be atomic together.
		return str_starts_with( strtoupper( $query ), 'DROP TABLE' );
	}

	/**
	 * Check if a table is critical (should be imported last).
	 *
	 * Critical tables contain session/authentication data that should
	 * be imported last to avoid losing the import session.
	 *
	 * @param string $table_name Full table name (with prefix).
	 * @return bool True if critical.
	 */
	private function is_critical_table( string $table_name ): bool {
		foreach ( self::CRITICAL_TABLES as $critical ) {
			// Match table names ending with the critical suffix (e.g., wp_options, wp_usermeta).
			if ( str_ends_with( $table_name, '_' . $critical ) || $table_name === $critical ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace table prefix in SQL query.
	 *
	 * @param string $query      SQL query.
	 * @param string $old_prefix Old prefix.
	 * @param string $new_prefix New prefix.
	 * @return string Query with replaced prefix.
	 */
	private function replace_table_prefix( string $query, string $old_prefix, string $new_prefix ): string {
		// Replace in table names (backtick quoted).
		$query = preg_replace(
			'/`' . preg_quote( $old_prefix, '/' ) . '(\w+)`/',
			'`' . $new_prefix . '$1`',
			$query
		);

		// Replace in table names (unquoted, after keywords).
		$keywords = 'TABLE|INTO|FROM|JOIN|UPDATE|REFERENCES|LIKE';
		$query = preg_replace(
			'/\b(' . $keywords . ')\s+' . preg_quote( $old_prefix, '/' ) . '(\w+)\b/i',
			'$1 ' . $new_prefix . '$2',
			$query
		);

		return $query;
	}

	/**
	 * Execute query with connection recovery.
	 *
	 * @param string $query SQL query.
	 * @return int|bool Number of rows affected or false on error.
	 */
	private function execute_query_with_retry( string $query ) {
		global $wpdb;

		// First attempt.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $query );

		// Check for "MySQL server has gone away" error.
		if ( false === $result && $wpdb->last_error ) {
			$errno = 0;
			if ( $wpdb->dbh instanceof \mysqli ) {
				$errno = mysqli_errno( $wpdb->dbh );
			}

			// Error 2006 = MySQL server has gone away.
			// Error 2013 = Lost connection to MySQL server.
			if ( 2006 === $errno || 2013 === $errno || str_contains( $wpdb->last_error, 'gone away' ) ) {
				$this->logger->warning( 'MySQL connection lost, attempting reconnect...' );

				// Try to reconnect.
				if ( method_exists( $wpdb, 'check_connection' ) ) {
					$wpdb->check_connection( false );
				}

				// Retry the query.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->query( $query );
			}
		}

		return $result;
	}

	/**
	 * Phase: Restore content/files (chunked).
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_content( array $state ): array {
		$extract_dir = $state['extract_dir'];

		// Check if file restore is requested.
		$options = $state['options'] ?? array();
		if ( ! ( $options['restore_files'] ?? true ) ) {
			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'content',
				'phase_label' => self::PHASE_LABELS['content'],
				'message'     => 'File restore skipped (user option)',
				'detail'      => 'File restoration was disabled in import options',
				'progress'    => $this->calculate_progress( 200, 100 ),
			);
		}

		$total_files = $state['total_files_count'] ?? 0;
		$total_size = $state['total_files_size'] ?? 0;

		// Delegate to restore manager for file restoration.
		// File restoration is already efficient (uses tar/rsync).
		// Files are restored BEFORE database to ensure plugins/themes are available.
		$result = $this->restore_manager->restore( $state['backup_path'], array(
			'restore_database' => false, // Database is restored in the next phase.
			'restore_files'    => true,
		) );

		if ( $result ) {
			$message = 'Files restored successfully';
			$detail = sprintf( '%s copied to wp-content', size_format( $total_size ) );
			if ( $total_files > 0 ) {
				$detail = sprintf( '%d files (%s) copied to wp-content', $total_files, size_format( $total_size ) );
			}
		} else {
			$message = 'File restore failed';
			$detail = 'Check server permissions and disk space';
		}

		return array(
			'success'     => $result,
			'completed'   => true,
			'phase'       => 'content',
			'phase_label' => self::PHASE_LABELS['content'],
			'message'     => $message,
			'detail'      => $detail,
			'progress'    => $this->calculate_progress( 200, 100 ),
		);
	}

	/**
	 * Phase: Search and replace URLs (chunked).
	 *
	 * This phase processes URL replacements in chunks to avoid timeouts
	 * on large databases. State tracks the current replacement operation,
	 * table index, and row offset.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_url_replace( array $state ): array {
		$options = $state['options'] ?? array();
		$old_url = $options['old_url'] ?? '';
		$new_url = $options['new_url'] ?? '';

		// Debug logging to diagnose URL replacement issues (always logs to error_log).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'Swish Import URL Replace: old_url="%s", new_url="%s", options_keys=[%s], state_keys=[%s]',
			$old_url,
			$new_url,
			implode( ', ', array_keys( $options ) ),
			implode( ', ', array_keys( $state ) )
		) );

		if ( empty( $old_url ) || empty( $new_url ) || $old_url === $new_url ) {
			$reason = empty( $old_url ) ? 'old_url empty' : ( empty( $new_url ) ? 'new_url empty' : 'URLs are the same' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Swish Import URL Replace SKIPPED: ' . $reason );

			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'url_replace',
				'phase_label' => self::PHASE_LABELS['url_replace'],
				'message'     => 'URL replacement not needed',
				'detail'      => empty( $old_url ) ? 'Old URL not provided' : ( empty( $new_url ) ? 'New URL not provided' : 'Source and destination URLs are the same' ),
				'progress'    => $this->calculate_progress( 300, 100 ),
			);
		}

		if ( ! $this->migrator ) {
			return array(
				'success'     => true,
				'completed'   => true,
				'phase'       => 'url_replace',
				'phase_label' => self::PHASE_LABELS['url_replace'],
				'message'     => 'URL replacement skipped',
				'detail'      => 'Migrator component not available',
				'progress'    => $this->calculate_progress( 300, 100 ),
			);
		}

		// Get or initialize URL replacement state.
		$url_replace_state = $state['url_replace_state'] ?? array(
			'replacement_index' => 0,
			'table_index'       => 0,
			'row_offset'        => 0,
			'total_replacements' => 0,
		);

		// Normalize URLs.
		$old_url = rtrim( $old_url, '/' );
		$new_url = rtrim( $new_url, '/' );

		// Generate all replacement variations.
		$search_replace = $this->migrator->get_search_replace();
		$replacements = $search_replace->generate_url_replacements( $old_url, $new_url );

		// Also handle path changes.
		$old_path = wp_parse_url( $old_url, PHP_URL_PATH ) ?: '';
		$new_path = wp_parse_url( $new_url, PHP_URL_PATH ) ?: '';
		if ( $old_path !== $new_path && ! empty( $old_path ) ) {
			$replacements[ $old_path ] = $new_path;
		}

		// Convert to indexed array for iteration.
		$replacement_pairs = array_map(
			fn( $search, $replace ) => array( 'search' => $search, 'replace' => $replace ),
			array_keys( $replacements ),
			array_values( $replacements )
		);

		$total_pairs = count( $replacement_pairs );
		$current_index = $url_replace_state['replacement_index'];

		// Check if all replacements are done.
		if ( $current_index >= $total_pairs ) {
			// Update WordPress options with new URL.
			update_option( 'siteurl', $new_url );
			update_option( 'home', $new_url );

			return array(
				'success'       => true,
				'completed'     => true,
				'phase'         => 'url_replace',
				'phase_label'   => self::PHASE_LABELS['url_replace'],
				'message'       => 'URL replacement complete',
				'detail'        => sprintf( '%d replacements made across %d patterns', $url_replace_state['total_replacements'], $total_pairs ),
				'progress'      => $this->calculate_progress( 300, 100 ),
				'state_updates' => array(
					'url_replacements' => $url_replace_state['total_replacements'],
				),
			);
		}

		// Process current replacement pair.
		$pair = $replacement_pairs[ $current_index ];

		$this->logger->info( 'Processing URL replacement chunk', array(
			'replacement_index' => $current_index,
			'total_pairs'       => $total_pairs,
			'search'            => $pair['search'],
			'table_index'       => $url_replace_state['table_index'],
			'row_offset'        => $url_replace_state['row_offset'],
		) );

		$result = $search_replace->run_chunked(
			$pair['search'],
			$pair['replace'],
			$url_replace_state['table_index'],
			$url_replace_state['row_offset'],
			(int) $this->get_remaining_time()
		);

		$url_replace_state['total_replacements'] += $result['replacements'] ?? 0;

		if ( $result['completed'] ) {
			// This replacement pair is done, move to next.
			$url_replace_state['replacement_index'] = $current_index + 1;
			$url_replace_state['table_index'] = 0;
			$url_replace_state['row_offset'] = 0;
		} else {
			// Still processing this pair, save position.
			$url_replace_state['table_index'] = $result['table_index'];
			$url_replace_state['row_offset'] = $result['row_offset'];
		}

		// Calculate progress within this phase.
		$pairs_complete = $url_replace_state['replacement_index'];
		$phase_progress = $total_pairs > 0 ? ( $pairs_complete / $total_pairs ) * 100 : 100;

		$phase_completed = $url_replace_state['replacement_index'] >= $total_pairs;

		if ( $phase_completed ) {
			// Update WordPress options with new URL.
			update_option( 'siteurl', $new_url );
			update_option( 'home', $new_url );
		}

		// Build detailed progress info.
		$current_pattern = $pair['search'];
		if ( strlen( $current_pattern ) > 40 ) {
			$current_pattern = substr( $current_pattern, 0, 37 ) . '...';
		}

		$message = $phase_completed
			? 'URL replacement complete'
			: sprintf( 'Updating URLs: pattern %d of %d', $pairs_complete + 1, $total_pairs );

		$detail = $phase_completed
			? sprintf( '%d replacements made', $url_replace_state['total_replacements'] )
			: sprintf( 'Processing: %s (%d replacements so far)', $current_pattern, $url_replace_state['total_replacements'] );

		if ( ! $phase_completed && isset( $result['current_table'] ) ) {
			$detail .= sprintf( ' | Table: %s', $result['current_table'] );
		}

		return array(
			'success'       => true,
			'completed'     => $phase_completed,
			'phase'         => 'url_replace',
			'phase_label'   => self::PHASE_LABELS['url_replace'],
			'message'       => $message,
			'detail'        => $detail,
			'progress'      => $this->calculate_progress( 300, $phase_progress ),
			'state_updates' => array(
				'url_replace_state' => $url_replace_state,
				'url_replacements'  => $url_replace_state['total_replacements'],
			),
		);
	}

	/**
	 * Phase: Finalize import.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_finalize( array $state ): array {
		$actions_taken = array();

		// Flush caches.
		wp_cache_flush();
		flush_rewrite_rules();
		$actions_taken[] = 'caches flushed';

		// Clear opcache if available.
		if ( function_exists( 'opcache_reset' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@opcache_reset();
			$actions_taken[] = 'opcache cleared';
		}

		// Deactivate problematic plugins that can break login.
		$deactivated = $this->deactivate_problematic_plugins();
		if ( ! empty( $deactivated ) ) {
			$actions_taken[] = sprintf( '%d security plugins deactivated', count( $deactivated ) );
		}

		$this->logger->info( 'Import finalized' );

		return array(
			'success'     => true,
			'completed'   => true,
			'phase'       => 'finalize',
			'phase_label' => self::PHASE_LABELS['finalize'],
			'message'     => 'Migration finalized',
			'detail'      => ucfirst( implode( ', ', $actions_taken ) ),
			'progress'    => $this->calculate_progress( 350, 100 ),
		);
	}

	/**
	 * Phase: Cleanup temporary files.
	 *
	 * @param array $state Import state.
	 * @return array Result.
	 */
	private function phase_cleanup( array $state ): array {
		$extract_dir = $state['extract_dir'] ?? '';
		$cleaned_size = 0;

		if ( ! empty( $extract_dir ) && is_dir( $extract_dir ) ) {
			// Calculate size before deletion.
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $extract_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$cleaned_size += $file->getSize();
				}
			}

			$this->recursive_delete( $extract_dir );
		}

		// Clear import session state.
		ImportSession::clear_state();

		$this->logger->info( 'Import cleanup completed' );

		$detail = 'Session data cleared';
		if ( $cleaned_size > 0 ) {
			$detail = sprintf( 'Removed %s of temporary files', size_format( $cleaned_size ) );
		}

		return array(
			'success'     => true,
			'completed'   => true,
			'phase'       => 'cleanup',
			'phase_label' => self::PHASE_LABELS['cleanup'],
			'message'     => 'Migration complete!',
			'detail'      => $detail,
			'progress'    => 100,
		);
	}

	/**
	 * Deactivate plugins that can break login after migration.
	 *
	 * @return array List of deactivated plugin paths.
	 */
	private function deactivate_problematic_plugins(): array {
		$problematic = array(
			'wordfence',
			'sucuri-scanner',
			'all-in-one-wp-security',
			'better-wp-security',
			'ithemes-security',
			'login-lockdown',
			'limit-login-attempts',
			'wp-hide-login',
			'wps-hide-login',
			'rename-wp-login',
			'lockdown-wp-admin',
			'google-authenticator',
			'two-factor',
			'duo-two-factor',
			'jetpack', // Only disable photon/SSO modules ideally, but safe to note.
		);

		$active_plugins = get_option( 'active_plugins', array() );
		$deactivated = array();

		foreach ( $active_plugins as $key => $plugin ) {
			foreach ( $problematic as $slug ) {
				if ( str_contains( strtolower( $plugin ), $slug ) ) {
					$deactivated[] = $plugin;
					unset( $active_plugins[ $key ] );
					break;
				}
			}
		}

		if ( ! empty( $deactivated ) ) {
			update_option( 'active_plugins', array_values( $active_plugins ) );
			$this->logger->info( 'Deactivated problematic plugins after migration', array(
				'plugins' => $deactivated,
			) );
		}

		return $deactivated;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool True if deleted.
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
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $file->getRealPath() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return rmdir( $dir );
	}

	/**
	 * Spawn a non-blocking HTTP request to continue the import.
	 *
	 * This allows the import to continue across multiple HTTP requests
	 * without relying on the browser to trigger each request.
	 *
	 * @param string $secret_key The secret key for authentication.
	 * @return void
	 */
	public function spawn_continuation( string $secret_key ): void {
		$url = add_query_arg(
			array(
				'action'     => 'swish_import_continue',
				'secret_key' => $secret_key,
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}
}
