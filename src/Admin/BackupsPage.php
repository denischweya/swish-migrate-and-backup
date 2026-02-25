<?php
/**
 * Backups Admin Page.
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
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Storage\StorageManager;

/**
 * Backups page controller.
 */
final class BackupsPage {

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
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Constructor.
	 *
	 * @param BackupManager  $backup_manager  Backup manager.
	 * @param RestoreManager $restore_manager Restore manager.
	 * @param StorageManager $storage_manager Storage manager.
	 */
	public function __construct(
		BackupManager $backup_manager,
		RestoreManager $restore_manager,
		StorageManager $storage_manager
	) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->storage_manager = $storage_manager;
	}

	/**
	 * Render the backups page.
	 *
	 * @return void
	 */
	public function render(): void {
		$backups = $this->backup_manager->get_backups( 50 );
		?>
		<div class="wrap swish-backup-wrap">
			<?php AdminNav::render(); ?>

			<h1 class="wp-heading-inline"><?php esc_html_e( 'Backups', 'swish-migrate-and-backup' ); ?></h1>
			<button type="button" class="page-title-action" id="swish-backup-now">
				<?php esc_html_e( 'Create Backup', 'swish-migrate-and-backup' ); ?>
			</button>
			<hr class="wp-header-end">

			<!-- Backup Type Selector -->
			<div class="swish-backup-card swish-backup-type-selector" id="swish-backup-type-selector" style="display:none;">
				<h2><?php esc_html_e( 'Select Backup Type', 'swish-migrate-and-backup' ); ?></h2>
				<div class="swish-backup-type-options">
					<div class="swish-backup-type-option" data-type="full">
						<span class="dashicons dashicons-database-export"></span>
						<h3><?php esc_html_e( 'Full Backup', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Database, files, themes, plugins, and uploads', 'swish-migrate-and-backup' ); ?></p>
					</div>
					<div class="swish-backup-type-option" data-type="database">
						<span class="dashicons dashicons-database"></span>
						<h3><?php esc_html_e( 'Database Only', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Just the database (fastest)', 'swish-migrate-and-backup' ); ?></p>
					</div>
					<div class="swish-backup-type-option" data-type="files">
						<span class="dashicons dashicons-media-archive"></span>
						<h3><?php esc_html_e( 'Files Only', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Themes, plugins, and uploads', 'swish-migrate-and-backup' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Backup List -->
			<?php if ( empty( $backups ) ) : ?>
				<div class="swish-backup-empty-state">
					<span class="dashicons dashicons-cloud-saved"></span>
					<h2><?php esc_html_e( 'No Backups Yet', 'swish-migrate-and-backup' ); ?></h2>
					<p><?php esc_html_e( 'Create your first backup to protect your site.', 'swish-migrate-and-backup' ); ?></p>
					<button type="button" class="button button-primary button-hero" id="swish-backup-first">
						<?php esc_html_e( 'Create First Backup', 'swish-migrate-and-backup' ); ?>
					</button>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="column-filename"><?php esc_html_e( 'Backup', 'swish-migrate-and-backup' ); ?></th>
							<th class="column-type"><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
							<th class="column-size"><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
							<th class="column-date"><?php esc_html_e( 'Date', 'swish-migrate-and-backup' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $backup ) : ?>
							<tr data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
								<td class="column-filename">
									<strong><?php echo esc_html( $backup['filename'] ); ?></strong>
									<?php if ( ! empty( $backup['checksum'] ) ) : ?>
										<br><small class="swish-backup-checksum">
											<?php
											/* translators: %s: checksum value */
											printf( esc_html__( 'SHA256: %s', 'swish-migrate-and-backup' ), esc_html( substr( $backup['checksum'], 0, 16 ) . '...' ) );
											?>
										</small>
									<?php endif; ?>
								</td>
								<td class="column-type">
									<span class="swish-backup-type-badge swish-backup-type-<?php echo esc_attr( $backup['type'] ); ?>">
										<?php echo esc_html( ucfirst( $backup['type'] ) ); ?>
									</span>
								</td>
								<td class="column-size"><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
								<td class="column-date">
									<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup['created_at'] ) ) ); ?>
								</td>
								<td class="column-actions">
									<button type="button" class="button button-small swish-backup-restore" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
										<?php esc_html_e( 'Restore', 'swish-migrate-and-backup' ); ?>
									</button>
									<button type="button" class="button button-small swish-backup-download" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
										<?php esc_html_e( 'Download', 'swish-migrate-and-backup' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete swish-backup-delete" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
										<?php esc_html_e( 'Delete', 'swish-migrate-and-backup' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Modals -->
			<div id="swish-backup-progress-modal" class="swish-backup-modal" style="display:none;">
				<div class="swish-backup-modal-content">
					<h3 id="swish-backup-modal-title"><?php esc_html_e( 'Backup in Progress', 'swish-migrate-and-backup' ); ?></h3>
					<div class="swish-backup-progress-bar">
						<div class="swish-backup-progress-bar-inner" style="width: 0%;"></div>
					</div>
					<p class="swish-backup-progress-status"><?php esc_html_e( 'Initializing...', 'swish-migrate-and-backup' ); ?></p>
				</div>
			</div>

			<div id="swish-backup-restore-modal" class="swish-backup-modal" style="display:none;">
				<div class="swish-backup-modal-content">
					<h3><?php esc_html_e( 'Restore Backup', 'swish-migrate-and-backup' ); ?></h3>
					<p class="swish-backup-warning">
						<?php esc_html_e( 'Warning: This will overwrite your current site data. This action cannot be undone.', 'swish-migrate-and-backup' ); ?>
					</p>
					<div class="swish-backup-restore-options">
						<label>
							<input type="checkbox" name="restore_database" checked>
							<?php esc_html_e( 'Restore Database', 'swish-migrate-and-backup' ); ?>
						</label>
						<label>
							<input type="checkbox" name="restore_files" checked>
							<?php esc_html_e( 'Restore Files', 'swish-migrate-and-backup' ); ?>
						</label>
						<label>
							<input type="checkbox" name="create_backup" checked>
							<?php esc_html_e( 'Create backup before restore', 'swish-migrate-and-backup' ); ?>
						</label>
					</div>
					<div class="swish-backup-modal-actions">
						<button type="button" class="button button-primary" id="swish-backup-restore-confirm">
							<?php esc_html_e( 'Restore Now', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="button swish-backup-modal-cancel">
							<?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
