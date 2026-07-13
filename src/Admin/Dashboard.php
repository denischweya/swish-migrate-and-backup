<?php
/**
 * Dashboard Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Storage\StorageManager;
use SwishMigrateAndBackup\Admin\Multisite\AdminLayout;

/**
 * Dashboard page controller.
 */
final class Dashboard {

	/**
	 * Backup manager.
	 *
	 * @var BackupManager
	 */
	private BackupManager $backup_manager;

	/**
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Constructor.
	 *
	 * @param BackupManager  $backup_manager  Backup manager.
	 * @param StorageManager $storage_manager Storage manager.
	 */
	public function __construct( BackupManager $backup_manager, StorageManager $storage_manager ) {
		$this->backup_manager  = $backup_manager;
		$this->storage_manager = $storage_manager;
	}

	/**
	 * Render the dashboard page.
	 *
	 * Server-rendered dashboard using the shared swish-* component system so it
	 * matches the multisite network-admin UI.
	 *
	 * @return void
	 */
	public function render(): void {
		$backups      = $this->backup_manager->get_backups( 5 );
		$all_backups  = $this->backup_manager->get_backups( 1000 );
		$backup_count = count( $all_backups );
		$total_size   = 0;
		foreach ( $all_backups as $backup ) {
			$total_size += (int) $backup['size'];
		}

		$backup_dir = WP_CONTENT_DIR . '/swish-backups';
		$dir_target = is_dir( $backup_dir ) ? $backup_dir : ABSPATH;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$disk_total = (float) @disk_total_space( $dir_target );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$disk_free = (float) @disk_free_space( $dir_target );
		$disk_used = max( 0, $disk_total - $disk_free );
		$disk_pct  = $disk_total > 0 ? ( $disk_used / $disk_total ) * 100 : 0;

		$backups_url   = admin_url( 'admin.php?page=swish-backup-backups' );
		$migration_url = admin_url( 'admin.php?page=swish-backup-migration' );
		$settings_url  = admin_url( 'admin.php?page=swish-backup-settings' );

		AdminNav::render_start(
			__( 'Dashboard', 'swish-migrate-and-backup' ),
			__( 'Overview of your backups and system.', 'swish-migrate-and-backup' )
		);
		?>

		<!-- Dashboard Cards -->
		<div class="swish-dashboard-cards">
			<!-- Backups Card -->
			<div class="swish-dashboard-card">
				<div class="swish-dashboard-card-header">
					<div class="swish-dashboard-card-icon" style="background: var(--swish-primary-100); color: var(--swish-primary-600);">
						<span class="material-symbols-outlined">inventory_2</span>
					</div>
					<span class="swish-dashboard-card-label"><?php esc_html_e( 'Total Backups', 'swish-migrate-and-backup' ); ?></span>
				</div>
				<div class="swish-dashboard-card-value"><?php echo esc_html( number_format_i18n( $backup_count ) ); ?></div>
				<div class="swish-dashboard-card-meta">
					<span style="font-size: var(--swish-text-xs); color: var(--swish-text-tertiary);">
						<?php
						printf(
							/* translators: %s: total size of all backups */
							esc_html__( '%s stored', 'swish-migrate-and-backup' ),
							esc_html( size_format( $total_size ) )
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
				<div class="swish-dashboard-card-value" style="font-size: var(--swish-text-lg);"><?php esc_html_e( 'Ready', 'swish-migrate-and-backup' ); ?></div>
				<div class="swish-dashboard-card-details">
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'PHP', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( PHP_VERSION ); ?></span>
					</div>
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'Memory', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( (string) ini_get( 'memory_limit' ) ); ?></span>
					</div>
					<div class="swish-detail-row">
						<span class="swish-detail-label"><?php esc_html_e( 'Max Exec', 'swish-migrate-and-backup' ); ?></span>
						<span class="swish-detail-value"><?php echo esc_html( (string) ini_get( 'max_execution_time' ) ); ?>s</span>
					</div>
				</div>
			</div>

			<!-- Storage Card -->
			<div class="swish-dashboard-card">
				<div class="swish-dashboard-card-header">
					<div class="swish-dashboard-card-icon" style="background: var(--swish-info-100); color: var(--swish-info-600);">
						<span class="material-symbols-outlined">hard_drive</span>
					</div>
					<span class="swish-dashboard-card-label"><?php esc_html_e( 'Disk Storage', 'swish-migrate-and-backup' ); ?></span>
				</div>
				<div class="swish-dashboard-card-value"><?php echo esc_html( size_format( $disk_used ) ); ?></div>
				<div class="swish-dashboard-card-meta">
					<div class="swish-storage-mini-bar">
						<div class="swish-storage-mini-fill" style="width: <?php echo esc_attr( (string) min( 100, $disk_pct ) ); ?>%;"></div>
					</div>
					<span style="font-size: var(--swish-text-xs); color: var(--swish-text-tertiary);">
						<?php
						printf(
							/* translators: %s: percentage of disk used */
							esc_html__( '%s%% of disk used', 'swish-migrate-and-backup' ),
							esc_html( number_format_i18n( $disk_pct, 1 ) )
						);
						?>
					</span>
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
					<a href="<?php echo esc_url( $backups_url ); ?>" class="swish-btn swish-btn-primary">
						<span class="material-symbols-outlined">backup</span>
						<?php esc_html_e( 'Create New Backup', 'swish-migrate-and-backup' ); ?>
					</a>
					<a href="<?php echo esc_url( $migration_url ); ?>" class="swish-btn swish-btn-secondary">
						<span class="material-symbols-outlined">swap_horiz</span>
						<?php esc_html_e( 'Migration Tools', 'swish-migrate-and-backup' ); ?>
					</a>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="swish-btn swish-btn-secondary">
						<span class="material-symbols-outlined">settings</span>
						<?php esc_html_e( 'Settings', 'swish-migrate-and-backup' ); ?>
					</a>
				</div>
			</div>
		</div>

		<!-- Backup History -->
		<div class="swish-card swish-mt-4">
			<div class="swish-card-header" style="display: flex; justify-content: space-between; align-items: center;">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<span class="material-symbols-outlined" style="vertical-align: middle; margin-right: var(--swish-space-2);">history</span>
					<?php esc_html_e( 'Backup History', 'swish-migrate-and-backup' ); ?>
				</h4>
				<a href="<?php echo esc_url( $backups_url ); ?>" class="swish-btn swish-btn-ghost swish-btn-sm">
					<?php esc_html_e( 'View All', 'swish-migrate-and-backup' ); ?>
					<span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
				</a>
			</div>
			<div class="swish-card-body" style="padding: 0;">
				<?php if ( empty( $backups ) ) : ?>
					<div style="padding: var(--swish-space-6);">
						<?php
						AdminLayout::render_empty_state(
							__( 'No Backups Yet', 'swish-migrate-and-backup' ),
							__( 'Create your first backup to get started.', 'swish-migrate-and-backup' ),
							'backup',
							array(
								'label' => __( 'Create Backup', 'swish-migrate-and-backup' ),
								'icon'  => 'add',
								'id'    => 'swish-dashboard-create-backup',
							)
						);
						?>
						<script>
							jQuery( '#swish-dashboard-create-backup' ).on( 'click', function() {
								window.location.href = '<?php echo esc_url( $backups_url ); ?>';
							} );
						</script>
					</div>
				<?php else : ?>
					<table class="swish-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date & Time', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Status', 'swish-migrate-and-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $backups as $backup ) : ?>
								<?php $created = strtotime( (string) $backup['created_at'] ); ?>
								<tr>
									<td>
										<div class="swish-flex" style="flex-direction: column;">
											<span class="swish-table-cell-primary"><?php echo esc_html( $created ? date_i18n( get_option( 'date_format' ), $created ) : '—' ); ?></span>
											<span class="swish-table-cell-secondary"><?php echo esc_html( $created ? date_i18n( get_option( 'time_format' ), $created ) : '' ); ?></span>
										</div>
									</td>
									<td>
										<span class="swish-badge swish-badge-info"><?php echo esc_html( ucfirst( (string) $backup['type'] ) ); ?></span>
									</td>
									<td>
										<span class="swish-table-cell-mono"><?php echo esc_html( size_format( (int) $backup['size'] ) ); ?></span>
									</td>
									<td>
										<span class="swish-badge swish-badge-success"><?php esc_html_e( 'Completed', 'swish-migrate-and-backup' ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<?php
		/**
		 * Hook to add content after dashboard.
		 *
		 * @since 1.0.0
		 */
		do_action( 'swish_backup_admin_page_after_content' );

		AdminNav::render_end();
	}
}
