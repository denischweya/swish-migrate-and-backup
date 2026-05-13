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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Backups', 'swish-migrate-and-backup' ); ?></h1>
			<button type="button" class="page-title-action" id="swish-backup-now">
				<?php esc_html_e( 'Create Backup', 'swish-migrate-and-backup' ); ?>
			</button>
			<button type="button" class="page-title-action swish-backup-settings-btn" id="swish-backup-open-settings">
				<span class="dashicons dashicons-admin-generic" style="vertical-align: middle; margin-top: -2px;"></span>
				<?php esc_html_e( 'Settings', 'swish-migrate-and-backup' ); ?>
			</button>
			<hr class="wp-header-end">

			<?php AdminNav::render(); ?>

			<!-- Active Backup Jobs Container (populated by JavaScript) -->
			<div id="swish-active-jobs-container"></div>

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
				<!-- Bulk Actions Bar -->
				<div class="swish-backup-bulk-actions hidden" id="swish-backup-bulk-actions">
					<span class="swish-backup-bulk-select-info">
						<span id="swish-backup-selected-count">0</span> <?php esc_html_e( 'backup(s) selected', 'swish-migrate-and-backup' ); ?>
					</span>
					<div class="swish-backup-bulk-buttons">
						<button type="button" class="button button-secondary" id="swish-backup-bulk-download">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Download Selected', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="button button-link-delete" id="swish-backup-bulk-delete">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Delete Selected', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="column-cb check-column">
								<input type="checkbox" id="swish-backup-select-all" title="<?php esc_attr_e( 'Select All', 'swish-migrate-and-backup' ); ?>">
							</th>
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
								<td class="column-cb check-column">
									<input type="checkbox" class="swish-backup-checkbox" value="<?php echo esc_attr( $backup['id'] ); ?>">
								</td>
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
									<button type="button" class="button button-small swish-backup-cli-download" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>" data-filename="<?php echo esc_attr( $backup['filename'] ); ?>">
										<?php esc_html_e( 'CLI', 'swish-migrate-and-backup' ); ?>
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

			<div id="swish-backup-cli-modal" class="swish-backup-modal" style="display:none;">
				<div class="swish-backup-modal-content swish-backup-cli-modal-content">
					<h3><?php esc_html_e( 'CLI Download Command', 'swish-migrate-and-backup' ); ?></h3>
					<p class="swish-backup-cli-description">
						<?php esc_html_e( 'Use these commands to download the backup via command line. Both support resume on failure.', 'swish-migrate-and-backup' ); ?>
					</p>

					<!-- Tool Toggle -->
					<div class="swish-backup-cli-toggle">
						<button type="button" class="swish-cli-tab active" data-tool="curl">
							<?php esc_html_e( 'curl', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-cli-tab" data-tool="aria2c">
							<?php esc_html_e( 'aria2c', 'swish-migrate-and-backup' ); ?>
							<span class="swish-cli-recommended"><?php esc_html_e( 'Recommended for 3GB+', 'swish-migrate-and-backup' ); ?></span>
						</button>
					</div>

					<!-- curl command -->
					<div id="swish-cli-curl" class="swish-cli-tool-section">
						<div class="swish-backup-cli-command-wrapper">
							<pre id="swish-backup-cli-command" class="swish-backup-cli-command"></pre>
							<button type="button" class="button button-small swish-backup-cli-copy" data-target="swish-backup-cli-command">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
					</div>

					<!-- aria2c command -->
					<div id="swish-cli-aria2c" class="swish-cli-tool-section" style="display:none;">
						<div class="swish-backup-cli-command-wrapper">
							<pre id="swish-backup-aria2c-command" class="swish-backup-cli-command"></pre>
							<button type="button" class="button button-small swish-backup-cli-copy" data-target="swish-backup-aria2c-command">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
						<p class="swish-backup-cli-tip">
							<span class="dashicons dashicons-info-outline"></span>
							<?php
							printf(
								/* translators: %s: link to documentation */
								esc_html__( 'aria2c not installed? See %s for installation instructions.', 'swish-migrate-and-backup' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=swish-backup-docs#aria2c-installation' ) ) . '">' . esc_html__( 'documentation', 'swish-migrate-and-backup' ) . '</a>'
							);
							?>
						</p>
					</div>

					<p class="swish-backup-cli-note">
						<strong><?php esc_html_e( 'Note:', 'swish-migrate-and-backup' ); ?></strong>
						<?php esc_html_e( 'The download link expires in 24 hours. If interrupted, re-run the same command to resume.', 'swish-migrate-and-backup' ); ?>
					</p>
					<div class="swish-backup-modal-actions">
						<button type="button" class="button swish-backup-modal-cancel">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Settings Modal -->
			<div id="swish-backup-settings-modal" class="swish-backup-modal" style="display:none;">
				<div class="swish-backup-modal-content swish-backup-settings-modal-content">
					<div class="swish-modal-header">
						<h3>
							<span class="dashicons dashicons-admin-settings"></span>
							<?php esc_html_e( 'Backup Settings', 'swish-migrate-and-backup' ); ?>
						</h3>
						<button type="button" class="swish-modal-close swish-backup-modal-cancel">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>

					<div class="swish-settings-body">
						<!-- Performance Section -->
						<div class="swish-settings-section">
							<h4><?php esc_html_e( 'Performance', 'swish-migrate-and-backup' ); ?></h4>
							<p class="description"><?php esc_html_e( 'Adjust batch sizes based on your hosting environment. Lower values are safer for shared hosting.', 'swish-migrate-and-backup' ); ?></p>

							<div class="swish-setting-row">
								<label for="swish-pipeline-batch-size"><?php esc_html_e( 'Files per Request', 'swish-migrate-and-backup' ); ?></label>
								<div class="swish-range-control">
									<input type="range" id="swish-pipeline-batch-size" min="25" max="500" step="25" value="150">
									<span class="swish-range-value">150</span>
								</div>
								<div class="swish-preset-buttons">
									<button type="button" class="button button-small" data-value="50"><?php esc_html_e( 'Shared (50)', 'swish-migrate-and-backup' ); ?></button>
									<button type="button" class="button button-small" data-value="150"><?php esc_html_e( 'VPS (150)', 'swish-migrate-and-backup' ); ?></button>
									<button type="button" class="button button-small" data-value="300"><?php esc_html_e( 'Dedicated (300)', 'swish-migrate-and-backup' ); ?></button>
								</div>
							</div>

							<div class="swish-setting-row">
								<label for="swish-db-batch-size"><?php esc_html_e( 'Database Rows per Batch', 'swish-migrate-and-backup' ); ?></label>
								<div class="swish-range-control">
									<input type="range" id="swish-db-batch-size" min="100" max="2000" step="100" value="500">
									<span class="swish-range-value">500</span>
								</div>
							</div>
						</div>

						<!-- Backup Contents Section -->
						<div class="swish-settings-section">
							<h4><?php esc_html_e( 'Default Backup Contents', 'swish-migrate-and-backup' ); ?></h4>

							<div class="swish-checkbox-grid">
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-database" checked>
									<span class="dashicons dashicons-database"></span>
									<?php esc_html_e( 'Database', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-plugins" checked>
									<span class="dashicons dashicons-admin-plugins"></span>
									<?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-themes" checked>
									<span class="dashicons dashicons-admin-appearance"></span>
									<?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-uploads" checked>
									<span class="dashicons dashicons-admin-media"></span>
									<?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-core">
									<span class="dashicons dashicons-wordpress"></span>
									<?php esc_html_e( 'Core Files', 'swish-migrate-and-backup' ); ?>
								</label>
							</div>
						</div>

						<!-- Hosting Presets -->
						<div class="swish-settings-section">
							<h4><?php esc_html_e( 'Quick Presets', 'swish-migrate-and-backup' ); ?></h4>
							<div class="swish-hosting-presets">
								<button type="button" class="button" data-preset="shared">
									<span class="dashicons dashicons-cloud"></span>
									<?php esc_html_e( 'Shared Hosting', 'swish-migrate-and-backup' ); ?>
								</button>
								<button type="button" class="button" data-preset="vps">
									<span class="dashicons dashicons-desktop"></span>
									<?php esc_html_e( 'VPS / Managed', 'swish-migrate-and-backup' ); ?>
								</button>
								<button type="button" class="button" data-preset="dedicated">
									<span class="dashicons dashicons-building"></span>
									<?php esc_html_e( 'Dedicated', 'swish-migrate-and-backup' ); ?>
								</button>
							</div>
						</div>
					</div>

					<div class="swish-modal-footer">
						<button type="button" class="button swish-backup-modal-cancel">
							<?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="button button-primary" id="swish-backup-save-settings">
							<?php esc_html_e( 'Save Settings', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
