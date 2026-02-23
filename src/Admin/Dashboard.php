<?php
/**
 * Dashboard Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Storage\StorageManager;

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
	 * @return void
	 */
	public function render(): void {
		$backups = $this->backup_manager->get_backups( 5 );
		$storage_info = $this->storage_manager->get_total_storage_usage();
		$adapters_info = $this->storage_manager->get_adapters_info();

		$last_backup = $backups[0] ?? null;
		$next_scheduled = wp_next_scheduled( 'swish_backup_scheduled_backup' );
		?>
		<div class="wrap swish-backup-wrap">
			<h1><?php esc_html_e( 'Swish Backup Dashboard', 'swish-migrate-and-backup' ); ?></h1>

			<div class="swish-backup-dashboard">
				<!-- Quick Actions -->
				<div class="swish-backup-card">
					<h2><?php esc_html_e( 'Quick Actions', 'swish-migrate-and-backup' ); ?></h2>
					<div class="swish-backup-actions">
						<button type="button" class="button button-primary button-hero" id="swish-backup-now">
							<?php esc_html_e( 'Backup Now', 'swish-migrate-and-backup' ); ?>
						</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=swish-backup-migration' ) ); ?>" class="button button-secondary button-hero">
							<?php esc_html_e( 'Migrate Site', 'swish-migrate-and-backup' ); ?>
						</a>
					</div>
				</div>

				<!-- Backup Status -->
				<div class="swish-backup-card">
					<h2><?php esc_html_e( 'Backup Status', 'swish-migrate-and-backup' ); ?></h2>
					<div class="swish-backup-status-grid">
						<div class="swish-backup-status-item">
							<span class="swish-backup-status-label"><?php esc_html_e( 'Last Backup', 'swish-migrate-and-backup' ); ?></span>
							<span class="swish-backup-status-value">
								<?php
								if ( $last_backup ) {
									/* translators: %s: human-readable time difference */
									printf( esc_html__( '%s ago', 'swish-migrate-and-backup' ), esc_html( human_time_diff( strtotime( $last_backup['created_at'] ) ) ) );
								} else {
									esc_html_e( 'Never', 'swish-migrate-and-backup' );
								}
								?>
							</span>
						</div>
						<div class="swish-backup-status-item">
							<span class="swish-backup-status-label"><?php esc_html_e( 'Next Scheduled', 'swish-migrate-and-backup' ); ?></span>
							<span class="swish-backup-status-value">
								<?php
								if ( $next_scheduled ) {
									/* translators: %s: human-readable time difference */
									printf( esc_html__( 'In %s', 'swish-migrate-and-backup' ), esc_html( human_time_diff( $next_scheduled ) ) );
								} else {
									esc_html_e( 'Not scheduled', 'swish-migrate-and-backup' );
								}
								?>
							</span>
						</div>
						<div class="swish-backup-status-item">
							<span class="swish-backup-status-label"><?php esc_html_e( 'Total Backups', 'swish-migrate-and-backup' ); ?></span>
							<span class="swish-backup-status-value"><?php echo esc_html( (string) count( $backups ) ); ?></span>
						</div>
						<div class="swish-backup-status-item">
							<span class="swish-backup-status-label"><?php esc_html_e( 'Storage Used', 'swish-migrate-and-backup' ); ?></span>
							<span class="swish-backup-status-value"><?php echo esc_html( size_format( $storage_info['total'] ?? 0 ) ); ?></span>
						</div>
					</div>
				</div>

				<!-- Storage Destinations -->
				<div class="swish-backup-card">
					<h2><?php esc_html_e( 'Storage Destinations', 'swish-migrate-and-backup' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Storage', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Status', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Usage', 'swish-migrate-and-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $adapters_info as $id => $info ) : ?>
								<tr>
									<td><?php echo esc_html( $info['name'] ); ?></td>
									<td>
										<?php if ( $info['configured'] ) : ?>
											<span class="swish-backup-status-badge swish-backup-status-success">
												<?php esc_html_e( 'Connected', 'swish-migrate-and-backup' ); ?>
											</span>
										<?php else : ?>
											<span class="swish-backup-status-badge swish-backup-status-warning">
												<?php esc_html_e( 'Not Configured', 'swish-migrate-and-backup' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										if ( isset( $info['storage']['used'] ) && null !== $info['storage']['used'] ) {
											echo esc_html( size_format( $info['storage']['used'] ) );
										} else {
											echo '—';
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="swish-backup-card-footer">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=swish-backup-settings' ) ); ?>">
							<?php esc_html_e( 'Configure Storage Settings', 'swish-migrate-and-backup' ); ?> &rarr;
						</a>
					</p>
				</div>

				<!-- Recent Backups -->
				<div class="swish-backup-card">
					<h2><?php esc_html_e( 'Recent Backups', 'swish-migrate-and-backup' ); ?></h2>
					<?php if ( empty( $backups ) ) : ?>
						<p><?php esc_html_e( 'No backups found. Create your first backup now!', 'swish-migrate-and-backup' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Backup', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Date', 'swish-migrate-and-backup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $backups as $backup ) : ?>
									<tr>
										<td><?php echo esc_html( $backup['filename'] ); ?></td>
										<td><?php echo esc_html( ucfirst( $backup['type'] ) ); ?></td>
										<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
										<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup['created_at'] ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<p class="swish-backup-card-footer">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=swish-backup-backups' ) ); ?>">
							<?php esc_html_e( 'View All Backups', 'swish-migrate-and-backup' ); ?> &rarr;
						</a>
					</p>
				</div>
			</div>

			<!-- Backup Progress Modal -->
			<div id="swish-backup-progress-modal" class="swish-backup-modal" style="display:none;">
				<div class="swish-backup-modal-content">
					<h3><?php esc_html_e( 'Backup in Progress', 'swish-migrate-and-backup' ); ?></h3>
					<div class="swish-backup-progress-bar">
						<div class="swish-backup-progress-bar-inner" style="width: 0%;"></div>
					</div>
					<p class="swish-backup-progress-status"><?php esc_html_e( 'Initializing...', 'swish-migrate-and-backup' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
