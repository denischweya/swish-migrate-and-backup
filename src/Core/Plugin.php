<?php
/**
 * Main Plugin bootstrap class.
 *
 * @package SwishMigrateAndBackup\Core
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Admin\AdminMenu;
use SwishMigrateAndBackup\Admin\Dashboard;
use SwishMigrateAndBackup\Admin\BackupsPage;
use SwishMigrateAndBackup\Admin\SettingsPage;
use SwishMigrateAndBackup\Admin\SchedulesPage;
use SwishMigrateAndBackup\Admin\MigrationPage;
use SwishMigrateAndBackup\Admin\ProPage;
use SwishMigrateAndBackup\Admin\DocumentationPage;
use SwishMigrateAndBackup\Api\RestController;
use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Backup\BackupState;
use SwishMigrateAndBackup\Backup\DatabaseBackup;
use SwishMigrateAndBackup\Backup\FileBackup;
use SwishMigrateAndBackup\Backup\BackupArchiver;
use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Migration\SearchReplace;
use SwishMigrateAndBackup\Queue\JobQueue;
use SwishMigrateAndBackup\Queue\Scheduler;
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Security\Encryption;
use SwishMigrateAndBackup\Storage\StorageManager;
use SwishMigrateAndBackup\Storage\LocalAdapter;
use SwishMigrateAndBackup\Storage\S3Adapter;
use SwishMigrateAndBackup\Storage\DropboxAdapter;
use SwishMigrateAndBackup\Storage\GoogleDriveAdapter;

/**
 * Plugin class responsible for bootstrapping all components.
 */
final class Plugin {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->maybe_upgrade();
		$this->register_services();
		$this->init_hooks();

		/**
		 * Fires after the plugin has been fully booted.
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'swish_backup_booted', $this->container );
	}

	/**
	 * Check if database needs upgrading and run migrations.
	 *
	 * @return void
	 */
	private function maybe_upgrade(): void {
		$current_version = get_option( 'swish_backup_db_version', '1.0.0' );

		// Upgrade to 1.0.2: Add BackupState table for file-based checkpoints.
		if ( version_compare( $current_version, '1.0.2', '<' ) ) {
			BackupState::create_table();
			update_option( 'swish_backup_db_version', '1.0.2' );
		}
	}

