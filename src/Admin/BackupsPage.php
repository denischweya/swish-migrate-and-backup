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
use SwishMigrateAndBackup\Admin\Multisite\AdminLayout;

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
		<?php
		AdminNav::render_start(
			__( 'Backups', 'swish-migrate-and-backup' ),
			'',
			array(
				'<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-now">'
					. '<span class="material-symbols-outlined" style="font-size: 18px;">backup</span>'
					. '<span>' . esc_html__( 'Create Backup', 'swish-migrate-and-backup' ) . '</span>'
					. '</button>',
				'<button type="button" class="swish-btn swish-btn-secondary swish-backup-settings-btn" id="swish-backup-open-settings">'
					. '<span class="material-symbols-outlined" style="font-size: 18px;">settings</span>'
					. '<span>' . esc_html__( 'Settings', 'swish-migrate-and-backup' ) . '</span>'
					. '</button>',
			)
		);
		?>
			<!-- Active Backup Jobs Container (populated by JavaScript) -->
			<div id="swish-active-jobs-container"></div>

			<!-- Backup Type Selector -->
			<div class="swish-backup-card swish-backup-type-selector" id="swish-backup-type-selector" style="display:none;">
				<h2><?php esc_html_e( 'Select Backup Type', 'swish-migrate-and-backup' ); ?></h2>
				<div class="swish-backup-type-options">
					<div class="swish-backup-type-option" data-type="full">
						<span class="material-symbols-outlined">database</span>
						<h3><?php esc_html_e( 'Full Backup', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Database, files, themes, plugins, and uploads', 'swish-migrate-and-backup' ); ?></p>
					</div>
					<div class="swish-backup-type-option" data-type="database">
						<span class="material-symbols-outlined">database</span>
						<h3><?php esc_html_e( 'Database Only', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Just the database (fastest)', 'swish-migrate-and-backup' ); ?></p>
					</div>
					<div class="swish-backup-type-option" data-type="files">
						<span class="material-symbols-outlined">folder_zip</span>
						<h3><?php esc_html_e( 'Files Only', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Themes, plugins, and uploads', 'swish-migrate-and-backup' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Backup List -->
			<?php if ( empty( $backups ) ) : ?>
				<div class="swish-card swish-mt-4">
					<div class="swish-card-body">
						<?php
						AdminLayout::render_empty_state(
							__( 'No Backups Yet', 'swish-migrate-and-backup' ),
							__( 'Create your first backup to protect your site.', 'swish-migrate-and-backup' ),
							'backup',
							array(
								'label' => __( 'Create First Backup', 'swish-migrate-and-backup' ),
								'icon'  => 'add',
								'id'    => 'swish-backup-first',
							)
						);
						?>
					</div>
				</div>
			<?php else : ?>
				<!-- Bulk Actions Bar -->
				<div class="swish-backup-bulk-actions hidden" id="swish-backup-bulk-actions">
					<span class="swish-backup-bulk-select-info">
						<span id="swish-backup-selected-count">0</span> <?php esc_html_e( 'backup(s) selected', 'swish-migrate-and-backup' ); ?>
					</span>
					<div class="swish-backup-bulk-buttons">
						<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm" id="swish-backup-bulk-download">
							<span class="material-symbols-outlined" style="font-size: 18px;">download</span>
							<?php esc_html_e( 'Download Selected', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-btn swish-btn-ghost swish-btn-sm swish-btn-danger" id="swish-backup-bulk-delete">
							<span class="material-symbols-outlined" style="font-size: 18px;">delete</span>
							<?php esc_html_e( 'Delete Selected', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<div class="swish-card swish-mt-4">
					<div class="swish-card-body" style="padding: 0;">
						<table class="swish-table">
							<thead>
								<tr>
									<th class="check-column">
										<input type="checkbox" id="swish-backup-select-all" title="<?php esc_attr_e( 'Select All', 'swish-migrate-and-backup' ); ?>">
									</th>
									<th><?php esc_html_e( 'Backup', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Date', 'swish-migrate-and-backup' ); ?></th>
									<th style="text-align: right;"><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $backups as $backup ) : ?>
									<?php
									$type_variants = array(
										'full'     => 'swish-badge-info',
										'database' => 'swish-badge-neutral',
										'files'    => 'swish-badge-success',
									);
									$type_variant  = $type_variants[ $backup['type'] ] ?? 'swish-badge-info';
									?>
									<tr data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
										<td class="check-column">
											<input type="checkbox" class="swish-backup-checkbox" value="<?php echo esc_attr( $backup['id'] ); ?>">
										</td>
										<td>
											<span class="swish-table-cell-primary"><?php echo esc_html( $backup['filename'] ); ?></span>
											<?php if ( ! empty( $backup['checksum'] ) ) : ?>
												<span class="swish-table-cell-secondary swish-backup-checksum">
													<?php
													/* translators: %s: checksum value */
													printf( esc_html__( 'SHA256: %s', 'swish-migrate-and-backup' ), esc_html( substr( $backup['checksum'], 0, 16 ) . '...' ) );
													?>
												</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="swish-badge <?php echo esc_attr( $type_variant ); ?>">
												<?php echo esc_html( ucfirst( $backup['type'] ) ); ?>
											</span>
										</td>
										<td><span class="swish-table-cell-mono"><?php echo esc_html( size_format( $backup['size'] ) ); ?></span></td>
										<td>
											<span class="swish-table-cell-secondary"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup['created_at'] ) ) ); ?></span>
										</td>
										<td>
											<div class="swish-table-actions">
												<button type="button" class="swish-btn-icon swish-backup-restore" title="<?php esc_attr_e( 'Restore', 'swish-migrate-and-backup' ); ?>" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
													<span class="material-symbols-outlined">settings_backup_restore</span>
												</button>
												<button type="button" class="swish-btn-icon swish-backup-download" title="<?php esc_attr_e( 'Download', 'swish-migrate-and-backup' ); ?>" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
													<span class="material-symbols-outlined">download</span>
												</button>
												<button type="button" class="swish-btn-icon swish-backup-cli-download" title="<?php esc_attr_e( 'CLI Download', 'swish-migrate-and-backup' ); ?>" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>" data-filename="<?php echo esc_attr( $backup['filename'] ); ?>">
													<span class="material-symbols-outlined">terminal</span>
												</button>
												<button type="button" class="swish-btn-icon danger swish-backup-delete" title="<?php esc_attr_e( 'Delete', 'swish-migrate-and-backup' ); ?>" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
													<span class="material-symbols-outlined">delete</span>
												</button>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
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
						<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-restore-confirm">
							<?php esc_html_e( 'Restore Now', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-btn swish-btn-secondary swish-backup-modal-cancel">
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
							<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm swish-backup-cli-copy" data-target="swish-backup-cli-command">
								<span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>
								<?php esc_html_e( 'Copy', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
					</div>

					<!-- aria2c command -->
					<div id="swish-cli-aria2c" class="swish-cli-tool-section" style="display:none;">
						<div class="swish-backup-cli-command-wrapper">
							<pre id="swish-backup-aria2c-command" class="swish-backup-cli-command"></pre>
							<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm swish-backup-cli-copy" data-target="swish-backup-aria2c-command">
								<span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>
								<?php esc_html_e( 'Copy', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
						<p class="swish-backup-cli-tip">
							<span class="material-symbols-outlined" style="font-size: 16px; vertical-align: middle;">info</span>
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
						<button type="button" class="swish-btn swish-btn-secondary swish-backup-modal-cancel">
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
							<span class="material-symbols-outlined">settings</span>
							<?php esc_html_e( 'Backup Settings', 'swish-migrate-and-backup' ); ?>
						</h3>
						<button type="button" class="swish-modal-close swish-backup-modal-cancel">
							<span class="material-symbols-outlined">close</span>
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
									<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm" data-value="50"><?php esc_html_e( 'Shared (50)', 'swish-migrate-and-backup' ); ?></button>
									<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm" data-value="150"><?php esc_html_e( 'VPS (150)', 'swish-migrate-and-backup' ); ?></button>
									<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm" data-value="300"><?php esc_html_e( 'Dedicated (300)', 'swish-migrate-and-backup' ); ?></button>
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
									<span class="material-symbols-outlined">database</span>
									<?php esc_html_e( 'Database', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-plugins" checked>
									<span class="material-symbols-outlined">extension</span>
									<?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-themes" checked>
									<span class="material-symbols-outlined">palette</span>
									<?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-uploads" checked>
									<span class="material-symbols-outlined">image</span>
									<?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?>
								</label>
								<label class="swish-checkbox-item">
									<input type="checkbox" id="swish-backup-core">
									<span class="material-symbols-outlined">public</span>
									<?php esc_html_e( 'Core Files', 'swish-migrate-and-backup' ); ?>
								</label>
							</div>
						</div>

						<!-- Hosting Presets -->
						<div class="swish-settings-section">
							<h4><?php esc_html_e( 'Quick Presets', 'swish-migrate-and-backup' ); ?></h4>
							<div class="swish-hosting-presets">
								<button type="button" class="swish-btn swish-btn-secondary" data-preset="shared">
									<span class="material-symbols-outlined">cloud</span>
									<?php esc_html_e( 'Shared Hosting', 'swish-migrate-and-backup' ); ?>
								</button>
								<button type="button" class="swish-btn swish-btn-secondary" data-preset="vps">
									<span class="material-symbols-outlined">computer</span>
									<?php esc_html_e( 'VPS / Managed', 'swish-migrate-and-backup' ); ?>
								</button>
								<button type="button" class="swish-btn swish-btn-secondary" data-preset="dedicated">
									<span class="material-symbols-outlined">apartment</span>
									<?php esc_html_e( 'Dedicated', 'swish-migrate-and-backup' ); ?>
								</button>
							</div>
						</div>
					</div>

					<div class="swish-modal-footer">
						<button type="button" class="swish-btn swish-btn-secondary swish-backup-modal-cancel">
							<?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-save-settings">
							<?php esc_html_e( 'Save Settings', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>
		<?php
		AdminNav::render_end();
	}
}
