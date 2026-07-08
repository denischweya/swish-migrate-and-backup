<?php
/**
 * Site Duplicator.
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
 * Handles site duplication in a multisite network.
 */
final class SiteDuplicator {

	/**
	 * Multisite detector.
	 *
	 * @var MultisiteDetector
	 */
	private MultisiteDetector $detector;

	/**
	 * Debug log file path.
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
		$this->detector = $detector;
		$this->log_file = WP_CONTENT_DIR . '/swish-backups/duplicate-log.log';
	}

	/**
	 * Write to debug log file.
	 *
	 * Writes only when WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data    Optional data to log.
	 * @return void
	 */
	private function debug_log( string $message, $data = null ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$log_dir = dirname( $this->log_file );
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$log_entry = "[{$timestamp}] {$message}";

		if ( $data !== null ) {
			if ( is_array( $data ) || is_object( $data ) ) {
				$log_entry .= "\n" . print_r( $data, true );
			} else {
				$log_entry .= " | Data: {$data}";
			}
		}

		$log_entry .= "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Clear the debug log file.
	 *
	 * @return void
	 */
	public function clear_debug_log(): void {
		if ( file_exists( $this->log_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $this->log_file, '' );
		}
	}

	/**
	 * Check if a site exists with an exact domain and path match.
	 *
	 * @param string $domain Domain to check.
	 * @param string $path   Path to check.
	 * @return bool True if site exists with exact match.
	 */
	private function site_exists_exact( string $domain, string $path ): bool {
		global $wpdb;

		// Normalize for comparison.
		$domain = strtolower( trim( $domain ) );
		$path   = '/' . trim( $path, '/' ) . '/';
		if ( $path === '//' ) {
			$path = '/';
		}

		// Direct database query for exact match (most reliable).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE LOWER(domain) = %s AND path = %s LIMIT 1",
				$domain,
				$path
			)
		);