	/**
	 * Register all services in the container.
	 *
	 * @return void
	 */
	private function register_services(): void {
		// Core services.
		$this->container->singleton(
			Logger::class,
			fn( Container $c ) => new Logger()
		);

		$this->container->singleton(
			Encryption::class,
			fn( Container $c ) => new Encryption()
		);

		// Storage adapters.
		$this->container->singleton(
			LocalAdapter::class,
			fn( Container $c ) => new LocalAdapter( $c->get( Logger::class ) )
		);

		$this->container->singleton(
			S3Adapter::class,
			fn( Container $c ) => new S3Adapter(
				$c->get( Logger::class ),
				$c->get( Encryption::class )
			)
		);

		$this->container->singleton(
			DropboxAdapter::class,
			fn( Container $c ) => new DropboxAdapter(
				$c->get( Logger::class ),
				$c->get( Encryption::class )
			)
		);

		$this->container->singleton(
			GoogleDriveAdapter::class,
			fn( Container $c ) => new GoogleDriveAdapter(
				$c->get( Logger::class ),
				$c->get( Encryption::class )
			)
		);

		$this->container->singleton(
			StorageManager::class,
			fn( Container $c ) => new StorageManager( $c )
		);

		// Backup services.
		$this->container->singleton(
			DatabaseBackup::class,
			fn( Container $c ) => new DatabaseBackup( $c->get( Logger::class ) )
		);

		$this->container->singleton(
			FileBackup::class,
			fn( Container $c ) => new FileBackup( $c->get( Logger::class ) )
		);

		$this->container->singleton(
			BackupArchiver::class,
			fn( Container $c ) => new BackupArchiver( $c->get( Logger::class ) )
		);

		$this->container->singleton(
			BackupManager::class,
			fn( Container $c ) => new BackupManager(
				$c->get( DatabaseBackup::class ),
				$c->get( FileBackup::class ),
				$c->get( BackupArchiver::class ),
				$c->get( StorageManager::class ),
				$c->get( Logger::class )
			)
		);

		// Restore services.
		$this->container->singleton(
			RestoreManager::class,
			fn( Container $c ) => new RestoreManager(
				$c->get( StorageManager::class ),
				$c->get( Logger::class )
			)
		);

		// Migration services.
		$this->container->singleton(
			SearchReplace::class,
			fn( Container $c ) => new SearchReplace( $c->get( Logger::class ) )
		);

		$this->container->singleton(
			Migrator::class,
			fn( Container $c ) => new Migrator(
				$c->get( BackupManager::class ),
				$c->get( RestoreManager::class ),
				$c->get( SearchReplace::class ),
				$c->get( Logger::class )
			)
		);

		// Queue and scheduler.
		$this->container->singleton(
			JobQueue::class,
			fn( Container $c ) => new JobQueue( $c->get( Logger::class ) )
		);

		$this->container->singleton(
			Scheduler::class,
			fn( Container $c ) => new Scheduler(
				$c->get( BackupManager::class ),
				$c->get( Logger::class )
			)
		);

		// Admin pages.
		$this->container->singleton(
			Dashboard::class,
			fn( Container $c ) => new Dashboard(
				$c->get( BackupManager::class ),
				$c->get( StorageManager::class )
			)
		);

		$this->container->singleton(
			BackupsPage::class,
			fn( Container $c ) => new BackupsPage(
				$c->get( BackupManager::class ),
				$c->get( RestoreManager::class ),
				$c->get( StorageManager::class )
			)
		);

		$this->container->singleton(
			SettingsPage::class,
			fn( Container $c ) => new SettingsPage(
				$c->get( StorageManager::class ),
				$c->get( Encryption::class )
			)
		);

		$this->container->singleton(
			SchedulesPage::class,
			fn( Container $c ) => new SchedulesPage( $c->get( Scheduler::class ) )
		);

		$this->container->singleton(
			MigrationPage::class,
			fn( Container $c ) => new MigrationPage( $c->get( Migrator::class ) )
		);

		$this->container->singleton(
			ProPage::class,
			fn() => new ProPage()
		);

		$this->container->singleton(
			DocumentationPage::class,
			fn() => new DocumentationPage()
		);

		$this->container->singleton(
			AdminMenu::class,
			fn( Container $c ) => new AdminMenu(
				$c->get( Dashboard::class ),
				$c->get( BackupsPage::class ),
				$c->get( SettingsPage::class ),
				$c->get( SchedulesPage::class ),
				$c->get( MigrationPage::class ),
				$c->get( ProPage::class ),
				$c->get( DocumentationPage::class )
			)
		);

		// REST API.
		$this->container->singleton(
			RestController::class,
			fn( Container $c ) => new RestController(
				$c->get( BackupManager::class ),
				$c->get( RestoreManager::class ),
				$c->get( Migrator::class ),
				$c->get( StorageManager::class ),
				$c->get( JobQueue::class )
			)
		);

		/**
		 * Fires after all services have been registered.
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'swish_backup_services_registered', $this->container );
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Admin hooks.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this->container->get( AdminMenu::class ), 'register' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_filter( 'plugin_action_links_' . SWISH_BACKUP_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
		}

		// REST API.
		add_action( 'rest_api_init', array( $this->container->get( RestController::class ), 'register_routes' ) );

		// Cron scheduler.
		add_action( 'swish_backup_scheduled_backup', array( $this->container->get( Scheduler::class ), 'run_scheduled_backup' ) );

		// Async backup processor.
		add_action( 'swish_backup_process_async', array( $this->container->get( BackupManager::class ), 'process_async_backup' ) );

		// Backup continuation for chunked/timeout processing.
		add_action( 'swish_backup_continue', array( $this->container->get( BackupManager::class ), 'continue_backup' ) );

		// Register storage adapters.
		add_action( 'init', array( $this, 'register_storage_adapters' ) );

		// Handle backup file downloads.
		add_action( 'admin_init', array( $this, 'handle_backup_download' ) );
	}

	/**
	 * Handle backup file download requests.
	 *
	 * @return void
	 */
	public function handle_backup_download(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token validation is done via transient.
		if ( ! isset( $_GET['swish_download'] ) || ! isset( $_GET['file'] ) ) {
			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download backups.', 'swish-migrate-and-backup' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token = sanitize_text_field( wp_unslash( $_GET['swish_download'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = sanitize_text_field( wp_unslash( $_GET['file'] ) );

		// Get the stored download data.
		$transient_key = 'swish_backup_download_' . md5( $file );
		$download_data = get_transient( $transient_key );

		if ( ! $download_data || ! is_array( $download_data ) ) {
			wp_die( esc_html__( 'Download link has expired. Please generate a new one.', 'swish-migrate-and-backup' ), 403 );
		}

		// Validate the token.
		if ( ! hash_equals( $download_data['token'], $token ) ) {
			wp_die( esc_html__( 'Invalid download token.', 'swish-migrate-and-backup' ), 403 );
		}

		// Check expiry.
		if ( isset( $download_data['expiry'] ) && time() > $download_data['expiry'] ) {
			delete_transient( $transient_key );
			wp_die( esc_html__( 'Download link has expired. Please generate a new one.', 'swish-migrate-and-backup' ), 403 );
		}

		// Get the file path.
		$local_adapter = $this->container->get( LocalAdapter::class );
		$backup_dir    = $local_adapter->get_base_directory();
		$file_path     = $backup_dir . '/' . $file;

		// Validate the file path to prevent directory traversal.
		$real_backup_dir = realpath( $backup_dir );
		$real_file_path  = realpath( $file_path );

		if ( ! $real_file_path || strpos( $real_file_path, $real_backup_dir ) !== 0 ) {
			wp_die( esc_html__( 'Invalid file path.', 'swish-migrate-and-backup' ), 403 );
		}

		if ( ! file_exists( $real_file_path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'swish-migrate-and-backup' ), 404 );
		}

		// Delete the transient to prevent reuse.
		delete_transient( $transient_key );

		// Serve the file.
		$filename = basename( $real_file_path );
		$filesize = filesize( $real_file_path );

		// Clear any output buffers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers for download.
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $filesize );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Pragma: public' );

		// Output the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_file_path );
		exit;
	}

