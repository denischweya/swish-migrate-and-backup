<?php
/**
 * Plugin activator.
 *
 * @package SwishMigrateAndBackup\Core
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Core;

/**
 * Handles plugin activation tasks.
 */
final class Activator {

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->check_requirements();
		$this->create_backup_directory();
		$this->create_database_tables();
		$this->set_default_options();
		$this->schedule_cron_events();

		// Set activation flag for welcome screen.
		set_transient( 'swish_backup_activated', true, 30 );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Check system requirements.
	 *
	 * @return void
	 */
	private function check_requirements(): void {
		// Check PHP version.
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			wp_die(
				esc_html__( 'Swish Migrate and Backup requires PHP 8.1 or higher.', 'swish-migrate-and-backup' ),
				esc_html__( 'Plugin Activation Error', 'swish-migrate-and-backup' ),
				array( 'back_link' => true )
			);
		}

		// Check WordPress version.
		global $wp_version;
		if ( version_compare( $wp_version, '6.0', '<' ) ) {
			wp_die(
				esc_html__( 'Swish Migrate and Backup requires WordPress 6.0 or higher.', 'swish-migrate-and-backup' ),
				esc_html__( 'Plugin Activation Error', 'swish-migrate-and-backup' ),
				array( 'back_link' => true )
			);
		}

		// Check for required PHP extensions.
		$required_extensions = array( 'zip', 'json', 'mysqli' );
		$missing_extensions  = array();

		foreach ( $required_extensions as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing_extensions[] = $extension;
			}
		}

		if ( ! empty( $missing_extensions ) ) {
			wp_die(
				sprintf(
					/* translators: %s: comma-separated list of PHP extensions */
					esc_html__( 'Swish Migrate and Backup requires the following PHP extensions: %s', 'swish-migrate-and-backup' ),
					esc_html( implode( ', ', $missing_extensions ) )
				),
				esc_html__( 'Plugin Activation Error', 'swish-migrate-and-backup' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Create the backup storage directory.
	 *
	 * @return void
	 */
	private function create_backup_directory(): void {
		$upload_dir  = wp_upload_dir();
		$backup_dir  = $upload_dir['basedir'] . '/swish-backups';
		$temp_dir    = $backup_dir . '/temp';
		$logs_dir    = $backup_dir . '/logs';

		// Create directories.
		foreach ( array( $backup_dir, $temp_dir, $logs_dir ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		// Protect backup directory with .htaccess.
		$htaccess_content = "Order deny,allow\nDeny from all";
		$htaccess_file    = $backup_dir . '/.htaccess';

		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_file, $htaccess_content );
		}

		// Add index.php for additional protection.
		$index_content = '<?php // Silence is golden.';
		$index_file    = $backup_dir . '/index.php';

		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_file, $index_content );
		}

		// Store backup directory path.
		update_option( 'swish_backup_directory', $backup_dir );
	}

	/**
	 * Create required database tables.
	 *
	 * @return void
	 */
	private function create_database_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Backup jobs table.
		$jobs_table = $wpdb->prefix . 'swish_backup_jobs';
		$jobs_sql   = "CREATE TABLE {$jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id varchar(64) NOT NULL,
			type varchar(32) NOT NULL DEFAULT 'full',
			status varchar(32) NOT NULL DEFAULT 'pending',
			progress int(3) NOT NULL DEFAULT 0,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			file_path varchar(512) DEFAULT NULL,
			file_size bigint(20) unsigned DEFAULT 0,
			checksum varchar(64) DEFAULT NULL,
			manifest longtext DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY job_id (job_id),
			KEY status (status),
			KEY type (type),
			KEY created_at (created_at)
		) {$charset_collate};";

		// Backup logs table.
		$logs_table = $wpdb->prefix . 'swish_backup_logs';
		$logs_sql   = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id varchar(64) DEFAULT NULL,
			level varchar(16) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY job_id (job_id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		// Schedules table.
		$schedules_table = $wpdb->prefix . 'swish_backup_schedules';
		$schedules_sql   = "CREATE TABLE {$schedules_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(128) NOT NULL,
			frequency varchar(32) NOT NULL DEFAULT 'daily',
			backup_type varchar(32) NOT NULL DEFAULT 'full',
			storage_destinations varchar(512) NOT NULL DEFAULT 'local',
			retention_count int(11) NOT NULL DEFAULT 5,
			next_run datetime DEFAULT NULL,
			last_run datetime DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			options longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY frequency (frequency),
			KEY is_active (is_active),
			KEY next_run (next_run)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $jobs_sql );
		dbDelta( $logs_sql );
		dbDelta( $schedules_sql );

		// Store database version for future migrations.
		update_option( 'swish_backup_db_version', '1.0.0' );
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private function set_default_options(): void {
		$defaults = array(
			'swish_backup_settings' => array(
				'default_storage'       => 'local',
				'compression_level'     => 6,
				'chunk_size'            => 5 * 1024 * 1024, // 5MB.
				'max_execution_time'    => 300,
				'exclude_files'         => array(
					'*.log',
					'*.tmp',
					'cache/*',
					'wp-content/cache/*',
					'wp-content/uploads/swish-backups/*',
				),
				'exclude_tables'        => array(),
				'email_notifications'   => false,
				'notification_email'    => get_option( 'admin_email' ),
				'backup_core_files'     => true,
				'backup_plugins'        => true,
				'backup_themes'         => true,
				'backup_uploads'        => true,
				'backup_database'       => true,
			),
		);

		foreach ( $defaults as $option_name => $option_value ) {
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $option_value );
			}
		}
	}

	/**
	 * Schedule cron events.
	 *
	 * @return void
	 */
	private function schedule_cron_events(): void {
		// Add custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Schedule cleanup task.
		if ( ! wp_next_scheduled( 'swish_backup_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'swish_backup_cleanup' );
		}
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		$schedules['swish_backup_weekly'] = array(
			'interval' => 604800,
			'display'  => __( 'Once Weekly', 'swish-migrate-and-backup' ),
		);

		$schedules['swish_backup_monthly'] = array(
			'interval' => 2592000,
			'display'  => __( 'Once Monthly', 'swish-migrate-and-backup' ),
		);

		return $schedules;
	}
}
