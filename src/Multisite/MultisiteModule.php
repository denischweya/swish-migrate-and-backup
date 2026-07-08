<?php
/**
 * Multisite Module Bootstrap.
 *
 * Wires up the multisite backup, migration, and site duplication features
 * (formerly the Pro add-on) into the main plugin.
 *
 * @package SwishMigrateAndBackup\Multisite
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Api\MultisiteRestController;
use SwishMigrateAndBackup\Admin\Multisite\SiteSelectorUI;
use SwishMigrateAndBackup\Admin\Multisite\BackupHistoryUI;
use SwishMigrateAndBackup\Admin\Multisite\MigrationUI;
use SwishMigrateAndBackup\Admin\Multisite\ProgressModal;
use SwishMigrateAndBackup\Admin\Multisite\AdminLayout;
use SwishMigrateAndBackup\Admin\Multisite\DuplicateUI;

/**
 * Multisite Module Class.
 */
final class MultisiteModule {

	/**
	 * Multisite detector.
	 *
	 * @var MultisiteDetector
	 */
	private MultisiteDetector $detector;

	/**
	 * Multisite manager.
	 *
	 * @var MultisiteManager
	 */
	private MultisiteManager $manager;

	/**
	 * Site selector UI.
	 *
	 * @var SiteSelectorUI
	 */
	private SiteSelectorUI $site_selector;

	/**
	 * Backup history UI.
	 *
	 * @var BackupHistoryUI
	 */
	private BackupHistoryUI $backup_history;

	/**
	 * Migration handler.
	 *
	 * @var MultisiteMigration
	 */
	private MultisiteMigration $migration;

	/**
	 * Migration UI.
	 *
	 * @var MigrationUI
	 */
	private MigrationUI $migration_ui;

	/**
	 * Progress modal.
	 *
	 * @var ProgressModal
	 */
	private ProgressModal $progress_modal;

	/**
	 * Site duplicator.
	 *
	 * @var SiteDuplicator
	 */
	private SiteDuplicator $site_duplicator;

	/**
	 * Duplicate UI.
	 *
	 * @var DuplicateUI
	 */
	private DuplicateUI $duplicate_ui;

	/**
	 * Boot the multisite module.
	 *
	 * @return void
	 */
	public function boot(): void {
		// Check for database upgrades.
		$this->maybe_upgrade_database();

		// Initialize services.
		$this->init_services();

		// Add hooks to enhance free plugin.
		add_action( 'init', array( $this, 'enhance_free_plugin' ), 20 );

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Enqueue multisite admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Add network admin menu items (multisite only).
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_admin_menu' ), 100 );
		}

		// Add migration menu for single-site WordPress (to import multisite backups).
		if ( ! is_multisite() ) {
			add_action( 'admin_menu', array( $this, 'add_single_site_migration_menu' ), 100 );
		}

		// AJAX handlers for multisite backup.
		add_action( 'wp_ajax_swish_backup_start_multisite_backup', array( $this, 'handle_start_multisite_backup' ) );
		add_action( 'wp_ajax_swish_backup_start_multisite_backup_async', array( $this, 'handle_start_multisite_backup_async' ) );
		add_action( 'wp_ajax_swish_backup_check_progress', array( $this, 'handle_check_progress' ) );
		add_action( 'wp_ajax_swish_backup_delete_multisite_backup', array( $this, 'handle_delete_multisite_backup' ) );

		// AJAX handlers for migration.
		add_action( 'wp_ajax_swish_backup_validate_multisite_import', array( $this, 'handle_validate_import' ) );
		add_action( 'wp_ajax_swish_backup_import_multisite', array( $this, 'handle_import_multisite' ) );
		add_action( 'wp_ajax_swish_backup_import_multisite_async', array( $this, 'handle_import_multisite_async' ) );
		add_action( 'wp_ajax_swish_backup_check_import_progress', array( $this, 'handle_check_import_progress' ) );
		add_action( 'wp_ajax_swish_backup_preview_search_replace', array( $this, 'handle_preview_search_replace' ) );

		// AJAX handler for theme preference.
		add_action( 'wp_ajax_swish_backup_save_theme', array( $this, 'handle_save_theme' ) );

		// AJAX handlers for site duplication.
		add_action( 'wp_ajax_swish_backup_duplicate_site_async', array( $this, 'handle_duplicate_site_async' ) );
		add_action( 'wp_ajax_swish_backup_check_duplicate_progress', array( $this, 'handle_check_duplicate_progress' ) );
		add_action( 'wp_ajax_swish_backup_delete_site', array( $this, 'handle_delete_site' ) );
		add_action( 'wp_ajax_swish_backup_check_slug_available', array( $this, 'handle_check_slug_available' ) );

		// WP Cron hook for background backups.
		add_action( 'swish_backup_run_multisite_backup', array( $this, 'run_background_backup' ) );

		// WP Cron hook for background imports.
		add_action( 'swish_backup_run_import', array( $this, 'run_import_job' ) );

		// WP Cron hook for background site duplication.
		add_action( 'swish_backup_run_duplicate_site', array( $this, 'run_duplicate_job' ) );