	/**
	 * Add action links to the plugins listing page.
	 *
	 * @param array<string> $links Existing plugin action links.
	 * @return array<string> Modified plugin action links.
	 */
	public function add_plugin_action_links( array $links ): array {
		$plugin_links = array(
			'dashboard' => '<a href="' . esc_url( admin_url( 'admin.php?page=swish-backup' ) ) . '">' . esc_html__( 'Dashboard', 'swish-migrate-and-backup' ) . '</a>',
			'settings'  => '<a href="' . esc_url( admin_url( 'admin.php?page=swish-backup-settings' ) ) . '">' . esc_html__( 'Settings', 'swish-migrate-and-backup' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Register default storage adapters.
	 *
	 * @return void
	 */
	public function register_storage_adapters(): void {
		$storage_manager = $this->container->get( StorageManager::class );

		$storage_manager->register_adapter( 'local', $this->container->get( LocalAdapter::class ) );
		$storage_manager->register_adapter( 's3', $this->container->get( S3Adapter::class ) );
		$storage_manager->register_adapter( 'dropbox', $this->container->get( DropboxAdapter::class ) );
		$storage_manager->register_adapter( 'googledrive', $this->container->get( GoogleDriveAdapter::class ) );

		/**
		 * Fires after storage adapters have been registered.
		 *
		 * @param StorageManager $storage_manager The storage manager instance.
		 */
		do_action( 'swish_backup_storage_registered', $storage_manager );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		// Only load on our plugin pages.
		if ( strpos( $hook_suffix, 'swish-backup' ) === false ) {
			return;
		}

		// Enqueue WordPress components for all pages.
		wp_enqueue_style( 'wp-components' );

		// Check if this is the main dashboard page.
		$is_dashboard = strpos( $hook_suffix, 'toplevel_page_swish-backup' ) !== false;

		// Always use the consolidated built CSS for all pages.
		$this->enqueue_built_styles();

		if ( $is_dashboard ) {
			// Enqueue React dashboard JS for the main page.
			$this->enqueue_react_dashboard_js();
		} else {
			// Enqueue legacy admin JS for other pages.
			$this->enqueue_legacy_js();
		}

		// Enqueue documentation page inline script.
		if ( strpos( $hook_suffix, 'swish-backup-docs' ) !== false ) {
			wp_add_inline_script(
				'swish-backup-admin',
				DocumentationPage::get_inline_script()
			);
		}
	}

	/**
	 * Enqueue built styles (consolidated CSS).
	 *
	 * @return void
	 */
	private function enqueue_built_styles(): void {
		$css_file   = SWISH_BACKUP_PLUGIN_DIR . 'build/index.css';
		$asset_file = SWISH_BACKUP_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $css_file ) ) {
			// Use file modification time for reliable cache busting.
			$version = SWISH_BACKUP_VERSION . '.' . filemtime( $css_file );

			wp_enqueue_style(
				'swish-backup-admin',
				SWISH_BACKUP_PLUGIN_URL . 'build/index.css',
				array( 'wp-components' ),
				$version
			);
		} elseif ( file_exists( SWISH_BACKUP_PLUGIN_DIR . 'assets/css/admin.css' ) ) {
			// Fallback to legacy CSS if build doesn't exist.
			$legacy_css = SWISH_BACKUP_PLUGIN_DIR . 'assets/css/admin.css';
			$version    = SWISH_BACKUP_VERSION . '.' . filemtime( $legacy_css );

			wp_enqueue_style(
				'swish-backup-admin',
				SWISH_BACKUP_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				$version
			);
		}
	}

	/**
	 * Enqueue React dashboard JS.
	 *
	 * @return void
	 */
	private function enqueue_react_dashboard_js(): void {
		$js_file    = SWISH_BACKUP_PLUGIN_DIR . 'build/index.js';
		$asset_file = SWISH_BACKUP_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $js_file ) && file_exists( $asset_file ) ) {
			$assets = include $asset_file;

			// Use file modification time for reliable cache busting.
			$version = SWISH_BACKUP_VERSION . '.' . filemtime( $js_file );

			wp_enqueue_script(
				'swish-backup-dashboard',
				SWISH_BACKUP_PLUGIN_URL . 'build/index.js',
				$assets['dependencies'] ?? array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				$version,
				true
			);

			// Localize script with data.
			wp_localize_script(
				'swish-backup-dashboard',
				'swishBackupData',
				array(
					'apiUrl'      => rest_url( 'swish-backup/v1' ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'proUrl'      => SWISH_BACKUP_PRO_URL,
					'isProActive' => apply_filters( 'swish_backup_is_pro', false ),
				)
			);
		} else {
			// Fallback to legacy JS if React build doesn't exist.
			$this->enqueue_legacy_js();
		}
	}

