<?php
/**
 * Migrator - Handles site migrations.
 *
 * @package SwishMigrateAndBackup\Migration
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Migration;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Restore\RestoreManager;

/**
 * Orchestrates site migration operations.
 */
final class Migrator {

	/**
	 * Backup manager.
	 *
	 * @var BackupManager
	 */
	private BackupManager $backup_manager;

	/**
	 * Restore manager.
	 *
	 * @var RestoreManager
	 */
	private RestoreManager $restore_manager;

	/**
	 * Search and replace handler.
	 *
	 * @var SearchReplace
	 */
	private SearchReplace $search_replace;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param BackupManager  $backup_manager  Backup manager.
	 * @param RestoreManager $restore_manager Restore manager.
	 * @param SearchReplace  $search_replace  Search and replace handler.
	 * @param Logger         $logger          Logger instance.
	 */
	public function __construct(
		BackupManager $backup_manager,
		RestoreManager $restore_manager,
		SearchReplace $search_replace,
		Logger $logger
	) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->search_replace  = $search_replace;
		$this->logger          = $logger;
	}

	/**
	 * Import and migrate from a backup file.
	 *
	 * @param string $backup_path Path to backup file.
	 * @param array  $options     Migration options.
	 * @return array Migration result.
	 */
	public function import_and_migrate( string $backup_path, array $options = array() ): array {
		$this->logger->info( 'Starting migration import', array(
			'backup' => $backup_path,
			'options' => $options,
		) );

		try {
			// Verify backup.
			$backup_info = $this->restore_manager->get_backup_info( $backup_path );
			if ( ! $backup_info ) {
				throw new \RuntimeException( 'Invalid backup file' );
			}

			// Create pre-migration backup if requested.
			if ( $options['create_backup'] ?? true ) {
				$this->logger->info( 'Creating pre-migration backup...' );
				$pre_backup = $this->backup_manager->create_full_backup( array(
					'storage_destinations' => array( 'local' ),
				) );

				if ( ! $pre_backup ) {
					throw new \RuntimeException( 'Failed to create pre-migration backup' );
				}
			}

			// Restore from backup.
			$this->logger->info( 'Restoring from backup...' );
			$restore_result = $this->restore_manager->restore( $backup_path, array(
				'restore_database'  => $options['restore_database'] ?? true,
				'restore_files'     => $options['restore_files'] ?? true,
				'restore_wp_config' => $options['restore_wp_config'] ?? false,
			) );

			if ( ! $restore_result ) {
				throw new \RuntimeException( 'Restore failed' );
			}

			// Perform URL replacement if needed.
			$replace_result = null;
			if ( ! empty( $options['old_url'] ) && ! empty( $options['new_url'] ) ) {
				$this->logger->info( 'Performing URL replacement...', array(
					'old_url' => $options['old_url'],
					'new_url' => $options['new_url'],
				) );

				$replace_result = $this->replace_urls(
					$options['old_url'],
					$options['new_url']
				);
			}

			// Flush caches.
			$this->flush_all_caches();

			$result = array(
				'success'        => true,
				'backup_info'    => $backup_info,
				'url_replacement' => $replace_result,
			);

			$this->logger->info( 'Migration completed successfully', $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Migration failed: ' . $e->getMessage() );

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Replace URLs in the database.
	 *
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @param array  $tables  Tables to process (empty for all).
	 * @return array Replacement result.
	 */
	public function replace_urls( string $old_url, string $new_url, array $tables = array() ): array {
		$this->logger->info( 'Starting URL replacement', array(
			'old_url' => $old_url,
			'new_url' => $new_url,
		) );

		// Normalize URLs.
		$old_url = rtrim( $old_url, '/' );
		$new_url = rtrim( $new_url, '/' );

		// Generate all replacement variations.
		$replacements = $this->search_replace->generate_url_replacements( $old_url, $new_url );

		// Also handle path changes.
		$old_path = wp_parse_url( $old_url, PHP_URL_PATH ) ?: '';
		$new_path = wp_parse_url( $new_url, PHP_URL_PATH ) ?: '';

		if ( $old_path !== $new_path ) {
			$replacements[ $old_path ] = $new_path;
		}

		// Run replacements.
		$result = $this->search_replace->run_multiple( $replacements, $tables );

		// Update WordPress options.
		$this->update_wp_options( $new_url );

		$this->logger->info( 'URL replacement completed', $result );

		return $result;
	}

	/**
	 * Preview URL replacement.
	 *
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @param int    $limit   Maximum matches to return.
	 * @return array Preview results.
	 */
	public function preview_url_replacement( string $old_url, string $new_url, int $limit = 50 ): array {
		$old_url = rtrim( $old_url, '/' );
		$new_url = rtrim( $new_url, '/' );

		return $this->search_replace->dry_run( $old_url, $new_url, array(), $limit );
	}

	/**
	 * Run custom search and replace.
	 *
	 * @param string $search  Search string.
	 * @param string $replace Replace string.
	 * @param array  $tables  Tables to process.
	 * @return array Result.
	 */
	public function custom_search_replace( string $search, string $replace, array $tables = array() ): array {
		$this->logger->info( 'Starting custom search and replace', array(
			'search'  => $search,
			'replace' => $replace,
		) );

		return $this->search_replace->run( $search, $replace, $tables );
	}

	/**
	 * Export site for migration.
	 *
	 * @param array $options Export options.
	 * @return array|null Export result or null on failure.
	 */
	public function export_for_migration( array $options = array() ): ?array {
		$this->logger->info( 'Creating migration export' );

		$backup = $this->backup_manager->create_full_backup( array(
			'backup_database'      => true,
			'backup_core_files'    => $options['include_core'] ?? false,
			'backup_plugins'       => $options['include_plugins'] ?? true,
			'backup_themes'        => $options['include_themes'] ?? true,
			'backup_uploads'       => $options['include_uploads'] ?? true,
			'storage_destinations' => array( 'local' ),
		) );

		if ( ! $backup ) {
			return null;
		}

		// Add migration-specific metadata.
		$backup['migration_info'] = array(
			'source_url'     => get_site_url(),
			'source_home'    => get_home_url(),
			'table_prefix'   => $GLOBALS['wpdb']->prefix,
			'wp_version'     => get_bloginfo( 'version' ),
			'export_time'    => current_time( 'mysql', true ),
		);

		return $backup;
	}

	/**
	 * Get migration analysis.
	 *
	 * @param string $backup_path Path to backup file.
	 * @return array|null Analysis or null on error.
	 */
	public function analyze_backup( string $backup_path ): ?array {
		$info = $this->restore_manager->get_backup_info( $backup_path );

		if ( ! $info ) {
			return null;
		}

		$current_url = get_site_url();
		$backup_url = $info['site_url'] ?? '';

		$analysis = array(
			'backup'         => $info,
			'current_site'   => array(
				'url'         => $current_url,
				'home_url'    => get_home_url(),
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
			),
			'url_change'     => $backup_url !== $current_url,
			'backup_url'     => $backup_url,
			'warnings'       => array(),
			'recommendations' => array(),
		);

		// Check for version differences.
		if ( isset( $info['wordpress_version'] ) ) {
			$backup_version = $info['wordpress_version'];
			$current_version = get_bloginfo( 'version' );

			if ( version_compare( $backup_version, $current_version, '>' ) ) {
				$analysis['warnings'][] = sprintf(
					/* translators: 1: backup version, 2: current version */
					__( 'Backup is from WordPress %1$s, but you are running %2$s. Consider upgrading first.', 'swish-migrate-and-backup' ),
					$backup_version,
					$current_version
				);
			}
		}

		// Check for URL changes.
		if ( $analysis['url_change'] ) {
			$analysis['recommendations'][] = __( 'URL replacement will be performed to update all references.', 'swish-migrate-and-backup' );
		}

		// Check file size.
		if ( isset( $info['file_size'] ) && $info['file_size'] > 500 * 1024 * 1024 ) {
			$analysis['warnings'][] = __( 'Large backup file. Import may take some time.', 'swish-migrate-and-backup' );
		}

		return $analysis;
	}

	/**
	 * Validate migration settings.
	 *
	 * @param array $settings Migration settings.
	 * @return array Validation result.
	 */
	public function validate_settings( array $settings ): array {
		$errors = array();
		$warnings = array();

		// Validate URLs.
		if ( ! empty( $settings['old_url'] ) ) {
			if ( ! filter_var( $settings['old_url'], FILTER_VALIDATE_URL ) ) {
				$errors[] = __( 'Old URL is not valid.', 'swish-migrate-and-backup' );
			}
		}

		if ( ! empty( $settings['new_url'] ) ) {
			if ( ! filter_var( $settings['new_url'], FILTER_VALIDATE_URL ) ) {
				$errors[] = __( 'New URL is not valid.', 'swish-migrate-and-backup' );
			}
		}

		// Check if URLs are the same.
		if ( ! empty( $settings['old_url'] ) && ! empty( $settings['new_url'] ) ) {
			if ( rtrim( $settings['old_url'], '/' ) === rtrim( $settings['new_url'], '/' ) ) {
				$warnings[] = __( 'Old and new URLs are the same. No URL replacement will be performed.', 'swish-migrate-and-backup' );
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Update WordPress options after migration.
	 *
	 * @param string $new_url New site URL.
	 * @return void
	 */
	private function update_wp_options( string $new_url ): void {
		// Update site URL and home.
		update_option( 'siteurl', $new_url );
		update_option( 'home', $new_url );

		$this->logger->info( 'WordPress options updated', array( 'new_url' => $new_url ) );
	}

	/**
	 * Flush all caches.
	 *
	 * @return void
	 */
	private function flush_all_caches(): void {
		// Flush rewrite rules.
		flush_rewrite_rules();

		// Flush object cache.
		wp_cache_flush();

		// Clear any transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );

		// Clear opcache if available.
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		$this->logger->info( 'All caches flushed' );
	}

	/**
	 * Get search replace instance for custom operations.
	 *
	 * @return SearchReplace
	 */
	public function get_search_replace(): SearchReplace {
		return $this->search_replace;
	}
}
