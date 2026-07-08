<?php
/**
 * Multisite Manager.
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
 * Orchestrates multisite backup operations.
 */
final class MultisiteManager {

	/**
	 * Multisite detector.
	 *
	 * @var MultisiteDetector
	 */
	private MultisiteDetector $detector;

	/**
	 * Network backup handler.
	 *
	 * @var NetworkBackup
	 */
	private NetworkBackup $network_backup;

	/**
	 * Constructor.
	 *
	 * @param MultisiteDetector $detector        Multisite detector.
	 * @param NetworkBackup     $network_backup  Network backup handler.
	 */
	public function __construct( MultisiteDetector $detector, NetworkBackup $network_backup ) {
		$this->detector       = $detector;
		$this->network_backup = $network_backup;
	}

	/**
	 * Backup entire network.
	 *
	 * @param array $options Backup options.
	 * @return array|null Backup result or null on failure.
	 */
	public function backup_network( array $options = array() ): ?array {
		if ( ! $this->detector->is_multisite() ) {
			return null;
		}

		// Get all sites in network.
		$sites = $this->detector->get_network_sites();
		$site_ids = array_column( $sites, 'blog_id' );

		// Backup all sites.
		return $this->backup_sites( $site_ids, $options );
	}

	/**
	 * Backup selected sites.
	 *
	 * @param array $site_ids Site IDs to backup.
	 * @param array $options  Backup options.
	 * @return array|null Backup result or null on failure.
	 */
	public function backup_sites( array $site_ids, array $options = array() ): ?array {
		if ( empty( $site_ids ) ) {
			return null;
		}

		// Get archive mode from options.
		$archive_mode = $this->get_archive_mode( $options );

		// Create multisite backup job record.
		$job_id = $this->create_multisite_job( $site_ids, $archive_mode );

		// Perform backup based on archive mode.
		if ( $archive_mode === 'separate' ) {
			return $this->backup_sites_separate( $job_id, $site_ids, $options );
		}

		return $this->backup_sites_single( $job_id, $site_ids, $options );
	}

	/**
	 * Backup sites as a single archive.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Site IDs.
	 * @param array  $options  Options.
	 * @return array|null Backup result.
	 */
	private function backup_sites_single( string $job_id, array $site_ids, array $options ): ?array {
		$result = $this->network_backup->backup_network_single_archive(
			$job_id,
			$site_ids,
			$options
		);

		if ( $result && isset( $result['status'] ) && $result['status'] === 'completed' ) {
			// Store backup files and update status.
			$backup_files = array(
				array(
					'filename' => $result['filename'],
					'path'     => $result['path'],
					'size'     => $result['size'],
				),
			);

			$this->update_multisite_job_completed( $job_id, $backup_files, count( $site_ids ) );
		}

		return $result;
	}

	/**
	 * Backup sites as separate archives.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Site IDs.
	 * @param array  $options  Options.
	 * @return array|null Backup result.
	 */
	private function backup_sites_separate( string $job_id, array $site_ids, array $options ): ?array {
		$result = $this->network_backup->backup_network_separate_archives(
			$job_id,
			$site_ids,
			$options
		);

		if ( $result && isset( $result['status'] ) && $result['status'] === 'completed' && isset( $result['archives'] ) ) {
			// Store backup files and update status.
			$backup_files = array();
			foreach ( $result['archives'] as $archive ) {
				$backup_files[] = array(
					'filename' => $archive['filename'],
					'path'     => $archive['archive_path'],
					'size'     => $archive['size'],
					'site_id'  => $archive['site_id'],
				);
			}

			$this->update_multisite_job_completed( $job_id, $backup_files, count( $site_ids ) );
		}

		return $result;
	}

	/**
	 * Get archive mode from options.
	 *
	 * @param array $options Backup options.
	 * @return string 'single' or 'separate'.
	 */
	public function get_archive_mode( array $options ): string {
		$mode = $options['archive_mode'] ?? 'single';

		if ( ! in_array( $mode, array( 'single', 'separate' ), true ) ) {
			return 'single';
		}

		return $mode;
	}

