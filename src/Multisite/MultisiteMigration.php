<?php
/**
 * Multisite Migration Handler.
 *
 * @package SwishMigrateAndBackup\Multisite
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles multisite migration operations (import/restore).
 */
final class MultisiteMigration {

	/**
	 * Multisite detector.
	 *
	 * @var MultisiteDetector
	 */
	private MultisiteDetector $detector;

	/**
	 * Backup directory path.
	 *
	 * @var string
	 */
	private string $backup_dir;

	/**
	 * Log file path.
	 *
	 * @var string
	 */
	private string $log_file;

	/**
	 * Constructor.
	 *
	 * @param MultisiteDetector $detector Multisite detector.
	 */
	public function __construct( MultisiteDetector $detector ) {
		$this->detector   = $detector;
		$this->backup_dir = WP_CONTENT_DIR . '/swish-backups';
		$this->log_file   = $this->backup_dir . '/migration-debug.log';
	}

	/**
	 * Log a message to the migration debug log.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function log( string $message ): void {
		$timestamp   = gmdate( 'Y-m-d H:i:s' );
		$log_message = "[{$timestamp}] {$message}\n";

		// Ensure backup dir exists.
		if ( ! file_exists( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $log_message, FILE_APPEND );
	}

	/**
	 * Get the migration debug log file path.
	 *
	 * @return string Log file path.
	 */
	public function get_log_file_path(): string {
		return $this->log_file;
	}

	/**
	 * Get the migration debug log contents.
	 *
	 * @return string Log contents.
	 */
	public function get_log_contents(): string {
		if ( ! file_exists( $this->log_file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $this->log_file );
	}

	/**
	 * Validate uploaded backup file.
	 *
	 * @param string $file_path         Path to uploaded file.
	 * @param string $original_filename Original filename (for uploaded files where temp path has no extension).
	 * @return array Validation result with manifest if valid.
	 */
	public function validate_backup( string $file_path, string $original_filename = '' ): array {
		if ( ! file_exists( $file_path ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Backup file not found.', 'swish-migrate-and-backup' ),
			);
		}

		// Check file extension (use original filename if provided, otherwise use file path).
		$filename_to_check = ! empty( $original_filename ) ? $original_filename : $file_path;
		$extension = strtolower( pathinfo( $filename_to_check, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'swish', 'zip' ), true ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid backup file format. Expected a .swish or .zip file.', 'swish-migrate-and-backup' ),
			);
		}

		// Try to read manifest from the archive (.swish or legacy .zip).
		$manifest = $this->read_manifest_from_archive( $file_path );

		if ( ! $manifest ) {
			return array(
				'valid'   => false,
				'message' => __( 'Could not read backup manifest. Invalid backup file.', 'swish-migrate-and-backup' ),
			);
		}

		// Determine backup type - handle both pro and free plugin formats.
		// Pro plugin uses: backup_type = 'multisite', sites = [array]
		// Free plugin uses: type = 'full', multisite = true/false (boolean), site_url at root
		$backup_type = $manifest['backup_type'] ?? $manifest['type'] ?? 'full';
		$is_multisite_backup = ( $backup_type === 'multisite' );

		// Also check if this is a free plugin backup from a multisite installation.
		// Free plugin sets 'multisite' => true when backing up from a multisite site.
		$was_multisite_site = ! empty( $manifest['multisite'] ) && $manifest['multisite'] === true;

		$this->log( 'Validating backup - backup_type: ' . $backup_type . ', is_multisite_backup: ' . ( $is_multisite_backup ? 'yes' : 'no' ) . ', was_multisite_site: ' . ( $was_multisite_site ? 'yes' : 'no' ) );

		// For backups without a sites array, create one from available URL info.
		if ( ! isset( $manifest['sites'] ) || empty( $manifest['sites'] ) ) {
			// Extract URL from various possible locations.
			$site_url = $manifest['site_url'] ?? $manifest['home_url'] ?? '';

			// If this was from a multisite but has no sites array (free plugin single-site export from multisite).
			if ( $was_multisite_site && ! $is_multisite_backup ) {
				$this->log( 'Free plugin backup from multisite detected, creating pseudo-sites array' );
			}

			$manifest['sites'] = array(
				array(
					'site_id'   => 1,
					'site_url'  => $site_url,
					'site_name' => $manifest['site_name'] ?? get_bloginfo( 'name' ) ?? 'Main Site',
				),
			);

			$this->log( 'Created pseudo-sites array with URL: ' . $site_url );
		}

		// Log the sites array for debugging.
		$this->log( 'Sites in manifest: ' . wp_json_encode( $manifest['sites'] ) );

		// Determine if this backup needs special handling for import into single site.
		// This includes:
		// 1. Pro plugin multisite backups (backup_type = 'multisite')
		// 2. Free plugin backups from multisite sites (multisite = true)
		$needs_multisite_handling = ( $is_multisite_backup || $was_multisite_site ) && ! is_multisite();

		$this->log( 'needs_multisite_handling: ' . ( $needs_multisite_handling ? 'yes' : 'no' ) . ', is_multisite(): ' . ( is_multisite() ? 'yes' : 'no' ) );

		// For multisite backups on single site installations, allow if importing just one site.
		if ( $needs_multisite_handling ) {
			$sites_count = count( $manifest['sites'] ?? array() );

			$this->log( 'Sites count: ' . $sites_count );

			// Allow import if there's only one site in the backup.
			// User can also select a single site from a multi-site backup via options.
			if ( $sites_count === 1 ) {
				// Single site from multisite - allowed, will convert to single site format.
				$result = array(
					'valid'                    => true,
					'message'                  => __( 'Multisite backup with single site detected. Will import as single site.', 'swish-migrate-and-backup' ),
					'manifest'                 => $manifest,
					'backup_type'              => $backup_type,
					'is_multisite_backup'      => $is_multisite_backup || $was_multisite_site,
					'was_multisite_site'       => $was_multisite_site,
					'is_multisite'             => false,
					'import_as_single_site'    => true,
					'available_sites'          => $manifest['sites'],
				);
				$this->log( 'Returning single site result with URL: ' . ( $manifest['sites'][0]['site_url'] ?? 'none' ) );
				return $result;
			}

			// Multiple sites in backup - show site selection option.
			$this->log( 'Multiple sites detected, requiring site selection' );
			return array(
				'valid'                  => true,
				'is_multisite_backup'    => $is_multisite_backup || $was_multisite_site,
				'was_multisite_site'     => $was_multisite_site,
				'requires_site_selection' => true,
				'message'                => __( 'Multisite backup detected. Please select a single site to import into this WordPress installation.', 'swish-migrate-and-backup' ),
				'manifest'               => $manifest,
				'available_sites'        => $manifest['sites'],
				'import_as_single_site'  => true,
			);
		}

		$result = array(
			'valid'               => true,
			'message'             => __( 'Backup file is valid.', 'swish-migrate-and-backup' ),
			'manifest'            => $manifest,
			'backup_type'         => $backup_type,
			'is_multisite_backup' => $is_multisite_backup,
			'is_multisite'        => is_multisite(),
		);

		return $result;
	}

	/**
	 * Read manifest from a backup archive (.swish or legacy .zip).
	 *
	 * Detects the format by content (ZIP magic bytes), not extension, since
	 * uploaded temp files may have no extension.
	 *
	 * @param string $file_path Path to archive file.
	 * @return array|null Manifest data or null on failure.
	 */
	public function read_manifest_from_archive( string $file_path ): ?array {
		if ( $this->is_zip_file( $file_path ) ) {
			return $this->read_manifest_from_zip( $file_path );
		}

		return $this->read_manifest_from_swish( $file_path );
	}

	/**
	 * Check whether a file is a ZIP archive by magic bytes.
	 *
	 * @param string $file_path Path to file.
	 * @return bool True if ZIP.
	 */
	private function is_zip_file( string $file_path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$magic = fread( $handle, 4 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return "PK\x03\x04" === $magic;
	}

	/**
	 * Read manifest from a .swish archive.
	 *
	 * @param string $swish_path Path to .swish file.
	 * @return array|null Manifest data or null on failure.
	 */
	private function read_manifest_from_swish( string $swish_path ): ?array {
		$extractor = new \SwishMigrateAndBackup\Archive\SwishExtractor( $swish_path );

		if ( ! $extractor->open() ) {
			return null;
		}

		$header = $extractor->find_file( 'manifest.json' );

		if ( ! $header ) {
			$extractor->close();
			return null;
		}

		$manifest_content = $extractor->extract_content( $header );
		$extractor->close();

		if ( false === $manifest_content ) {
			return null;
		}

		$manifest = json_decode( $manifest_content, true );

		return is_array( $manifest ) ? $manifest : null;
	}

	/**
	 * Read manifest from ZIP file.
	 *
	 * @param string $zip_path Path to ZIP file.
	 * @return array|null Manifest data or null on failure.
	 */
	public function read_manifest_from_zip( string $zip_path ): ?array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( $zip->open( $zip_path ) !== true ) {
			return null;
		}

		$manifest_content = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( ! $manifest_content ) {
			return null;
		}

		$manifest = json_decode( $manifest_content, true );

		return is_array( $manifest ) ? $manifest : null;
	}

	/**
	 * Start async import with progress tracking.
	 *
	 * @param string $file_path         Path to backup file.
	 * @param array  $options           Import options.
	 * @param string $original_filename Original filename (for uploaded files where temp path has no extension).
	 * @return array Result with job_id for tracking.
	 */
	public function start_import_async( string $file_path, array $options = array(), string $original_filename = '' ): array {
		// Generate unique job ID.
		$job_id = 'import_' . wp_generate_uuid4();

		// Remove stale progress file mirrors from previous jobs.
		foreach ( glob( $this->backup_dir . '/temp/import-job-*.json' ) ?: array() as $old_file ) {
			if ( ( time() - (int) filemtime( $old_file ) ) > HOUR_IN_SECONDS ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $old_file );
			}
		}

		// Validate backup first.
		$validation = $this->validate_backup( $file_path, $original_filename );

		if ( ! $validation['valid'] ) {
			// Check if it requires site selection (multisite backup on single site).
			if ( ! empty( $validation['requires_site_selection'] ) ) {
				return array(
					'success'                 => false,
					'requires_site_selection' => true,
					'available_sites'         => $validation['available_sites'] ?? array(),
					'message'                 => $validation['message'],
					'manifest'                => $validation['manifest'] ?? array(),
				);
			}

			return array(
				'success' => false,
				'message' => $validation['message'],
			);
		}

		// Check if this requires site selection but none was provided.
		if ( ! empty( $validation['requires_site_selection'] ) && empty( $options['site_id'] ) ) {
			return array(
				'success'                 => false,
				'requires_site_selection' => true,
				'available_sites'         => $validation['available_sites'] ?? array(),
				'message'                 => $validation['message'],
				'manifest'                => $validation['manifest'] ?? array(),
			);
		}

		// Copy file to imports directory if it's a temp file.
		$import_dir = $this->backup_dir . '/imports';
		if ( ! file_exists( $import_dir ) ) {
			wp_mkdir_p( $import_dir );
		}

