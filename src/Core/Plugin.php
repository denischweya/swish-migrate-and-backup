<?php
/**
 * Main Plugin bootstrap class.
 *
 * @package SwishMigrateAndBackup\Core
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Core;

use SwishMigrateAndBackup\Admin\AdminMenu;
use SwishMigrateAndBackup\Admin\Dashboard;
use SwishMigrateAndBackup\Admin\BackupsPage;
use SwishMigrateAndBackup\Admin\SettingsPage;
use SwishMigrateAndBackup\Admin\SchedulesPage;
use SwishMigrateAndBackup\Admin\MigrationPage;
use SwishMigrateAndBackup\Api\RestController;
use SwishMigrateAndBackup\Backup\BackupManager;
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
			AdminMenu::class,
			fn( Container $c ) => new AdminMenu(
				$c->get( Dashboard::class ),
				$c->get( BackupsPage::class ),
				$c->get( SettingsPage::class ),
				$c->get( SchedulesPage::class ),
				$c->get( MigrationPage::class )
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
		}

		// REST API.
		add_action( 'rest_api_init', array( $this->container->get( RestController::class ), 'register_routes' ) );

		// Cron scheduler.
		add_action( 'swish_backup_scheduled_backup', array( $this->container->get( Scheduler::class ), 'run_scheduled_backup' ) );

		// Register storage adapters.
		add_action( 'init', array( $this, 'register_storage_adapters' ) );
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
	}

	/**
	 * Enqueue built styles (consolidated CSS).
	 *
	 * @return void
	 */
	private function enqueue_built_styles(): void {
		$asset_file = SWISH_BACKUP_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$assets = include $asset_file;

			wp_enqueue_style(
				'swish-backup-admin',
				SWISH_BACKUP_PLUGIN_URL . 'build/style-index.css',
				array( 'wp-components' ),
				$assets['version'] ?? SWISH_BACKUP_VERSION
			);
		} else {
			// Fallback to legacy CSS if build doesn't exist.
			wp_enqueue_style(
				'swish-backup-admin',
				SWISH_BACKUP_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				SWISH_BACKUP_VERSION
			);
		}
	}

	/**
	 * Enqueue React dashboard JS.
	 *
	 * @return void
	 */
	private function enqueue_react_dashboard_js(): void {
		$asset_file = SWISH_BACKUP_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$assets = include $asset_file;

			wp_enqueue_script(
				'swish-backup-dashboard',
				SWISH_BACKUP_PLUGIN_URL . 'build/index.js',
				$assets['dependencies'] ?? array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				$assets['version'] ?? SWISH_BACKUP_VERSION,
				true
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
		wp_enqueue_script(
			'swish-backup-admin',
			SWISH_BACKUP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-api-fetch' ),
			SWISH_BACKUP_VERSION,
			true
		);

		wp_localize_script(
			'swish-backup-admin',
			'swishBackup',
			array(
				'apiUrl'   => rest_url( 'swish-backup/v1' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'backupStarted'   => __( 'Backup started...', 'swish-migrate-and-backup' ),
					'backupComplete'  => __( 'Backup completed successfully!', 'swish-migrate-and-backup' ),
					'backupFailed'    => __( 'Backup failed. Check the logs.', 'swish-migrate-and-backup' ),
					'restoreStarted'  => __( 'Restore started...', 'swish-migrate-and-backup' ),
					'restoreComplete' => __( 'Restore completed successfully!', 'swish-migrate-and-backup' ),
					'restoreFailed'   => __( 'Restore failed. Check the logs.', 'swish-migrate-and-backup' ),
					'confirmDelete'   => __( 'Are you sure you want to delete this backup?', 'swish-migrate-and-backup' ),
					'confirmRestore'  => __( 'Are you sure you want to restore this backup? This will overwrite your current site.', 'swish-migrate-and-backup' ),
				),
			)
		);
	}
}
