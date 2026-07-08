<?php
/**
 * Multisite Detector.
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
 * Detects and provides information about multisite environment.
 */
final class MultisiteDetector {

	/**
	 * Check if this is a multisite installation.
	 *
	 * @return bool True if multisite.
	 */
	public function is_multisite(): bool {
		return is_multisite();
	}

	/**
	 * Get all sites in the network.
	 *
	 * @param int $network_id Network ID (default: current network).
	 * @return array Array of site objects.
	 */
	public function get_network_sites( int $network_id = 0 ): array {
		if ( ! $this->is_multisite() ) {
			return array();
		}

		$args = array(
			'number'     => 9999,
			'orderby'    => 'id',
			'order'      => 'ASC',
			'network_id' => $network_id ?: get_current_network_id(),
		);

		$sites = get_sites( $args );

		// Enhance site data.
		$enhanced_sites = array();
		foreach ( $sites as $site ) {
			$enhanced_sites[] = array(
				'blog_id'    => (int) $site->blog_id,
				'site_id'    => (int) $site->site_id,
				'domain'     => $site->domain,
				'path'       => $site->path,
				'site_url'   => get_site_url( $site->blog_id ),
				'home_url'   => get_home_url( $site->blog_id ),
				'site_name'  => get_blog_details( $site->blog_id )->blogname,
				'registered' => $site->registered,
				'is_main'    => (int) $site->blog_id === 1,
			);
		}

		return $enhanced_sites;
	}

	/**
	 * Get shared database tables (common across all sites).
	 *
	 * @return array Array of shared table names.
	 */
	public function get_shared_tables(): array {
		global $wpdb;

		$shared_tables = array(
			$wpdb->users,
			$wpdb->usermeta,
		);

		// Add network-specific tables if multisite.
		if ( $this->is_multisite() ) {
			$shared_tables = array_merge(
				$shared_tables,
				array(
					$wpdb->blogs,
					$wpdb->blogmeta,
					$wpdb->site,
					$wpdb->sitemeta,
					$wpdb->sitecategories,
					$wpdb->registration_log,
					$wpdb->signups,
				)
			);
		}

		// Remove null values (some tables may not exist).
		$shared_tables = array_filter( $shared_tables );

		return array_values( $shared_tables );
	}

	/**
	 * Get site-specific database tables.
	 *
	 * @param int $site_id Site/Blog ID.
	 * @return array Array of table names for the site.
	 */
	public function get_site_tables( int $site_id ): array {
		global $wpdb;

		// Get the prefix for this site.
		$prefix = $this->get_site_prefix( $site_id );

		// Standard WordPress tables for a site.
		$tables = array(
			$prefix . 'commentmeta',
			$prefix . 'comments',
			$prefix . 'links',
			$prefix . 'options',
			$prefix . 'postmeta',
			$prefix . 'posts',
			$prefix . 'term_relationships',
			$prefix . 'term_taxonomy',
			$prefix . 'termmeta',
			$prefix . 'terms',
		);

		// Get all actual tables in database with this prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_tables = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		// Filter to only include tables that actually exist.
		$existing_tables = array_intersect( $tables, $all_tables );

		// Add any custom tables with this prefix.
		foreach ( $all_tables as $table ) {
			if ( ! in_array( $table, $existing_tables, true ) ) {
				// Exclude shared tables.
				if ( ! in_array( $table, $this->get_shared_tables(), true ) ) {
					$existing_tables[] = $table;
				}
			}
		}

		return array_values( $existing_tables );
	}

	/**
	 * Get table prefix for a specific site.
	 *
	 * @param int $site_id Site/Blog ID.
	 * @return string Table prefix.
	 */
	public function get_site_prefix( int $site_id ): string {
		global $wpdb;

		if ( $site_id === 1 ) {
			return $wpdb->base_prefix;
		}

		return $wpdb->base_prefix . $site_id . '_';
	}

	/**
	 * Get uploads directory path for a site.
	 *
	 * @param int $site_id Site/Blog ID.
	 * @return string Uploads directory path.
	 */
	public function get_site_uploads_path( int $site_id ): string {
		if ( ! $this->is_multisite() ) {
			return wp_upload_dir()['basedir'];
		}

		// Switch to the site to get correct upload dir.
		switch_to_blog( $site_id );
		$upload_dir = wp_upload_dir();
		restore_current_blog();

		return $upload_dir['basedir'];
	}

	/**
	 * Get site size estimation.
	 *
	 * @param int $site_id Site/Blog ID.
	 * @return array Size information.
	 */
	public function estimate_site_size( int $site_id ): array {
		global $wpdb;

		// Estimate database size.
		$tables = $this->get_site_tables( $site_id );
		$db_size = 0;

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
				$db_size += (int) $result->size;
			}
		}

		// Estimate uploads size.
		$uploads_path = $this->get_site_uploads_path( $site_id );
		$uploads_size = 0;

		if ( file_exists( $uploads_path ) && is_dir( $uploads_path ) ) {
			$uploads_size = $this->get_directory_size( $uploads_path );
		}

		return array(
			'database_size' => $db_size,
			'uploads_size'  => $uploads_size,
			'total_size'    => $db_size + $uploads_size,
		);
	}

	/**
	 * Get directory size recursively.
	 *
	 * @param string $path Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( string $path ): int {
		$size = 0;

		// Directories to exclude from size calculation.
		$exclude_dirs = array( 'swish-backups', 'cache', 'wflogs' );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
					function ( $current, $key, $iterator ) use ( $exclude_dirs ) {
						// Skip excluded directories.
						if ( $current->isDir() && in_array( $current->getFilename(), $exclude_dirs, true ) ) {
							return false;
						}
						return true;
					}
				)
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			// Silent fail - size estimation is not critical.
			return 0;
		}

		return $size;
	}

	/**
	 * Get network information.
	 *
	 * @return array Network data.
	 */
	public function get_network_info(): array {
		global $wpdb;

		if ( ! $this->is_multisite() ) {
			return array();
		}

		$network = get_network();

		return array(
			'network_id'   => (int) $network->id,
			'domain'       => $network->domain,
			'path'         => $network->path,
			'site_name'    => get_network()->site_name,
			'site_count'   => get_blog_count(),
			'is_subdomain' => is_subdomain_install(),
			'base_prefix'  => $wpdb->base_prefix,
		);
	}
}
