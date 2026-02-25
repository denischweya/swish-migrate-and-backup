<?php
/**
 * Backup Manager.
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
use SwishMigrateAndBackup\Storage\StorageManager;

/**
 * Orchestrates backup operations.
 */
final class BackupManager {

	/**
	 * Database backup handler.
	 *
	 * @var DatabaseBackup
	 */
	private DatabaseBackup $database_backup;

	/**
	 * File backup handler.
	 *
	 * @var FileBackup
	 */
	private FileBackup $file_backup;

	/**
	 * Backup archiver.
	 *
	 * @var BackupArchiver
	 */
	private BackupArchiver $archiver;

	/**
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param DatabaseBackup $database_backup Database backup handler.
	 * @param FileBackup     $file_backup     File backup handler.
	 * @param BackupArchiver $archiver        Backup archiver.
	 * @param StorageManager $storage_manager Storage manager.
	 * @param Logger         $logger          Logger instance.
	 */
	public function __construct(
		DatabaseBackup $database_backup,
		FileBackup $file_backup,
		BackupArchiver $archiver,
		StorageManager $storage_manager,
		Logger $logger
	) {
		$this->database_backup = $database_backup;
		$this->file_backup     = $file_backup;
		$this->archiver        = $archiver;
		$this->storage_manager = $storage_manager;
		$this->logger          = $logger;
	}

	/**
	 * Check if backup size exceeds the free version limit.
	 *
	 * @param string $backup_path Backup file path.
	 * @param string $job_id      Job ID.
	 * @return bool True if size is within limit.
	 * @throws \Exception If size limit exceeded.
	 */
	private function check_backup_size_limit( string $backup_path, string $job_id ): bool {
		// Apply filter to allow Pro version to bypass size limit.
		$size_limit = apply_filters( 'swish_backup_size_limit', SWISH_BACKUP_FREE_SIZE_LIMIT );

		// If size limit is null (Pro version), skip check.
		if ( null === $size_limit ) {
			return true;
		}

		// Get backup file size.
		if ( ! file_exists( $backup_path ) ) {
			return true;
		}

		$backup_size = filesize( $backup_path );

		// Check if size exceeds limit.
		if ( $backup_size > $size_limit ) {
			// Delete the backup file.
			@unlink( $backup_path );

			// Mark job as size limit exceeded.
			global $wpdb;
			$table = $wpdb->prefix . 'swish_backup_jobs';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'size_limit_exceeded' => 1,
					'status'              => 'failed',
					'error_message'       => 'Backup exceeds 1GB limit for free version',
				),
				array( 'job_id' => $job_id )
			);

			$this->logger->warning(
				'Backup exceeds 1GB limit',
				array(
					'job_id'      => $job_id,
					'backup_size' => size_format( $backup_size ),
					'size_limit'  => size_format( $size_limit ),
				)
			);