		// Add backup history to free plugin's admin page.
		add_action( 'swish_backup_admin_page_after_content', array( $this, 'render_backup_history_on_main_page' ) );
	}

	/**
	 * Maybe upgrade database schema.
	 *
	 * @return void
	 */
	private function maybe_upgrade_database(): void {
		global $wpdb;

		$current_version = get_option( 'swish_backup_pro_db_version', '0' );
		$table = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// Check if table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		// If table doesn't exist, create the multisite tables.
		if ( ! $table_exists ) {
			\SwishMigrateAndBackup\Core\Activator::create_multisite_tables();
			return;
		}

		// Upgrade to 1.0.1 - Add backup_files column.
		if ( version_compare( $current_version, '1.0.1', '<' ) ) {
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

			// Update version.
			update_option( 'swish_backup_pro_db_version', '1.0.1' );
		}
	}

	/**
	 * Initialize services.
	 *
	 * @return void
	 */
	private function init_services(): void {
		$this->detector       = new MultisiteDetector();
		$network_backup       = new NetworkBackup( $this->detector );
		$this->manager        = new MultisiteManager( $this->detector, $network_backup );
		$this->site_selector  = new SiteSelectorUI( $this->manager );
		$this->backup_history = new BackupHistoryUI( $this->manager );
		$this->migration        = new MultisiteMigration( $this->detector );
		$this->migration_ui     = new MigrationUI( $this->migration, $this->manager );
		$this->progress_modal   = new ProgressModal();
		$this->site_duplicator  = new SiteDuplicator( $this->detector );
		$this->duplicate_ui     = new DuplicateUI( $this->detector );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controller = new MultisiteRestController( $this->manager, $this->detector );
		$controller->register_routes();
	}

	/**
	 * Register back-compat filters formerly provided by the Pro add-on.
	 *
	 * Size limits are now unrestricted by default in the main plugin; these
	 * filters remain only for third-party code that reads them.
	 *
	 * @return void
	 */
	public function enhance_free_plugin(): void {
		// Back-compat: multisite capability flag.
		add_filter( 'swish_backup_has_multisite', '__return_true' );

		// Back-compat: plugin info additions.
		add_filter(
			'swish_backup_plugin_info',
			function ( array $info ): array {
				$info['pro_version'] = SWISH_BACKUP_VERSION;
				$info['has_pro']     = true;
				$info['multisite']   = is_multisite();
				return $info;
			}
		);
	}

	/**
	 * Enqueue multisite admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on Swish Backup pages (including network admin).
		$is_swish_page = strpos( $hook, 'swish-backup' ) !== false
			|| strpos( $hook, 'swish-multisite' ) !== false
			|| strpos( $hook, 'swish-migration' ) !== false;

		if ( ! $is_swish_page ) {
			return;
		}

		// Enqueue Google Fonts - Inter.
		wp_enqueue_style(
			'swish-backup-font-inter',
			'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap',
			array(),
			null
		);

		// Enqueue Material Symbols icons.
		wp_enqueue_style(
			'swish-backup-material-symbols',
			'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap',
			array(),
			null
		);

		// Enqueue multisite admin CSS with file modification time for cache busting.
		$css_file    = SWISH_BACKUP_PLUGIN_DIR . 'assets/css/pro-admin.css';
		$css_version = SWISH_BACKUP_VERSION;
		if ( file_exists( $css_file ) ) {
			$css_version = SWISH_BACKUP_VERSION . '.' . filemtime( $css_file );
		}

		wp_enqueue_style(
			'swish-backup-pro-admin',
			SWISH_BACKUP_PLUGIN_URL . 'assets/css/pro-admin.css',
			array( 'swish-backup-font-inter', 'swish-backup-material-symbols' ),
			$css_version
		);

		// Enqueue multisite admin JS with file modification time for cache busting.
		$js_file    = SWISH_BACKUP_PLUGIN_DIR . 'assets/js/pro-admin.js';
		$js_version = SWISH_BACKUP_VERSION;
		if ( file_exists( $js_file ) ) {
			$js_version = SWISH_BACKUP_VERSION . '.' . filemtime( $js_file );
		}

		wp_enqueue_script(
			'swish-backup-pro-admin',
			SWISH_BACKUP_PLUGIN_URL . 'assets/js/pro-admin.js',
			array( 'jquery' ),
			$js_version,
			true
		);

		// Localize script.
		wp_localize_script(
			'swish-backup-pro-admin',
			'swishBackupPro',
			array(
				'version'     => SWISH_BACKUP_VERSION,
				'isMultisite' => is_multisite(),
				'nonce'       => wp_create_nonce( 'swish_backup_pro_nonce' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'theme'       => AdminLayout::get_theme(),
			)
		);
	}

	/**
	 * Add network admin menu items (for super admins only).
	 *
	 * @return void
	 */
	public function add_network_admin_menu(): void {
		// Add Multisite Backup as a top-level menu in Network Admin.
		add_menu_page(
			__( 'Swish Multisite Backup', 'swish-migrate-and-backup' ),
			__( 'Swish Backup', 'swish-migrate-and-backup' ),
			'manage_network',
			'swish-backup-multisite',
			array( $this, 'render_multisite_page' ),
			'dashicons-backup',
			30
		);

		// Add Backup submenu (same as parent).
		add_submenu_page(
			'swish-backup-multisite',
			__( 'Create Backup', 'swish-migrate-and-backup' ),
			__( 'Create Backup', 'swish-migrate-and-backup' ),
			'manage_network',
			'swish-backup-multisite',
			array( $this, 'render_multisite_page' )
		);

		// Add Migration submenu.
		add_submenu_page(
			'swish-backup-multisite',
			__( 'Migration', 'swish-migrate-and-backup' ),
			__( 'Migration', 'swish-migrate-and-backup' ),
			'manage_network',
			'swish-backup-migration',
			array( $this, 'render_migration_page' )
		);

		// Add Duplicate submenu.
		add_submenu_page(
			'swish-backup-multisite',
			__( 'Duplicate Site', 'swish-migrate-and-backup' ),
			__( 'Duplicate Site', 'swish-migrate-and-backup' ),
			'manage_network',
			'swish-backup-duplicate',
			array( $this, 'render_duplicate_page' )
		);
	}

	/**
	 * Add migration menu for single-site WordPress.
	 *
	 * This allows importing multisite backups into a single-site installation.
	 *
	 * @return void
	 */
	public function add_single_site_migration_menu(): void {
		// Add as submenu under the free plugin's menu.
		add_submenu_page(
			'swish-backup', // Parent slug (free plugin's menu).
			__( 'Pro Migration', 'swish-migrate-and-backup' ),
			__( 'Pro Migration', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup-pro-migration',
			array( $this, 'render_migration_page' )
		);
	}

	/**
	 * Render migration page.
	 *
	 * @return void
	 */
	public function render_migration_page(): void {
		AdminLayout::render_start( 'swish-backup-migration' );
		AdminLayout::render_header(
			__( 'Migration', 'swish-migrate-and-backup' ),
			__( 'Export backups for migration or import backups from another installation.', 'swish-migrate-and-backup' )
		);

		$this->migration_ui->render();

		AdminLayout::render_content_end();
		AdminLayout::render_end();
	}

	/**
	 * Render duplicate site page.
	 *
	 * @return void
	 */
	public function render_duplicate_page(): void {
		if ( ! $this->detector->is_multisite() ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Duplicate Site', 'swish-migrate-and-backup' ); ?></h1>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'This feature is only available on multisite installations.', 'swish-migrate-and-backup' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		AdminLayout::render_start( 'swish-backup-duplicate' );
		AdminLayout::render_header(
			__( 'Duplicate Site', 'swish-migrate-and-backup' ),
			__( 'Create a copy of any site in your network with a new URL.', 'swish-migrate-and-backup' )
		);

		$this->duplicate_ui->render();

		AdminLayout::render_content_end();
		AdminLayout::render_end();
	}

	/**
	 * Render multisite backup page.
	 *
	 * @return void
	 */
	public function render_multisite_page(): void {
		if ( ! $this->detector->is_multisite() ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Multisite Backup', 'swish-migrate-and-backup' ); ?></h1>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'This feature is only available on multisite installations.', 'swish-migrate-and-backup' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		// Get current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

		// Route to appropriate tab content.
		switch ( $current_tab ) {
			case 'backup':
				$this->render_backup_tab();
				break;
			case 'history':
				$this->render_history_tab();
				break;
			default:
				$this->render_dashboard_tab();
				break;
		}
	}

	/**
	 * Render the Dashboard tab.
	 *
	 * @return void
	 */
	private function render_dashboard_tab(): void {
		$network_info = $this->detector->get_network_info();
		$storage_info = $this->get_storage_info();
		$system_info  = $this->get_system_info();
		$backups      = $this->manager->get_multisite_backups( 5 );

		// Start the modern layout.
		AdminLayout::render_start( 'swish-backup-multisite' );
		AdminLayout::render_header(
			__( 'Dashboard', 'swish-migrate-and-backup' ),
			__( 'Overview of your multisite backup system.', 'swish-migrate-and-backup' )
		);
		?>

		<!-- Dashboard Cards -->
		<div class="swish-dashboard-cards">
			<!-- Storage Card -->
			<div class="swish-dashboard-card">
				<div class="swish-dashboard-card-header">
					<div class="swish-dashboard-card-icon" style="background: var(--swish-primary-100); color: var(--swish-primary-600);">
						<span class="material-symbols-outlined">cloud_upload</span>
					</div>
					<span class="swish-dashboard-card-label"><?php esc_html_e( 'Backup Storage', 'swish-migrate-and-backup' ); ?></span>
				</div>
				<div class="swish-dashboard-card-value"><?php echo esc_html( $storage_info['used_text'] ); ?></div>
				<div class="swish-dashboard-card-meta">
					<div class="swish-storage-mini-bar">
						<div class="swish-storage-mini-fill" style="width: <?php echo esc_attr( min( 100, $storage_info['percentage'] ) ); ?>%;"></div>
					</div>
					<span style="font-size: var(--swish-text-xs); color: var(--swish-text-tertiary);">
						<?php
						printf(
							/* translators: %s: percentage used */
							esc_html__( '%s%% of disk used', 'swish-migrate-and-backup' ),
							esc_html( number_format( $storage_info['percentage'], 1 ) )
						);
						?>
					</span>
				</div>
			</div>

			<!-- System Status Card -->
			<div class="swish-dashboard-card">
				<div class="swish-dashboard-card-header">
					<div class="swish-dashboard-card-icon" style="background: var(--swish-success-100); color: var(--swish-success-600);">
						<span class="material-symbols-outlined">terminal</span>
					</div>
					<span class="swish-dashboard-card-label"><?php esc_html_e( 'System Status', 'swish-migrate-and-backup' ); ?></span>
				</div>
				<div class="swish-dashboard-card-value" style="font-size: var(--swish-text-lg);">
					<?php echo esc_html( $system_info['status_text'] ); ?>
				</div>
				<div class="swish-dashboard-card-details">
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'PHP', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( $system_info['php_version'] ); ?></span>
					</div>
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'Memory', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( $system_info['memory_limit'] ); ?></span>
					</div>
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'Max Exec', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( $system_info['max_execution_time'] ); ?>s</span>
					</div>
				</div>
			</div>

			<!-- Network Sites Card -->
			<div class="swish-dashboard-card">
				<div class="swish-dashboard-card-header">
					<div class="swish-dashboard-card-icon" style="background: var(--swish-info-100); color: var(--swish-info-600);">
						<span class="material-symbols-outlined">language</span>
					</div>
					<span class="swish-dashboard-card-label"><?php esc_html_e( 'Network Sites', 'swish-migrate-and-backup' ); ?></span>
				</div>
				<div class="swish-dashboard-card-value"><?php echo esc_html( $network_info['site_count'] ); ?></div>
				<div class="swish-dashboard-card-details">
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'Domain', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( $network_info['domain'] ); ?></span>
					</div>
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value">
							<?php echo $network_info['is_subdomain'] ? esc_html__( 'Subdomain', 'swish-migrate-and-backup' ) : esc_html__( 'Subdirectory', 'swish-migrate-and-backup' ); ?>
						</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Quick Actions -->
		<div class="swish-card swish-mt-4">
			<div class="swish-card-header">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<?php esc_html_e( 'Quick Actions', 'swish-migrate-and-backup' ); ?>
				</h4>
			</div>
			<div class="swish-card-body">
				<div class="swish-flex swish-gap-4">
					<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-multisite&tab=backup' ) ); ?>" class="swish-btn swish-btn-primary">
						<span class="material-symbols-outlined">backup</span>
						<?php esc_html_e( 'Create New Backup', 'swish-migrate-and-backup' ); ?>
					</a>
					<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-multisite&tab=history' ) ); ?>" class="swish-btn swish-btn-secondary">
						<span class="material-symbols-outlined">history</span>
						<?php esc_html_e( 'View History', 'swish-migrate-and-backup' ); ?>
					</a>
					<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-migration' ) ); ?>" class="swish-btn swish-btn-secondary">
						<span class="material-symbols-outlined">swap_horiz</span>
						<?php esc_html_e( 'Migration Tools', 'swish-migrate-and-backup' ); ?>
					</a>
				</div>
			</div>
		</div>

		<!-- Recent Backups -->
		<div class="swish-card swish-mt-4">
			<div class="swish-card-header" style="display: flex; justify-content: space-between; align-items: center;">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<?php esc_html_e( 'Recent Backups', 'swish-migrate-and-backup' ); ?>
				</h4>
				<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-multisite&tab=history' ) ); ?>" class="swish-btn swish-btn-ghost swish-btn-sm">
					<?php esc_html_e( 'View All', 'swish-migrate-and-backup' ); ?>
					<span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
				</a>
			</div>
			<div class="swish-card-body">
				<?php if ( empty( $backups ) ) : ?>
					<?php
					AdminLayout::render_empty_state(
						__( 'No Backups Yet', 'swish-migrate-and-backup' ),
						__( 'Create your first multisite backup to get started.', 'swish-migrate-and-backup' ),
						'backup',
						array(
							'label' => __( 'Create Backup', 'swish-migrate-and-backup' ),
							'icon'  => 'add',
							'id'    => 'swish-dashboard-create-backup',
						)
					);
					?>
					<script>
					jQuery('#swish-dashboard-create-backup').on('click', function() {
						window.location.href = '<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-multisite&tab=backup' ) ); ?>';
					});
					</script>
				<?php else : ?>
					<table class="swish-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Sites', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Status', 'swish-migrate-and-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $backups as $backup ) : ?>
								<?php
								$total_size = 0;
								foreach ( $backup['files'] as $file ) {
									$total_size += $file['size'];
								}
								$created_time = strtotime( $backup['created_at'] );
								?>
								<tr>
									<td>
										<span class="swish-table-cell-primary">
											<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_time ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $backup['total_sites'] ); ?></td>
									<td><span class="swish-table-cell-mono"><?php echo esc_html( size_format( $total_size ) ); ?></span></td>
									<td><span class="swish-badge swish-badge-success"><?php esc_html_e( 'Complete', 'swish-migrate-and-backup' ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<?php
		AdminLayout::render_content_end();
		AdminLayout::render_end();
	}

	/**
	 * Render the Create Backup tab.
	 *
	 * @return void
	 */
	private function render_backup_tab(): void {
		// Start the modern layout.
		AdminLayout::render_start( 'swish-backup-multisite' );
		AdminLayout::render_header(
			__( 'Create Backup', 'swish-migrate-and-backup' ),
			__( 'Select sites and configure backup options.', 'swish-migrate-and-backup' )
		);
		?>

		<!-- Backup Form -->
		<div class="swish-card">
			<div class="swish-card-header">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<?php esc_html_e( 'Select Sites to Backup', 'swish-migrate-and-backup' ); ?>
				</h4>
			</div>
			<div class="swish-card-body">
				<p style="color: var(--swish-text-secondary); margin-bottom: var(--swish-space-4);">
					<?php esc_html_e( 'Choose which sites you want to include in the backup.', 'swish-migrate-and-backup' ); ?>
				</p>

				<form id="swish-multisite-backup-form" method="post">
					<?php wp_nonce_field( 'swish_multisite_backup', 'swish_nonce' ); ?>

					<?php $this->site_selector->render(); ?>

					<div class="swish-flex swish-items-center swish-gap-4 swish-mt-4" style="padding-top: var(--swish-space-4); border-top: 1px solid var(--swish-border-light);">
						<button type="submit" class="swish-btn swish-btn-primary swish-btn-lg" disabled id="swish-start-multisite-backup">
							<span class="material-symbols-outlined" style="font-size: 20px;">backup</span>
							<span><?php esc_html_e( 'Start Backup', 'swish-migrate-and-backup' ); ?></span>
						</button>
						<p style="color: var(--swish-text-tertiary); font-size: var(--swish-text-sm); margin: 0;">
							<?php esc_html_e( 'Select at least one site to enable backup.', 'swish-migrate-and-backup' ); ?>
						</p>
					</div>
				</form>
			</div>
		</div>

		<script>
		jQuery( document ).ready( function( $ ) {
			// Enable/disable backup button based on site selection.
			$( '.swish-site-checkbox' ).on( 'change', function() {
				const checkedCount = $( '.swish-site-checkbox:checked' ).length;
				$( '#swish-start-multisite-backup' ).prop( 'disabled', checkedCount === 0 );
			} );

			// Select all checkbox in header.
			$( '#swish-select-all-checkbox' ).on( 'change', function() {
				const isChecked = $( this ).is( ':checked' );
				$( '.swish-site-checkbox:visible' ).prop( 'checked', isChecked ).trigger( 'change' );
			} );

			// Select all / Deselect all buttons.
			$( '.swish-select-all-sites' ).on( 'click', function() {
				$( '#swish-select-all-checkbox' ).prop( 'checked', true ).trigger( 'change' );
			} );

			$( '.swish-deselect-all-sites' ).on( 'click', function() {
				$( '#swish-select-all-checkbox' ).prop( 'checked', false ).trigger( 'change' );
			} );

			// Load more sites.
			$( '.swish-load-more-sites' ).on( 'click', function() {
				const $button = $( this );
				const $hiddenRows = $( '.swish-site-row-hidden' );

				$hiddenRows.removeClass( 'swish-site-row-hidden' ).fadeIn();
				$button.closest( '.swish-card-footer' ).fadeOut();
			} );

			// Search functionality.
			$( '.swish-site-search' ).on( 'keyup', function() {
				const searchTerm = $( this ).val().toLowerCase();

				$( '.swish-site-row' ).each( function() {
					const siteName = $( this ).data( 'site-name' );
					const siteUrl = $( this ).data( 'site-url' );

					if ( siteName.includes( searchTerm ) || siteUrl.includes( searchTerm ) ) {
						$( this ).show();
					} else {
						$( this ).hide();
					}
				} );

				// Hide load more button if searching.
				if ( searchTerm ) {
					$( '.swish-load-more-container, .swish-card-footer' ).hide();
				} else {
					$( '.swish-load-more-container, .swish-card-footer' ).show();
				}
			} );

			// Archive mode selection.
			$( '.swish-archive-mode-option' ).on( 'click', function() {
				$( '.swish-archive-mode-option' ).removeClass( 'selected' ).css( {
					borderColor: 'var(--swish-border-default)',
					background: 'transparent'
				} );
				$( this ).addClass( 'selected' ).css( {
					borderColor: 'var(--swish-primary-600)',
					background: 'var(--swish-primary-50)'
				} );
				$( this ).find( 'input[type="radio"]' ).prop( 'checked', true );
			} );

			// Handle backup form submission.
			$( '#swish-multisite-backup-form' ).on( 'submit', function( e ) {
				e.preventDefault();

				// Get selected sites.
				const siteIds = [];
				$( '.swish-site-checkbox:checked' ).each( function() {
					siteIds.push( $( this ).val() );
				} );

				if ( siteIds.length === 0 ) {
					alert( '<?php echo esc_js( __( 'Please select at least one site.', 'swish-migrate-and-backup' ) ); ?>' );
					return;
				}

				// Get archive mode.
				const archiveMode = $( 'input[name="archive_mode"]:checked' ).val() || 'single';

				// Get backup type (full or database).
				const backupType = $( 'input[name="backup_type"]:checked' ).val() || 'full';
				const isDatabaseOnly = backupType === 'database';

				// Get backup options.
				const backupOptions = {
					database_only: isDatabaseOnly,
					include_core_files: ! isDatabaseOnly && $( '#include_core_files' ).is( ':checked' ),
					include_themes: ! isDatabaseOnly && $( '#include_themes' ).is( ':checked' ),
					include_plugins: ! isDatabaseOnly && $( '#include_plugins' ).is( ':checked' ),
					include_uploads: ! isDatabaseOnly && $( '#include_uploads' ).is( ':checked' ),
					include_mu_plugins: ! isDatabaseOnly && $( '#include_mu_plugins' ).is( ':checked' )
				};

				// Start backup with progress modal.
				SwishProgressModal.startBackup( siteIds, archiveMode, backupOptions );
			} );
		} );
		</script>

		<?php
		// Render progress modal.
		$this->progress_modal->render();
		$this->progress_modal->render_scripts();

		AdminLayout::render_content_end();
		AdminLayout::render_end();
	}

	/**
	 * Render the History tab.
	 *
	 * @return void
	 */
	private function render_history_tab(): void {
		// Start the modern layout.
		AdminLayout::render_start( 'swish-backup-multisite' );
		AdminLayout::render_header(
			__( 'Backup History', 'swish-migrate-and-backup' ),
			__( 'View and manage your previous backups.', 'swish-migrate-and-backup' )
		);

		// Render backup history.
		$this->backup_history->render();

		AdminLayout::render_content_end();
		AdminLayout::render_end();
	}

	/**
	 * Get storage usage information.
	 *
	 * @return array Storage info.
	 */
	private function get_storage_info(): array {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups';
		$used_bytes = 0;

		if ( is_dir( $backup_dir ) ) {
			$used_bytes = $this->get_directory_size( $backup_dir );
		}

		// Get disk free space.
		$free_bytes  = disk_free_space( WP_CONTENT_DIR );
		$total_bytes = $used_bytes + ( $free_bytes ?: 0 );

		// Calculate percentage.
		$percentage = $total_bytes > 0 ? ( $used_bytes / $total_bytes ) * 100 : 0;

		return array(
			'used'       => $used_bytes,
			'total'      => $total_bytes,
			'percentage' => $percentage,
			'used_text'  => size_format( $used_bytes, 2 ),
			'total_text' => size_format( $total_bytes, 2 ),
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

		if ( ! is_dir( $path ) ) {
			return $size;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Get system information.
	 *
	 * @return array System info.
	 */
	private function get_system_info(): array {
		$php_version        = PHP_VERSION;
		$memory_limit       = ini_get( 'memory_limit' );
		$max_execution_time = ini_get( 'max_execution_time' );

		// Determine status.
		$status      = 'ready';
		$status_text = __( 'Ready', 'swish-migrate-and-backup' );

		// Check memory (warn if less than 128M).
		$memory_bytes = wp_convert_hr_to_bytes( $memory_limit );
		if ( $memory_bytes < 128 * 1024 * 1024 ) {
			$status      = 'warning';
			$status_text = __( 'Low Memory', 'swish-migrate-and-backup' );
		}

		// Check execution time (warn if less than 60s).
		if ( (int) $max_execution_time > 0 && (int) $max_execution_time < 60 ) {
			$status      = 'warning';
			$status_text = __( 'Low Timeout', 'swish-migrate-and-backup' );
		}

		return array(
			'php_version'        => $php_version,
			'memory_limit'       => $memory_limit,
			'max_execution_time' => $max_execution_time ?: '∞',
			'status'             => $status,
			'status_text'        => $status_text,
		);
	}

	/**
	 * Handle AJAX request to start multisite backup.
	 *
	 * @return void
	 */
	public function handle_start_multisite_backup(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions (super admin only).
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get selected sites and archive mode.
		$site_ids = isset( $_POST['site_ids'] ) ? array_map( 'absint', (array) $_POST['site_ids'] ) : array();
		$archive_mode = isset( $_POST['archive_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_mode'] ) ) : 'single';

		if ( empty( $site_ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please select at least one site to backup.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Prepare options.
		$options = array(
			'archive_mode' => $archive_mode,
		);

		// Start the backup.
		try {
			$result = $this->manager->backup_sites( $site_ids, $options );

			if ( $result && isset( $result['status'] ) && $result['status'] === 'completed' ) {
				wp_send_json_success(
					array(
						'message' => $result['message'] ?? __( 'Backup completed successfully!', 'swish-migrate-and-backup' ),
						'result'  => $result,
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => $result['message'] ?? __( 'Backup failed.', 'swish-migrate-and-backup' ),
						'error'   => $result['error'] ?? null,
					)
				);
			}
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message' => __( 'Backup failed: ', 'swish-migrate-and-backup' ) . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Handle AJAX request to delete multisite backup.
	 *
	 * @return void
	 */
	public function handle_delete_multisite_backup(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions (super admin only).
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get job ID.
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid job ID.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Delete backup.
		$success = $this->manager->delete_multisite_backup( $job_id );

		if ( $success ) {
			wp_send_json_success(
				array(
					'message' => __( 'Backup deleted successfully.', 'swish-migrate-and-backup' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to delete backup.', 'swish-migrate-and-backup' ),
				)
			);
		}
	}

	/**
	 * Render backup history on main admin page.
	 *
	 * @return void
	 */
	public function render_backup_history_on_main_page(): void {
		// Only show if multisite.
		if ( ! is_multisite() ) {
			return;
		}

		// Check if there are any multisite backups.
		$backups = $this->manager->get_multisite_backups( 5 );

		if ( empty( $backups ) ) {
			return;
		}

		?>
		<div class="swish-backup-card" style="margin-top: 30px;">
			<?php $this->backup_history->render( array( 'limit' => 10 ) ); ?>

			<?php if ( is_multisite() && current_user_can( 'manage_network' ) ) : ?>
				<p style="text-align: center; margin-top: 20px;">
					<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-multisite' ) ); ?>" class="button">
						<?php esc_html_e( 'View All Multisite Backups', 'swish-migrate-and-backup' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resolve a user-supplied existing-backup path, constrained to the backups directory.
	 *
	 * @param string $requested Requested file path.
	 * @return string Resolved absolute path, or empty string if the path is invalid
	 *                or points outside the backups directory.
	 */
	private function resolve_existing_backup_path( string $requested ): string {
		if ( '' === $requested ) {
			return '';
		}

		$backups_dir = realpath( WP_CONTENT_DIR . '/swish-backups' );
		$resolved    = realpath( $requested );

		if ( false === $backups_dir || false === $resolved ) {
			return '';
		}

		if ( strpos( $resolved, $backups_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
			return '';
		}

		return $resolved;
	}

	/**
	 * Handle AJAX request to validate multisite import.
	 *
	 * @return void
	 */
	public function handle_validate_import(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$file_path = '';
		$original_filename = '';

		// Handle file upload.
		if ( ! empty( $_FILES['backup_file']['tmp_name'] ) ) {
			$file_path = sanitize_text_field( wp_unslash( $_FILES['backup_file']['tmp_name'] ) );
			$original_filename = sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ?? '' ) );
		} elseif ( ! empty( $_POST['existing_backup'] ) ) {
			$file_path = $this->resolve_existing_backup_path( sanitize_text_field( wp_unslash( $_POST['existing_backup'] ) ) );
			$original_filename = basename( $file_path );
		}

		if ( empty( $file_path ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No backup file specified.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Validate the backup (pass original filename for extension check).
		$result = $this->migration->validate_backup( $file_path, $original_filename );

		if ( $result['valid'] ) {
			$manifest = $result['manifest'];

			// Extract main site URL for easy access - try multiple locations.
			$main_site_url = '';
			if ( ! empty( $manifest['sites'][0]['site_url'] ) ) {
				$main_site_url = $manifest['sites'][0]['site_url'];
			} elseif ( ! empty( $manifest['site_url'] ) ) {
				$main_site_url = $manifest['site_url'];
			} elseif ( ! empty( $manifest['home_url'] ) ) {
				$main_site_url = $manifest['home_url'];
			} elseif ( ! empty( $manifest['network']['domain'] ) ) {
				$domain = $manifest['network']['domain'];
				$path = $manifest['network']['path'] ?? '/';
				$main_site_url = 'https://' . $domain . ( $path !== '/' ? rtrim( $path, '/' ) : '' );
			}

			$response = array(
				'message'             => $result['message'],
				'manifest'            => $manifest,
				'main_site_url'       => $main_site_url,
				'requires_conversion' => $result['requires_conversion'] ?? false,
				'is_multisite'        => $result['is_multisite'] ?? is_multisite(),
			);

			// Include site selection data for multisite-to-single import.
			if ( ! empty( $result['requires_site_selection'] ) ) {
				$response['requires_site_selection'] = true;
				$response['available_sites'] = $result['available_sites'] ?? array();
			}

			if ( ! empty( $result['import_as_single_site'] ) ) {
				$response['import_as_single_site'] = true;
				$response['available_sites'] = $result['available_sites'] ?? $manifest['sites'] ?? array();
			}

			// Include warning if conversion is required.
			if ( ! empty( $result['warning'] ) ) {
				$response['warning'] = $result['warning'];
			}

			wp_send_json_success( $response );
		} else {
			// Check if this is a site selection scenario (valid but needs user input).
			if ( ! empty( $result['requires_site_selection'] ) ) {
				$available_sites = $result['available_sites'] ?? array();
				$manifest = $result['manifest'] ?? array();

				// Extract main site URL from first available site.
				$main_site_url = '';
				if ( ! empty( $available_sites[0]['site_url'] ) ) {
					$main_site_url = $available_sites[0]['site_url'];
				} elseif ( ! empty( $manifest['site_url'] ) ) {
					$main_site_url = $manifest['site_url'];
				} elseif ( ! empty( $manifest['home_url'] ) ) {
					$main_site_url = $manifest['home_url'];
				}

				wp_send_json_success(
					array(
						'message'                 => $result['message'],
						'manifest'                => $manifest,
						'requires_site_selection' => true,
						'available_sites'         => $available_sites,
						'import_as_single_site'   => true,
						'main_site_url'           => $main_site_url,
					)
				);
			}

			wp_send_json_error(
				array(
					'message'                => $result['message'],
					'requires_multisite'     => $result['requires_multisite'] ?? false,
					'multisite_instructions' => $result['multisite_instructions'] ?? null,
					'manifest'               => $result['manifest'] ?? null,
				)
			);
		}
	}

	/**
	 * Handle AJAX request to import multisite backup.
	 *
	 * @return void
	 */
	public function handle_import_multisite(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions (allow manage_options for single site conversion).
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$file_path = '';

		// Handle file upload.
		if ( ! empty( $_FILES['backup_file']['tmp_name'] ) ) {
			// Move uploaded file to a permanent location.
			$imports_dir = WP_CONTENT_DIR . '/swish-backups/imports';
			if ( ! is_dir( $imports_dir ) ) {
				wp_mkdir_p( $imports_dir );
			}
			$temp_file = $imports_dir . '/import-' . wp_generate_uuid4() . '.zip';

			if ( ! move_uploaded_file( $_FILES['backup_file']['tmp_name'], $temp_file ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to upload backup file.', 'swish-migrate-and-backup' ),
					)
				);
			}

			$file_path = $temp_file;
		} elseif ( ! empty( $_POST['existing_backup'] ) ) {
			$file_path = $this->resolve_existing_backup_path( sanitize_text_field( wp_unslash( $_POST['existing_backup'] ) ) );
		}

		if ( empty( $file_path ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No backup file specified.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Build options.
		$options = array();

		// Search/replace.
		if ( ! empty( $_POST['search_url'] ) && ! empty( $_POST['replace_url'] ) ) {
			$search  = sanitize_text_field( wp_unslash( $_POST['search_url'] ) );
			$replace = sanitize_text_field( wp_unslash( $_POST['replace_url'] ) );

			$options['search_replace'] = array(
				$search => $replace,
			);
		}

		// Import shared tables.
		if ( ! empty( $_POST['import_shared_tables'] ) ) {
			$options['import_shared_tables'] = true;
		}

		// Conversion confirmation for single site to multisite.
		if ( ! empty( $_POST['confirm_conversion'] ) ) {
			$options['confirm_conversion'] = true;
		}

		// Import the backup.
		$result = $this->migration->import_backup( $file_path, $options );

		// Clean up uploaded temp file if it was uploaded.
		if ( isset( $temp_file ) && file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message'        => $result['message'],
					'imported_sites' => $result['imported_sites'] ?? array(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}
	}

	/**
	 * Handle AJAX request to preview search/replace changes.
	 *
	 * @return void
	 */
	public function handle_preview_search_replace(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$search_url = isset( $_POST['search_url'] ) ? sanitize_text_field( wp_unslash( $_POST['search_url'] ) ) : '';
		$replace_url = isset( $_POST['replace_url'] ) ? sanitize_text_field( wp_unslash( $_POST['replace_url'] ) ) : '';

		if ( empty( $search_url ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a URL to search for.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Use the free plugin's SearchReplace class for preview.
		if ( class_exists( '\\SwishMigrateAndBackup\\Migration\\SearchReplace' ) ) {
			$container = \SwishMigrateAndBackup\Core\Container::get_instance();
			$logger = $container->get( \SwishMigrateAndBackup\Logger\Logger::class );
			$search_replace = new \SwishMigrateAndBackup\Migration\SearchReplace( $logger );

			$result = $search_replace->dry_run( $search_url, $replace_url ?: $search_url, array(), 50 );

			wp_send_json_success( $result );
		} else {
			// Fallback: Simple database search.
			global $wpdb;

			$total_matches = 0;
			$preview = array();
			$tables = $wpdb->get_col( 'SHOW TABLES' );

			foreach ( $tables as $table ) {
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

					// Count matches.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$count = (int) $wpdb->get_var(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT COUNT(*) FROM `{$table}` WHERE `{$col_name}` LIKE %s",
							'%' . $wpdb->esc_like( $search_url ) . '%'
						)
					);

					$total_matches += $count;

					// Get sample matches for preview.
					if ( $count > 0 && count( $preview ) < 50 ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$rows = $wpdb->get_results(
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								"SELECT `{$col_name}` FROM `{$table}` WHERE `{$col_name}` LIKE %s LIMIT 5",
								'%' . $wpdb->esc_like( $search_url ) . '%'
							),
							ARRAY_A
						);

						foreach ( $rows as $row ) {
							$before = substr( $row[ $col_name ], 0, 200 );
							$after = str_replace( $search_url, $replace_url ?: $search_url, $before );

							$preview[] = array(
								'table'  => $table,
								'column' => $col_name,
								'before' => $before . ( strlen( $row[ $col_name ] ) > 200 ? '...' : '' ),
								'after'  => $after . ( strlen( $row[ $col_name ] ) > 200 ? '...' : '' ),
							);

							if ( count( $preview ) >= 50 ) {
								break 3;
							}
						}
					}
				}
			}

			wp_send_json_success(
				array(
					'total_matches' => $total_matches,
					'preview'       => $preview,
					'truncated'     => $total_matches > 50,
				)
			);
		}
	}

	/**
	 * Handle AJAX request to start async import.
	 *
	 * @return void
	 */
	public function handle_import_multisite_async(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$file_path         = '';
		$original_filename = '';

		// Handle file upload.
		if ( ! empty( $_FILES['backup_file']['tmp_name'] ) ) {
			$file_path         = sanitize_text_field( wp_unslash( $_FILES['backup_file']['tmp_name'] ) );
			$original_filename = sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ?? '' ) );
		} elseif ( ! empty( $_POST['existing_backup'] ) ) {
			$file_path         = $this->resolve_existing_backup_path( sanitize_text_field( wp_unslash( $_POST['existing_backup'] ) ) );
			$original_filename = basename( $file_path );
		}

		if ( empty( $file_path ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No backup file specified.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Build options.
		$options = array();

		// Search/replace.
		if ( ! empty( $_POST['search_url'] ) && ! empty( $_POST['replace_url'] ) ) {
			$search  = sanitize_text_field( wp_unslash( $_POST['search_url'] ) );
			$replace = sanitize_text_field( wp_unslash( $_POST['replace_url'] ) );

			$options['search_replace'] = array(
				$search => $replace,
			);
		}

		// Import shared tables.
		if ( ! empty( $_POST['import_shared_tables'] ) ) {
			$options['import_shared_tables'] = true;
		}

		// Import as single site (multisite backup to single WP).
		if ( ! empty( $_POST['import_as_single_site'] ) ) {
			$options['import_as_single_site'] = true;
		}

		// Specific site to import (for multisite backups with multiple sites).
		if ( ! empty( $_POST['site_id'] ) ) {
			$options['site_id'] = absint( $_POST['site_id'] );
		}

		// Start async import.
		$result = $this->migration->start_import_async( $file_path, $options, $original_filename );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'job_id'  => $result['job_id'],
					'message' => $result['message'],
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}
	}

	/**
	 * Handle AJAX request to check import progress.
	 *
	 * This also triggers the import job if it's still pending, ensuring
	 * the import runs even if WP Cron doesn't fire.
	 *
	 * @return void
	 */
	public function handle_check_import_progress(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid job ID.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$progress = $this->migration->get_import_progress( $job_id );

		if ( ! $progress ) {
			wp_send_json_error(
				array(
					'message' => __( 'Import job not found.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// If the job is still pending, trigger it directly.
		// This ensures the import runs even if WP Cron doesn't fire.
		if ( $progress['status'] === 'pending' ) {
			// Run the import directly (this will block but update progress).
			$this->migration->run_import_job( $job_id );

			// Get updated progress after running.
			$progress = $this->migration->get_import_progress( $job_id );

			if ( ! $progress ) {
				wp_send_json_error(
					array(
						'message' => __( 'Import job lost after execution.', 'swish-migrate-and-backup' ),
					)
				);
			}
		}

		wp_send_json_success(
			array(
				'status'       => $progress['status'],
				'progress'     => $progress['progress'],
				'current_step' => $progress['current_step'],
				'message'      => $progress['message'],
				'error'        => $progress['error'] ?? null,
			)
		);
	}

	/**
	 * Run import job (WP Cron callback).
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_import_job( string $job_id ): void {
		$this->migration->run_import_job( $job_id );
	}

	/**
	 * Handle AJAX request to start async multisite backup.
	 *
	 * @return void
	 */
	public function handle_start_multisite_backup_async(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions (super admin only).
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get selected sites and archive mode.
		$site_ids     = isset( $_POST['site_ids'] ) ? array_map( 'absint', (array) $_POST['site_ids'] ) : array();
		$archive_mode = isset( $_POST['archive_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_mode'] ) ) : 'single';

		if ( empty( $site_ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please select at least one site to backup.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Build options from POST data.
		$options = array(
			'database_only'      => ! empty( $_POST['database_only'] ),
			'include_core_files' => ! empty( $_POST['include_core_files'] ),
			'include_themes'     => ! empty( $_POST['include_themes'] ),
			'include_plugins'    => ! empty( $_POST['include_plugins'] ),
			'include_uploads'    => ! empty( $_POST['include_uploads'] ),
			'include_mu_plugins' => ! empty( $_POST['include_mu_plugins'] ),
		);

		// Schedule background backup.
		$job_id = $this->manager->schedule_background_backup( $site_ids, $archive_mode, $options );

		wp_send_json_success(
			array(
				'job_id'  => $job_id,
				'message' => __( 'Backup started.', 'swish-migrate-and-backup' ),
			)
		);
	}

	/**
	 * Handle AJAX request to check backup progress.
	 *
	 * @return void
	 */
	public function handle_check_progress(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get job ID.
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid job ID.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get progress.
		$progress = $this->manager->get_job_progress( $job_id );

		if ( ! $progress ) {
			wp_send_json_error(
				array(
					'message' => __( 'Job not found.', 'swish-migrate-and-backup' ),
				)
			);
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Run background backup via WP Cron.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_background_backup( string $job_id ): void {
		$this->manager->run_scheduled_backup( $job_id );
	}

	/**
	 * Handle AJAX request to save theme preference.
	 *
	 * @return void
	 */
	public function handle_save_theme(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get theme preference.
		$theme = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : 'light';

		// Validate theme value.
		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$theme = 'light';
		}

		// Save to user meta.
		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'swish_backup_theme', $theme );

		wp_send_json_success(
			array(
				'theme'   => $theme,
				'message' => __( 'Theme preference saved.', 'swish-migrate-and-backup' ),
			)
		);
	}

	/**
	 * Handle AJAX request to start async site duplication.
	 *
	 * @return void
	 */
	public function handle_duplicate_site_async(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions (super admin only).
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get parameters.
		$source_site_id = isset( $_POST['source_site_id'] ) ? absint( $_POST['source_site_id'] ) : 0;
		$new_slug       = isset( $_POST['new_slug'] ) ? sanitize_title( wp_unslash( $_POST['new_slug'] ) ) : '';
		$new_title      = isset( $_POST['new_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_title'] ) ) : '';

		// Validate.
		if ( ! $source_site_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid source site.', 'swish-migrate-and-backup' ),
				)
			);
		}

		if ( empty( $new_slug ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a URL slug.', 'swish-migrate-and-backup' ),
				)
			);
		}

		if ( empty( $new_title ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a site title.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Validate duplication is possible.
		$validation = $this->site_duplicator->validate_duplication( $source_site_id, $new_slug );
		if ( ! $validation['valid'] ) {
			wp_send_json_error(
				array(
					'message' => $validation['message'],
				)
			);
		}

		// Build options.
		$options = array(
			'copy_uploads' => ! empty( $_POST['copy_uploads'] ),
		);

		// Schedule the duplication job.
		$job_id = $this->site_duplicator->schedule_duplicate_job( $source_site_id, $new_slug, $new_title, $options );

		wp_send_json_success(
			array(
				'job_id'  => $job_id,
				'message' => __( 'Duplication started.', 'swish-migrate-and-backup' ),
			)
		);
	}

	/**
	 * Handle AJAX request to check duplication progress.
	 *
	 * @return void
	 */
	public function handle_check_duplicate_progress(): void {
		// Debug log helper (writes only when WP_DEBUG is enabled).
		$log_file  = WP_CONTENT_DIR . '/swish-backups/duplicate-log.log';
		$debug_log = function ( $msg ) use ( $log_file ) {
			if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
				return;
			}
			$log_dir = dirname( $log_file );
			if ( ! is_dir( $log_dir ) ) {
				wp_mkdir_p( $log_dir );
			}
			$timestamp = gmdate( 'Y-m-d H:i:s' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $log_file, "[{$timestamp}] [PROGRESS CHECK] {$msg}\n", FILE_APPEND | LOCK_EX );
		};

		$debug_log( 'Progress check called' );

		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) ) {
			$debug_log( 'Permission denied' );
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get job ID.
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$debug_log( 'Job ID: ' . $job_id );

		if ( empty( $job_id ) ) {
			$debug_log( 'Empty job ID' );
			wp_send_json_error(
				array(
					'message' => __( 'Invalid job ID.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get progress.
		$progress = $this->site_duplicator->get_duplicate_progress( $job_id );
		$debug_log( 'Current progress: ' . wp_json_encode( $progress ) );

		if ( ! $progress ) {
			$debug_log( 'Progress not found' );
			wp_send_json_error(
				array(
					'message' => __( 'Job not found.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// If job is completed or failed, clean up progress after sending response.
		$should_cleanup = in_array( $progress['current_step'], array( 'completed', 'failed' ), true );

		// If the job is still pending/init and not already running, trigger it directly.
		$lock_key = 'swish_duplicate_lock_' . $job_id;

		// Clear cache to get fresh lock value.
		wp_cache_delete( $lock_key, 'transient' );
		wp_cache_delete( '_transient_' . $lock_key, 'options' );

		$is_running = get_transient( $lock_key ) === 'running';
		$debug_log( 'Lock key: ' . $lock_key . ', Is running: ' . ( $is_running ? 'yes' : 'no' ) );

		if ( $progress['current_step'] === 'init' && $progress['progress'] <= 10 && ! $is_running ) {
			$debug_log( 'Job stuck at init, triggering direct execution...' );

			try {
				// Run the duplication directly (the job itself will acquire a lock).
				$this->site_duplicator->run_duplicate_job( $job_id );
				$debug_log( 'Direct execution completed' );
			} catch ( \Throwable $e ) {
				$debug_log( 'EXCEPTION during direct execution: ' . $e->getMessage() );
				$debug_log( 'Stack trace: ' . $e->getTraceAsString() );
			}

			// Get updated progress.
			$progress = $this->site_duplicator->get_duplicate_progress( $job_id );
			$debug_log( 'Updated progress after execution: ' . wp_json_encode( $progress ) );

			if ( ! $progress ) {
				$debug_log( 'Job lost after execution' );
				wp_send_json_error(
					array(
						'message' => __( 'Job lost after execution.', 'swish-migrate-and-backup' ),
					)
				);
			}

			// Check if now completed.
			$should_cleanup = in_array( $progress['current_step'], array( 'completed', 'failed' ), true );
		}

		$debug_log( 'Returning progress to client: ' . $progress['progress'] . '% - ' . $progress['current_step'] );

		// Schedule cleanup for later if completed (give client time to read it).
		if ( $should_cleanup ) {
			$debug_log( 'Job finished, will clean up progress on next poll or timeout' );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Run site duplication job via WP Cron.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_duplicate_job( string $job_id ): void {
		$this->site_duplicator->run_duplicate_job( $job_id );
	}

	/**
	 * Handle AJAX request to check if a slug is available.
	 *
	 * @return void
	 */
	public function handle_check_slug_available(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'swish-migrate-and-backup' ),
				)
			);
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';

		if ( empty( $slug ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a URL slug.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Build the target URL using the network's scheme.
		$network = get_network();
		$scheme  = wp_parse_url( network_home_url(), PHP_URL_SCHEME );
		$scheme  = $scheme ? $scheme : 'https';
		if ( is_subdomain_install() ) {
			$target_domain = strtolower( $slug . '.' . preg_replace( '/^www\./', '', $network->domain ) );
			$target_path   = '/';
			$target_url    = $scheme . '://' . $target_domain;
		} else {
			$target_domain = strtolower( $network->domain );
			$target_path   = rtrim( $network->path, '/' ) . '/' . $slug . '/';
			$target_url    = $scheme . '://' . $target_domain . $target_path;
		}

		// Check if site exists with exact match.
		global $wpdb;

		// Normalize path.
		$check_path = '/' . trim( $target_path, '/' ) . '/';
		if ( $check_path === '//' ) {
			$check_path = '/';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE LOWER(domain) = %s AND path = %s LIMIT 1",
				$target_domain,
				$check_path
			)
		);

		if ( $exists ) {
			wp_send_json_error(
				array(
					'available' => false,
					'message'   => sprintf(
						/* translators: %s: the URL */
						__( 'URL "%s" is already taken.', 'swish-migrate-and-backup' ),
						$target_url
					),
					'url'       => $target_url,
				)
			);
		}

		// Check reserved slugs.
		$reserved = array( 'www', 'web', 'root', 'admin', 'main', 'invite', 'administrator', 'files' );
		if ( in_array( strtolower( $slug ), $reserved, true ) ) {
			wp_send_json_error(
				array(
					'available' => false,
					'message'   => __( 'This slug is reserved and cannot be used.', 'swish-migrate-and-backup' ),
					'url'       => $target_url,
				)
			);
		}

		wp_send_json_success(
			array(
				'available' => true,
				'message'   => __( 'URL is available!', 'swish-migrate-and-backup' ),
				'url'       => $target_url,
			)
		);
	}

	/**
	 * Handle AJAX request to delete a site.
	 *
	 * @return void
	 */
	public function handle_delete_site(): void {
		// Check nonce.
		check_ajax_referer( 'swish_backup_pro_nonce', 'nonce' );

		// Check permissions (super admin only).
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Get site ID.
		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;

		if ( ! $site_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid site ID.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Check site exists.
		$site = get_site( $site_id );
		if ( ! $site ) {
			wp_send_json_error(
				array(
					'message' => __( 'Site not found.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Prevent deletion of main site.
		if ( is_main_site( $site_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot delete the main site.', 'swish-migrate-and-backup' ),
				)
			);
		}

		// Delete the site.
		// The second parameter (true) means drop the site's database tables.
		$result = wpmu_delete_blog( $site_id, true );

		// wpmu_delete_blog doesn't return a value, so check if site still exists.
		$site_still_exists = get_site( $site_id );

		if ( $site_still_exists ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to delete site. Please try again.', 'swish-migrate-and-backup' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Site deleted successfully.', 'swish-migrate-and-backup' ),
			)
		);
	}
}