	/**
	 * Estimate backup size for sites.
	 *
	 * @param array $site_ids Site IDs.
	 * @return array Size estimation.
	 */
	public function estimate_backup_size( array $site_ids ): array {
		$total_db_size      = 0;
		$total_uploads_size = 0;
		$site_sizes         = array();

		foreach ( $site_ids as $site_id ) {
			$size = $this->detector->estimate_site_size( $site_id );

			$site_sizes[ $site_id ] = $size;
			$total_db_size         += $size['database_size'];
			$total_uploads_size    += $size['uploads_size'];
		}

		// Add shared tables size.
		$shared_size = $this->estimate_shared_tables_size();

		return array(
			'site_sizes'         => $site_sizes,
			'shared_tables_size' => $shared_size,
			'total_database'     => $total_db_size + $shared_size,
			'total_uploads'      => $total_uploads_size,
			'total_size'         => $total_db_size + $total_uploads_size + $shared_size,
			'total_sites'        => count( $site_ids ),
		);
	}

	/**
	 * Estimate shared tables size.
	 *
	 * @return int Size in bytes.
	 */
	private function estimate_shared_tables_size(): int {
		global $wpdb;

		$tables = $this->detector->get_shared_tables();
		$size   = 0;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT (data_length + index_length) as size FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
					DB_NAME,
					$table
				)
			);

			if ( $result ) {
				$size += (int) $result->size;
			}
		}

		return $size;
	}

	/**
	 * Create multisite backup job record.
	 *
	 * @param array  $site_ids     Site IDs.
	 * @param string $archive_mode Archive mode.
	 * @return string Job ID.
	 */
	private function create_multisite_job( array $site_ids, string $archive_mode ): string {
		global $wpdb;

		$job_id = wp_generate_uuid4();
		$table  = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'job_id'          => $job_id,
				'network_id'      => get_current_network_id(),
				'site_ids'        => wp_json_encode( $site_ids ),
				'archive_mode'    => $archive_mode,
				'total_sites'     => count( $site_ids ),
				'completed_sites' => 0,
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $job_id;
	}

	/**
	 * Update multisite job status.
	 *
	 * @param string $job_id  Job ID.
	 * @param string $status  Status.
	 * @param int    $completed Number of completed sites.
	 * @return void
	 */
	public function update_multisite_job( string $job_id, string $status, int $completed = 0 ): void {
		global $wpdb;

		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		$data = array(
			'status'          => $status,
			'completed_sites' => $completed,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			$data,
			array( 'job_id' => $job_id ),
			array( '%s', '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Update multisite job as completed.
	 *
	 * @param string $job_id       Job ID.
	 * @param array  $backup_files Array of backup file info.
	 * @param int    $completed    Number of completed sites.
	 * @return void
	 */
	private function update_multisite_job_completed( string $job_id, array $backup_files, int $completed ): void {
		global $wpdb;

		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// Ensure backup_files column exists.
		$this->ensure_backup_files_column();

		$data = array(
			'status'          => 'completed',
			'completed_sites' => $completed,
			'backup_files'    => wp_json_encode( $backup_files ),
			'completed_at'    => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			$data,
			array( 'job_id' => $job_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Ensure backup_files column exists in the multisite jobs table.
	 *
	 * @return void
	 */
	private function ensure_backup_files_column(): void {
		global $wpdb;

		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// Check if column exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results(
			"SHOW COLUMNS FROM {$table} LIKE 'backup_files'"
		);

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE {$table} ADD COLUMN backup_files text DEFAULT NULL AFTER completed_sites"
			);
		}
	}

	/**
	 * Update job progress.
	 *
	 * @param string $job_id       Job ID.
	 * @param int    $progress     Progress percentage (0-100).
	 * @param string $current_step Current step name.
	 * @param string $message      Status message.
	 * @return void
	 */
	public function update_job_progress( string $job_id, int $progress, string $current_step, string $message ): void {
		$progress_data = array(
			'progress'     => $progress,
			'current_step' => $current_step,
			'message'      => $message,
			'updated_at'   => time(),
		);

		set_transient( 'swish_backup_progress_' . $job_id, $progress_data, HOUR_IN_SECONDS );
	}

	/**
	 * Get job progress.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Progress data or null if not found.
	 */
	public function get_job_progress( string $job_id ): ?array {
		global $wpdb;

		// Get progress from transient.
		$progress_data = get_transient( 'swish_backup_progress_' . $job_id );

		// Get job record.
		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job ) {
			return null;
		}

		$result = array(
			'job_id'       => $job_id,
			'status'       => $job['status'],
			'total_sites'  => (int) $job['total_sites'],
			'progress'     => 0,
			'current_step' => 'init',
			'message'      => __( 'Initializing...', 'swish-migrate-and-backup' ),
		);

		if ( $progress_data ) {
			$result['progress']     = $progress_data['progress'];
			$result['current_step'] = $progress_data['current_step'];
			$result['message']      = $progress_data['message'];
		}

		// If completed, add download URL.
		if ( $job['status'] === 'completed' && ! empty( $job['backup_files'] ) ) {
			$backup_files = json_decode( $job['backup_files'], true );
			if ( ! empty( $backup_files[0]['filename'] ) ) {
				$result['download_url'] = $this->get_backup_download_url( $backup_files[0]['filename'] );
			}
		}

		// If failed, add error.
		if ( $job['status'] === 'failed' ) {
			$result['error'] = $progress_data['message'] ?? __( 'Backup failed.', 'swish-migrate-and-backup' );
		}

		return $result;
	}

	/**
	 * Mark job as failed.
	 *
	 * @param string $job_id Job ID.
	 * @param string $error  Error message.
	 * @return void
	 */
	public function mark_job_failed( string $job_id, string $error ): void {
		global $wpdb;

		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => 'failed',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		// Update progress transient.
		$this->update_job_progress( $job_id, 0, 'failed', $error );
	}

	/**
	 * Schedule background backup.
	 *
	 * @param array  $site_ids     Site IDs to backup.
	 * @param string $archive_mode Archive mode.
	 * @param array  $options      Additional options (include_core_files, etc).
	 * @return string Job ID.
	 */
	public function schedule_background_backup( array $site_ids, string $archive_mode, array $options = array() ): string {
		// Create job record.
		$job_id = $this->create_multisite_job( $site_ids, $archive_mode );

		// Merge archive mode into options.
		$options['archive_mode'] = $archive_mode;

		// Store backup parameters in transient.
		set_transient(
			'swish_backup_params_' . $job_id,
			array(
				'site_ids' => $site_ids,
				'options'  => $options,
			),
			HOUR_IN_SECONDS
		);

		// Schedule the backup to run immediately via WP Cron.
		wp_schedule_single_event( time(), 'swish_backup_run_multisite_backup', array( $job_id ) );

		// Update initial progress.
		$this->update_job_progress(
			$job_id,
			5,
			'init',
			__( 'Backup scheduled. Starting...', 'swish-migrate-and-backup' )
		);

		return $job_id;
	}

	/**
	 * Run scheduled backup.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_scheduled_backup( string $job_id ): void {
		// Get backup parameters.
		$params = get_transient( 'swish_backup_params_' . $job_id );

		if ( ! $params ) {
			$this->mark_job_failed( $job_id, __( 'Backup parameters not found.', 'swish-migrate-and-backup' ) );
			return;
		}

		$site_ids = $params['site_ids'];
		$options  = $params['options'] ?? array();
		$archive_mode = $options['archive_mode'] ?? 'single';
		$include_core_files = ! empty( $options['include_core_files'] );

		// Update progress - start with core files step if enabled.
		$initial_step = $include_core_files ? 'core' : 'shared';
		$initial_message = $include_core_files
			? __( 'Backing up WordPress core files...', 'swish-migrate-and-backup' )
			: __( 'Backing up shared network tables...', 'swish-migrate-and-backup' );

		$this->update_job_progress( $job_id, 10, $initial_step, $initial_message );

		// Run the backup.
		if ( $archive_mode === 'separate' ) {
			$result = $this->network_backup->backup_network_separate_archives_with_progress(
				$job_id,
				$site_ids,
				$options,
				array( $this, 'update_job_progress' )
			);
		} else {
			$result = $this->network_backup->backup_network_single_archive_with_progress(
				$job_id,
				$site_ids,
				$options,
				array( $this, 'update_job_progress' )
			);
		}

		// Process result.
		if ( $result && isset( $result['status'] ) && $result['status'] === 'completed' ) {
			if ( $archive_mode === 'separate' && isset( $result['archives'] ) ) {
				$backup_files = array();
				foreach ( $result['archives'] as $archive ) {
					$backup_files[] = array(
						'filename' => $archive['filename'],
						'path'     => $archive['archive_path'],
						'size'     => $archive['size'],
						'site_id'  => $archive['site_id'],
					);
				}
				$this->update_multisite_job_completed( $job_id, $backup_files, count( $site_ids ) );
			} else {
				$backup_files = array(
					array(
						'filename' => $result['filename'],
						'path'     => $result['path'],
						'size'     => $result['size'],
					),
				);
				$this->update_multisite_job_completed( $job_id, $backup_files, count( $site_ids ) );
			}

			$this->update_job_progress(
				$job_id,
				100,
				'cleanup',
				$result['message'] ?? __( 'Backup completed successfully!', 'swish-migrate-and-backup' )
			);
		} else {
			$this->mark_job_failed( $job_id, $result['error'] ?? __( 'Backup failed.', 'swish-migrate-and-backup' ) );
		}

		// Clean up transient.
		delete_transient( 'swish_backup_params_' . $job_id );
	}

	/**
	 * Get sites for selection UI.
	 *
	 * @return array Sites with size information.
	 */
	public function get_sites_for_selection(): array {
		$sites = $this->detector->get_network_sites();

		foreach ( $sites as &$site ) {
			$size = $this->detector->estimate_site_size( $site['blog_id'] );
			$site['estimated_size'] = $size['total_size'];
			$site['formatted_size'] = size_format( $size['total_size'] );
		}

		return $sites;
	}

	/**
	 * Get multisite backup history.
	 *
	 * @param int $limit Number of backups to retrieve.
	 * @return array Backup history.
	 */
	public function get_multisite_backups( int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
				'completed',
				$limit
			),
			ARRAY_A
		);

		if ( ! $jobs ) {
			return array();
		}

		$backups = array();

		foreach ( $jobs as $job ) {
			$site_ids = json_decode( $job['site_ids'], true );
			$backup_files = json_decode( $job['backup_files'], true );

			// Skip if no backup files stored.
			if ( empty( $backup_files ) ) {
				continue;
			}

			// Verify files still exist.
			$files = array();
			foreach ( $backup_files as $file_info ) {
				if ( isset( $file_info['path'] ) && file_exists( $file_info['path'] ) ) {
					$files[] = array(
						'filename' => $file_info['filename'],
						'path'     => $file_info['path'],
						'size'     => $file_info['size'],
						'site_id'  => $file_info['site_id'] ?? null,
					);
				}
			}

			// Only include backup if files exist.
			if ( ! empty( $files ) ) {
				$backups[] = array(
					'job_id'       => $job['job_id'],
					'created_at'   => $job['created_at'],
					'completed_at' => $job['completed_at'],
					'archive_mode' => $job['archive_mode'],
					'total_sites'  => $job['total_sites'],
					'site_ids'     => $site_ids,
					'files'        => $files,
				);
			}
		}

		return $backups;
	}

	/**
	 * Get backup download URL.
	 *
	 * Uses the local storage adapter's signed, expiring download URLs so
	 * archives are never exposed as direct, guessable web paths.
	 *
	 * @param string $filename Backup filename.
	 * @return string Download URL, or empty string if the file is unavailable.
	 */
	public function get_backup_download_url( string $filename ): string {
		$adapter = \SwishMigrateAndBackup\Core\Container::get_instance()->get( \SwishMigrateAndBackup\Storage\LocalAdapter::class );
		$url     = $adapter->get_download_url( basename( $filename ), DAY_IN_SECONDS );

		return $url ? $url : '';
	}

	/**
	 * Delete multisite backup.
	 *
	 * @param string $job_id Job ID.
	 * @return bool Success status.
	 */
	public function delete_multisite_backup( string $job_id ): bool {
		global $wpdb;

		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// Get job details.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job ) {
			return false;
		}

		// Get backup files from stored data.
		$backup_files = json_decode( $job['backup_files'], true );

		if ( ! empty( $backup_files ) ) {
			foreach ( $backup_files as $file_info ) {
				if ( isset( $file_info['path'] ) && file_exists( $file_info['path'] ) ) {
					wp_delete_file( $file_info['path'] );
				}
			}
		}

		// Delete job record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array( 'job_id' => $job_id ),
			array( '%s' )
		);

		return true;
	}
}