		return ! empty( $exists );
	}

	/**
	 * Validate duplication parameters.
	 *
	 * @param int    $source_site_id Source site blog ID.
	 * @param string $new_slug       New site slug.
	 * @return array Validation result with 'valid' boolean and 'message' string.
	 */
	public function validate_duplication( int $source_site_id, string $new_slug ): array {
		// Check source site exists.
		$source = get_site( $source_site_id );
		if ( ! $source ) {
			return array(
				'valid'   => false,
				'message' => __( 'Source site not found.', 'swish-migrate-and-backup' ),
			);
		}

		// Validate slug format.
		$new_slug = sanitize_title( $new_slug );
		if ( empty( $new_slug ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid site slug.', 'swish-migrate-and-backup' ),
			);
		}

		// Build the target domain and path for the new site.
		$network = get_network();
		if ( is_subdomain_install() ) {
			$target_domain = strtolower( $new_slug . '.' . preg_replace( '/^www\./', '', $network->domain ) );
			$target_path   = '/';
		} else {
			$target_domain = strtolower( $network->domain );
			$target_path   = rtrim( $network->path, '/' ) . '/' . $new_slug . '/';
		}

		// Check if a site with this exact domain/path already exists.
		// We query all sites and do exact string comparison because WordPress
		// get_sites() and get_site_by_path() do partial/fuzzy matching.
		$site_exists = $this->site_exists_exact( $target_domain, $target_path );

		if ( $site_exists ) {
			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: %s: the URL that already exists */
					__( 'A site with URL "%s" already exists.', 'swish-migrate-and-backup' ),
					is_subdomain_install() ? 'https://' . $target_domain : 'https://' . $target_domain . $target_path
				),
			);
		}

		// Check reserved slugs.
		$reserved = array( 'www', 'web', 'root', 'admin', 'main', 'invite', 'administrator', 'files' );
		if ( in_array( strtolower( $new_slug ), $reserved, true ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'This slug is reserved and cannot be used.', 'swish-migrate-and-backup' ),
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Schedule a duplication job to run in the background.
	 *
	 * @param int    $source_site_id Source site blog ID.
	 * @param string $new_slug       New site slug.
	 * @param string $new_title      New site title.
	 * @param array  $options        Optional duplication options.
	 * @return string Job ID.
	 */
	public function schedule_duplicate_job( int $source_site_id, string $new_slug, string $new_title, array $options = array() ): string {
		$job_id = wp_generate_uuid4();

		$this->debug_log( '========== NEW DUPLICATION JOB ==========' );
		$this->debug_log( 'Job ID: ' . $job_id );
		$this->debug_log( 'Source Site ID: ' . $source_site_id );
		$this->debug_log( 'New Slug: ' . $new_slug );
		$this->debug_log( 'New Title: ' . $new_title );

		// Default options.
		$options = wp_parse_args(
			$options,
			array(
				'copy_uploads' => true,
			)
		);

		$this->debug_log( 'Options:', $options );

		// Store job parameters in transient.
		$params = array(
			'source_site_id' => $source_site_id,
			'new_slug'       => sanitize_title( $new_slug ),
			'new_title'      => sanitize_text_field( $new_title ),
			'options'        => $options,
		);

		$transient_set = set_transient( 'swish_duplicate_params_' . $job_id, $params, HOUR_IN_SECONDS );
		$this->debug_log( 'Transient set result: ' . ( $transient_set ? 'true' : 'false' ) );

		// Initialize progress.
		$this->update_progress(
			$job_id,
			5,
			'init',
			__( 'Duplication scheduled. Starting...', 'swish-migrate-and-backup' )
		);

		// Schedule the job to run immediately via WP Cron.
		$cron_result = wp_schedule_single_event( time(), 'swish_backup_run_duplicate_site', array( $job_id ) );
		$this->debug_log( 'WP Cron scheduled result: ' . ( $cron_result ? 'true' : 'false' ) );

		// Check if cron hook is registered.
		$next_scheduled = wp_next_scheduled( 'swish_backup_run_duplicate_site', array( $job_id ) );
		$this->debug_log( 'Next scheduled time: ' . ( $next_scheduled ? gmdate( 'Y-m-d H:i:s', $next_scheduled ) : 'NOT SCHEDULED' ) );

		return $job_id;
	}

	/**
	 * Run a scheduled duplication job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_duplicate_job( string $job_id ): void {
		$this->debug_log( '---------- RUN DUPLICATE JOB ----------' );
		$this->debug_log( 'Job ID: ' . $job_id );

		// Acquire lock to prevent duplicate execution.
		$lock_key = 'swish_duplicate_lock_' . $job_id;

		// Clear cache to get fresh value.
		wp_cache_delete( $lock_key, 'transient' );
		wp_cache_delete( '_transient_' . $lock_key, 'options' );

		$lock = get_transient( $lock_key );

		$this->debug_log( 'Lock check - Key: ' . $lock_key . ', Value: ' . ( $lock ?: 'null' ) );

		if ( $lock === 'running' ) {
			$this->debug_log( 'SKIPPING: Job is already running (lock exists)' );
			return;
		}

		// Check if job already completed or failed.
		$progress = $this->get_duplicate_progress( $job_id );
		$this->debug_log( 'Current progress:', $progress );

		if ( $progress && in_array( $progress['current_step'], array( 'completed', 'failed' ), true ) ) {
			$this->debug_log( 'SKIPPING: Job already finished with status: ' . $progress['current_step'] );
			return;
		}

		// Set lock (expires in 10 minutes).
		wp_cache_delete( $lock_key, 'transient' );
		wp_cache_delete( '_transient_' . $lock_key, 'options' );
		$lock_set = set_transient( $lock_key, 'running', 10 * MINUTE_IN_SECONDS );
		$this->debug_log( 'Lock set result: ' . ( $lock_set ? 'true' : 'false' ) );

		// Get job parameters.
		$params = get_transient( 'swish_duplicate_params_' . $job_id );
		$this->debug_log( 'Job params retrieved:', $params );

		if ( ! $params ) {
			$this->debug_log( 'ERROR: Job parameters not found!' );
			delete_transient( $lock_key );
			$this->update_progress( $job_id, 0, 'failed', __( 'Job parameters not found.', 'swish-migrate-and-backup' ) );
			return;
		}

		$source_site_id = $params['source_site_id'];
		$new_slug       = $params['new_slug'];
		$new_title      = $params['new_title'];
		$options        = $params['options'] ?? array();

		$this->debug_log( 'Starting duplication with params - Source: ' . $source_site_id . ', Slug: ' . $new_slug . ', Title: ' . $new_title );

		// Run the duplication.
		try {
			$result = $this->duplicate_site( $source_site_id, $new_slug, $new_title, $job_id, $options );
			$this->debug_log( 'Duplication result:', $result );
		} catch ( \Throwable $e ) {
			$this->debug_log( 'EXCEPTION in duplicate_site: ' . $e->getMessage() );
			$this->debug_log( 'Stack trace: ' . $e->getTraceAsString() );
			$result = array(
				'success' => false,
				'message' => 'Exception: ' . $e->getMessage(),
			);
		}

		// Clean up params transient (not needed anymore).
		$params_key = 'swish_duplicate_params_' . $job_id;
		wp_cache_delete( $params_key, 'transient' );
		wp_cache_delete( '_transient_' . $params_key, 'options' );
		delete_transient( $params_key );

		// Clean up lock transient.
		wp_cache_delete( $lock_key, 'transient' );
		wp_cache_delete( '_transient_' . $lock_key, 'options' );
		delete_transient( $lock_key );

		// NOTE: We do NOT delete the progress option here.
		// It needs to stay so the client can read the completed status.
		// It will be cleaned up when the client acknowledges completion or by a scheduled cleanup.

		$this->debug_log( 'Job transients cleaned up (progress preserved for client)' );

		if ( ! $result['success'] ) {
			$this->debug_log( 'Job FAILED: ' . $result['message'] );
			$this->update_progress( $job_id, 0, 'failed', $result['message'] );
		} else {
			$this->debug_log( 'Job COMPLETED successfully' );
		}
	}

	/**
	 * Duplicate a site.
	 *
	 * @param int         $source_site_id Source site blog ID.
	 * @param string      $new_slug       New site slug.
	 * @param string      $new_title      New site title.
	 * @param string|null $job_id         Optional job ID for progress tracking.
	 * @param array       $options        Optional duplication options.
	 * @return array Result with 'success' boolean and additional data.
	 */
	public function duplicate_site( int $source_site_id, string $new_slug, string $new_title, ?string $job_id = null, array $options = array() ): array {
		$this->debug_log( '>>>>>> DUPLICATE_SITE STARTED <<<<<<' );
		$this->debug_log( 'Source Site ID: ' . $source_site_id );
		$this->debug_log( 'New Slug: ' . $new_slug );
		$this->debug_log( 'New Title: ' . $new_title );
		$this->debug_log( 'Job ID: ' . ( $job_id ?: 'null' ) );

		// Default options.
		$options = wp_parse_args(
			$options,
			array(
				'copy_uploads' => true,
			)
		);

		$this->debug_log( 'Options:', $options );

		// Note: Validation is done in the AJAX handler and via real-time UI check.
		// We skip validation here to avoid race conditions and duplicate checks.

		if ( $job_id ) {
			$this->update_progress( $job_id, 10, 'init', __( 'Creating new site...', 'swish-migrate-and-backup' ) );
		}

		// Step 1: Create new site.
		$this->debug_log( 'STEP 1: Creating new site...' );
		try {
			$new_site_id = $this->create_new_site( $new_slug, $new_title );
			$this->debug_log( 'New site created with ID: ' . $new_site_id );
		} catch ( \Exception $e ) {
			$this->debug_log( 'ERROR creating site: ' . $e->getMessage() );
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		// IMPORTANT: Capture the correct new site URL NOW, before copying tables overwrites it.
		$new_site_url  = get_site_url( $new_site_id );
		$new_home_url  = get_home_url( $new_site_id );
		$this->debug_log( 'Captured new site URL (before table copy): ' . $new_site_url );
		$this->debug_log( 'Captured new home URL (before table copy): ' . $new_home_url );

		if ( $job_id ) {
			$this->update_progress( $job_id, 20, 'database', __( 'Copying database tables...', 'swish-migrate-and-backup' ) );
		}

		// Step 2: Copy database tables.
		$this->debug_log( 'STEP 2: Copying database tables...' );
		try {
			$this->copy_site_tables( $source_site_id, $new_site_id, $job_id );
			$this->debug_log( 'Database tables copied successfully' );
		} catch ( \Exception $e ) {
			$this->debug_log( 'ERROR copying tables: ' . $e->getMessage() );
			// Clean up the created site on failure.
			wpmu_delete_blog( $new_site_id, true );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to copy database: %s', 'swish-migrate-and-backup' ),
					$e->getMessage()
				),
			);
		}

		// Step 3: Copy uploads directory (if enabled).
		$this->debug_log( 'STEP 3: Copy uploads - Enabled: ' . ( $options['copy_uploads'] ? 'yes' : 'no' ) );
		if ( $options['copy_uploads'] ) {
			if ( $job_id ) {
				$this->update_progress( $job_id, 60, 'uploads', __( 'Copying uploads...', 'swish-migrate-and-backup' ) );
			}

			try {
				$this->copy_site_uploads( $source_site_id, $new_site_id );
				$this->debug_log( 'Uploads copied successfully' );
			} catch ( \Exception $e ) {
				$this->debug_log( 'WARNING: Failed to copy uploads: ' . $e->getMessage() );
				// Non-fatal - continue without uploads.
			}
		} else {
			if ( $job_id ) {
				$this->update_progress( $job_id, 60, 'uploads', __( 'Skipping uploads (not selected)...', 'swish-migrate-and-backup' ) );
			}
		}

		if ( $job_id ) {
			$this->update_progress( $job_id, 80, 'replace', __( 'Replacing URLs...', 'swish-migrate-and-backup' ) );
		}

		// Step 4: Search/replace URLs.
		$this->debug_log( 'STEP 4: Replacing URLs...' );
		try {
			$this->replace_urls( $source_site_id, $new_site_id, $new_site_url, $new_home_url, $job_id );
			$this->debug_log( 'URLs replaced successfully' );
		} catch ( \Exception $e ) {
			$this->debug_log( 'WARNING: Failed to replace URLs: ' . $e->getMessage() );
		}

		if ( $job_id ) {
			$this->update_progress( $job_id, 95, 'finalize', __( 'Finalizing...', 'swish-migrate-and-backup' ) );
		}

		// Step 5: Update site options with correct URLs.
		$this->debug_log( 'STEP 5: Updating site options...' );
		$this->update_site_options( $new_site_id, $new_title, $new_site_url, $new_home_url );
		$this->debug_log( 'Site options updated' );
		$this->debug_log( 'Final new site URL: ' . $new_site_url );

		if ( $job_id ) {
			$this->update_progress(
				$job_id,
				100,
				'completed',
				sprintf(
					/* translators: %d: new site ID */
					__( 'Site duplicated successfully! New site ID: %d', 'swish-migrate-and-backup' ),
					$new_site_id
				),
				array(
					'new_site_id'  => $new_site_id,
					'new_site_url' => $new_site_url,
				)
			);
		}

		$this->debug_log( '>>>>>> DUPLICATE_SITE COMPLETED <<<<<<' );
		$this->debug_log( 'Result - New Site ID: ' . $new_site_id . ', URL: ' . $new_site_url );

		return array(
			'success'      => true,
			'new_site_id'  => $new_site_id,
			'new_site_url' => $new_site_url,
			'message'      => sprintf(
				/* translators: %d: new site ID */
				__( 'Site duplicated successfully. New site ID: %d', 'swish-migrate-and-backup' ),
				$new_site_id
			),
		);
	}

	/**
	 * Create a new site.
	 *
	 * @param string $new_slug  New site slug.
	 * @param string $new_title New site title.
	 * @return int New site ID.
	 * @throws \Exception On failure.
	 */
	private function create_new_site( string $new_slug, string $new_title ): int {
		$this->debug_log( 'create_new_site() called' );
		$this->debug_log( 'Slug: ' . $new_slug . ', Title: ' . $new_title );

		$network = get_network();
		$user_id = get_current_user_id();

		$this->debug_log( 'Network domain: ' . $network->domain );
		$this->debug_log( 'Network path: ' . $network->path );
		$this->debug_log( 'Current user ID: ' . $user_id );
		$this->debug_log( 'Is subdomain install: ' . ( is_subdomain_install() ? 'yes' : 'no' ) );

		if ( is_subdomain_install() ) {
			$domain = $new_slug . '.' . preg_replace( '/^www\./', '', $network->domain );
			$path   = '/';
		} else {
			$domain = $network->domain;
			$path   = rtrim( $network->path, '/' ) . '/' . $new_slug . '/';
		}

		$this->debug_log( 'Target domain: ' . $domain );
		$this->debug_log( 'Target path: ' . $path );
		$this->debug_log( 'Calling wpmu_create_blog()...' );

		$new_site_id = wpmu_create_blog( $domain, $path, $new_title, $user_id );

		$this->debug_log( 'wpmu_create_blog() returned: ' . ( is_wp_error( $new_site_id ) ? 'WP_Error' : $new_site_id ) );

		if ( is_wp_error( $new_site_id ) ) {
			$error_msg = $new_site_id->get_error_message();
			$this->debug_log( 'ERROR: ' . $error_msg );
			throw new \Exception( $error_msg );
		}

		$this->debug_log( 'Site created successfully with ID: ' . $new_site_id );
		return $new_site_id;
	}

	/**
	 * Copy database tables from source to new site.
	 *
	 * @param int         $source_site_id Source site ID.
	 * @param int         $new_site_id    New site ID.
	 * @param string|null $job_id         Optional job ID for progress.
	 * @return void
	 * @throws \Exception On failure.
	 */
	private function copy_site_tables( int $source_site_id, int $new_site_id, ?string $job_id = null ): void {
		global $wpdb;

		$source_prefix = $this->detector->get_site_prefix( $source_site_id );
		$new_prefix    = $this->detector->get_site_prefix( $new_site_id );
		$source_tables = $this->detector->get_site_tables( $source_site_id );

		$total_tables = count( $source_tables );
		$completed    = 0;

		foreach ( $source_tables as $source_table ) {
			$new_table = str_replace( $source_prefix, $new_prefix, $source_table );

			// Drop the new table first (it was created by wpmu_create_blog).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$new_table}`" );

			// Copy structure.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "CREATE TABLE `{$new_table}` LIKE `{$source_table}`" );

			// Copy data.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "INSERT INTO `{$new_table}` SELECT * FROM `{$source_table}`" );

			++$completed;

			// Update progress.
			if ( $job_id && $total_tables > 0 ) {
				$progress = 20 + (int) ( ( $completed / $total_tables ) * 40 );
				$this->update_progress(
					$job_id,
					$progress,
					'database',
					sprintf(
						/* translators: 1: completed count, 2: total count */
						__( 'Copying table %1$d of %2$d...', 'swish-migrate-and-backup' ),
						$completed,
						$total_tables
					)
				);
			}
		}
	}

	/**
	 * Copy uploads directory from source to new site.
	 *
	 * @param int $source_site_id Source site ID.
	 * @param int $new_site_id    New site ID.
	 * @return void
	 */
	private function copy_site_uploads( int $source_site_id, int $new_site_id ): void {
		$source_path = $this->detector->get_site_uploads_path( $source_site_id );
		$new_path    = $this->detector->get_site_uploads_path( $new_site_id );

		if ( ! is_dir( $source_path ) ) {
			return;
		}

		$this->copy_directory( $source_path, $new_path );
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source Source directory path.
	 * @param string $dest   Destination directory path.
	 * @return void
	 */
	private function copy_directory( string $source, string $dest ): void {
		if ( ! is_dir( $dest ) ) {
			wp_mkdir_p( $dest );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$dest_path = $dest . '/' . $iterator->getSubPathname();

			if ( $item->isDir() ) {
				wp_mkdir_p( $dest_path );
			} else {
				copy( $item->getPathname(), $dest_path );
			}
		}
	}

	/**
	 * Replace source URLs with new site URLs in database.
	 *
	 * @param int         $source_site_id Source site ID.
	 * @param int         $new_site_id    New site ID.
	 * @param string      $new_url        The correct new site URL (captured before table copy).
	 * @param string      $new_home       The correct new home URL (captured before table copy).
	 * @param string|null $job_id         Optional job ID for progress updates.
	 * @return void
	 */
	private function replace_urls( int $source_site_id, int $new_site_id, string $new_url, string $new_home, ?string $job_id = null ): void {
		$source_url  = get_site_url( $source_site_id );
		$source_home = get_home_url( $source_site_id );

		$this->debug_log( 'URL replacement - Source URL: ' . $source_url );
		$this->debug_log( 'URL replacement - New URL: ' . $new_url );
		$this->debug_log( 'URL replacement - Source Home: ' . $source_home );
		$this->debug_log( 'URL replacement - New Home: ' . $new_home );

		// Get uploads paths for media URL replacement.
		// For source, we can use get_site_uploads_url.
		// For new site, we need to construct it based on the new URL.
		$source_uploads_url = $this->get_site_uploads_url( $source_site_id );

		// Construct new uploads URL based on the correct new site URL.
		// In multisite, uploads are in /wp-content/uploads/sites/{blog_id}/
		$upload_dir     = wp_upload_dir();
		$uploads_subdir = '/wp-content/uploads/sites/' . $new_site_id;
		$new_uploads_url = rtrim( $new_url, '/' ) . $uploads_subdir;

		$this->debug_log( 'URL replacement - Source Uploads: ' . $source_uploads_url );
		$this->debug_log( 'URL replacement - New Uploads: ' . $new_uploads_url );

		// Get new site tables.
		$tables = $this->detector->get_site_tables( $new_site_id );

		// Build list of replacements (order matters - most specific first).
		$replacements = array();

		// 1. Uploads URL (most specific, e.g., /wp-content/uploads/sites/2/).
		if ( $source_uploads_url && $new_uploads_url && $source_uploads_url !== $new_uploads_url ) {
			$replacements[] = array( $source_uploads_url, $new_uploads_url );

			// Also handle without trailing slash.
			$replacements[] = array( rtrim( $source_uploads_url, '/' ), rtrim( $new_uploads_url, '/' ) );
		}

		// 2. Site URL.
		$replacements[] = array( $source_url, $new_url );

		// Also handle with trailing slash.
		$replacements[] = array( trailingslashit( $source_url ), trailingslashit( $new_url ) );

		// 3. Home URL if different.
		if ( $source_home !== $source_url ) {
			$replacements[] = array( $source_home, $new_home );
			$replacements[] = array( trailingslashit( $source_home ), trailingslashit( $new_home ) );
		}

		// 4. Handle http/https variations.
		$source_url_http  = str_replace( 'https://', 'http://', $source_url );
		$source_url_https = str_replace( 'http://', 'https://', $source_url );
		$new_url_http     = str_replace( 'https://', 'http://', $new_url );
		$new_url_https    = str_replace( 'http://', 'https://', $new_url );

		if ( $source_url_http !== $source_url ) {
			$replacements[] = array( $source_url_http, $new_url_http );
		}
		if ( $source_url_https !== $source_url ) {
			$replacements[] = array( $source_url_https, $new_url_https );
		}

		// Perform all replacements.
		$total_replacements = count( $replacements );
		$completed          = 0;

		foreach ( $replacements as $pair ) {
			list( $search, $replace ) = $pair;

			if ( $job_id ) {
				$progress = 80 + (int) ( ( $completed / $total_replacements ) * 15 );
				$this->update_progress(
					$job_id,
					$progress,
					'replace',
					sprintf(
						/* translators: %s: URL being replaced */
						__( 'Replacing: %s', 'swish-migrate-and-backup' ),
						$search
					)
				);
			}

			$this->search_replace_in_tables( $search, $replace, $tables );
			++$completed;
		}
	}

	/**
	 * Get the uploads URL for a site.
	 *
	 * @param int $site_id Site ID.
	 * @return string Uploads URL.
	 */
	private function get_site_uploads_url( int $site_id ): string {
		switch_to_blog( $site_id );
		$upload_dir = wp_upload_dir();
		restore_current_blog();

		return $upload_dir['baseurl'] ?? '';
	}

	/**
	 * Perform search and replace in database tables.
	 *
	 * @param string $search  String to search for.
	 * @param string $replace String to replace with.
	 * @param array  $tables  Tables to process.
	 * @return void
	 */
	private function search_replace_in_tables( string $search, string $replace, array $tables ): void {
		global $wpdb;

		foreach ( $tables as $table ) {
			// Get columns for the table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );

			if ( ! $columns ) {
				continue;
			}

			// Get primary key column.
			$primary_key = null;
			$text_columns = array();

			foreach ( $columns as $column ) {
				if ( 'PRI' === $column['Key'] ) {
					$primary_key = $column['Field'];
				}

				// Only process text-type columns.
				$type = strtolower( $column['Type'] );
				if ( strpos( $type, 'text' ) !== false ||
					strpos( $type, 'char' ) !== false ||
					strpos( $type, 'blob' ) !== false ) {
					$text_columns[] = $column['Field'];
				}
			}

			if ( empty( $text_columns ) || ! $primary_key ) {
				continue;
			}

			// Process each row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT `{$primary_key}`, " . implode( ', ', array_map( function( $col ) { return "`{$col}`"; }, $text_columns ) ) . " FROM `{$table}`", ARRAY_A );

			if ( ! $rows ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$updates = array();

				foreach ( $text_columns as $column ) {
					$value = $row[ $column ];

					if ( empty( $value ) || strpos( $value, $search ) === false ) {
						continue;
					}

					// Handle serialized data.
					$new_value = $this->search_replace_value( $value, $search, $replace );

					if ( $new_value !== $value ) {
						$updates[ $column ] = $new_value;
					}
				}

				if ( ! empty( $updates ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						$updates,
						array( $primary_key => $row[ $primary_key ] )
					);
				}
			}
		}
	}

	/**
	 * Search and replace in a value, handling serialized data.
	 *
	 * @param string $value   Value to process.
	 * @param string $search  String to search for.
	 * @param string $replace String to replace with.
	 * @return string Processed value.
	 */
	private function search_replace_value( string $value, string $search, string $replace ): string {
		// Check if serialized.
		$unserialized = @unserialize( $value );

		if ( false !== $unserialized || 'b:0;' === $value ) {
			// It's serialized data - recurse through it.
			$unserialized = $this->search_replace_recursive( $unserialized, $search, $replace );
			return serialize( $unserialized );
		}

		// Regular string replacement.
		return str_replace( $search, $replace, $value );
	}

	/**
	 * Recursively search and replace in arrays/objects.
	 *
	 * @param mixed  $data    Data to process.
	 * @param string $search  String to search for.
	 * @param string $replace String to replace with.
	 * @return mixed Processed data.
	 */
	private function search_replace_recursive( $data, string $search, string $replace ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->search_replace_recursive( $value, $search, $replace );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = $this->search_replace_recursive( $value, $search, $replace );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Update site options for the new site.
	 *
	 * @param int    $new_site_id  New site ID.
	 * @param string $new_title    New site title.
	 * @param string $new_site_url The correct site URL.
	 * @param string $new_home_url The correct home URL.
	 * @return void
	 */
	private function update_site_options( int $new_site_id, string $new_title, string $new_site_url, string $new_home_url ): void {
		switch_to_blog( $new_site_id );

		update_option( 'siteurl', $new_site_url );
		update_option( 'home', $new_home_url );
		update_option( 'blogname', $new_title );

		$this->debug_log( 'Set siteurl to: ' . $new_site_url );
		$this->debug_log( 'Set home to: ' . $new_home_url );
		$this->debug_log( 'Set blogname to: ' . $new_title );

		restore_current_blog();
	}

	/**
	 * Update job progress.
	 *
	 * @param string     $job_id       Job ID.
	 * @param int        $progress     Progress percentage (0-100).
	 * @param string     $current_step Current step name.
	 * @param string     $message      Status message.
	 * @param array|null $extra_data   Optional extra data to include.
	 * @return void
	 */
	public function update_progress( string $job_id, int $progress, string $current_step, string $message, ?array $extra_data = null ): void {
		global $wpdb;

		$progress_data = array(
			'progress'     => $progress,
			'current_step' => $current_step,
			'message'      => $message,
			'updated_at'   => time(),
		);

		if ( $extra_data ) {
			$progress_data = array_merge( $progress_data, $extra_data );
		}

		$option_name = 'swish_duplicate_progress_' . $job_id;

		// Use direct database query to avoid any caching issues.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		$serialized = maybe_serialize( $progress_data );

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => $serialized ),
				array( 'option_name' => $option_name )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $option_name,
					'option_value' => $serialized,
					'autoload'     => 'no',
				)
			);
		}

		// Clear any cached value.
		wp_cache_delete( $option_name, 'options' );

		$this->debug_log( 'Progress updated: ' . $progress . '% - ' . $current_step . ' - ' . $message );
	}

	/**
	 * Get job progress.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Progress data or null if not found.
	 */
	public function get_duplicate_progress( string $job_id ): ?array {
		global $wpdb;

		$option_name = 'swish_duplicate_progress_' . $job_id;

		// Clear any cached value first.
		wp_cache_delete( $option_name, 'options' );

		// Use direct database query to avoid any caching issues.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( ! $value ) {
			return null;
		}

		$progress_data = maybe_unserialize( $value );

		if ( ! is_array( $progress_data ) ) {
			return null;
		}

		return $progress_data;
	}

	/**
	 * Clean up progress data for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function cleanup_progress( string $job_id ): void {
		global $wpdb;

		$option_name = 'swish_duplicate_progress_' . $job_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->options,
			array( 'option_name' => $option_name )
		);

		wp_cache_delete( $option_name, 'options' );

		$this->debug_log( 'Progress cleaned up for job: ' . $job_id );
	}
}