		$permanent_path = $file_path;
		if ( strpos( $file_path, sys_get_temp_dir() ) !== false || strpos( $file_path, '/tmp/' ) !== false ) {
			$permanent_path = $import_dir . '/' . $job_id . '.zip';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! copy( $file_path, $permanent_path ) ) {
				return array(
					'success' => false,
					'message' => __( 'Failed to copy backup file for processing.', 'swish-migrate-and-backup' ),
				);
			}
		}

		// Merge import_as_single_site flag into options if set in validation.
		if ( ! empty( $validation['import_as_single_site'] ) ) {
			$options['import_as_single_site'] = true;
		}

		// Store job data in transient.
		$job_data = array(
			'job_id'       => $job_id,
			'file_path'    => $permanent_path,
			'options'      => $options,
			'manifest'     => $validation['manifest'],
			'is_multisite' => $validation['is_multisite_backup'] ?? false,
			'status'       => 'pending',
			'progress'     => 0,
			'current_step' => 'init',
			'message'      => __( 'Initializing import...', 'swish-migrate-and-backup' ),
			'started_at'   => current_time( 'mysql' ),
		);

		set_transient( 'swish_import_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
		$this->save_progress_file( $job_data );

		// Schedule the import to run immediately via WP Cron.
		wp_schedule_single_event( time(), 'swish_backup_run_import', array( $job_id ) );

		// Also try to spawn cron immediately.
		spawn_cron();

		return array(
			'success' => true,
			'job_id'  => $job_id,
			'message' => __( 'Import started.', 'swish-migrate-and-backup' ),
		);
	}

	/**
	 * Run import job (called via WP Cron).
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_import_job( string $job_id ): void {
		$job_data = $this->get_import_progress( $job_id );

		if ( ! $job_data ) {
			return;
		}

		// Mark as running.
		$job_data['status'] = 'running';
		set_transient( 'swish_import_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
		$this->save_progress_file( $job_data );

		try {
			$result = $this->import_backup_with_progress( $job_id, $job_data );

			if ( $result['success'] ) {
				$job_data['status']   = 'completed';
				$job_data['progress'] = 100;
				$job_data['message']  = $result['message'];
			} else {
				$job_data['status']  = 'failed';
				$job_data['message'] = $result['message'];
				$job_data['error']   = $result['message'];
			}
		} catch ( \Exception $e ) {
			$job_data['status']  = 'failed';
			$job_data['message'] = $e->getMessage();
			$job_data['error']   = $e->getMessage();
		}

		$job_data['completed_at'] = current_time( 'mysql' );
		set_transient( 'swish_import_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
		$this->save_progress_file( $job_data );

		// Cleanup the import file if it was a temp copy.
		$import_dir = $this->backup_dir . '/imports';
		if ( strpos( $job_data['file_path'], $import_dir ) !== false ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $job_data['file_path'] );
		}
	}

	/**
	 * Get the path to the file-based progress mirror for a job.
	 *
	 * The progress transient lives in the options table, which the import
	 * itself replaces, so progress is also mirrored to a file that survives
	 * the database restore.
	 *
	 * @param string $job_id Job ID.
	 * @return string|null File path, or null if the job ID is malformed.
	 */
	private function get_progress_file_path( string $job_id ): ?string {
		if ( ! preg_match( '/^import_[a-f0-9\-]{36}$/', $job_id ) ) {
			return null;
		}

		$temp_dir = $this->backup_dir . '/temp';
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		return $temp_dir . '/import-job-' . $job_id . '.json';
	}

	/**
	 * Write job progress to the file mirror.
	 *
	 * @param array $job_data Job data.
	 * @return void
	 */
	private function save_progress_file( array $job_data ): void {
		$path = $this->get_progress_file_path( $job_data['job_id'] ?? '' );
		if ( $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $path, wp_json_encode( $job_data ), LOCK_EX );
		}
	}

	/**
	 * Get import progress.
	 *
	 * Reads the file mirror first (it survives the database restore),
	 * falling back to the transient.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Progress data or null if not found.
	 */
	public function get_import_progress( string $job_id ): ?array {
		$path = $this->get_progress_file_path( $job_id );
		if ( $path && file_exists( $path ) ) {
			clearstatcache( true, $path );
			if ( ( time() - (int) filemtime( $path ) ) < HOUR_IN_SECONDS ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				$json = @file_get_contents( $path );
				$data = $json ? json_decode( $json, true ) : null;
				if ( is_array( $data ) && ! empty( $data ) ) {
					return $data;
				}
			}
		}

		$job_data = get_transient( 'swish_import_job_' . $job_id );
		return $job_data ?: null;
	}

	/**
	 * Update import progress.
	 *
	 * @param string $job_id   Job ID.
	 * @param int    $progress Progress percentage.
	 * @param string $step     Current step.
	 * @param string $message  Status message.
	 * @return void
	 */
	private function update_import_progress( string $job_id, int|float $progress, string $step, string $message ): void {
		$job_data = $this->get_import_progress( $job_id );
		if ( $job_data ) {
			$job_data['progress']     = (int) $progress;
			$job_data['current_step'] = $step;
			$job_data['message']      = $message;
			set_transient( 'swish_import_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
			$this->save_progress_file( $job_data );
		}
	}

	/**
	 * Import backup with progress tracking.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $job_data Job data.
	 * @return array Import result.
	 */
	private function import_backup_with_progress( string $job_id, array $job_data ): array {
		$file_path             = $job_data['file_path'];
		$options               = $job_data['options'];
		$manifest              = $job_data['manifest'];
		$is_multisite_backup   = $job_data['is_multisite'];
		$import_as_single_site = ! empty( $options['import_as_single_site'] ) || ( $is_multisite_backup && ! is_multisite() );

		// Step 1: Extract backup (10%).
		$this->update_import_progress( $job_id, 5, 'extract', __( 'Extracting backup file...', 'swish-migrate-and-backup' ) );

		$temp_dir = $this->extract_backup( $file_path );
		if ( ! $temp_dir ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to extract backup file.', 'swish-migrate-and-backup' ),
			);
		}

		$this->update_import_progress( $job_id, 10, 'extract', __( 'Backup extracted successfully.', 'swish-migrate-and-backup' ) );

		try {
			// Handle multisite backup import to single site WordPress.
			if ( $is_multisite_backup && $import_as_single_site ) {
				return $this->import_multisite_to_single_with_progress( $job_id, $temp_dir, $manifest, $options );
			}

			if ( $is_multisite_backup ) {
				// Step 2: Import shared tables (20%).
				// For multisite-to-multisite imports, shared tables MUST be imported
				// to restore wp_blogs, wp_site, wp_users, etc.
				$should_import_shared = ! empty( $options['import_shared_tables'] ) || is_multisite();
				if ( $should_import_shared ) {
					$this->update_import_progress( $job_id, 15, 'shared', __( 'Importing shared network tables...', 'swish-migrate-and-backup' ) );
					$import_options = $options;
					unset( $import_options['search_replace'] );
					$this->import_shared_tables( $temp_dir, $import_options );
					$this->update_import_progress( $job_id, 20, 'shared', __( 'Shared tables imported.', 'swish-migrate-and-backup' ) );
				}

				// Step 3: Import site databases (40%).
				$sites_to_import = $options['site_ids'] ?? array_column( $manifest['sites'], 'site_id' );
				// Ensure site IDs are integers for comparison.
				$sites_to_import = array_map( 'intval', $sites_to_import );
				$total_sites     = count( $sites_to_import );
				$imported_count  = 0;
				$import_errors   = array();

				$this->log( "Multisite import: Starting import of {$total_sites} sites" );
				$this->log( "Multisite import: Sites to import: " . implode( ', ', $sites_to_import ) );

				$this->update_import_progress( $job_id, 25, 'database', __( 'Importing site databases...', 'swish-migrate-and-backup' ) );

				foreach ( $manifest['sites'] as $site_data ) {
					$site_id = (int) $site_data['site_id'];

					if ( ! in_array( $site_id, $sites_to_import, true ) ) {
						$this->log( "Multisite import: Skipping site {$site_id} (not in import list)" );
						continue;
					}

					$site_name = $site_data['site_name'] ?? 'Site ' . $site_id;
					$this->log( "Multisite import: Processing site {$site_id} ({$site_name})" );

					$this->update_import_progress(
						$job_id,
						25 + ( 15 * ( $imported_count / max( $total_sites, 1 ) ) ),
						'database',
						sprintf(
							/* translators: %1$s: site name, %2$d: current, %3$d: total */
							__( 'Importing database: %1$s (%2$d/%3$d)', 'swish-migrate-and-backup' ),
							$site_name,
							$imported_count + 1,
							$total_sites
						)
					);

					$import_options = $options;
					unset( $import_options['search_replace'] );

					try {
						$result = $this->import_site( $temp_dir, $site_data, $import_options );

						if ( ! $result['success'] ) {
							$import_errors[] = "Site {$site_id}: " . $result['message'];
							$this->log( "Multisite import: Site {$site_id} import failed: " . $result['message'] );
						} else {
							$this->log( "Multisite import: Site {$site_id} imported successfully" );
						}
					} catch ( \Exception $e ) {
						$import_errors[] = "Site {$site_id}: " . $e->getMessage();
						$this->log( "Multisite import: Site {$site_id} exception: " . $e->getMessage() );
					}

					$imported_count++;
				}

				$this->log( "Multisite import: Completed {$imported_count}/{$total_sites} sites with " . count( $import_errors ) . " errors" );

				$this->update_import_progress( $job_id, 40, 'database', __( 'Site databases imported.', 'swish-migrate-and-backup' ) );

				// CRITICAL: Reactivate plugins immediately after database import.
				// The database import replaces wp_options including active_plugins.
				// We must restore our plugins before the session check fails.
				$this->update_import_progress( $job_id, 42, 'database', __( 'Reactivating plugins...', 'swish-migrate-and-backup' ) );
				$this->reactivate_plugins( $manifest );
			} else {
				// Single site backup - import database.
				$this->update_import_progress( $job_id, 20, 'database', __( 'Importing database...', 'swish-migrate-and-backup' ) );
				$this->import_single_site_database( $temp_dir );
				$this->update_import_progress( $job_id, 40, 'database', __( 'Database imported.', 'swish-migrate-and-backup' ) );

				// CRITICAL: Reactivate plugins immediately after database import.
				$this->update_import_progress( $job_id, 42, 'database', __( 'Reactivating plugins...', 'swish-migrate-and-backup' ) );
				$this->reactivate_plugins( $manifest );
			}

			// Step 4: Restore files (60%).
			$this->update_import_progress( $job_id, 45, 'files', __( 'Restoring files...', 'swish-migrate-and-backup' ) );

			// Check for tar.gz archive first (preferred, faster).
			$tar_files = glob( $temp_dir . '/files*.tar.gz' );
			if ( ! empty( $tar_files ) ) {
				sort( $tar_files );
				$this->update_import_progress( $job_id, 50, 'files', __( 'Extracting tar.gz archives...', 'swish-migrate-and-backup' ) );
				foreach ( $tar_files as $tar_path ) {
					$this->log( 'Extracting tar.gz archive: ' . basename( $tar_path ) );
					$this->restore_files_tar( $tar_path );
				}
			}

			// Check for files.zip (free plugin format).
			$files_zip = $temp_dir . '/files.zip';
			if ( file_exists( $files_zip ) ) {
				$this->update_import_progress( $job_id, 50, 'files', __( 'Extracting files archive...', 'swish-migrate-and-backup' ) );
				$zip = new \ZipArchive();
				if ( $zip->open( $files_zip ) === true ) {
					$this->safe_extract_zip( $zip, ABSPATH );
					$zip->close();
				}
			}

			// Check for multiple batch parts (files-001.zip, files-002.zip, etc.).
			$file_parts = glob( $temp_dir . '/files-*.zip' );
			if ( ! empty( $file_parts ) ) {
				sort( $file_parts ); // Ensure correct order.
				$this->update_import_progress( $job_id, 52, 'files', __( 'Extracting file batch parts...', 'swish-migrate-and-backup' ) );
				foreach ( $file_parts as $part_path ) {
					$this->log( 'Extracting file part: ' . basename( $part_path ) );
					$zip = new \ZipArchive();
					if ( $zip->open( $part_path ) === true ) {
						$this->safe_extract_zip( $zip, ABSPATH );
						$zip->close();
					}
				}
			}

			// Restore wp-content files.
			$this->update_import_progress( $job_id, 55, 'files', __( 'Restoring wp-content files...', 'swish-migrate-and-backup' ) );
			$this->restore_wp_content_files( $temp_dir, $manifest );

			// Restore core files if included.
			if ( ! empty( $manifest['include_core_files'] ) ) {
				$this->update_import_progress( $job_id, 60, 'files', __( 'Restoring WordPress core files...', 'swish-migrate-and-backup' ) );
				$this->restore_wp_core_files( $temp_dir );
			}

			$this->update_import_progress( $job_id, 65, 'files', __( 'Files restored.', 'swish-migrate-and-backup' ) );

			// Step 5: Search/Replace (80%).
			if ( ! empty( $options['search_replace'] ) ) {
				$this->update_import_progress( $job_id, 70, 'search_replace', __( 'Running search and replace...', 'swish-migrate-and-backup' ) );
				$this->run_search_replace( $options['search_replace'] );
				$this->update_import_progress( $job_id, 80, 'search_replace', __( 'Search and replace completed.', 'swish-migrate-and-backup' ) );

				// Step 5.5: Force fix site configuration with the target domain.
				// This ensures wp_site and wp_blogs have the correct domain.
				$this->update_import_progress( $job_id, 82, 'search_replace', __( 'Fixing site configuration...', 'swish-migrate-and-backup' ) );
				$replace_url = array_values( $options['search_replace'] )[0] ?? '';
				if ( ! empty( $replace_url ) ) {
					$this->fix_site_configuration( $replace_url );
				}
				$this->update_import_progress( $job_id, 85, 'search_replace', __( 'Site configuration fixed.', 'swish-migrate-and-backup' ) );
			} else {
				$this->update_import_progress( $job_id, 85, 'search_replace', __( 'No URL replacement needed.', 'swish-migrate-and-backup' ) );
			}

			// Step 6: Cleanup (100%).
			$this->update_import_progress( $job_id, 88, 'cleanup', __( 'Reactivating plugins...', 'swish-migrate-and-backup' ) );
			$this->reactivate_plugins( $manifest );

			$this->update_import_progress( $job_id, 92, 'cleanup', __( 'Flushing caches...', 'swish-migrate-and-backup' ) );
			$this->flush_caches();

			$this->update_import_progress( $job_id, 96, 'cleanup', __( 'Cleaning up temporary files...', 'swish-migrate-and-backup' ) );
			$this->cleanup_temp_directory( $temp_dir );

			$this->update_import_progress( $job_id, 100, 'complete', __( 'Import completed successfully!', 'swish-migrate-and-backup' ) );

			return array(
				'success' => true,
				'message' => __( 'Import completed successfully!', 'swish-migrate-and-backup' ),
			);

		} catch ( \Exception $e ) {
			// Cleanup on error.
			if ( isset( $temp_dir ) && file_exists( $temp_dir ) ) {
				$this->cleanup_temp_directory( $temp_dir );
			}

			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Import a multisite backup as a single site installation.
	 *
	 * This method converts multisite table prefixes to single-site format
	 * and skips network-specific tables.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $temp_dir Temporary directory with extracted backup.
	 * @param array  $manifest Backup manifest.
	 * @param array  $options  Import options.
	 * @return array Import result.
	 */
	private function import_multisite_to_single_with_progress( string $job_id, string $temp_dir, array $manifest, array $options ): array {
		global $wpdb;

		try {
			// Determine which site to import.
			$sites = $manifest['sites'] ?? array();
			$site_to_import = null;

			// If site_id is specified in options, use that.
			if ( ! empty( $options['site_id'] ) ) {
				foreach ( $sites as $site ) {
					if ( (int) $site['site_id'] === (int) $options['site_id'] ) {
						$site_to_import = $site;
						break;
					}
				}
			} elseif ( count( $sites ) === 1 ) {
				// Only one site in backup, use it.
				$site_to_import = $sites[0];
			} else {
				// Multiple sites but none selected - use the first one (main site).
				$site_to_import = $sites[0];
				$this->log( 'Multiple sites in backup, importing first site: ' . ( $site_to_import['site_name'] ?? 'Site 1' ) );
			}

			if ( ! $site_to_import ) {
				return array(
					'success' => false,
					'message' => __( 'Could not determine which site to import.', 'swish-migrate-and-backup' ),
				);
			}

			$site_id = (int) $site_to_import['site_id'];
			$site_name = $site_to_import['site_name'] ?? 'Site ' . $site_id;

			$this->log( "Importing multisite site '{$site_name}' (ID: {$site_id}) as single site" );

			// Step 2: Import site database with prefix conversion (20-40%).
			$this->update_import_progress( $job_id, 20, 'database', sprintf(
				/* translators: %s: site name */
				__( 'Importing database for %s...', 'swish-migrate-and-backup' ),
				$site_name
			) );

			$this->import_multisite_database_as_single( $temp_dir, $site_id, $manifest );

			$this->update_import_progress( $job_id, 40, 'database', __( 'Database imported.', 'swish-migrate-and-backup' ) );

			// CRITICAL: Reactivate plugins immediately after database import.
			// The database import replaces wp_options including active_plugins.
			// We must restore our plugins before the session check fails.
			$this->update_import_progress( $job_id, 42, 'database', __( 'Reactivating plugins...', 'swish-migrate-and-backup' ) );
			$this->reactivate_plugins( $manifest );

			// Step 3: Restore files (40-65%).
			$this->update_import_progress( $job_id, 45, 'files', __( 'Restoring files...', 'swish-migrate-and-backup' ) );

			// Check for tar.gz archive first (preferred, faster).
			$tar_files = glob( $temp_dir . '/files*.tar.gz' );
			if ( ! empty( $tar_files ) ) {
				sort( $tar_files );
				$this->update_import_progress( $job_id, 50, 'files', __( 'Extracting tar.gz archives...', 'swish-migrate-and-backup' ) );
				foreach ( $tar_files as $tar_path ) {
					$this->log( 'Extracting tar.gz archive: ' . basename( $tar_path ) );
					$this->restore_files_tar( $tar_path );
				}
			}

			// Check for files.zip.
			$files_zip = $temp_dir . '/files.zip';
			if ( file_exists( $files_zip ) ) {
				$this->update_import_progress( $job_id, 50, 'files', __( 'Extracting files archive...', 'swish-migrate-and-backup' ) );
				$zip = new \ZipArchive();
				if ( $zip->open( $files_zip ) === true ) {
					$this->safe_extract_zip( $zip, ABSPATH );
					$zip->close();
				}
			}

			// Restore wp-content files.
			$this->update_import_progress( $job_id, 55, 'files', __( 'Restoring wp-content files...', 'swish-migrate-and-backup' ) );
			$this->restore_wp_content_files( $temp_dir, $manifest );

			// Restore core files if included.
			if ( ! empty( $manifest['include_core_files'] ) ) {
				$this->update_import_progress( $job_id, 60, 'files', __( 'Restoring WordPress core files...', 'swish-migrate-and-backup' ) );
				$this->restore_wp_core_files( $temp_dir );
			}

			$this->update_import_progress( $job_id, 65, 'files', __( 'Files restored.', 'swish-migrate-and-backup' ) );

			// Step 4: Search/Replace (65-85%).
			if ( ! empty( $options['search_replace'] ) ) {
				$this->update_import_progress( $job_id, 70, 'search_replace', __( 'Running search and replace...', 'swish-migrate-and-backup' ) );
				$this->run_search_replace( $options['search_replace'] );
				$this->update_import_progress( $job_id, 85, 'search_replace', __( 'Search and replace completed.', 'swish-migrate-and-backup' ) );
			} else {
				$this->update_import_progress( $job_id, 85, 'search_replace', __( 'No URL replacement needed.', 'swish-migrate-and-backup' ) );
			}

			// Step 5: Cleanup (85-100%).
			$this->update_import_progress( $job_id, 88, 'cleanup', __( 'Reactivating plugins...', 'swish-migrate-and-backup' ) );
			$this->reactivate_plugins( $manifest );

			$this->update_import_progress( $job_id, 92, 'cleanup', __( 'Flushing caches...', 'swish-migrate-and-backup' ) );
			$this->flush_caches();

			$this->update_import_progress( $job_id, 96, 'cleanup', __( 'Cleaning up temporary files...', 'swish-migrate-and-backup' ) );
			$this->cleanup_temp_directory( $temp_dir );

			$this->update_import_progress( $job_id, 100, 'complete', __( 'Import completed successfully!', 'swish-migrate-and-backup' ) );

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: site name */
					__( 'Successfully imported multisite "%s" as single site!', 'swish-migrate-and-backup' ),
					$site_name
				),
			);

		} catch ( \Exception $e ) {
			// Cleanup on error.
			if ( file_exists( $temp_dir ) ) {
				$this->cleanup_temp_directory( $temp_dir );
			}

			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Import a multisite site's database as a single site.
	 *
	 * Converts multisite table prefixes (e.g., wp_2_posts) to single site format (wp_posts).
	 *
	 * @param string $temp_dir Temporary directory with extracted backup.
	 * @param int    $site_id  Site ID to import.
	 * @param array  $manifest Optional manifest for table_prefix info (free plugin backups).
	 * @return void
	 */
	private function import_multisite_database_as_single( string $temp_dir, int $site_id, array $manifest = array() ): void {
		global $wpdb;

		// Try to find the database file - check both pro format and free format.
		$sql_file = $temp_dir . '/site-' . $site_id . '-database.sql';
		$is_free_plugin_backup = false;

		if ( ! file_exists( $sql_file ) ) {
			// Try free plugin format (single database.sql file).
			$sql_file = $temp_dir . '/database.sql';
			$is_free_plugin_backup = true;

			if ( ! file_exists( $sql_file ) ) {
				$this->log( "Database file not found: site-{$site_id}-database.sql or database.sql" );
				return;
			}
			$this->log( 'Using free plugin database format (database.sql)' );
		}

		$this->log( "Importing site {$site_id} database with prefix conversion" );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sql_content = file_get_contents( $sql_file );

		// Determine the multisite prefix for this site.
		// Priority order:
		// 1. Manifest table_prefix (free plugin backups have this)
		// 2. Site-specific table_prefix from manifest sites array (pro plugin)
		// 3. Detect from SQL file content (most reliable)
		// 4. Fall back to assumption based on site_id
		$multisite_prefix = '';

		// Option 1: Check manifest root table_prefix (free plugin format).
		if ( $is_free_plugin_backup && ! empty( $manifest['table_prefix'] ) ) {
			$multisite_prefix = $manifest['table_prefix'];
			$this->log( "Using table prefix from manifest root: {$multisite_prefix}" );
		}

		// Option 2: Check site-specific table_prefix in sites array (pro plugin format).
		if ( empty( $multisite_prefix ) && ! empty( $manifest['sites'] ) ) {
			foreach ( $manifest['sites'] as $site ) {
				if ( (int) ( $site['site_id'] ?? 0 ) === $site_id && ! empty( $site['table_prefix'] ) ) {
					$multisite_prefix = $site['table_prefix'];
					$this->log( "Using table prefix from site manifest: {$multisite_prefix}" );
					break;
				}
			}
		}

		// Option 3: Detect from SQL content (most reliable for unknown formats).
		if ( empty( $multisite_prefix ) ) {
			// Look for CREATE TABLE statement to extract the actual prefix.
			// Pattern matches: CREATE TABLE `prefix_tablename` or CREATE TABLE prefix_tablename
			if ( preg_match( '/CREATE TABLE [`]?([a-z0-9_]+)(posts|options|usermeta|users)[`]?\s/i', $sql_content, $matches ) ) {
				$detected_prefix = $matches[1];
				// Verify this looks like a valid WordPress prefix.
				if ( ! empty( $detected_prefix ) ) {
					$multisite_prefix = $detected_prefix;
					$this->log( "Detected table prefix from SQL: {$multisite_prefix}" );
				}
			}
		}

		// Option 4: Fall back to convention-based assumption (may not work for all backups).
		if ( empty( $multisite_prefix ) ) {
			// Try to detect from manifest network info.
			$base_prefix = $manifest['network']['base_prefix'] ?? 'wp_';

			if ( $site_id === 1 ) {
				$multisite_prefix = $base_prefix;
			} else {
				$multisite_prefix = $base_prefix . $site_id . '_';
			}
			$this->log( "Using convention-based prefix (site {$site_id}): {$multisite_prefix}" );
		}

		$target_prefix = $wpdb->prefix;

		$this->log( "Converting prefix from '{$multisite_prefix}' to '{$target_prefix}'" );

		// Replace table prefixes in the SQL content.
		// We need to be careful to only replace table names, not data.
		if ( $multisite_prefix !== $target_prefix ) {
			// Replace in CREATE TABLE statements.
			$sql_content = preg_replace(
				'/CREATE TABLE (`?)' . preg_quote( $multisite_prefix, '/' ) . '/',
				'CREATE TABLE $1' . $target_prefix,
				$sql_content
			);

			// Replace in DROP TABLE statements.
			$sql_content = preg_replace(
				'/DROP TABLE IF EXISTS (`?)' . preg_quote( $multisite_prefix, '/' ) . '/',
				'DROP TABLE IF EXISTS $1' . $target_prefix,
				$sql_content
			);

			// Replace in INSERT INTO statements.
			$sql_content = preg_replace(
				'/INSERT INTO (`?)' . preg_quote( $multisite_prefix, '/' ) . '/',
				'INSERT INTO $1' . $target_prefix,
				$sql_content
			);

			// Replace in LOCK TABLES statements.
			$sql_content = preg_replace(
				'/LOCK TABLES (`?)' . preg_quote( $multisite_prefix, '/' ) . '/',
				'LOCK TABLES $1' . $target_prefix,
				$sql_content
			);

			// Replace table references in data (e.g., usermeta keys like wp_2_capabilities).
			// This handles serialized data references.
			$sql_content = str_replace(
				"'" . $multisite_prefix,
				"'" . $target_prefix,
				$sql_content
			);
		}

		// Disable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		// Split and execute SQL statements.
		$statements = $this->split_sql_statements( $sql_content );
		$executed = 0;

		foreach ( $statements as $statement ) {
			$statement = trim( $statement );
			if ( ! empty( $statement ) && strpos( $statement, '--' ) !== 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $statement );
				$executed++;
			}
		}

		// Re-enable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		$this->log( "Executed {$executed} SQL statements for site {$site_id}" );
	}

	/**
	 * Import single site database (helper for progress tracking).
	 *
	 * @param string $temp_dir Temp directory.
	 * @return void
	 */
	private function import_single_site_database( string $temp_dir ): void {
		global $wpdb;

		$db_file = $temp_dir . '/database.sql';
		if ( ! file_exists( $db_file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sql_content = file_get_contents( $db_file );

		// Disable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		// Split and execute SQL statements.
		$statements = $this->split_sql_statements( $sql_content );

		foreach ( $statements as $statement ) {
			$statement = trim( $statement );
			if ( ! empty( $statement ) && strpos( $statement, '--' ) !== 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $statement );
			}
		}

		// Re-enable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
	}

	/**
	 * Import/restore backup (supports both multisite and single-site backups).
	 * Synchronous version for backwards compatibility.
	 *
	 * @param string $file_path   Path to backup file.
	 * @param array  $options     Import options.
	 * @return array Import result.
	 */
	public function import_backup( string $file_path, array $options = array() ): array {
		// Validate backup first.
		$validation = $this->validate_backup( $file_path );

		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => $validation['message'],
			);
		}

		$manifest = $validation['manifest'];
		$is_multisite_backup = $validation['is_multisite_backup'] ?? false;

		// Check if we need to convert to multisite.
		if ( ! empty( $validation['requires_conversion'] ) ) {
			if ( empty( $options['confirm_conversion'] ) ) {
				return array(
					'success'             => false,
					'requires_conversion' => true,
					'message'             => __( 'This import requires converting your site to multisite. Please confirm this action.', 'swish-migrate-and-backup' ),
				);
			}

			// Attempt to convert to multisite.
			$conversion_result = $this->convert_to_multisite();

			if ( ! $conversion_result['success'] ) {
				return array(
					'success' => false,
					'message' => $conversion_result['message'],
				);
			}
		}

		try {
			// Extract backup to temp directory.
			$temp_dir = $this->extract_backup( $file_path );

			if ( ! $temp_dir ) {
				throw new \Exception( __( 'Failed to extract backup file.', 'swish-migrate-and-backup' ) );
			}

			// Handle differently based on backup type.
			if ( $is_multisite_backup ) {
				$result = $this->import_multisite_backup( $temp_dir, $manifest, $options );
			} else {
				$result = $this->import_single_site_backup( $temp_dir, $manifest, $options );
			}

			// Now run search/replace on the database if specified.
			if ( ! empty( $options['search_replace'] ) ) {
				$this->run_search_replace( $options['search_replace'] );
			}

			// Flush caches.
			$this->flush_caches();

			// Cleanup temp directory.
			$this->cleanup_temp_directory( $temp_dir );

			return $result;

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Import multisite backup.
	 *
	 * @param string $temp_dir Temp directory with extracted backup.
	 * @param array  $manifest Backup manifest.
	 * @param array  $options  Import options.
	 * @return array Import result.
	 */
	private function import_multisite_backup( string $temp_dir, array $manifest, array $options ): array {
		// Get sites to import.
		$sites_to_import = $options['site_ids'] ?? array_column( $manifest['sites'], 'site_id' );

		// Remove search_replace from options for SQL import - we'll do it after.
		$import_options = $options;
		unset( $import_options['search_replace'] );

		// Import shared tables first.
		// For multisite-to-multisite imports, shared tables MUST be imported.
		$should_import_shared = ! empty( $options['import_shared_tables'] ) || is_multisite();
		if ( $should_import_shared ) {
			$this->import_shared_tables( $temp_dir, $import_options );
		}

		// Import each site database.
		$imported_sites = array();
		foreach ( $manifest['sites'] as $site_data ) {
			if ( ! in_array( $site_data['site_id'], $sites_to_import, true ) ) {
				continue;
			}

			$site_result = $this->import_site( $temp_dir, $site_data, $import_options );
			$imported_sites[] = $site_result;
		}

		// Restore wp-content files (themes, plugins, uploads).
		$this->restore_wp_content_files( $temp_dir, $manifest );

		// Restore wp-core files if they were included.
		if ( ! empty( $manifest['include_core_files'] ) ) {
			$this->restore_wp_core_files( $temp_dir );
		}

		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %d: number of sites */
				__( 'Successfully imported %d sites.', 'swish-migrate-and-backup' ),
				count( $imported_sites )
			),
			'imported_sites' => $imported_sites,
		);
	}

	/**
	 * Import single-site backup (from free plugin).
	 *
	 * @param string $temp_dir Temp directory with extracted backup.
	 * @param array  $manifest Backup manifest.
	 * @param array  $options  Import options.
	 * @return array Import result.
	 */
	private function import_single_site_backup( string $temp_dir, array $manifest, array $options ): array {
		global $wpdb;

		// Restore database if exists.
		$db_file = $temp_dir . '/database.sql';
		if ( file_exists( $db_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$sql_content = file_get_contents( $db_file );

			// Disable foreign key checks.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

			// Split and execute SQL statements.
			$statements = $this->split_sql_statements( $sql_content );

			foreach ( $statements as $statement ) {
				$statement = trim( $statement );
				if ( ! empty( $statement ) && strpos( $statement, '--' ) !== 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( $statement );
				}
			}

			// Re-enable foreign key checks.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		}

		// Check for tar.gz archive first (preferred, faster).
		$tar_files = glob( $temp_dir . '/files*.tar.gz' );
		if ( ! empty( $tar_files ) ) {
			sort( $tar_files );
			foreach ( $tar_files as $tar_path ) {
				$this->log( 'Extracting tar.gz archive: ' . basename( $tar_path ) );
				$this->restore_files_tar( $tar_path );
			}
		}

		// Restore files if files.zip exists (free plugin format).
		$files_zip = $temp_dir . '/files.zip';
		if ( file_exists( $files_zip ) ) {
			$zip = new \ZipArchive();
			if ( $zip->open( $files_zip ) === true ) {
				$this->safe_extract_zip( $zip, ABSPATH );
				$zip->close();
			}
		}

		// Check for multiple batch parts (files-001.zip, files-002.zip, etc.).
		$file_parts = glob( $temp_dir . '/files-*.zip' );
		if ( ! empty( $file_parts ) ) {
			sort( $file_parts ); // Ensure correct order.
			foreach ( $file_parts as $part_path ) {
				$this->log( 'Extracting file part: ' . basename( $part_path ) );
				$zip = new \ZipArchive();
				if ( $zip->open( $part_path ) === true ) {
					$this->safe_extract_zip( $zip, ABSPATH );
					$zip->close();
				}
			}
		}

		// Also check for wp-content folder directly (alternative format).
		$this->restore_wp_content_files( $temp_dir, $manifest );

		// Restore wp-core if included.
		if ( ! empty( $manifest['include_core_files'] ) ) {
			$this->restore_wp_core_files( $temp_dir );
		}

		return array(
			'success' => true,
			'message' => __( 'Successfully imported backup.', 'swish-migrate-and-backup' ),
		);
	}

	/**
	 * Restore wp-content files (themes, plugins, uploads).
	 *
	 * @param string $temp_dir Temp directory with extracted backup.
	 * @param array  $manifest Backup manifest.
	 * @return void
	 */
	private function restore_wp_content_files( string $temp_dir, array $manifest ): void {
		$wp_content_source = $temp_dir . '/wp-content';

		// Check if wp-content folder exists in backup.
		if ( ! is_dir( $wp_content_source ) ) {
			return;
		}

		$wp_content_dest = WP_CONTENT_DIR;

		// Restore themes.
		if ( is_dir( $wp_content_source . '/themes' ) ) {
			$this->copy_directory( $wp_content_source . '/themes', $wp_content_dest . '/themes' );
		}

		// Restore plugins.
		if ( is_dir( $wp_content_source . '/plugins' ) ) {
			$this->copy_directory( $wp_content_source . '/plugins', $wp_content_dest . '/plugins' );
		}

		// Restore uploads.
		if ( is_dir( $wp_content_source . '/uploads' ) ) {
			$this->copy_directory( $wp_content_source . '/uploads', $wp_content_dest . '/uploads' );
		}

		// Restore mu-plugins.
		if ( is_dir( $wp_content_source . '/mu-plugins' ) ) {
			$this->copy_directory( $wp_content_source . '/mu-plugins', $wp_content_dest . '/mu-plugins' );
		}
	}

	/**
	 * Restore WordPress core files.
	 *
	 * @param string $temp_dir Temp directory with extracted backup.
	 * @return void
	 */
	private function restore_wp_core_files( string $temp_dir ): void {
		$wp_core_source = $temp_dir . '/wp-core';

		if ( ! is_dir( $wp_core_source ) ) {
			return;
		}

		// Copy wp-admin.
		if ( is_dir( $wp_core_source . '/wp-admin' ) ) {
			$this->copy_directory( $wp_core_source . '/wp-admin', ABSPATH . 'wp-admin' );
		}

		// Copy wp-includes.
		if ( is_dir( $wp_core_source . '/wp-includes' ) ) {
			$this->copy_directory( $wp_core_source . '/wp-includes', ABSPATH . 'wp-includes' );
		}

		// Copy root PHP files.
		$files = glob( $wp_core_source . '/*.php' );
		foreach ( $files as $file ) {
			$filename = basename( $file );
			// Skip wp-config.php - should not overwrite.
			if ( $filename === 'wp-config.php' ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $file, ABSPATH . $filename );
		}
	}

	/**
	 * Copy directory recursively.
	 *
	 * @param string $source      Source directory.
	 * @param string $destination Destination directory.
	 * @return void
	 */
	private function copy_directory( string $source, string $destination ): void {
		if ( ! is_dir( $source ) ) {
			return;
		}

		if ( ! is_dir( $destination ) ) {
			wp_mkdir_p( $destination );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$target = $destination . '/' . $iterator->getSubPathname();

			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					wp_mkdir_p( $target );
				}
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				copy( $item->getPathname(), $target );
			}
		}
	}

	/**
	 * Reactivate plugins after import.
	 *
	 * After importing a database, the active_plugins option may reference plugins
	 * that don't exist or may be missing the backup plugins themselves.
	 * This method ensures the current site's plugins are properly activated.
	 *
	 * CRITICAL: This uses $wpdb directly to avoid WordPress caching issues
	 * that can occur when the database has just been replaced.
	 *
	 * @param array $manifest Optional manifest with active_plugins from the backup.
	 * @return void
	 */
	private function reactivate_plugins( array $manifest = array() ): void {
		global $wpdb;

		$this->log( '--- reactivate_plugins() ---' );

		// Clear WordPress option cache to ensure we read fresh data.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'active_plugins', 'options' );

		// Get current active plugins directly from database to avoid cache issues.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'active_plugins'
			)
		);

		$active_plugins = array();
		if ( $row && ! empty( $row->option_value ) ) {
			$active_plugins = maybe_unserialize( $row->option_value );
			if ( ! is_array( $active_plugins ) ) {
				$active_plugins = array();
			}
		}

		$this->log( 'Current active_plugins from DB: ' . count( $active_plugins ) . ' plugins' );
		$this->log( 'Active plugins list: ' . implode( ', ', $active_plugins ) );

		// Always ensure our backup plugin is active.
		$required_plugins = array(
			'swish-migrate-and-backup/swish-migrate-and-backup.php',
		);

		foreach ( $required_plugins as $plugin ) {
			// Check if plugin file exists.
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin;
			$this->log( 'Checking required plugin: ' . $plugin . ' at ' . $plugin_path );

			if ( file_exists( $plugin_path ) ) {
				if ( ! in_array( $plugin, $active_plugins, true ) ) {
					$active_plugins[] = $plugin;
					$this->log( 'Added required plugin to active list: ' . $plugin );
				} else {
					$this->log( 'Required plugin already in active list: ' . $plugin );
				}
			} else {
				$this->log( 'WARNING: Required plugin file not found: ' . $plugin_path );
			}
		}

		// Validate all plugins in the active list exist.
		$valid_plugins = array();
		foreach ( $active_plugins as $plugin ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin;
			if ( file_exists( $plugin_path ) ) {
				$valid_plugins[] = $plugin;
			} else {
				$this->log( 'Removed non-existent plugin from active list: ' . $plugin );
			}
		}

		// Remove duplicates and re-index.
		$valid_plugins = array_values( array_unique( $valid_plugins ) );

		// Serialize for database storage.
		$serialized = serialize( $valid_plugins );

		// Update directly in database to avoid any caching issues.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $serialized ),
			array( 'option_name' => 'active_plugins' ),
			array( '%s' ),
			array( '%s' )
		);

		if ( $updated === false ) {
			$this->log( 'ERROR: Failed to update active_plugins in database: ' . $wpdb->last_error );
		} else {
			$this->log( 'Updated active_plugins directly in database with ' . count( $valid_plugins ) . ' valid plugins' );
		}

		// Clear caches again after update.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'active_plugins', 'options' );

		// If manifest has active_plugins, log for debugging.
		if ( ! empty( $manifest['active_plugins'] ) ) {
			$this->log( 'Manifest had ' . count( $manifest['active_plugins'] ) . ' active plugins listed' );
		}

		$this->log( 'Final active plugins: ' . implode( ', ', $valid_plugins ) );
		$this->log( '--- reactivate_plugins() completed ---' );
	}

	/**
	 * Flush caches and rewrite rules.
	 *
	 * Uses batch deletion for transients to avoid memory issues on large sites.
	 *
	 * @return void
	 */
	private function flush_caches(): void {
		$this->log( '--- flush_caches() ---' );

		// Flush rewrite rules.
		flush_rewrite_rules();
		$this->log( 'Flushed rewrite rules' );

		// Clear object cache.
		wp_cache_flush();
		$this->log( 'Flushed object cache' );

		// Clear transients in batches to avoid memory issues on large sites.
		global $wpdb;
		$batch_size = 1000;
		$total_deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
					'_transient_%',
					$batch_size
				)
			);

			if ( $deleted > 0 ) {
				$total_deleted += $deleted;
			}

			// Free memory between batches.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		} while ( $deleted > 0 );

		$this->log( "Cleared {$total_deleted} transients in batches" );

		// Trigger Action Scheduler table recreation if library exists.
		$this->recreate_action_scheduler_tables();

		$this->log( '--- flush_caches() completed ---' );
	}

	/**
	 * Recreate Action Scheduler tables if they don't exist.
	 *
	 * Action Scheduler is used by WooCommerce, WP Mail SMTP, and other plugins.
	 * After import, these tables may be missing and need to be recreated.
	 *
	 * @return void
	 */
	private function recreate_action_scheduler_tables(): void {
		global $wpdb;

		$this->log( 'Checking Action Scheduler tables...' );

		// Check if Action Scheduler is available.
		if ( ! class_exists( 'ActionScheduler_HybridStore' ) && ! class_exists( 'ActionScheduler_DataController' ) ) {
			$this->log( 'Action Scheduler not loaded, skipping table recreation' );
			return;
		}

		// Tables that Action Scheduler creates.
		$as_tables = array(
			$wpdb->prefix . 'actionscheduler_actions',
			$wpdb->prefix . 'actionscheduler_claims',
			$wpdb->prefix . 'actionscheduler_groups',
			$wpdb->prefix . 'actionscheduler_logs',
		);

		$missing_tables = array();
		foreach ( $as_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				$missing_tables[] = $table;
			}
		}

		if ( empty( $missing_tables ) ) {
			$this->log( 'All Action Scheduler tables exist' );
			return;
		}

		$this->log( 'Missing Action Scheduler tables: ' . implode( ', ', $missing_tables ) );

		// Try to trigger table creation via Action Scheduler's own mechanism.
		if ( class_exists( 'ActionScheduler_DataController' ) ) {
			try {
				// Reset the initialization flag so tables get recreated.
				delete_option( 'action_scheduler_hybrid_store_demarkation' );
				delete_option( 'schema-ActionScheduler_StoreSchema' );
				delete_option( 'schema-ActionScheduler_LoggerSchema' );

				// Trigger re-initialization.
				if ( method_exists( 'ActionScheduler_DataController', 'init' ) ) {
					\ActionScheduler_DataController::init();
					$this->log( 'Triggered ActionScheduler_DataController::init()' );
				}
			} catch ( \Exception $e ) {
				$this->log( 'Error initializing Action Scheduler: ' . $e->getMessage() );
			}
		}

		// If tables still don't exist, create them manually.
		$this->create_action_scheduler_tables_manually();
	}

	/**
	 * Create Action Scheduler tables manually if they don't exist.
	 *
	 * @return void
	 */
	private function create_action_scheduler_tables_manually(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// wp_actionscheduler_actions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_actions' ) );
		if ( ! $exists ) {
			$sql = "CREATE TABLE {$wpdb->prefix}actionscheduler_actions (
				action_id bigint(20) unsigned NOT NULL auto_increment,
				hook varchar(191) NOT NULL,
				status varchar(20) NOT NULL,
				scheduled_date_gmt datetime NULL default '0000-00-00 00:00:00',
				scheduled_date_local datetime NULL default '0000-00-00 00:00:00',
				priority tinyint(10) unsigned NOT NULL default '10',
				args varchar(191) default NULL,
				schedule longtext default NULL,
				group_id bigint(20) unsigned NOT NULL default '0',
				attempts int(11) NOT NULL default '0',
				last_attempt_gmt datetime NULL default '0000-00-00 00:00:00',
				last_attempt_local datetime NULL default '0000-00-00 00:00:00',
				claim_id bigint(20) unsigned NOT NULL default '0',
				extended_args varchar(8000) default NULL,
				PRIMARY KEY (action_id),
				KEY hook (hook),
				KEY status (status),
				KEY scheduled_date_gmt (scheduled_date_gmt),
				KEY args (args),
				KEY group_id (group_id),
				KEY last_attempt_gmt (last_attempt_gmt),
				KEY claim_id_status_scheduled_date_gmt (claim_id, status, scheduled_date_gmt)
			) {$charset_collate}";
			dbDelta( $sql );
			$this->log( 'Created wp_actionscheduler_actions table' );
		}

		// wp_actionscheduler_claims.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_claims' ) );
		if ( ! $exists ) {
			$sql = "CREATE TABLE {$wpdb->prefix}actionscheduler_claims (
				claim_id bigint(20) unsigned NOT NULL auto_increment,
				date_created_gmt datetime NULL default '0000-00-00 00:00:00',
				PRIMARY KEY (claim_id),
				KEY date_created_gmt (date_created_gmt)
			) {$charset_collate}";
			dbDelta( $sql );
			$this->log( 'Created wp_actionscheduler_claims table' );
		}

		// wp_actionscheduler_groups.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_groups' ) );
		if ( ! $exists ) {
			$sql = "CREATE TABLE {$wpdb->prefix}actionscheduler_groups (
				group_id bigint(20) unsigned NOT NULL auto_increment,
				slug varchar(255) NOT NULL,
				PRIMARY KEY (group_id),
				KEY slug (slug(191))
			) {$charset_collate}";
			dbDelta( $sql );
			$this->log( 'Created wp_actionscheduler_groups table' );
		}

		// wp_actionscheduler_logs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_logs' ) );
		if ( ! $exists ) {
			$sql = "CREATE TABLE {$wpdb->prefix}actionscheduler_logs (
				log_id bigint(20) unsigned NOT NULL auto_increment,
				action_id bigint(20) unsigned NOT NULL,
				message text NOT NULL,
				log_date_gmt datetime NULL default '0000-00-00 00:00:00',
				log_date_local datetime NULL default '0000-00-00 00:00:00',
				PRIMARY KEY (log_id),
				KEY action_id (action_id),
				KEY log_date_gmt (log_date_gmt)
			) {$charset_collate}";
			dbDelta( $sql );
			$this->log( 'Created wp_actionscheduler_logs table' );
		}
	}

	/**
	 * Run search and replace on the database using the free plugin's SearchReplace class.
	 *
	 * @param array $replacements Array of search => replace pairs.
	 * @return bool Success status.
	 */
	private function run_search_replace( array $replacements ): bool {
		// Use the free plugin's SearchReplace class if available.
		if ( class_exists( '\\SwishMigrateAndBackup\\Migration\\SearchReplace' ) ) {
			$container = \SwishMigrateAndBackup\Core\Container::get_instance();
			$logger = $container->get( \SwishMigrateAndBackup\Logger\Logger::class );
			$search_replace = new \SwishMigrateAndBackup\Migration\SearchReplace( $logger );

			foreach ( $replacements as $search => $replace ) {
				// Generate URL-aware replacements (handles JSON escaped, URL encoded, etc.).
				$url_replacements = $search_replace->generate_url_replacements( $search, $replace );
				$search_replace->run_multiple( $url_replacements );
			}

			return true;
		}

		// Fallback: simple database search/replace (less reliable for serialized data).
		global $wpdb;

		foreach ( $replacements as $search => $replace ) {
			$tables = $wpdb->get_col( 'SHOW TABLES' );

			foreach ( $tables as $table ) {
				$this->simple_table_replace( $table, $search, $replace );
			}
		}

		return true;
	}

	/**
	 * Simple table replace fallback (for when SearchReplace class is unavailable).
	 *
	 * @param string $table   Table name.
	 * @param string $search  Search string.
	 * @param string $replace Replace string.
	 * @return void
	 */
	private function simple_table_replace( string $table, string $search, string $replace ): void {
		global $wpdb;

		// Get text columns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

		$text_types = array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext' );

		foreach ( $columns as $column ) {
			$type = strtolower( preg_replace( '/\(.*\)/', '', $column['Type'] ) );
			if ( ! in_array( $type, $text_types, true ) ) {
				continue;
			}

			$col_name = $column['Field'];

			// Update column with simple replace (doesn't handle serialized data well).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE `{$table}` SET `{$col_name}` = REPLACE(`{$col_name}`, %s, %s) WHERE `{$col_name}` LIKE %s",
					$search,
					$replace,
					'%' . $wpdb->esc_like( $search ) . '%'
				)
			);
		}
	}

	/**
	 * Fix site configuration to ensure the correct domain is set.
	 *
	 * This is critical after import to ensure wp_site and wp_blogs tables
	 * have the target domain, preventing redirect loops.
	 *
	 * @param string $target_url The target URL to set (e.g., https://swishfolio.ddev.site).
	 * @return void
	 */
	private function fix_site_configuration( string $target_url ): void {
		global $wpdb;

		$this->log( '--- fix_site_configuration() ---' );
		$this->log( "Target URL: {$target_url}" );

		$parsed_url = wp_parse_url( $target_url );
		$domain     = $parsed_url['host'] ?? '';
		$path       = isset( $parsed_url['path'] ) ? trailingslashit( $parsed_url['path'] ) : '/';

		// Include port in domain for non-standard ports (WordPress multisite requires this).
		if ( ! empty( $parsed_url['port'] ) && ! in_array( (int) $parsed_url['port'], array( 80, 443 ), true ) ) {
			$domain .= ':' . $parsed_url['port'];
		}

		if ( empty( $domain ) ) {
			$this->log( 'ERROR: Could not parse domain from target URL' );
			return;
		}

		$this->log( "Parsed domain: {$domain}, path: {$path}" );

		// Get current values before update.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current_site = $wpdb->get_row( "SELECT * FROM {$wpdb->base_prefix}site WHERE id = 1", ARRAY_A );
		$this->log( 'Current wp_site: ' . wp_json_encode( $current_site ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current_blogs = $wpdb->get_results( "SELECT blog_id, domain, path FROM {$wpdb->base_prefix}blogs", ARRAY_A );
		$this->log( 'Current wp_blogs: ' . wp_json_encode( $current_blogs ) );

		// Update wp_site table - FORCE set the domain.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_result = $wpdb->update(
			$wpdb->base_prefix . 'site',
			array(
				'domain' => $domain,
				'path'   => $path,
			),
			array( 'id' => 1 ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$this->log( "wp_site force update result: " . ( $site_result !== false ? 'success' : 'failed' ) );

		// Update wp_blogs table for main site (blog_id = 1).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blogs_result = $wpdb->update(
			$wpdb->base_prefix . 'blogs',
			array(
				'domain' => $domain,
				'path'   => $path,
			),
			array( 'blog_id' => 1 ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$this->log( "wp_blogs (blog_id=1) force update result: " . ( $blogs_result !== false ? 'success' : 'failed' ) );

		// Check if this is a subdomain or subdirectory multisite install.
		$is_subdomain_install = defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL;
		$this->log( "Multisite type: " . ( $is_subdomain_install ? 'subdomain' : 'subdirectory' ) );

		if ( $is_subdomain_install ) {
			// For subdomain multisite, update subsites to use subdomain.newdomain format.
			// Get all blogs except main site.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$other_blogs = $wpdb->get_results(
				"SELECT blog_id, domain, path FROM {$wpdb->base_prefix}blogs WHERE blog_id > 1",
				ARRAY_A
			);

			$this->log( "Found " . count( $other_blogs ) . " subsites to update for subdomain install" );

			// Get the main site's old domain from the imported data to determine subdomain prefixes.
			$main_blog = $current_blogs[0] ?? null;
			$old_main_domain = $main_blog ? $main_blog['domain'] : '';
			$this->log( "Old main domain (for subdomain extraction): {$old_main_domain}" );

			foreach ( $other_blogs as $blog ) {
				$old_blog_domain = $blog['domain'];
				$subdomain_prefix = '';

				// Extract subdomain prefix from old domain.
				// E.g., site2.oldsite.com → site2
				if ( ! empty( $old_main_domain ) && strpos( $old_blog_domain, '.' . $old_main_domain ) !== false ) {
					// Standard case: subdomain.maindomain.com
					$subdomain_prefix = str_replace( '.' . $old_main_domain, '', $old_blog_domain );
				} elseif ( preg_match( '/^([^.]+)\./', $old_blog_domain, $matches ) ) {
					// Fallback: extract first part as subdomain.
					$subdomain_prefix = $matches[1];
				}

				if ( ! empty( $subdomain_prefix ) ) {
					// Build new domain: subdomain.newdomain.
					// Remove port from domain for subdomain prefix, then add port back if needed.
					$domain_without_port = preg_replace( '/:\d+$/', '', $domain );
					$port_suffix = ( $domain !== $domain_without_port ) ? substr( $domain, strlen( $domain_without_port ) ) : '';
					$new_blog_domain = $subdomain_prefix . '.' . $domain_without_port . $port_suffix;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->update(
						$wpdb->base_prefix . 'blogs',
						array( 'domain' => $new_blog_domain ),
						array( 'blog_id' => $blog['blog_id'] ),
						array( '%s' ),
						array( '%d' )
					);
					$this->log( "Blog {$blog['blog_id']}: {$old_blog_domain} → {$new_blog_domain} (result: " . ( $result !== false ? 'success' : 'failed' ) . ")" );

					// Also update siteurl and home in the site's options table.
					$scheme = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] : 'https';
					$new_site_url = $scheme . '://' . $new_blog_domain . $blog['path'];
					$new_site_url = untrailingslashit( $new_site_url );

					$blog_prefix = $wpdb->base_prefix . $blog['blog_id'] . '_';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$blog_prefix . 'options',
						array( 'option_value' => $new_site_url ),
						array( 'option_name' => 'siteurl' ),
						array( '%s' ),
						array( '%s' )
					);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$blog_prefix . 'options',
						array( 'option_value' => $new_site_url ),
						array( 'option_name' => 'home' ),
						array( '%s' ),
						array( '%s' )
					);
					$this->log( "Blog {$blog['blog_id']} siteurl/home updated to: {$new_site_url}" );

					// Clear cache for this site.
					wp_cache_delete( $blog['blog_id'], 'sites' );
				} else {
					$this->log( "Blog {$blog['blog_id']}: Could not extract subdomain from {$old_blog_domain}, skipping" );
				}
			}
		} else {
			// For subdirectory multisite, update all blogs to use the same domain.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_blogs_result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->base_prefix}blogs SET domain = %s WHERE domain != %s",
					$domain,
					$domain
				)
			);
			$this->log( "wp_blogs (all others) domain update: {$all_blogs_result} rows affected" );
		}

		// Verify the updates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_site = $wpdb->get_row( "SELECT * FROM {$wpdb->base_prefix}site WHERE id = 1", ARRAY_A );
		$this->log( 'New wp_site: ' . wp_json_encode( $new_site ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_blogs = $wpdb->get_results( "SELECT blog_id, domain, path FROM {$wpdb->base_prefix}blogs", ARRAY_A );
		$this->log( 'New wp_blogs: ' . wp_json_encode( $new_blogs ) );

		// Also update wp_options siteurl and home for main site.
		update_option( 'siteurl', $target_url );
		update_option( 'home', $target_url );
		$this->log( "wp_options siteurl and home set to: {$target_url}" );

		// Clear site caches.
		wp_cache_delete( 1, 'networks' );
		wp_cache_delete( 1, 'sites' );
		wp_cache_delete( 'current_network', 'site-options' );

		$this->log( '--- fix_site_configuration() completed ---' );
	}

	/**
	 * Extract backup to temp directory.
	 *
	 * Uses safe extraction with Zip Slip protection.
	 *
	 * @param string $file_path Path to backup file.
	 * @return string|null Temp directory path or null on failure.
	 */
	private function extract_backup( string $file_path ): ?string {
		$temp_dir = sys_get_temp_dir() . '/swish-import-' . wp_generate_uuid4();
		wp_mkdir_p( $temp_dir );

		if ( $this->is_zip_file( $file_path ) ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return null;
			}

			$zip = new \ZipArchive();

			if ( $zip->open( $file_path ) !== true ) {
				return null;
			}

			// Use safe extraction to prevent Zip Slip attacks.
			$extracted = $this->safe_extract_zip( $zip, $temp_dir );
			$zip->close();
		} else {
			// .swish archive: SwishExtractor::extract_all() rejects unsafe entry paths itself.
			$extractor = new \SwishMigrateAndBackup\Archive\SwishExtractor( $file_path );
			$extracted = false;

			if ( $extractor->open() ) {
				// This runs in a background job: disable time slicing with a very long timeout.
				$result = $extractor->extract_all( $temp_dir, 0, 0, DAY_IN_SECONDS );
				$extractor->close();
				$extracted = ! empty( $result['completed'] );
			}
		}

		if ( ! $extracted ) {
			$this->cleanup_temp_directory( $temp_dir );
			return null;
		}

		return $temp_dir;
	}

	/**
	 * Safely extract a ZIP archive with path traversal validation.
	 *
	 * Prevents Zip Slip attacks by ensuring all extracted files stay within the destination directory.
	 * Uses stream-based extraction to handle large files without memory exhaustion.
	 *
	 * @param \ZipArchive $zip         The ZipArchive object.
	 * @param string      $destination The destination directory.
	 * @return bool True if successful.
	 */
	private function safe_extract_zip( \ZipArchive $zip, string $destination ): bool {
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

			// Reject absolute paths and traversal segments before any filesystem
			// operation, so directories are never created outside the destination.
			$normalized_entry = str_replace( '\\', '/', $entry_name );
			if ( str_starts_with( $normalized_entry, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $normalized_entry ) ) {
				$this->log( 'Skipping unsafe ZIP entry (path traversal attempt): ' . $entry_name );
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
				$this->log( 'Skipping unsafe ZIP entry (path traversal attempt): ' . $entry_name );
				continue;
			}

			// Extract the file using streams to avoid memory exhaustion on large files.
			if ( ! $this->extract_file_stream( $zip, $entry_name, $target_path ) ) {
				$this->log( 'Failed to extract file: ' . $entry_name );
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
	 * @param \ZipArchive $zip         The ZipArchive object.
	 * @param string      $entry_name  The name of the entry in the ZIP.
	 * @param string      $target_path The target path to write to.
	 * @return bool True if successful.
	 */
	private function extract_file_stream( \ZipArchive $zip, string $entry_name, string $target_path ): bool {
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
	 * Import shared tables.
	 *
	 * IMPORTANT: We skip importing wp_site and wp_blogs tables to preserve
	 * the current site's domain configuration. These tables contain the domain
	 * that WordPress uses to determine the site URL, and importing them with
	 * the backup's domain would cause redirect loops.
	 *
	 * @param string $temp_dir Temp directory path.
	 * @param array  $options  Import options.
	 * @return bool Success status.
	 */
	private function import_shared_tables( string $temp_dir, array $options ): bool {
		global $wpdb;

		$sql_file = $temp_dir . '/network-shared.sql';

		if ( ! file_exists( $sql_file ) ) {
			$this->log( 'import_shared_tables: network-shared.sql not found' );
			return false;
		}

		$this->log( 'import_shared_tables: Starting import of shared tables' );

		// For multisite-to-multisite imports, we MUST import wp_blogs and wp_site
		// to restore all sites in the network. The domains will be fixed by
		// fix_site_configuration() after import.
		// For other scenarios (e.g., single site), skip these to preserve config.
		$is_multisite_to_multisite = is_multisite();

		if ( $is_multisite_to_multisite ) {
			// Import all shared tables including wp_blogs, wp_site for full network restore.
			$skip_tables = array();
			$this->log( 'import_shared_tables: Multisite-to-multisite import - importing ALL shared tables' );
		} else {
			// Skip tables that contain domain/path info to preserve current site configuration.
			$skip_tables = array(
				$wpdb->base_prefix . 'site',
				$wpdb->base_prefix . 'blogs',
				$wpdb->base_prefix . 'blogmeta',
			);
			$this->log( 'import_shared_tables: Will skip tables: ' . implode( ', ', $skip_tables ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sql_content = file_get_contents( $sql_file );

		if ( empty( $sql_content ) ) {
			$this->log( 'import_shared_tables: SQL file is empty' );
			return false;
		}

		$this->log( 'import_shared_tables: SQL file size: ' . strlen( $sql_content ) . ' bytes' );

		// Disable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"' );

		// Split SQL into individual statements.
		$statements = $this->split_sql_statements( $sql_content );

		$executed  = 0;
		$skipped   = 0;
		$errors    = 0;

		$this->log( 'import_shared_tables: Found ' . count( $statements ) . ' SQL statements' );

		foreach ( $statements as $statement ) {
			$statement = trim( $statement );
			if ( empty( $statement ) || strpos( $statement, '--' ) === 0 ) {
				continue;
			}

			// Check if this statement affects a table we should skip.
			$should_skip = false;
			foreach ( $skip_tables as $table ) {
				// Check for DROP TABLE, CREATE TABLE, INSERT INTO, TRUNCATE.
				if ( stripos( $statement, $table ) !== false &&
					 ( stripos( $statement, 'DROP TABLE' ) !== false ||
					   stripos( $statement, 'CREATE TABLE' ) !== false ||
					   stripos( $statement, 'INSERT INTO' ) !== false ||
					   stripos( $statement, 'TRUNCATE' ) !== false ) ) {
					$should_skip = true;
					$this->log( "import_shared_tables: SKIPPING statement for table {$table}" );
					break;
				}
			}

			if ( ! $should_skip ) {
				// Suppress errors temporarily.
				$suppress = $wpdb->suppress_errors( true );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->query( $statement );

				$wpdb->suppress_errors( $suppress );

				if ( false === $result ) {
					$errors++;
					$this->log( 'import_shared_tables: SQL error: ' . $wpdb->last_error );
				} else {
					$executed++;
				}
			} else {
				$skipped++;
			}
		}

		// Re-enable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		$this->log( "import_shared_tables: Completed. Executed: {$executed}, Skipped: {$skipped}, Errors: {$errors}" );

		return $errors < ( $executed * 0.1 ) || $errors < 5;
	}

	/**
	 * Import a single site.
	 *
	 * @param string $temp_dir  Temp directory path.
	 * @param array  $site_data Site data from manifest.
	 * @param array  $options   Import options.
	 * @return array Import result.
	 */
	private function import_site( string $temp_dir, array $site_data, array $options ): array {
		global $wpdb;

		$site_id  = (int) $site_data['site_id'];
		$sql_file = $temp_dir . '/site-' . $site_id . '-database.sql';

		$this->log( "import_site: Starting import for site {$site_id}" );
		$this->log( "import_site: Looking for SQL file: {$sql_file}" );

		// List available files in temp directory for debugging.
		$available_files = glob( $temp_dir . '/site-*-database.sql' );
		$this->log( "import_site: Available site database files: " . implode( ', ', array_map( 'basename', $available_files ) ) );

		if ( ! file_exists( $sql_file ) ) {
			$this->log( "import_site: SQL file not found: {$sql_file}" );
			return array(
				'site_id' => $site_id,
				'success' => false,
				'message' => __( 'Site database file not found.', 'swish-migrate-and-backup' ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sql_content = file_get_contents( $sql_file );

		if ( empty( $sql_content ) ) {
			$this->log( "import_site: SQL file is empty: {$sql_file}" );
			return array(
				'site_id' => $site_id,
				'success' => false,
				'message' => __( 'Site database file is empty.', 'swish-migrate-and-backup' ),
			);
		}

		$this->log( "import_site: SQL file size: " . strlen( $sql_content ) . " bytes" );

		// Handle table prefix conversion if source prefix differs from target.
		// The manifest's table_prefix is already the complete prefix for this site
		// (e.g., "wp_2_" for site 2, not "wp_" that needs site_id appended).
		$source_prefix = $site_data['table_prefix'] ?? $wpdb->base_prefix;
		$target_prefix = $wpdb->base_prefix;

		// For multisite sites (not the main site), tables are prefixed with site ID.
		if ( $site_id > 1 ) {
			// Source prefix from manifest is already complete (e.g., "wp_2_").
			$source_site_prefix = $source_prefix;
			$target_site_prefix = $target_prefix . $site_id . '_';

			if ( $source_site_prefix !== $target_site_prefix ) {
				$this->log( "import_site: Converting prefix from {$source_site_prefix} to {$target_site_prefix}" );
				$sql_content = str_replace( $source_site_prefix, $target_site_prefix, $sql_content );
			}
		} elseif ( $source_prefix !== $target_prefix ) {
			// Main site (site_id = 1) uses base prefix without site ID.
			$this->log( "import_site: Converting base prefix from {$source_prefix} to {$target_prefix}" );
			$sql_content = str_replace( $source_prefix, $target_prefix, $sql_content );
		}

		// Disable foreign key checks and set safe SQL mode.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"' );

		// Split SQL into individual statements.
		$statements = $this->split_sql_statements( $sql_content );
		$executed   = 0;
		$errors     = 0;
		$error_messages = array();

		$this->log( "import_site: Found " . count( $statements ) . " SQL statements to execute" );

		foreach ( $statements as $index => $statement ) {
			$statement = trim( $statement );

			// Skip empty statements and comments.
			if ( empty( $statement ) || strpos( $statement, '--' ) === 0 ) {
				continue;
			}

			// Suppress errors temporarily to prevent PHP warnings from breaking the import.
			$suppress = $wpdb->suppress_errors( true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $statement );

			$wpdb->suppress_errors( $suppress );

			if ( false === $result ) {
				$errors++;
				$error_msg = $wpdb->last_error;
				$error_messages[] = $error_msg;

				// Log first 300 chars of statement for debugging.
				$stmt_preview = substr( $statement, 0, 300 );
				$this->log( "import_site: SQL error #{$errors}: {$error_msg}" );
				$this->log( "import_site: Failed statement preview: {$stmt_preview}" );

				// If we have too many errors, something is seriously wrong.
				if ( $errors > 50 ) {
					$this->log( "import_site: Too many errors ({$errors}), aborting site {$site_id} import" );
					break;
				}
			} else {
				$executed++;
			}
		}

		// Re-enable foreign key checks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		$this->log( "import_site: Completed site {$site_id}. Executed: {$executed}, Errors: {$errors}" );

		// Consider success if most statements executed (some errors might be expected for duplicate keys, etc.).
		$success = $executed > 0 && ( $errors < ( $executed * 0.1 ) || $errors < 5 );

		return array(
			'site_id'        => $site_id,
			'site_url'       => $site_data['site_url'] ?? '',
			'site_name'      => $site_data['site_name'] ?? '',
			'success'        => $success,
			'message'        => $success
				? sprintf( __( 'Site imported: %d statements executed.', 'swish-migrate-and-backup' ), $executed )
				: sprintf( __( 'Site import had issues: %d executed, %d errors.', 'swish-migrate-and-backup' ), $executed, $errors ),
			'executed'       => $executed,
			'errors'         => $errors,
			'error_messages' => array_slice( $error_messages, 0, 5 ), // First 5 errors.
		);
	}

	/**
	 * Split SQL content into individual statements.
	 *
	 * @param string $sql_content SQL content.
	 * @return array Array of SQL statements.
	 */
	private function split_sql_statements( string $sql_content ): array {
		// Remove single-line comments (-- style).
		$sql_content = preg_replace( '/^--.*$/m', '', $sql_content );
		// Remove # style comments.
		$sql_content = preg_replace( '/^#.*$/m', '', $sql_content );

		// Split by semicolons, but be careful with values containing semicolons.
		$statements = array();
		$current_statement = '';
		$in_string = false;
		$string_char = '';
		$length = strlen( $sql_content );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql_content[ $i ];

			if ( $in_string ) {
				$current_statement .= $char;

				// Check for end of string, handling escaped quotes.
				if ( $char === $string_char ) {
					// Check if this quote is escaped.
					$num_backslashes = 0;
					$j = $i - 1;
					while ( $j >= 0 && $sql_content[ $j ] === '\\' ) {
						$num_backslashes++;
						$j--;
					}
					// If even number of backslashes, the quote ends the string.
					if ( $num_backslashes % 2 === 0 ) {
						$in_string = false;
					}
				}
			} else {
				if ( $char === "'" || $char === '"' ) {
					$in_string = true;
					$string_char = $char;
					$current_statement .= $char;
				} elseif ( $char === '`' ) {
					// Handle backtick-quoted identifiers.
					$current_statement .= $char;
					$i++;
					while ( $i < $length && $sql_content[ $i ] !== '`' ) {
						$current_statement .= $sql_content[ $i ];
						$i++;
					}
					if ( $i < $length ) {
						$current_statement .= '`';
					}
				} elseif ( $char === ';' ) {
					$trimmed = trim( $current_statement );
					if ( ! empty( $trimmed ) ) {
						$statements[] = $trimmed;
					}
					$current_statement = '';
				} else {
					$current_statement .= $char;
				}
			}
		}

		// Add any remaining statement.
		$trimmed = trim( $current_statement );
		if ( ! empty( $trimmed ) ) {
			$statements[] = $trimmed;
		}

		return $statements;
	}

	/**
	 * Restore files from a tar.gz archive.
	 *
	 * Uses system tar command for efficient extraction, with PHP fallback.
	 *
	 * @param string $archive_path Path to tar.gz archive.
	 * @return bool True if successful.
	 */
	private function restore_files_tar( string $archive_path ): bool {
		$this->log( 'Starting tar.gz file restore: ' . basename( $archive_path ) );

		try {
			// Check if tar is available.
			if ( ! $this->is_tar_available() ) {
				$this->log( 'System tar not available, using PHP fallback' );
				return $this->restore_files_tar_php( $archive_path );
			}

			$destination = ABSPATH;

			// Use system tar for extraction (most efficient).
			$command = sprintf(
				'tar -xzf %s -C %s 2>&1',
				escapeshellarg( $archive_path ),
				escapeshellarg( $destination )
			);

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( $command, $output, $return_code );

			if ( 0 !== $return_code ) {
				$this->log( 'System tar failed, falling back to PHP: ' . implode( "\n", $output ) );
				return $this->restore_files_tar_php( $archive_path );
			}

			$this->log( 'Tar.gz file restore completed (system tar)' );
			return true;

		} catch ( \Exception $e ) {
			$this->log( 'Tar.gz file restore failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Restore files from tar.gz using PHP (fallback when system tar unavailable).
	 *
	 * Uses PharData for extraction.
	 *
	 * @param string $archive_path Path to tar.gz archive.
	 * @return bool True if successful.
	 */
	private function restore_files_tar_php( string $archive_path ): bool {
		try {
			$destination = ABSPATH;

			// Use PharData to extract.
			$phar = new \PharData( $archive_path );

			// Extract - PharData handles gzip decompression automatically.
			$phar->extractTo( $destination, null, true ); // true = overwrite.

			$this->log( 'Tar.gz file restore completed (PHP fallback)' );

			return true;
		} catch ( \Exception $e ) {
			$this->log( 'PHP tar extraction failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if system tar command is available.
	 *
	 * @return bool True if tar is available.
	 */
	private function is_tar_available(): bool {
		// Check if exec is available.
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		// Check if exec is disabled.
		$disabled_functions = explode( ',', ini_get( 'disable_functions' ) );
		$disabled_functions = array_map( 'trim', $disabled_functions );
		if ( in_array( 'exec', $disabled_functions, true ) ) {
			return false;
		}

		// Check if tar exists.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'which tar 2>/dev/null', $output, $return_code );

		return 0 === $return_code && ! empty( $output );
	}

	/**
	 * Clean up temp directory.
	 *
	 * @param string $temp_dir Temp directory path.
	 * @return void
	 */
	private function cleanup_temp_directory( string $temp_dir ): void {
		if ( ! file_exists( $temp_dir ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $temp_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getRealPath() );
			} else {
				unlink( $file->getRealPath() );
			}
		}

		rmdir( $temp_dir );
	}

	/**
	 * Convert single site to multisite.
	 *
	 * @return array Conversion result.
	 */
	public function convert_to_multisite(): array {
		// Check if already multisite.
		if ( is_multisite() ) {
			return array(
				'success' => true,
				'message' => __( 'Already a multisite installation.', 'swish-migrate-and-backup' ),
			);
		}

		// Check requirements.
		if ( ! is_writable( ABSPATH . 'wp-config.php' ) ) {
			return array(
				'success' => false,
				'message' => __( 'wp-config.php is not writable. Please make it writable or manually enable multisite.', 'swish-migrate-and-backup' ),
			);
		}

		try {
			// Step 1: Add multisite constants to wp-config.php.
			$wp_config_path = ABSPATH . 'wp-config.php';
			$wp_config      = file_get_contents( $wp_config_path );

			// Check if multisite constants already exist.
			if ( strpos( $wp_config, 'MULTISITE' ) !== false ) {
				return array(
					'success' => false,
					'message' => __( 'Multisite constants already exist in wp-config.php but multisite is not active. Please check your configuration.', 'swish-migrate-and-backup' ),
				);
			}

			// Prepare multisite constants.
			$site_url   = get_site_url();
			$parsed_url = wp_parse_url( $site_url );
			$domain     = $parsed_url['host'];
			$path       = isset( $parsed_url['path'] ) ? trailingslashit( $parsed_url['path'] ) : '/';

			$multisite_constants = "\n/* Multisite - Added by Swish Backup Pro */\n";
			$multisite_constants .= "define( 'WP_ALLOW_MULTISITE', true );\n";
			$multisite_constants .= "define( 'MULTISITE', true );\n";
			$multisite_constants .= "define( 'SUBDOMAIN_INSTALL', false );\n";
			$multisite_constants .= "define( 'DOMAIN_CURRENT_SITE', '{$domain}' );\n";
			$multisite_constants .= "define( 'PATH_CURRENT_SITE', '{$path}' );\n";
			$multisite_constants .= "define( 'SITE_ID_CURRENT_SITE', 1 );\n";
			$multisite_constants .= "define( 'BLOG_ID_CURRENT_SITE', 1 );\n";
			$multisite_constants .= "/* End Multisite */\n";

			// Find the right place to insert (before "That's all, stop editing!").
			$insert_marker = "/* That's all, stop editing!";
			$alt_marker    = "require_once ABSPATH";

			if ( strpos( $wp_config, $insert_marker ) !== false ) {
				$wp_config = str_replace( $insert_marker, $multisite_constants . $insert_marker, $wp_config );
			} elseif ( strpos( $wp_config, $alt_marker ) !== false ) {
				$wp_config = str_replace( $alt_marker, $multisite_constants . $alt_marker, $wp_config );
			} else {
				// Append to the end.
				$wp_config .= $multisite_constants;
			}

			// Step 2: Create multisite database tables.
			$this->create_multisite_tables();

			// Step 3: Write updated wp-config.php.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $wp_config_path, $wp_config );

			// Step 4: Update .htaccess for multisite.
			$this->update_htaccess_for_multisite();

			return array(
				'success'          => true,
				'message'          => __( 'Successfully converted to multisite. Please refresh the page.', 'swish-migrate-and-backup' ),
				'requires_refresh' => true,
			);

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to convert to multisite: %s', 'swish-migrate-and-backup' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Create multisite database tables.
	 *
	 * @return void
	 */
	private function create_multisite_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Create wp_blogs table.
		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}blogs (
			blog_id bigint(20) NOT NULL auto_increment,
			site_id bigint(20) NOT NULL default '0',
			domain varchar(200) NOT NULL default '',
			path varchar(100) NOT NULL default '',
			registered datetime NOT NULL default '0000-00-00 00:00:00',
			last_updated datetime NOT NULL default '0000-00-00 00:00:00',
			public tinyint(2) NOT NULL default '1',
			archived tinyint(2) NOT NULL default '0',
			mature tinyint(2) NOT NULL default '0',
			spam tinyint(2) NOT NULL default '0',
			deleted tinyint(2) NOT NULL default '0',
			lang_id int(11) NOT NULL default '0',
			PRIMARY KEY  (blog_id),
			KEY domain (domain(50),path(5)),
			KEY lang_id (lang_id)
		) {$charset_collate};";
		dbDelta( $sql );

		// Create wp_site table.
		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}site (
			id bigint(20) NOT NULL auto_increment,
			domain varchar(200) NOT NULL default '',
			path varchar(100) NOT NULL default '',
			PRIMARY KEY  (id),
			KEY domain (domain(140),path(51))
		) {$charset_collate};";
		dbDelta( $sql );

		// Create wp_sitemeta table.
		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}sitemeta (
			meta_id bigint(20) NOT NULL auto_increment,
			site_id bigint(20) NOT NULL default '0',
			meta_key varchar(255) default NULL,
			meta_value longtext,
			PRIMARY KEY  (meta_id),
			KEY meta_key (meta_key(191)),
			KEY site_id (site_id)
		) {$charset_collate};";
		dbDelta( $sql );

		// Create wp_blogmeta table (WordPress 5.1+).
		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}blogmeta (
			meta_id bigint(20) unsigned NOT NULL auto_increment,
			blog_id bigint(20) NOT NULL default '0',
			meta_key varchar(255) default NULL,
			meta_value longtext,
			PRIMARY KEY  (meta_id),
			KEY meta_key (meta_key(191)),
			KEY blog_id (blog_id)
		) {$charset_collate};";
		dbDelta( $sql );

		// Insert initial site record.
		$site_url   = get_site_url();
		$parsed_url = wp_parse_url( $site_url );
		$domain     = $parsed_url['host'];
		$path       = isset( $parsed_url['path'] ) ? trailingslashit( $parsed_url['path'] ) : '/';

		// Check if site record exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_exists = $wpdb->get_var( "SELECT id FROM {$wpdb->base_prefix}site WHERE id = 1" );

		if ( ! $site_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->base_prefix . 'site',
				array(
					'id'     => 1,
					'domain' => $domain,
					'path'   => $path,
				),
				array( '%d', '%s', '%s' )
			);
		}

		// Check if blog record exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blog_exists = $wpdb->get_var( "SELECT blog_id FROM {$wpdb->base_prefix}blogs WHERE blog_id = 1" );

		if ( ! $blog_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->base_prefix . 'blogs',
				array(
					'blog_id'      => 1,
					'site_id'      => 1,
					'domain'       => $domain,
					'path'         => $path,
					'registered'   => current_time( 'mysql', true ),
					'last_updated' => current_time( 'mysql', true ),
					'public'       => 1,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		// Add site meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sitemeta_exists = $wpdb->get_var( "SELECT meta_id FROM {$wpdb->base_prefix}sitemeta WHERE site_id = 1 AND meta_key = 'site_name'" );

		if ( ! $sitemeta_exists ) {
			$site_name = get_bloginfo( 'name' );
			$admin_email = get_option( 'admin_email' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->base_prefix . 'sitemeta',
				array(
					'site_id'    => 1,
					'meta_key'   => 'site_name',
					'meta_value' => $site_name,
				),
				array( '%d', '%s', '%s' )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->base_prefix . 'sitemeta',
				array(
					'site_id'    => 1,
					'meta_key'   => 'admin_email',
					'meta_value' => $admin_email,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Update .htaccess for multisite.
	 *
	 * @return bool Success status.
	 */
	private function update_htaccess_for_multisite(): bool {
		$htaccess_path = ABSPATH . '.htaccess';

		if ( ! is_writable( $htaccess_path ) ) {
			return false;
		}

		$site_url   = get_site_url();
		$parsed_url = wp_parse_url( $site_url );
		$path       = isset( $parsed_url['path'] ) ? trailingslashit( $parsed_url['path'] ) : '/';

		$htaccess_content = file_get_contents( $htaccess_path );

		// Check if multisite rules already exist.
		if ( strpos( $htaccess_content, 'uploaded-file' ) !== false ) {
			return true;
		}

		$multisite_rules = "# BEGIN WordPress Multisite\n";
		$multisite_rules .= "RewriteEngine On\n";
		$multisite_rules .= "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
		$multisite_rules .= "RewriteBase {$path}\n";
		$multisite_rules .= "RewriteRule ^index\\.php$ - [L]\n\n";
		$multisite_rules .= "# add a trailing slash to /wp-admin\n";
		$multisite_rules .= "RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ \$1wp-admin/ [R=301,L]\n\n";
		$multisite_rules .= "RewriteCond %{REQUEST_FILENAME} -f [OR]\n";
		$multisite_rules .= "RewriteCond %{REQUEST_FILENAME} -d\n";
		$multisite_rules .= "RewriteRule ^ - [L]\n";
		$multisite_rules .= "RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) \$2 [L]\n";
		$multisite_rules .= "RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\\.php)$ \$2 [L]\n";
		$multisite_rules .= "RewriteRule . index.php [L]\n";
		$multisite_rules .= "# END WordPress Multisite\n\n";

		// Replace existing WordPress rules or prepend.
		if ( strpos( $htaccess_content, '# BEGIN WordPress' ) !== false ) {
			$htaccess_content = preg_replace(
				'/# BEGIN WordPress.*# END WordPress/s',
				$multisite_rules,
				$htaccess_content
			);
		} else {
			$htaccess_content = $multisite_rules . $htaccess_content;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess_path, $htaccess_content );

		return true;
	}

	/**
	 * Get list of available backups for import.
	 *
	 * @return array List of available backups.
	 */
	public function get_available_backups(): array {
		$backups = array();

		if ( ! file_exists( $this->backup_dir ) ) {
			return $backups;
		}

		$swish_files = glob( $this->backup_dir . '/*.swish' );
		$zip_files   = glob( $this->backup_dir . '/*.zip' );
		$files       = array_merge( $swish_files ? $swish_files : array(), $zip_files ? $zip_files : array() );

		foreach ( $files as $file ) {
			$manifest = $this->read_manifest_from_archive( $file );

			if ( $manifest ) {
				$backup_type = $manifest['backup_type'] ?? 'full';
				$is_multisite = ( $backup_type === 'multisite' );

				$backups[] = array(
					'filename'     => basename( $file ),
					'path'         => $file,
					'size'         => filesize( $file ),
					'created_at'   => $manifest['created_at'] ?? null,
					'backup_type'  => $backup_type,
					'is_multisite' => $is_multisite,
					'archive_mode' => $manifest['archive_mode'] ?? ( $is_multisite ? 'unknown' : 'single' ),
					'site_count'   => $is_multisite ? count( $manifest['sites'] ?? array() ) : 1,
					'site_url'     => $manifest['site_url'] ?? ( $manifest['sites'][0]['site_url'] ?? '' ),
					'manifest'     => $manifest,
				);
			}
		}

		// Sort by creation date (newest first).
		usort(
			$backups,
			function ( $a, $b ) {
				return strtotime( $b['created_at'] ?? '0' ) - strtotime( $a['created_at'] ?? '0' );
			}
		);

		return $backups;
	}

	/**
	 * Get instructions for manually setting up WordPress multisite.
	 *
	 * @return array Multisite setup instructions.
	 */
	private function get_multisite_setup_instructions(): array {
		$site_url   = get_site_url();
		$parsed_url = wp_parse_url( $site_url );
		$domain     = $parsed_url['host'] ?? 'example.com';
		$path       = isset( $parsed_url['path'] ) ? trailingslashit( $parsed_url['path'] ) : '/';

		return array(
			'title'       => __( 'WordPress Multisite Setup Required', 'swish-migrate-and-backup' ),
			'description' => __( 'This backup was created from a WordPress multisite network. To import it, you must first convert your WordPress installation to a multisite network.', 'swish-migrate-and-backup' ),
			'steps'       => array(
				array(
					'title'       => __( 'Step 1: Edit wp-config.php', 'swish-migrate-and-backup' ),
					'description' => __( 'Add this line above the "/* That\'s all, stop editing! */" comment:', 'swish-migrate-and-backup' ),
					'code'        => "define( 'WP_ALLOW_MULTISITE', true );",
				),
				array(
					'title'       => __( 'Step 2: Install Network', 'swish-migrate-and-backup' ),
					'description' => sprintf(
						/* translators: %s: URL to network setup page */
						__( 'Go to %s in your WordPress admin and follow the setup instructions.', 'swish-migrate-and-backup' ),
						'<strong>Tools > Network Setup</strong>'
					),
					'code'        => '',
				),
				array(
					'title'       => __( 'Step 3: Update wp-config.php', 'swish-migrate-and-backup' ),
					'description' => __( 'Add these lines to wp-config.php above the "/* That\'s all, stop editing! */" comment:', 'swish-migrate-and-backup' ),
					'code'        => "define( 'MULTISITE', true );\ndefine( 'SUBDOMAIN_INSTALL', false );\ndefine( 'DOMAIN_CURRENT_SITE', '{$domain}' );\ndefine( 'PATH_CURRENT_SITE', '{$path}' );\ndefine( 'SITE_ID_CURRENT_SITE', 1 );\ndefine( 'BLOG_ID_CURRENT_SITE', 1 );",
				),
				array(
					'title'       => __( 'Step 4: Update .htaccess', 'swish-migrate-and-backup' ),
					'description' => __( 'Replace your .htaccess WordPress rules with the multisite rules provided by WordPress during network setup.', 'swish-migrate-and-backup' ),
					'code'        => "RewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase {$path}\nRewriteRule ^index\\.php$ - [L]\n\n# add a trailing slash to /wp-admin\nRewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ \$1wp-admin/ [R=301,L]\n\nRewriteCond %{REQUEST_FILENAME} -f [OR]\nRewriteCond %{REQUEST_FILENAME} -d\nRewriteRule ^ - [L]\nRewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) \$2 [L]\nRewriteRule ^([_0-9a-zA-Z-]+/)?(.*\\.php)$ \$2 [L]\nRewriteRule . index.php [L]",
				),
				array(
					'title'       => __( 'Step 5: Log In Again', 'swish-migrate-and-backup' ),
					'description' => __( 'After making these changes, log out and log back in. Then return to this page to import your multisite backup.', 'swish-migrate-and-backup' ),
					'code'        => '',
				),
			),
			'docs_url'    => 'https://developer.wordpress.org/advanced-administration/multisite/create-network/',
		);
	}
}