	/**
	 * Enqueue legacy admin JS.
	 *
	 * @return void
	 */
	private function enqueue_legacy_js(): void {
		$js_file = SWISH_BACKUP_PLUGIN_DIR . 'assets/js/admin.js';
		$version = SWISH_BACKUP_VERSION;

		if ( file_exists( $js_file ) ) {
			$version = SWISH_BACKUP_VERSION . '.' . filemtime( $js_file );
		}

		wp_enqueue_script(
			'swish-backup-admin',
			SWISH_BACKUP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-api-fetch' ),
			$version,
			true
		);

		wp_localize_script(
			'swish-backup-admin',
			'swishBackup',
			array(
				'apiUrl'         => rest_url( 'swish-backup/v1' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'proUrl'         => SWISH_BACKUP_PRO_URL,
				'isProActive'    => apply_filters( 'swish_backup_is_pro', false ),
				'maxUploadSize'  => wp_max_upload_size(),
				'maxUploadSizeFormatted' => size_format( wp_max_upload_size() ),
				'postMaxSize'    => $this->get_post_max_size(),
				'postMaxSizeFormatted' => size_format( $this->get_post_max_size() ),
				'i18n'           => array(
					'backupStarted'   => __( 'Backup started...', 'swish-migrate-and-backup' ),
					'backupComplete'  => __( 'Backup completed successfully!', 'swish-migrate-and-backup' ),
					'backupFailed'    => __( 'Backup failed. Check the logs.', 'swish-migrate-and-backup' ),
					'restoreStarted'  => __( 'Restore started...', 'swish-migrate-and-backup' ),
					'restoreComplete' => __( 'Restore completed successfully!', 'swish-migrate-and-backup' ),
					'restoreFailed'   => __( 'Restore failed. Check the logs.', 'swish-migrate-and-backup' ),
					'confirmDelete'   => __( 'Are you sure you want to delete this backup?', 'swish-migrate-and-backup' ),
					'confirmRestore'  => __( 'Are you sure you want to restore this backup? This will overwrite your current site.', 'swish-migrate-and-backup' ),
					'fileTooLarge'    => __( 'The selected file is too large for your server.', 'swish-migrate-and-backup' ),
				),
			)
		);
	}

	/**
	 * Get post_max_size in bytes.
	 *
	 * @return int Post max size in bytes.
	 */
	private function get_post_max_size(): int {
		$post_max = ini_get( 'post_max_size' );

		if ( empty( $post_max ) ) {
			return 0;
		}

		$value = (int) $post_max;
		$unit  = strtoupper( substr( $post_max, -1 ) );

		switch ( $unit ) {
			case 'G':
				$value *= 1024;
				// Fall through.
			case 'M':
				$value *= 1024;
				// Fall through.
			case 'K':
				$value *= 1024;
		}

		return $value;
	}
}