			// Throw exception with upgrade URL.
			throw new \Exception(
				sprintf(
					'Your backup is %s which exceeds the 1GB limit for the free version. Upgrade to Pro to remove all limits: %s',
					size_format( $backup_size ),
					SWISH_BACKUP_PRO_URL
				)
			);
		}

		return true;
	}

	/**
	 * Configure batch sizes from options.
	 *
	 * @param array $options Backup options.
	 * @return void
	 */
	private function configure_batch_sizes( array $options ): void {
		if ( isset( $options['db_batch_size'] ) ) {
			$this->database_backup->set_rows_per_batch( (int) $options['db_batch_size'] );
		}

		if ( isset( $options['file_batch_size'] ) ) {
			$this->file_backup->set_files_per_batch( (int) $options['file_batch_size'] );
		}
	}

	/**
	 * Create a full backup.
	 *
	 * @param array $options Backup options.
	 * @return array|null Backup result or null on failure.
	 */
	public function create_full_backup( array $options = array() ): ?array {
		$job_id = $this->generate_job_id();
		$this->logger->set_job_id( $job_id );
		$this->logger->info( 'Starting full backup', array( 'options' => $options ) );

		// Configure batch sizes for shared hosting compatibility.
		$this->configure_batch_sizes( $options );

		/**
		 * Fires before a backup starts.
		 *
		 * @param string $job_id  Backup job ID.
		 * @param array  $options Backup options.
		 */
		do_action( 'swish_backup_before', $job_id, $options );

		// Create job record.
		$this->create_job_record( $job_id, 'full' );

		try {
			$temp_dir = $this->get_temp_directory( $job_id );
			$files_to_archive = array();

			// Backup database.
			if ( $options['backup_database'] ?? true ) {
				$this->update_job_status( $job_id, 'processing', 10, 'Backing up database...' );
				$db_file = $temp_dir . '/database.sql';

				if ( ! $this->database_backup->backup( $db_file ) ) {
					throw new \RuntimeException( 'Database backup failed' );
				}

				$files_to_archive[] = array(
					'path' => $db_file,
					'name' => 'database.sql',
				);
			}

			// Backup files.
			$this->update_job_status( $job_id, 'processing', 30, 'Preparing file list...' );
			$file_list = $this->file_backup->prepare_file_list( $options );

			if ( ! empty( $file_list['files'] ) ) {
				$this->update_job_status( $job_id, 'processing', 40, 'Backing up files...' );
				$files_archive = $temp_dir . '/files.zip';

				if ( ! $this->file_backup->backup( $file_list['files'], $files_archive ) ) {
					throw new \RuntimeException( 'File backup failed' );
				}

				$files_to_archive[] = array(
					'path' => $files_archive,
					'name' => 'files.zip',
				);
			}

			// Backup wp-config and special files.
			$special_files = $this->file_backup->backup_wp_config( $temp_dir );
			foreach ( $special_files as $file ) {
				$files_to_archive[] = array(
					'path' => $file,
					'name' => basename( $file ),
				);
			}

			// Create final archive.
			$this->update_job_status( $job_id, 'processing', 80, 'Creating archive...' );
			$backup_filename = $this->generate_backup_filename();
			$backup_path = $this->get_backup_directory() . '/' . $backup_filename;

			$metadata = array(
				'job_id'      => $job_id,
				'type'        => 'full',
				'options'     => $options,
				'file_count'  => $file_list['count'] ?? 0,
				'total_size'  => $file_list['total_size'] ?? 0,
			);

			if ( ! $this->archiver->create_archive( $files_to_archive, $backup_path, $metadata ) ) {
				throw new \RuntimeException( 'Archive creation failed' );
			}

			// Check backup size limit.
			$this->check_backup_size_limit( $backup_path, $job_id );

			// Upload to storage destinations.
			$this->update_job_status( $job_id, 'processing', 90, 'Uploading to storage...' );
			$destinations = $options['storage_destinations'] ?? array( 'local' );
			$upload_results = $this->storage_manager->upload_to_destinations(
				$backup_path,
				$backup_filename,
				$destinations
			);

			// Calculate checksum.
			$checksum = $this->archiver->calculate_checksum( $backup_path );

			// Clean up temp files.
			$this->cleanup_temp_directory( $temp_dir );

			// Update job as completed.
			$result = array(
				'job_id'      => $job_id,
				'filename'    => $backup_filename,
				'path'        => $backup_path,
				'size'        => filesize( $backup_path ),
				'checksum'    => $checksum,
				'destinations' => $upload_results,
				'manifest'    => $metadata,
			);

			$this->complete_job( $job_id, $result );

			/**
			 * Fires after a backup completes successfully.
			 *
			 * @param string $job_id Backup job ID.
			 * @param array  $result Backup result.
			 */
			do_action( 'swish_backup_after', $job_id, $result );

			$this->logger->info( 'Full backup completed', $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->fail_job( $job_id, $e->getMessage() );
			$this->logger->error( 'Full backup failed: ' . $e->getMessage() );

			if ( isset( $temp_dir ) ) {
				$this->cleanup_temp_directory( $temp_dir );
			}

			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Create a database-only backup.
	 *
	 * @param array $options Backup options.
	 * @return array|null Backup result or null on failure.
	 */
	public function create_database_backup( array $options = array() ): ?array {
		$job_id = $this->generate_job_id();
		$this->logger->set_job_id( $job_id );
		$this->logger->info( 'Starting database backup' );

		// Configure batch sizes for shared hosting compatibility.
		$this->configure_batch_sizes( $options );

		$this->create_job_record( $job_id, 'database' );

		try {
			$backup_filename = $this->generate_backup_filename( 'db' );
			$temp_file = $this->get_temp_directory( $job_id ) . '/database.sql';

			$this->update_job_status( $job_id, 'processing', 20, 'Backing up database...' );

			if ( ! $this->database_backup->backup( $temp_file ) ) {
				throw new \RuntimeException( 'Database backup failed' );
			}

			// Compress the SQL file.
			$this->update_job_status( $job_id, 'processing', 70, 'Compressing...' );
			$backup_path = $this->get_backup_directory() . '/' . $backup_filename;

			$metadata = array(
				'job_id' => $job_id,
				'type'   => 'database',
				'tables' => $this->database_backup->get_tables(),
			);

			if ( ! $this->archiver->create_archive(
				array( array( 'path' => $temp_file, 'name' => 'database.sql' ) ),
				$backup_path,
				$metadata
			) ) {
				throw new \RuntimeException( 'Archive creation failed' );
			}

			// Check backup size limit.
			$this->check_backup_size_limit( $backup_path, $job_id );

			// Upload to storage.
			$this->update_job_status( $job_id, 'processing', 90, 'Uploading to storage...' );
			$destinations = $options['storage_destinations'] ?? array( 'local' );
			$upload_results = $this->storage_manager->upload_to_destinations(
				$backup_path,
				$backup_filename,
				$destinations
			);

			// Clean up temp file.
			$this->cleanup_temp_directory( dirname( $temp_file ) );

			$result = array(
				'job_id'      => $job_id,
				'filename'    => $backup_filename,
				'path'        => $backup_path,
				'size'        => filesize( $backup_path ),
				'checksum'    => $this->archiver->calculate_checksum( $backup_path ),
				'destinations' => $upload_results,
			);

			$this->complete_job( $job_id, $result );
			$this->logger->info( 'Database backup completed', $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->fail_job( $job_id, $e->getMessage() );
			$this->logger->error( 'Database backup failed: ' . $e->getMessage() );
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Create a files-only backup.
	 *
	 * @param array $options Backup options.
	 * @return array|null Backup result or null on failure.
	 */
	public function create_files_backup( array $options = array() ): ?array {
		$job_id = $this->generate_job_id();
		$this->logger->set_job_id( $job_id );
		$this->logger->info( 'Starting files backup' );

		// Configure batch sizes for shared hosting compatibility.
		$this->configure_batch_sizes( $options );

		$this->create_job_record( $job_id, 'files' );

		try {
			$backup_filename = $this->generate_backup_filename( 'files' );
			$backup_path = $this->get_backup_directory() . '/' . $backup_filename;

			$this->update_job_status( $job_id, 'processing', 10, 'Preparing file list...' );
			$file_list = $this->file_backup->prepare_file_list( $options );

			$this->update_job_status( $job_id, 'processing', 30, 'Backing up files...' );

			if ( ! $this->file_backup->backup( $file_list['files'], $backup_path ) ) {
				throw new \RuntimeException( 'File backup failed' );
			}

			// Check backup size limit.
			$this->check_backup_size_limit( $backup_path, $job_id );

			// Upload to storage.
			$this->update_job_status( $job_id, 'processing', 90, 'Uploading to storage...' );
			$destinations = $options['storage_destinations'] ?? array( 'local' );
			$upload_results = $this->storage_manager->upload_to_destinations(
				$backup_path,
				$backup_filename,
				$destinations
			);

			$result = array(
				'job_id'      => $job_id,
				'filename'    => $backup_filename,
				'path'        => $backup_path,
				'size'        => filesize( $backup_path ),
				'checksum'    => $this->archiver->calculate_checksum( $backup_path ),
				'file_count'  => $file_list['count'],
				'destinations' => $upload_results,
			);

			$this->complete_job( $job_id, $result );
			$this->logger->info( 'Files backup completed', $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->fail_job( $job_id, $e->getMessage() );
			$this->logger->error( 'Files backup failed: ' . $e->getMessage() );
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Get list of existing backups.
	 *
	 * @param int $limit Maximum number of backups to return.
	 * @return array List of backups.
	 */
	public function get_backups( int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status = 'completed' ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$backups = array();
		foreach ( $jobs as $job ) {
			$backups[] = array(
				'id'           => $job['job_id'],
				'type'         => $job['type'],
				'filename'     => basename( $job['file_path'] ?? '' ),
				'path'         => $job['file_path'],
				'size'         => (int) $job['file_size'],
				'checksum'     => $job['checksum'],
				'created_at'   => $job['created_at'],
				'completed_at' => $job['completed_at'],
				'manifest'     => json_decode( $job['manifest'] ?? '{}', true ),
			);
		}

		return $backups;
	}

	/**
	 * Get a specific backup by job ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Backup data or null if not found.
	 */
	public function get_backup( string $job_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job ) {
			return null;
		}

		return array(
			'id'           => $job['job_id'],
			'type'         => $job['type'],
			'status'       => $job['status'],
			'progress'     => (int) $job['progress'],
			'filename'     => basename( $job['file_path'] ?? '' ),
			'path'         => $job['file_path'],
			'size'         => (int) $job['file_size'],
			'checksum'     => $job['checksum'],
			'created_at'   => $job['created_at'],
			'started_at'   => $job['started_at'],
			'completed_at' => $job['completed_at'],
			'error'        => $job['error_message'],
			'manifest'     => json_decode( $job['manifest'] ?? '{}', true ),
		);
	}

	/**
	 * Delete a backup.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True if deleted.
	 */
	public function delete_backup( string $job_id ): bool {
		$backup = $this->get_backup( $job_id );

		if ( ! $backup ) {
			return false;
		}

		// Delete from all storage destinations.
		$manifest = $backup['manifest'] ?? array();
		$destinations = array_keys( $manifest['options']['storage_destinations'] ?? array( 'local' => true ) );

		if ( ! empty( $backup['filename'] ) ) {
			$this->storage_manager->delete_from_destinations( $backup['filename'], $destinations );
		}

		// Delete local file if exists.
		if ( ! empty( $backup['path'] ) && file_exists( $backup['path'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $backup['path'] );
		}

		// Delete job record.
		global $wpdb;
		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'job_id' => $job_id ) );

		$this->logger->info( 'Backup deleted', array( 'job_id' => $job_id ) );

		return true;
	}

	/**
	 * Get backup job status.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job status or null if not found.
	 */
	public function get_job_status( string $job_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT status, progress, error_message, file_path, file_size FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job ) {
			return null;
		}

		return array(
			'status'   => $job['status'],
			'progress' => (int) $job['progress'],
			'message'  => $job['error_message'] ?? '',
			'path'     => $job['file_path'] ?? '',
			'size'     => (int) ( $job['file_size'] ?? 0 ),
		);
	}

	/**
	 * Generate a unique job ID.
	 *
	 * @return string
	 */
	private function generate_job_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Generate backup filename.
	 *
	 * @param string $type Backup type.
	 * @return string
	 */
	private function generate_backup_filename( string $type = 'full' ): string {
		$site_name = sanitize_file_name( wp_parse_url( get_site_url(), PHP_URL_HOST ) );
		$timestamp = gmdate( 'Y-m-d-His' );
		return "{$site_name}-{$type}-{$timestamp}.zip";
	}

	/**
	 * Get backup directory.
	 *
	 * @return string
	 */
	private function get_backup_directory(): string {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups';

		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		return $backup_dir;
	}

	/**
	 * Get temp directory for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private function get_temp_directory( string $job_id ): string {
		$temp_dir = $this->get_backup_directory() . '/temp/' . $job_id;

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		return $temp_dir;
	}

	/**
	 * Cleanup temp directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function cleanup_temp_directory( string $dir ): void {
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
				rmdir( $file->getRealPath() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Create job record in database.
	 *
	 * @param string $job_id Job ID.
	 * @param string $type   Backup type.
	 * @return void
	 */
	private function create_job_record( string $job_id, string $type ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'job_id'     => $job_id,
				'type'       => $type,
				'status'     => 'pending',
				'progress'   => 0,
				'started_at' => current_time( 'mysql', true ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Update job status.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $status   Status.
	 * @param int    $progress Progress percentage.
	 * @param string $message  Optional status message.
	 * @return void
	 */
	private function update_job_status(
		string $job_id,
		string $status,
		int $progress,
		string $message = ''
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		$data = array(
			'status'   => $status,
			'progress' => $progress,
		);

		// Store status message for API retrieval.
		if ( $message ) {
			$data['error_message'] = $message; // Reuse error_message for status messages during processing.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			$data,
			array( 'job_id' => $job_id )
		);

		if ( $message ) {
			$this->logger->info( $message );
		}
	}

	/**
	 * Complete a job successfully.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $result Backup result.
	 * @return void
	 */
	private function complete_job( string $job_id, array $result ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => 'completed',
				'progress'     => 100,
				'completed_at' => current_time( 'mysql', true ),
				'file_path'    => $result['path'] ?? '',
				'file_size'    => $result['size'] ?? 0,
				'checksum'     => $result['checksum'] ?? '',
				'manifest'     => wp_json_encode( $result['manifest'] ?? array() ),
			),
			array( 'job_id' => $job_id )
		);
	}

	/**
	 * Fail a job.
	 *
	 * @param string $job_id  Job ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private function fail_job( string $job_id, string $message ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'        => 'failed',
				'completed_at'  => current_time( 'mysql', true ),
				'error_message' => $message,
			),
			array( 'job_id' => $job_id )
		);
	}

	/**
	 * Apply retention policy to backups.
	 *
	 * @param int $retention_count Number of backups to keep.
	 * @return int Number of deleted backups.
	 */
	public function apply_retention_policy( int $retention_count = 5 ): int {
		$backups = $this->get_backups( 100 );
		$deleted = 0;

		// Keep only the specified number of backups.
		if ( count( $backups ) > $retention_count ) {
			$to_delete = array_slice( $backups, $retention_count );

			foreach ( $to_delete as $backup ) {
				if ( $this->delete_backup( $backup['id'] ) ) {
					++$deleted;
				}
			}
		}

		$this->logger->info( 'Retention policy applied', array(
			'retention_count' => $retention_count,
			'deleted'         => $deleted,
		) );

		return $deleted;
	}
}
