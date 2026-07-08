<?php
/**
 * Network Backup Handler.
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
 * Handles network-wide backup operations.
 */
final class NetworkBackup {

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
	 * Constructor.
	 *
	 * @param MultisiteDetector $detector Multisite detector.
	 */
	public function __construct( MultisiteDetector $detector ) {
		$this->detector   = $detector;
		$this->backup_dir = WP_CONTENT_DIR . '/swish-backups';

		// Ensure backup directory exists.
		if ( ! file_exists( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}
	}

	/**
	 * Backup network as a single archive with progress reporting.
	 *
	 * @param string   $job_id            Job ID.
	 * @param array    $site_ids          Site IDs.
	 * @param array    $options           Options.
	 * @param callable $progress_callback Progress callback function.
	 * @return array|null Backup result.
	 */
	public function backup_network_single_archive_with_progress( string $job_id, array $site_ids, array $options, callable $progress_callback ): ?array {
		try {
			$network_info       = $this->detector->get_network_info();
			$temp_dir           = $this->get_temp_directory( $job_id );
			$total_sites        = count( $site_ids );
			$include_core_files = ! empty( $options['include_core_files'] );
			$include_files      = $this->includes_file_backup( $options );
			$database_only      = ! empty( $options['database_only'] );

			// If database only, skip all file backups.
			if ( $database_only ) {
				$include_core_files = false;
				$include_files      = false;
			}

			// Calculate progress ranges based on enabled options.
			$progress_ranges = $this->calculate_progress_ranges( $include_core_files, $include_files );

			// Step 0: Backup WordPress core files if enabled.
			if ( $include_core_files ) {
				call_user_func( $progress_callback, $job_id, 5, 'core', __( 'Backing up WordPress core files...', 'swish-migrate-and-backup' ) );
				$this->backup_core_files( $temp_dir );
				call_user_func( $progress_callback, $job_id, $progress_ranges['core_end'], 'core', __( 'WordPress core files backed up.', 'swish-migrate-and-backup' ) );
			}

			// Step 1: Create manifest and backup shared tables.
			call_user_func( $progress_callback, $job_id, $progress_ranges['shared_start'], 'shared', __( 'Backing up shared network tables...', 'swish-migrate-and-backup' ) );

			$manifest = $this->create_multisite_manifest( $job_id, $site_ids, 'single', $options );
			file_put_contents( $temp_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

			$this->backup_shared_tables( $temp_dir );
			call_user_func( $progress_callback, $job_id, $progress_ranges['sites_start'], 'sites', __( 'Backing up site databases...', 'swish-migrate-and-backup' ) );

			// Step 2: Backup each site database.
			$sites_data = array();
			foreach ( $site_ids as $index => $site_id ) {
				$site_progress = $progress_ranges['sites_start'] + ( ( $index + 1 ) / $total_sites ) * $progress_ranges['sites_range'];
				$site_data     = $this->get_site_backup_data( $site_id );

				call_user_func(
					$progress_callback,
					$job_id,
					(int) $site_progress,
					'sites',
					sprintf(
						/* translators: 1: current site number, 2: total sites, 3: site name */
						__( 'Backing up site %1$d of %2$d: %3$s', 'swish-migrate-and-backup' ),
						$index + 1,
						$total_sites,
						$site_data['site_name'] ?? ''
					)
				);

				$this->backup_site_database( $site_id, $temp_dir );
				$sites_data[] = $site_data;
			}

			// Step 3: Backup wp-content folders if enabled.
			if ( $include_files ) {
				call_user_func( $progress_callback, $job_id, $progress_ranges['files_start'], 'files', __( 'Backing up wp-content folders...', 'swish-migrate-and-backup' ) );
				$this->backup_wp_content_folders( $temp_dir, $options );
				call_user_func( $progress_callback, $job_id, $progress_ranges['files_end'], 'files', __( 'Files backed up.', 'swish-migrate-and-backup' ) );
			}

			// Step 4: Create archive.
			call_user_func( $progress_callback, $job_id, $progress_ranges['archive_start'], 'archive', __( 'Creating backup archive...', 'swish-migrate-and-backup' ) );

			$filename     = $this->generate_multisite_backup_filename( 'single' );
			$archive_path = $this->backup_dir . '/' . $filename;
			$this->create_archive( $temp_dir, $archive_path );

			// Step 5: Cleanup.
			call_user_func( $progress_callback, $job_id, 95, 'cleanup', __( 'Cleaning up temporary files...', 'swish-migrate-and-backup' ) );
			$this->cleanup_temp_directory( $temp_dir );

			// Build success message.
			$message = $database_only
				? sprintf(
					/* translators: %d: number of sites */
					__( 'Successfully backed up databases for %d sites.', 'swish-migrate-and-backup' ),
					count( $site_ids )
				)
				: sprintf(
					/* translators: %d: number of sites */
					__( 'Successfully backed up %d sites in single archive.', 'swish-migrate-and-backup' ),
					count( $site_ids )
				);

			return array(
				'job_id'             => $job_id,
				'type'               => 'multisite',
				'archive_mode'       => 'single',
				'database_only'      => $database_only,
				'include_core_files' => $include_core_files,
				'include_files'      => $include_files,
				'filename'           => $filename,
				'path'               => $archive_path,
				'size'               => file_exists( $archive_path ) ? filesize( $archive_path ) : 0,
				'network'            => $network_info,
				'sites'              => $sites_data,
				'site_count'         => count( $site_ids ),
				'status'             => 'completed',
				'message'            => $message,
			);
		} catch ( \Exception $e ) {
			return array(
				'job_id'  => $job_id,
				'status'  => 'failed',
				'error'   => $e->getMessage(),
				'message' => 'Multisite backup failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Calculate progress ranges based on enabled options.
	 *
	 * @param bool $include_core_files Whether to include core files.
	 * @param bool $include_files      Whether to include wp-content files.
	 * @return array Progress ranges.
	 */
	private function calculate_progress_ranges( bool $include_core_files, bool $include_files ): array {
		// Base ranges for database-only backup.
		$ranges = array(
			'core_end'      => 0,
			'shared_start'  => 5,
			'sites_start'   => 15,
			'sites_range'   => 60,
			'files_start'   => 75,
			'files_end'     => 75,
			'archive_start' => 80,
		);

		if ( $include_core_files && $include_files ) {
			// Full backup with everything.
			$ranges['core_end']      = 10;
			$ranges['shared_start']  = 12;
			$ranges['sites_start']   = 20;
			$ranges['sites_range']   = 30;
			$ranges['files_start']   = 50;
			$ranges['files_end']     = 75;
			$ranges['archive_start'] = 80;
		} elseif ( $include_core_files ) {
			// Core files but no wp-content.
			$ranges['core_end']      = 15;
			$ranges['shared_start']  = 18;
			$ranges['sites_start']   = 25;
			$ranges['sites_range']   = 50;
			$ranges['archive_start'] = 80;
		} elseif ( $include_files ) {
			// WP-content but no core files.
			$ranges['shared_start']  = 5;
			$ranges['sites_start']   = 15;
			$ranges['sites_range']   = 30;
			$ranges['files_start']   = 45;
			$ranges['files_end']     = 75;
			$ranges['archive_start'] = 80;
		}

		return $ranges;
	}

	/**
	 * Backup network as separate archives with progress reporting.
	 *
	 * @param string   $job_id            Job ID.
	 * @param array    $site_ids          Site IDs.
	 * @param array    $options           Options.
	 * @param callable $progress_callback Progress callback function.
	 * @return array|null Backup result.
	 */
	public function backup_network_separate_archives_with_progress( string $job_id, array $site_ids, array $options, callable $progress_callback ): ?array {
		try {
			$network_info       = $this->detector->get_network_info();
			$archives           = array();
			$total_sites        = count( $site_ids );
			$include_core_files = ! empty( $options['include_core_files'] );
			$include_files      = $this->includes_file_backup( $options );
			$database_only      = ! empty( $options['database_only'] );

			// If database only, skip all file backups.
			if ( $database_only ) {
				$include_core_files = false;
				$include_files      = false;
			}

			// Calculate progress ranges based on enabled options.
			$progress_ranges = $this->calculate_progress_ranges_separate( $include_core_files, $include_files, $total_sites );

			// Prepare shared temp directory.
			$shared_temp_dir = $this->get_temp_directory( $job_id . '-shared' );

			// Step 0: Backup WordPress core files if enabled.
			if ( $include_core_files ) {
				call_user_func( $progress_callback, $job_id, 5, 'core', __( 'Backing up WordPress core files...', 'swish-migrate-and-backup' ) );
				$this->backup_core_files( $shared_temp_dir );
				call_user_func( $progress_callback, $job_id, $progress_ranges['core_end'], 'core', __( 'WordPress core files backed up.', 'swish-migrate-and-backup' ) );
			}

			// Step 1: Backup wp-content folders if enabled (only once to shared temp).
			if ( $include_files ) {
				call_user_func( $progress_callback, $job_id, $progress_ranges['files_start'], 'files', __( 'Backing up wp-content folders...', 'swish-migrate-and-backup' ) );
				$this->backup_wp_content_folders( $shared_temp_dir, $options );
				call_user_func( $progress_callback, $job_id, $progress_ranges['files_end'], 'files', __( 'Files backed up.', 'swish-migrate-and-backup' ) );
			}

			// Step 2: Backup shared tables.
			call_user_func( $progress_callback, $job_id, $progress_ranges['shared_start'], 'shared', __( 'Backing up shared network tables...', 'swish-migrate-and-backup' ) );
			$this->backup_shared_tables( $shared_temp_dir );

			// Step 3: Create archives for each site.
			foreach ( $site_ids as $index => $site_id ) {
				$site_progress = $progress_ranges['sites_start'] + ( ( $index + 1 ) / $total_sites ) * $progress_ranges['sites_range'];
				$site_data     = $this->get_site_backup_data( $site_id );

				call_user_func(
					$progress_callback,
					$job_id,
					(int) $site_progress,
					'sites',
					sprintf(
						/* translators: 1: current site number, 2: total sites, 3: site name */
						__( 'Creating archive for site %1$d of %2$d: %3$s', 'swish-migrate-and-backup' ),
						$index + 1,
						$total_sites,
						$site_data['site_name'] ?? ''
					)
				);

				$site_temp_dir = $this->get_temp_directory( $job_id . '-site-' . $site_id );

				// Copy shared tables.
				$this->copy_shared_tables( $shared_temp_dir, $site_temp_dir );

				// Copy core files if enabled.
				if ( $include_core_files ) {
					$this->copy_core_files( $shared_temp_dir, $site_temp_dir );
				}

				// Copy wp-content folders if enabled.
				if ( $include_files ) {
					$this->copy_wp_content_folders( $shared_temp_dir, $site_temp_dir );
				}

				$this->backup_site_database( $site_id, $site_temp_dir );

				$site_manifest = $this->create_multisite_manifest( $job_id, array( $site_id ), 'separate', $options );
				file_put_contents( $site_temp_dir . '/manifest.json', wp_json_encode( $site_manifest, JSON_PRETTY_PRINT ) );

				$filename     = $this->generate_site_backup_filename( $site_id, $site_data['site_name'] );
				$archive_path = $this->backup_dir . '/' . $filename;
				$this->create_archive( $site_temp_dir, $archive_path );

				$archives[] = array(
					'site_id'            => $site_id,
					'site_url'           => $site_data['site_url'],
					'site_name'          => $site_data['site_name'],
					'filename'           => $filename,
					'archive_path'       => $archive_path,
					'database_only'      => $database_only,
					'include_core_files' => $include_core_files,
					'include_files'      => $include_files,
					'size'               => file_exists( $archive_path ) ? filesize( $archive_path ) : 0,
					'status'             => 'completed',
				);

				$this->cleanup_temp_directory( $site_temp_dir );
			}

			// Step 4: Cleanup.
			call_user_func( $progress_callback, $job_id, 95, 'cleanup', __( 'Cleaning up temporary files...', 'swish-migrate-and-backup' ) );
			$this->cleanup_temp_directory( $shared_temp_dir );

			// Build success message.
			$message = $database_only
				? sprintf(
					/* translators: %d: number of sites */
					__( 'Successfully backed up databases for %d sites as separate archives.', 'swish-migrate-and-backup' ),
					count( $site_ids )
				)
				: sprintf(
					/* translators: %d: number of sites */
					__( 'Successfully backed up %d sites as separate archives.', 'swish-migrate-and-backup' ),
					count( $site_ids )
				);

			return array(
				'job_id'             => $job_id,
				'type'               => 'multisite',
				'archive_mode'       => 'separate',
				'database_only'      => $database_only,
				'include_core_files' => $include_core_files,
				'include_files'      => $include_files,
				'network'            => $network_info,
				'archives'           => $archives,
				'site_count'         => count( $site_ids ),
				'status'             => 'completed',
				'message'            => $message,
			);
		} catch ( \Exception $e ) {
			return array(
				'job_id'  => $job_id,
				'status'  => 'failed',
				'error'   => $e->getMessage(),
				'message' => 'Multisite backup failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Calculate progress ranges for separate archives mode.
	 *
	 * @param bool $include_core_files Whether to include core files.
	 * @param bool $include_files      Whether to include wp-content files.
	 * @param int  $total_sites        Total number of sites.
	 * @return array Progress ranges.
	 */
	private function calculate_progress_ranges_separate( bool $include_core_files, bool $include_files, int $total_sites ): array {
		// Base ranges for database-only backup.
		$ranges = array(
			'core_end'     => 0,
			'files_start'  => 0,
			'files_end'    => 0,
			'shared_start' => 5,
			'sites_start'  => 15,
			'sites_range'  => 75,
		);

		if ( $include_core_files && $include_files ) {
			// Full backup with everything.
			$ranges['core_end']     = 10;
			$ranges['files_start']  = 12;
			$ranges['files_end']    = 25;
			$ranges['shared_start'] = 27;
			$ranges['sites_start']  = 30;
			$ranges['sites_range']  = 60;
		} elseif ( $include_core_files ) {
			// Core files but no wp-content.
			$ranges['core_end']     = 15;
			$ranges['shared_start'] = 18;
			$ranges['sites_start']  = 22;
			$ranges['sites_range']  = 68;
		} elseif ( $include_files ) {
			// WP-content but no core files.
			$ranges['files_start']  = 5;
			$ranges['files_end']    = 20;
			$ranges['shared_start'] = 22;
			$ranges['sites_start']  = 25;
			$ranges['sites_range']  = 65;
		}

		return $ranges;
	}

	/**
	 * Copy wp-content folders from shared to site temp directory.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $target_dir Target directory.
	 * @return void
	 */
	private function copy_wp_content_folders( string $source_dir, string $target_dir ): void {
		$source_wp_content = $source_dir . '/wp-content';
		$target_wp_content = $target_dir . '/wp-content';

		if ( ! is_dir( $source_wp_content ) ) {
			return;
		}

		// Create target wp-content directory.
		wp_mkdir_p( $target_wp_content );

		// Copy each subfolder that exists.
		$folders = array( 'themes', 'plugins', 'uploads', 'mu-plugins' );

		foreach ( $folders as $folder ) {
			$source_folder = $source_wp_content . '/' . $folder;
			$target_folder = $target_wp_content . '/' . $folder;

			if ( is_dir( $source_folder ) ) {
				$this->copy_directory( $source_folder, $target_folder );
			}
		}
	}

	/**
	 * Backup network as a single archive.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Site IDs.
	 * @param array  $options  Options.
	 * @return array|null Backup result.
	 */
	public function backup_network_single_archive( string $job_id, array $site_ids, array $options ): ?array {
		try {
			$network_info = $this->detector->get_network_info();
			$temp_dir     = $this->get_temp_directory( $job_id );

			// Create manifest.
			$manifest = $this->create_multisite_manifest( $job_id, $site_ids, 'single', $options );
			file_put_contents( $temp_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

			// Backup shared tables.
			$this->backup_shared_tables( $temp_dir );

			// Backup each site.
			$sites_data = array();
			foreach ( $site_ids as $site_id ) {
				// Backup site database.
				$this->backup_site_database( $site_id, $temp_dir );

				$sites_data[] = $this->get_site_backup_data( $site_id );
			}

			// Create the archive.
			$filename = $this->generate_multisite_backup_filename( 'single' );
			$archive_path = $this->backup_dir . '/' . $filename;

			// Create ZIP of temp directory.
			$this->create_archive( $temp_dir, $archive_path );

			// Clean up temp directory.
			$this->cleanup_temp_directory( $temp_dir );

			return array(
				'job_id'       => $job_id,
				'type'         => 'multisite',
				'archive_mode' => 'single',
				'filename'     => $filename,
				'path'         => $archive_path,
				'size'         => file_exists( $archive_path ) ? filesize( $archive_path ) : 0,
				'network'      => $network_info,
				'sites'        => $sites_data,
				'site_count'   => count( $site_ids ),
				'status'       => 'completed',
				'message'      => sprintf( 'Successfully backed up %d sites in single archive.', count( $site_ids ) ),
			);
		} catch ( \Exception $e ) {
			return array(
				'job_id'  => $job_id,
				'status'  => 'failed',
				'error'   => $e->getMessage(),
				'message' => 'Multisite backup failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Backup network as separate archives.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Site IDs.
	 * @param array  $options  Options.
	 * @return array|null Backup result.
	 */
	public function backup_network_separate_archives( string $job_id, array $site_ids, array $options ): ?array {
		try {
			$network_info = $this->detector->get_network_info();
			$archives     = array();

			// Backup shared tables once (will be included in each archive).
			$shared_temp_dir = $this->get_temp_directory( $job_id . '-shared' );
			$this->backup_shared_tables( $shared_temp_dir );

			// Create separate archive for each site.
			foreach ( $site_ids as $site_id ) {
				$site_temp_dir = $this->get_temp_directory( $job_id . '-site-' . $site_id );

				// Copy shared tables to this site's temp dir.
				$this->copy_shared_tables( $shared_temp_dir, $site_temp_dir );

				// Backup this site's database.
				$this->backup_site_database( $site_id, $site_temp_dir );

				// Create manifest for this site.
				$site_manifest = $this->create_multisite_manifest( $job_id, array( $site_id ), 'separate', $options );
				file_put_contents( $site_temp_dir . '/manifest.json', wp_json_encode( $site_manifest, JSON_PRETTY_PRINT ) );

				// Create archive for this site.
				$site_data = $this->get_site_backup_data( $site_id );
				$filename = $this->generate_site_backup_filename( $site_id, $site_data['site_name'] );
				$archive_path = $this->backup_dir . '/' . $filename;

				$this->create_archive( $site_temp_dir, $archive_path );

				$archives[] = array(
					'site_id'      => $site_id,
					'site_url'     => $site_data['site_url'],
					'site_name'    => $site_data['site_name'],
					'filename'     => $filename,
					'archive_path' => $archive_path,
					'size'         => file_exists( $archive_path ) ? filesize( $archive_path ) : 0,
					'status'       => 'completed',
				);

				// Clean up this site's temp directory.
				$this->cleanup_temp_directory( $site_temp_dir );
			}

			// Clean up shared temp directory.
			$this->cleanup_temp_directory( $shared_temp_dir );

			return array(
				'job_id'       => $job_id,
				'type'         => 'multisite',
				'archive_mode' => 'separate',
				'network'      => $network_info,
				'archives'     => $archives,
				'site_count'   => count( $site_ids ),
				'status'       => 'completed',
				'message'      => sprintf( 'Successfully backed up %d sites as separate archives.', count( $site_ids ) ),
			);
		} catch ( \Exception $e ) {
			return array(
				'job_id'  => $job_id,
				'status'  => 'failed',
				'error'   => $e->getMessage(),
				'message' => 'Multisite backup failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Get site backup data.
	 *
	 * @param int $site_id Site ID.
	 * @return array Site data.
	 */
	private function get_site_backup_data( int $site_id ): array {
		$sites = $this->detector->get_network_sites();
		$site  = null;

		foreach ( $sites as $s ) {
			if ( $s['blog_id'] === $site_id ) {
				$site = $s;
				break;
			}
		}

		if ( ! $site ) {
			return array();
		}

		$tables       = $this->detector->get_site_tables( $site_id );
		$uploads_path = $this->detector->get_site_uploads_path( $site_id );
		$size         = $this->detector->estimate_site_size( $site_id );

		global $wpdb;

		// Determine table prefix for this site.
		// In multisite: site 1 uses base_prefix, others use base_prefix + site_id + '_'.
		$table_prefix = $site_id === 1
			? $wpdb->base_prefix
			: $wpdb->base_prefix . $site_id . '_';

		return array(
			'site_id'      => $site_id,
			'site_url'     => $site['site_url'],
			'site_name'    => $site['site_name'],
			'domain'       => $site['domain'],
			'path'         => $site['path'],
			'is_main'      => $site['is_main'],
			'table_prefix' => $table_prefix,
			'tables'       => $tables,
			'table_count'  => count( $tables ),
			'uploads_path' => $uploads_path,
			'size'         => $size,
		);
	}

	/**
	 * Backup shared tables.
	 *
	 * @param string $temp_dir Temporary directory.
	 * @return string|null Path to shared tables SQL file.
	 */
	public function backup_shared_tables( string $temp_dir ): ?string {
		global $wpdb;

		$shared_tables = $this->detector->get_shared_tables();

		if ( empty( $shared_tables ) ) {
			return null;
		}

		$sql_file = $temp_dir . '/network-shared.sql';

		// Export shared tables using basic mysqldump approach.
		$sql_content = "-- Swish Backup Pro - Shared Network Tables\n";
		$sql_content .= "-- Created: " . current_time( 'mysql' ) . "\n\n";

		foreach ( $shared_tables as $table ) {
			$sql_content .= "\n-- Table: {$table}\n";
			$sql_content .= "DROP TABLE IF EXISTS `{$table}`;\n";

			// Get table structure - always export even if table is empty.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );
			if ( $create_table ) {
				$sql_content .= $create_table[1] . ";\n\n";
			}

			// Get and add data rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

			if ( ! empty( $rows ) ) {
				// Add inserts (simplified version).
				foreach ( $rows as $row ) {
					$values = array_map(
						function ( $value ) use ( $wpdb ) {
							return null === $value ? 'NULL' : "'" . $wpdb->_real_escape( $value ) . "'";
						},
						$row
					);
					$sql_content .= "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n";
				}
			}
		}

		file_put_contents( $sql_file, $sql_content );

		return $sql_file;
	}

	/**
	 * Backup site database.
	 *
	 * @param int    $site_id  Site ID.
	 * @param string $temp_dir Temporary directory.
	 * @return string|null Path to site SQL file.
	 */
	public function backup_site_database( int $site_id, string $temp_dir ): ?string {
		global $wpdb;

		$site_tables = $this->detector->get_site_tables( $site_id );

		if ( empty( $site_tables ) ) {
			return null;
		}

		$sql_file = $temp_dir . '/site-' . $site_id . '-database.sql';

		// Export site tables.
		$sql_content = "-- Swish Backup Pro - Site {$site_id} Database\n";
		$sql_content .= "-- Created: " . current_time( 'mysql' ) . "\n\n";

		foreach ( $site_tables as $table ) {
			$sql_content .= "\n-- Table: {$table}\n";
			$sql_content .= "DROP TABLE IF EXISTS `{$table}`;\n";

			// Get table structure - always export even if table is empty.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );
			if ( $create_table ) {
				$sql_content .= $create_table[1] . ";\n\n";
			}

			// Get and add data rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

			if ( ! empty( $rows ) ) {
				// Add inserts.
				foreach ( $rows as $row ) {
					$values = array_map(
						function ( $value ) use ( $wpdb ) {
							return null === $value ? 'NULL' : "'" . $wpdb->_real_escape( $value ) . "'";
						},
						$row
					);
					$sql_content .= "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n";
				}
			}
		}

		file_put_contents( $sql_file, $sql_content );

		return $sql_file;
	}

	/**
	 * Create multisite manifest.
	 *
	 * @param string $job_id       Job ID.
	 * @param array  $site_ids     Site IDs.
	 * @param string $archive_mode Archive mode.
	 * @param array  $options      Options.
	 * @return array Manifest data.
	 */
	public function create_multisite_manifest( string $job_id, array $site_ids, string $archive_mode, array $options ): array {
		$network_info       = $this->detector->get_network_info();
		$sites_data         = array();
		$include_core_files = ! empty( $options['include_core_files'] );

		foreach ( $site_ids as $site_id ) {
			$sites_data[] = $this->get_site_backup_data( $site_id );
		}

		$shared_tables = $this->detector->get_shared_tables();

		$manifest = array(
			'version'              => SWISH_BACKUP_VERSION,
			'free_version'         => defined( 'SWISH_BACKUP_VERSION' ) ? SWISH_BACKUP_VERSION : null,
			'backup_type'          => 'multisite',
			'archive_mode'         => $archive_mode,
			'include_core_files'   => $include_core_files,
			'created_at'           => current_time( 'mysql', true ),
			'network'              => $network_info,
			'sites'                => $sites_data,
			'shared_tables'        => $shared_tables,
			'shared_database_file' => 'network-shared.sql',
			'options'              => $options,
		);

		// Add WordPress version info if core files are included.
		if ( $include_core_files ) {
			global $wp_version;
			$manifest['wordpress_version'] = $wp_version;
			$manifest['core_files_path']   = 'wp-core';
		}

		return $manifest;
	}

	/**
	 * Get temporary directory for backup.
	 *
	 * @param string $job_id Job ID.
	 * @return string Temporary directory path.
	 */
	private function get_temp_directory( string $job_id ): string {
		$temp_dir = sys_get_temp_dir() . '/swish-backup-' . $job_id;

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		return $temp_dir;
	}

	/**
	 * Generate multisite backup filename.
	 *
	 * @param string $mode Archive mode.
	 * @return string Filename.
	 */
	private function generate_multisite_backup_filename( string $mode ): string {
		$timestamp = gmdate( 'Y-m-d-His' );
		$site_url  = parse_url( get_site_url(), PHP_URL_HOST );

		return sanitize_file_name( $site_url . '-multisite-' . $mode . '-' . $timestamp . '.zip' );
	}

	/**
	 * Generate site backup filename.
	 *
	 * @param int    $site_id   Site ID.
	 * @param string $site_name Site name.
	 * @return string Filename.
	 */
	private function generate_site_backup_filename( int $site_id, string $site_name ): string {
		$timestamp = gmdate( 'Y-m-d-His' );
		$safe_name = sanitize_file_name( $site_name );

		return $safe_name . '-site-' . $site_id . '-' . $timestamp . '.zip';
	}

	/**
	 * Copy shared tables SQL file to target directory.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $target_dir Target directory.
	 * @return void
	 */
	private function copy_shared_tables( string $source_dir, string $target_dir ): void {
		$source_file = $source_dir . '/network-shared.sql';
		$target_file = $target_dir . '/network-shared.sql';

		if ( file_exists( $source_file ) ) {
			copy( $source_file, $target_file );
		}
	}

	/**
	 * Copy WordPress core files directory to target directory.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $target_dir Target directory.
	 * @return void
	 */
	private function copy_core_files( string $source_dir, string $target_dir ): void {
		$source_core = $source_dir . '/wp-core';
		$target_core = $target_dir . '/wp-core';

		if ( ! is_dir( $source_core ) ) {
			return;
		}

		// Create target core directory.
		wp_mkdir_p( $target_core );

		// Recursively copy.
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_core, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$relative_path = substr( $file->getRealPath(), strlen( $source_core ) + 1 );
			$target_path   = $target_core . '/' . $relative_path;

			if ( $file->isDir() ) {
				wp_mkdir_p( $target_path );
			} else {
				$target_path_dir = dirname( $target_path );
				if ( ! file_exists( $target_path_dir ) ) {
					wp_mkdir_p( $target_path_dir );
				}
				copy( $file->getRealPath(), $target_path );
			}
		}
	}

	/**
	 * Backup wp-content folders.
	 *
	 * @param string $temp_dir Temporary directory.
	 * @param array  $options  Backup options.
	 * @return void
	 */
	public function backup_wp_content_folders( string $temp_dir, array $options ): void {
		$wp_content = WP_CONTENT_DIR;
		$target_dir = $temp_dir . '/wp-content';

		wp_mkdir_p( $target_dir );

		// Backup themes.
		if ( ! empty( $options['include_themes'] ) && is_dir( $wp_content . '/themes' ) ) {
			$this->copy_directory( $wp_content . '/themes', $target_dir . '/themes' );
		}

		// Backup plugins.
		if ( ! empty( $options['include_plugins'] ) && is_dir( $wp_content . '/plugins' ) ) {
			$this->copy_directory( $wp_content . '/plugins', $target_dir . '/plugins' );
		}

		// Backup uploads.
		if ( ! empty( $options['include_uploads'] ) && is_dir( $wp_content . '/uploads' ) ) {
			$this->copy_directory( $wp_content . '/uploads', $target_dir . '/uploads' );
		}

		// Backup mu-plugins.
		if ( ! empty( $options['include_mu_plugins'] ) && is_dir( $wp_content . '/mu-plugins' ) ) {
			$this->copy_directory( $wp_content . '/mu-plugins', $target_dir . '/mu-plugins' );
		}
	}

	/**
	 * Copy directory recursively.
	 *
	 * @param string $source Source directory.
	 * @param string $dest   Destination directory.
	 * @return void
	 */
	private function copy_directory( string $source, string $dest ): void {
		if ( ! is_dir( $source ) ) {
			return;
		}

		wp_mkdir_p( $dest );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$relative_path = substr( $file->getRealPath(), strlen( $source ) + 1 );
			$target_path   = $dest . '/' . $relative_path;

			if ( $file->isDir() ) {
				wp_mkdir_p( $target_path );
			} else {
				$target_dir = dirname( $target_path );
				if ( ! file_exists( $target_dir ) ) {
					wp_mkdir_p( $target_dir );
				}
				copy( $file->getRealPath(), $target_path );
			}
		}
	}

	/**
	 * Check if any file backup options are enabled.
	 *
	 * @param array $options Backup options.
	 * @return bool True if any file options are enabled.
	 */
	private function includes_file_backup( array $options ): bool {
		return ! empty( $options['include_themes'] )
			|| ! empty( $options['include_plugins'] )
			|| ! empty( $options['include_uploads'] )
			|| ! empty( $options['include_mu_plugins'] );
	}

	/**
	 * Create ZIP archive.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $output_file Output ZIP file.
	 * @return bool True on success.
	 */
	private function create_archive( string $source_dir, string $output_file ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \Exception( 'ZipArchive class not available.' );
		}

		$zip = new \ZipArchive();

		if ( $zip->open( $output_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			throw new \Exception( 'Could not create ZIP archive.' );
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( ! $file->isDir() ) {
				$file_path     = $file->getRealPath();
				$relative_path = substr( $file_path, strlen( $source_dir ) + 1 );
				$zip->addFile( $file_path, $relative_path );
			}
		}

		$zip->close();

		return file_exists( $output_file );
	}

	/**
	 * Get WordPress core files to backup.
	 *
	 * @return array List of core file paths relative to ABSPATH.
	 */
	public function get_core_files(): array {
		$core_files = array();
		$abspath    = ABSPATH;

		// Core PHP files in root.
		$root_files = array(
			'index.php',
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

		foreach ( $root_files as $file ) {
			if ( file_exists( $abspath . $file ) ) {
				$core_files[] = $file;
			}
		}

		// wp-admin directory.
		$wp_admin = $abspath . 'wp-admin';
		if ( is_dir( $wp_admin ) ) {
			$core_files = array_merge( $core_files, $this->get_directory_files( $wp_admin, 'wp-admin' ) );
		}

		// wp-includes directory.
		$wp_includes = $abspath . 'wp-includes';
		if ( is_dir( $wp_includes ) ) {
			$core_files = array_merge( $core_files, $this->get_directory_files( $wp_includes, 'wp-includes' ) );
		}

		return $core_files;
	}

	/**
	 * Get all files from a directory recursively.
	 *
	 * @param string $dir     Directory path.
	 * @param string $prefix  Path prefix for relative paths.
	 * @return array List of relative file paths.
	 */
	private function get_directory_files( string $dir, string $prefix ): array {
		$files = array();

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$relative_path = $prefix . '/' . substr( $file->getRealPath(), strlen( $dir ) + 1 );
				$files[]       = str_replace( '\\', '/', $relative_path );
			}
		}

		return $files;
	}

	/**
	 * Backup WordPress core files.
	 *
	 * @param string $temp_dir Temporary directory.
	 * @return void
	 */
	public function backup_core_files( string $temp_dir ): void {
		$core_files = $this->get_core_files();
		$abspath    = ABSPATH;

		// Create wp-core directory in temp.
		$core_dir = $temp_dir . '/wp-core';
		wp_mkdir_p( $core_dir );

		foreach ( $core_files as $file ) {
			$source = $abspath . $file;
			$dest   = $core_dir . '/' . $file;

			// Create subdirectory if needed.
			$dest_dir = dirname( $dest );
			if ( ! file_exists( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			// Copy file.
			if ( file_exists( $source ) && is_file( $source ) ) {
				copy( $source, $dest );
			}
		}
	}

	/**
	 * Estimate WordPress core files size.
	 *
	 * @return int Size in bytes.
	 */
	public function estimate_core_files_size(): int {
		$size    = 0;
		$abspath = ABSPATH;

		// wp-admin size.
		if ( is_dir( $abspath . 'wp-admin' ) ) {
			$size += $this->get_directory_size( $abspath . 'wp-admin' );
		}

		// wp-includes size.
		if ( is_dir( $abspath . 'wp-includes' ) ) {
			$size += $this->get_directory_size( $abspath . 'wp-includes' );
		}

		// Root PHP files (small, approximate).
		$size += 500 * 1024; // ~500KB for root files.

		return $size;
	}

	/**
	 * Get directory size recursively.
	 *
	 * @param string $dir Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( string $dir ): int {
		$size = 0;

		if ( ! is_dir( $dir ) ) {
			return $size;
		}

		// Directories to exclude from size calculation.
		$exclude_dirs = array( 'swish-backups', 'cache', 'wflogs' );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					function ( $current, $key, $iterator ) use ( $exclude_dirs ) {
						// Skip excluded directories.
						if ( $current->isDir() && in_array( $current->getFilename(), $exclude_dirs, true ) ) {
							return false;
						}
						return true;
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			// Silently handle permission errors.
			return 0;
		}

		return $size;
	}

	/**
	 * Clean up temporary directory.
	 *
	 * @param string $temp_dir Temporary directory path.
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
}
