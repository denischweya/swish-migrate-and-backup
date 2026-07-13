<?php
/**
 * Migration UI Component.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Multisite\MultisiteMigration;
use SwishMigrateAndBackup\Multisite\MultisiteManager;

/**
 * Renders migration UI for multisite backups.
 */
final class MigrationUI {

	/**
	 * Migration handler.
	 *
	 * @var MultisiteMigration
	 */
	private MultisiteMigration $migration;

	/**
	 * Multisite manager.
	 *
	 * @var MultisiteManager
	 */
	private MultisiteManager $manager;

	/**
	 * Constructor.
	 *
	 * @param MultisiteMigration $migration Migration handler.
	 * @param MultisiteManager   $manager   Multisite manager.
	 */
	public function __construct( MultisiteMigration $migration, MultisiteManager $manager ) {
		$this->migration = $migration;
		$this->manager   = $manager;
	}

	/**
	 * Render migration page.
	 *
	 * @return void
	 */
	public function render(): void {
		$backups           = $this->manager->get_multisite_backups( 50 );
		$available_backups = $this->migration->get_available_backups();

		?>
		<!-- Tab Navigation -->
		<div class="swish-tabs">
			<button type="button" class="swish-tab-btn active" data-tab="export">
				<span class="material-symbols-outlined">download</span>
				<?php esc_html_e( 'Export / Download', 'swish-migrate-and-backup' ); ?>
			</button>
			<button type="button" class="swish-tab-btn" data-tab="import">
				<span class="material-symbols-outlined">upload</span>
				<?php esc_html_e( 'Import / Restore', 'swish-migrate-and-backup' ); ?>
			</button>
		</div>

		<!-- Export Tab -->
		<div class="swish-tab-panel active" id="tab-export">
			<div class="swish-card">
				<div class="swish-card-header">
					<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
						<span class="material-symbols-outlined" style="vertical-align: middle; margin-right: var(--swish-space-2);">cloud_download</span>
						<?php esc_html_e( 'Available Multisite Backups', 'swish-migrate-and-backup' ); ?>
					</h4>
				</div>
				<div class="swish-card-body">
					<p class="swish-text-secondary" style="margin-bottom: var(--swish-space-4);">
						<?php esc_html_e( 'Download backups to migrate to another server or keep as off-site storage.', 'swish-migrate-and-backup' ); ?>
					</p>

					<?php if ( empty( $backups ) ) : ?>
						<?php
						AdminLayout::render_empty_state(
							__( 'No Backups Available', 'swish-migrate-and-backup' ),
							__( 'Create a multisite backup first to enable migration.', 'swish-migrate-and-backup' ),
							'backup'
						);
						?>
						<div style="text-align: center; margin-top: var(--swish-space-4);">
							<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=swish-backup-multisite&tab=backup' ) ); ?>" class="swish-btn swish-btn-primary">
								<span class="material-symbols-outlined">add</span>
								<?php esc_html_e( 'Create Backup', 'swish-migrate-and-backup' ); ?>
							</a>
						</div>
					<?php else : ?>
						<table class="swish-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date & Time', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Archive Mode', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Sites', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
									<th style="text-align: right;"><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $backups as $backup ) : ?>
									<?php
									$total_size   = 0;
									foreach ( $backup['files'] as $file ) {
										$total_size += $file['size'];
									}
									$created_time = strtotime( $backup['created_at'] );
									?>
									<tr>
										<td>
											<div class="swish-flex" style="flex-direction: column;">
												<span class="swish-table-cell-primary">
													<?php echo esc_html( wp_date( get_option( 'date_format' ), $created_time ) ); ?>
												</span>
												<span class="swish-table-cell-secondary">
													<?php echo esc_html( wp_date( get_option( 'time_format' ), $created_time ) ); ?>
												</span>
											</div>
										</td>
										<td>
											<?php if ( $backup['archive_mode'] === 'single' ) : ?>
												<span class="swish-badge swish-badge-info">
													<?php esc_html_e( 'Single Archive', 'swish-migrate-and-backup' ); ?>
												</span>
											<?php else : ?>
												<span class="swish-badge swish-badge-success">
													<?php esc_html_e( 'Separate', 'swish-migrate-and-backup' ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="swish-table-cell-primary">
												<?php
												printf(
													/* translators: %d: number of sites */
													esc_html( _n( '%d site', '%d sites', $backup['total_sites'], 'swish-migrate-and-backup' ) ),
													absint( $backup['total_sites'] )
												);
												?>
											</span>
										</td>
										<td>
											<span class="swish-table-cell-mono"><?php echo esc_html( size_format( $total_size ) ); ?></span>
										</td>
										<td>
											<div class="swish-table-actions">
												<?php foreach ( $backup['files'] as $file ) : ?>
													<a href="<?php echo esc_url( $this->manager->get_backup_download_url( $file['filename'] ) ); ?>"
													   class="swish-btn swish-btn-secondary swish-btn-sm"
													   download
													   title="<?php echo esc_attr( $file['filename'] ); ?>">
														<span class="material-symbols-outlined">download</span>
														<?php
														if ( $file['site_id'] ) {
															printf(
																/* translators: %d: site ID */
																esc_html__( 'Site %d', 'swish-migrate-and-backup' ),
																absint( $file['site_id'] )
															);
														} else {
															esc_html_e( 'Download', 'swish-migrate-and-backup' );
														}
														?>
													</a>
												<?php endforeach; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Import Tab -->
		<div class="swish-tab-panel" id="tab-import">
			<div class="swish-card">
				<div class="swish-card-header">
					<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
						<span class="material-symbols-outlined" style="vertical-align: middle; margin-right: var(--swish-space-2);">cloud_upload</span>
						<?php esc_html_e( 'Import Multisite Backup', 'swish-migrate-and-backup' ); ?>
					</h4>
				</div>
				<div class="swish-card-body">
					<p class="swish-text-secondary" style="margin-bottom: var(--swish-space-4);">
						<?php esc_html_e( 'Upload a multisite backup file to restore or migrate sites.', 'swish-migrate-and-backup' ); ?>
					</p>

					<?php
					AdminLayout::render_alert(
						'warning',
						__( 'Warning:', 'swish-migrate-and-backup' ),
						__( 'Importing a backup will overwrite existing data. Make sure you have a current backup before proceeding.', 'swish-migrate-and-backup' )
					);
					?>

					<form id="swish-import-form" method="post" enctype="multipart/form-data" style="margin-top: var(--swish-space-6);">
						<?php wp_nonce_field( 'swish_multisite_import', 'swish_import_nonce' ); ?>

						<div class="swish-form-group">
							<label class="swish-label" for="backup_file">
								<?php esc_html_e( 'Upload Backup File', 'swish-migrate-and-backup' ); ?>
							</label>
							<div class="swish-file-dropzone" id="swish-file-dropzone">
								<input type="file" name="backup_file" id="backup_file" accept=".zip,.swish" class="swish-file-input-hidden" />
								<div class="swish-dropzone-content" id="swish-dropzone-content">
									<span class="material-symbols-outlined swish-dropzone-icon">cloud_upload</span>
									<p class="swish-dropzone-text">
										<?php esc_html_e( 'Drag and drop your backup file here', 'swish-migrate-and-backup' ); ?>
									</p>
									<p class="swish-dropzone-subtext">
										<?php esc_html_e( 'or', 'swish-migrate-and-backup' ); ?>
									</p>
									<button type="button" class="swish-btn swish-btn-secondary" id="swish-choose-file-btn">
										<span class="material-symbols-outlined">folder_open</span>
										<?php esc_html_e( 'Choose File', 'swish-migrate-and-backup' ); ?>
									</button>
									<p class="swish-dropzone-hint">
										<?php esc_html_e( 'Accepts .zip and .swish files', 'swish-migrate-and-backup' ); ?>
									</p>
								</div>
								<div class="swish-dropzone-selected" id="swish-dropzone-selected" style="display: none;">
									<span class="material-symbols-outlined swish-file-icon">description</span>
									<div class="swish-file-info">
										<span class="swish-file-name" id="swish-selected-filename"></span>
										<span class="swish-file-size" id="swish-selected-filesize"></span>
									</div>
									<button type="button" class="swish-btn swish-btn-icon" id="swish-remove-file" title="<?php esc_attr_e( 'Remove file', 'swish-migrate-and-backup' ); ?>">
										<span class="material-symbols-outlined">close</span>
									</button>
								</div>
							</div>
						</div>

						<div class="swish-form-group">
							<label class="swish-label">
								<?php esc_html_e( 'Or Select Existing Backup', 'swish-migrate-and-backup' ); ?>
							</label>
							<?php if ( ! empty( $available_backups ) ) : ?>
								<select name="existing_backup" id="existing_backup" class="swish-select">
									<option value=""><?php esc_html_e( '— Select a backup —', 'swish-migrate-and-backup' ); ?></option>
									<?php foreach ( $available_backups as $backup ) : ?>
										<option value="<?php echo esc_attr( $backup['path'] ); ?>">
											<?php echo esc_html( $backup['filename'] ); ?>
											(<?php echo esc_html( size_format( $backup['size'] ) ); ?>,
											<?php echo esc_html( $backup['site_count'] ); ?> <?php esc_html_e( 'sites', 'swish-migrate-and-backup' ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<p class="swish-help-text"><?php esc_html_e( 'No existing multisite backups found.', 'swish-migrate-and-backup' ); ?></p>
							<?php endif; ?>
						</div>

						<!-- Multisite Required Notice -->
						<div id="swish-multisite-required" class="swish-alert swish-alert-error" style="display: none; margin: var(--swish-space-6) 0;">
							<div class="swish-alert-icon">
								<span class="material-symbols-outlined">warning</span>
							</div>
							<div class="swish-alert-content">
								<h4 class="swish-alert-title"><?php esc_html_e( 'WordPress Multisite Setup Required', 'swish-migrate-and-backup' ); ?></h4>
								<p><?php esc_html_e( 'This backup was created from a WordPress multisite network. To import it, you must first convert your WordPress installation to a multisite network.', 'swish-migrate-and-backup' ); ?></p>
								<p><strong><?php esc_html_e( 'Automatic conversion is not supported.', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Please follow the manual steps below to set up multisite, then return to this page to import your backup.', 'swish-migrate-and-backup' ); ?></p>
								<div id="swish-multisite-instructions" style="margin-top: var(--swish-space-4);"></div>
								<p style="margin-top: var(--swish-space-4);">
									<a href="https://developer.wordpress.org/advanced-administration/multisite/create-network/" target="_blank" class="swish-btn swish-btn-secondary">
										<span class="material-symbols-outlined">open_in_new</span>
										<?php esc_html_e( 'Read Official Documentation', 'swish-migrate-and-backup' ); ?>
									</a>
								</p>
							</div>
						</div>

						<!-- Site Selector (shown for multisite backups on single site) -->
						<div id="swish-site-selector" style="display: none; margin-top: var(--swish-space-6); padding: var(--swish-space-4); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
							<h4 style="margin: 0 0 var(--swish-space-3) 0; font-size: var(--swish-text-base); font-weight: var(--swish-font-semibold);">
								<span class="material-symbols-outlined" style="vertical-align: middle; margin-right: var(--swish-space-2);">web</span>
								<?php esc_html_e( 'Select Site to Import', 'swish-migrate-and-backup' ); ?>
							</h4>
							<p class="swish-text-secondary" style="margin-bottom: var(--swish-space-3);">
								<?php esc_html_e( 'This multisite backup contains multiple sites. Select which site to import into this WordPress installation.', 'swish-migrate-and-backup' ); ?>
							</p>
							<div class="swish-form-group">
								<select name="site_id" id="swish-site-select" class="swish-select">
									<option value=""><?php esc_html_e( '— Select a site —', 'swish-migrate-and-backup' ); ?></option>
								</select>
							</div>
						</div>

						<!-- Import Options (shown after validation) -->
						<div id="swish-import-options" style="display: none; margin-top: var(--swish-space-6); padding-top: var(--swish-space-6); border-top: 1px solid var(--swish-border-light);">
							<h4 style="margin: 0 0 var(--swish-space-4) 0; font-size: var(--swish-text-base); font-weight: var(--swish-font-semibold);">
								<?php esc_html_e( 'Import Options', 'swish-migrate-and-backup' ); ?>
							</h4>

							<div class="swish-form-group">
								<label class="swish-label"><?php esc_html_e( 'URL Search & Replace', 'swish-migrate-and-backup' ); ?></label>
								<div class="swish-search-replace">
									<input type="text" name="search_url" id="swish-search-url" placeholder="<?php esc_attr_e( 'Old URL (e.g., https://old-site.com)', 'swish-migrate-and-backup' ); ?>" class="swish-input" />
									<span class="swish-search-replace-arrow material-symbols-outlined">arrow_forward</span>
									<input type="text" name="replace_url" id="swish-replace-url" placeholder="<?php esc_attr_e( 'New URL (e.g., https://new-site.com)', 'swish-migrate-and-backup' ); ?>" class="swish-input" />
								</div>
								<p class="swish-help-text">
									<?php esc_html_e( 'Replace URLs in the database during import. Leave empty to keep original URLs.', 'swish-migrate-and-backup' ); ?>
								</p>
								<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm" id="swish-preview-changes" style="margin-top: var(--swish-space-2);">
									<span class="material-symbols-outlined">preview</span>
									<?php esc_html_e( 'Preview Changes', 'swish-migrate-and-backup' ); ?>
								</button>
								<div id="swish-preview-results" class="swish-preview-box" style="display: none;">
									<h4><?php esc_html_e( 'Preview Results', 'swish-migrate-and-backup' ); ?></h4>
									<div id="swish-preview-content"></div>
								</div>
							</div>

							<div class="swish-form-group" id="swish-shared-tables-group">
								<label class="swish-checkbox-label">
									<input type="checkbox" name="import_shared_tables" id="swish-import-shared-tables" value="1" class="swish-checkbox" />
									<span class="swish-checkbox-text"><?php esc_html_e( 'Import shared network tables (users, usermeta, etc.)', 'swish-migrate-and-backup' ); ?></span>
								</label>
								<p class="swish-help-text" id="swish-shared-tables-help" style="margin-left: 24px;">
									<?php esc_html_e( 'Warning: This will overwrite all user data across the network.', 'swish-migrate-and-backup' ); ?>
								</p>
							</div>

							<div id="swish-backup-preview" class="swish-preview-box" style="display: none;">
								<h4><?php esc_html_e( 'Backup Contents', 'swish-migrate-and-backup' ); ?></h4>
								<div id="swish-backup-preview-content"></div>
							</div>
						</div>

						<!-- Inline error box for validation errors -->
						<div id="swish-upload-error" class="swish-alert swish-alert-error" style="display: none; margin-top: var(--swish-space-4);">
							<div class="swish-alert-icon">
								<span class="material-symbols-outlined">error</span>
							</div>
							<div class="swish-alert-content">
								<h4 class="swish-alert-title" id="swish-upload-error-title"><?php esc_html_e( 'Error', 'swish-migrate-and-backup' ); ?></h4>
								<div id="swish-upload-error-content"></div>
							</div>
							<button type="button" class="swish-alert-close" id="swish-upload-error-close">
								<span class="material-symbols-outlined">close</span>
							</button>
						</div>

						<div class="swish-form-actions" style="margin-top: var(--swish-space-6);">
							<button type="button" class="swish-btn swish-btn-primary" id="swish-validate-backup">
								<span class="material-symbols-outlined">check_circle</span>
								<?php esc_html_e( 'Continue', 'swish-migrate-and-backup' ); ?>
							</button>
							<button type="submit" class="swish-btn swish-btn-primary" id="swish-start-import" style="display: none;">
								<span class="material-symbols-outlined">cloud_upload</span>
								<?php esc_html_e( 'Start Import', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<!-- Import Progress Modal -->
		<div id="swish-import-modal" class="swish-modal-overlay" style="display: none;">
			<div class="swish-modal-content">
				<div class="swish-modal-header">
					<h3 class="swish-modal-title">
						<span class="material-symbols-outlined">cloud_upload</span>
						<?php esc_html_e( 'Importing Backup', 'swish-migrate-and-backup' ); ?>
					</h3>
				</div>
				<div class="swish-modal-body">
					<div class="swish-progress-container">
						<div class="swish-progress-bar">
							<div class="swish-progress-fill" id="swish-import-progress-fill" style="width: 0%;"></div>
						</div>
						<div style="text-align: center; margin-top: var(--swish-space-3);">
							<span id="swish-import-progress-percent" style="font-size: var(--swish-text-2xl); font-weight: var(--swish-font-bold); color: var(--swish-text-primary);">0%</span>
						</div>
					</div>

					<div style="text-align: center; margin: var(--swish-space-4) 0;">
						<p id="swish-import-progress-message" style="margin: 0; color: var(--swish-text-secondary);">
							<?php esc_html_e( 'Initializing import...', 'swish-migrate-and-backup' ); ?>
						</p>
					</div>

					<div style="background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg); padding: var(--swish-space-4);">
						<h4 style="margin: 0 0 var(--swish-space-3) 0; font-size: var(--swish-text-sm); font-weight: var(--swish-font-semibold); text-transform: uppercase; letter-spacing: 0.5px;">
							<?php esc_html_e( 'Import Progress', 'swish-migrate-and-backup' ); ?>
						</h4>
						<div class="swish-import-log" id="swish-import-log"></div>
					</div>
				</div>
				<div class="swish-modal-footer">
					<button type="button" class="swish-btn swish-btn-secondary" id="swish-import-run-background">
						<span class="material-symbols-outlined">background_replace</span>
						<?php esc_html_e( 'Run in Background', 'swish-migrate-and-backup' ); ?>
					</button>
				</div>

				<!-- Success state -->
				<div class="swish-modal-state" id="swish-import-modal-success" style="display: none;">
					<div class="swish-modal-state-icon success">
						<span class="material-symbols-outlined">check_circle</span>
					</div>
					<h3><?php esc_html_e( 'Import Complete!', 'swish-migrate-and-backup' ); ?></h3>
					<p id="swish-import-success-message"><?php esc_html_e( 'Your backup has been imported successfully.', 'swish-migrate-and-backup' ); ?></p>
					<div class="swish-modal-state-actions">
						<button type="button" class="swish-btn swish-btn-primary" id="swish-import-close-success">
							<?php esc_html_e( 'Close & Reload', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Error state -->
				<div class="swish-modal-state" id="swish-import-modal-error" style="display: none;">
					<div class="swish-modal-state-icon error">
						<span class="material-symbols-outlined">error</span>
					</div>
					<h3><?php esc_html_e( 'Import Failed', 'swish-migrate-and-backup' ); ?></h3>
					<p id="swish-import-error-message"></p>
					<div class="swish-modal-state-actions">
						<button type="button" class="swish-btn swish-btn-primary" id="swish-import-retry">
							<?php esc_html_e( 'Try Again', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-btn swish-btn-secondary" id="swish-import-close-error">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Background state -->
				<div class="swish-modal-state" id="swish-import-modal-background" style="display: none;">
					<div class="swish-modal-state-icon info">
						<span class="material-symbols-outlined">sync</span>
					</div>
					<h3><?php esc_html_e( 'Running in Background', 'swish-migrate-and-backup' ); ?></h3>
					<p><?php esc_html_e( 'The import is now running in the background. You can safely navigate away from this page.', 'swish-migrate-and-backup' ); ?></p>
					<p style="font-size: var(--swish-text-sm); color: var(--swish-text-tertiary);">
						<?php esc_html_e( 'You may need to log in again after the import completes.', 'swish-migrate-and-backup' ); ?>
					</p>
					<div class="swish-modal-state-actions">
						<button type="button" class="swish-btn swish-btn-primary" id="swish-import-close-background">
							<?php esc_html_e( 'Got it', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Session expired state (shown when database import invalidates session) -->
				<div class="swish-modal-state" id="swish-import-modal-session-expired" style="display: none;">
					<div class="swish-modal-state-icon success">
						<span class="material-symbols-outlined">check_circle</span>
					</div>
					<h3><?php esc_html_e( 'Import Likely Completed!', 'swish-migrate-and-backup' ); ?></h3>
					<p><?php esc_html_e( 'The database has been imported successfully. Your session was invalidated because the users table was replaced.', 'swish-migrate-and-backup' ); ?></p>
					<p style="font-size: var(--swish-text-sm); color: var(--swish-text-tertiary);">
						<?php esc_html_e( 'Please log in again to verify your site. Use credentials from the imported backup.', 'swish-migrate-and-backup' ); ?>
					</p>
					<div class="swish-modal-state-actions">
						<a href="<?php echo esc_url( wp_login_url() ); ?>" class="swish-btn swish-btn-primary">
							<?php esc_html_e( 'Go to Login', 'swish-migrate-and-backup' ); ?>
						</a>
						<button type="button" class="swish-btn swish-btn-secondary" id="swish-import-close-session-expired">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<style>
			/* Inline upload error styling */
			#swish-upload-error {
				position: relative;
			}
			#swish-upload-error .swish-alert-close {
				position: absolute;
				top: var(--swish-space-3, 12px);
				right: var(--swish-space-3, 12px);
				background: none;
				border: none;
				cursor: pointer;
				padding: var(--swish-space-1, 4px);
				color: var(--swish-error, #ef4444);
				border-radius: var(--swish-radius-md, 8px);
				transition: all 0.2s ease;
			}
			#swish-upload-error .swish-alert-close:hover {
				background: rgba(239, 68, 68, 0.1);
			}
			#swish-upload-error .swish-error-details {
				margin-top: var(--swish-space-3, 12px);
				padding-top: var(--swish-space-3, 12px);
				border-top: 1px solid rgba(239, 68, 68, 0.2);
			}
			#swish-upload-error .swish-error-row {
				display: flex;
				justify-content: flex-start;
				gap: var(--swish-space-2, 8px);
				margin-bottom: var(--swish-space-2, 8px);
			}
			#swish-upload-error .swish-error-label {
				color: var(--swish-text-secondary, #6b7280);
			}
			#swish-upload-error .swish-error-value {
				font-weight: var(--swish-font-semibold, 600);
			}
			#swish-upload-error .swish-error-list {
				margin: var(--swish-space-3, 12px) 0 0 0;
				padding-left: var(--swish-space-5, 20px);
			}
			#swish-upload-error .swish-error-list li {
				margin-bottom: var(--swish-space-1, 4px);
			}
			#swish-upload-error code {
				background: rgba(239, 68, 68, 0.1);
				padding: 2px 6px;
				border-radius: var(--swish-radius-sm, 4px);
				font-family: monospace;
				font-size: var(--swish-text-sm, 14px);
			}
			/* Disabled checkbox styling */
			.swish-form-group.swish-disabled {
				opacity: 0.7;
			}
			.swish-form-group.swish-disabled .swish-checkbox {
				cursor: not-allowed;
			}
			.swish-form-group.swish-disabled .swish-checkbox-text {
				color: var(--swish-text-secondary, #6b7280);
			}
			.swish-file-dropzone {
				border: 2px dashed var(--swish-border-medium, #d0d5dd);
				border-radius: var(--swish-radius-lg, 12px);
				padding: var(--swish-space-8, 32px);
				text-align: center;
				transition: all 0.2s ease;
				background: var(--swish-surface-container-low, #f9fafb);
				cursor: pointer;
			}
			.swish-file-dropzone:hover,
			.swish-file-dropzone.drag-over {
				border-color: var(--swish-primary, #6366f1);
				background: var(--swish-primary-container, #eef2ff);
			}
			.swish-file-dropzone.drag-over {
				border-style: solid;
				transform: scale(1.01);
			}
			.swish-file-dropzone.has-file {
				border-style: solid;
				border-color: var(--swish-success, #22c55e);
				background: var(--swish-success-container, #f0fdf4);
				padding: var(--swish-space-4, 16px);
			}
			.swish-file-input-hidden {
				position: absolute;
				width: 1px;
				height: 1px;
				padding: 0;
				margin: -1px;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
				border: 0;
			}
			.swish-dropzone-content {
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: var(--swish-space-2, 8px);
			}
			.swish-dropzone-icon {
				font-size: 48px;
				color: var(--swish-text-tertiary, #9ca3af);
			}
			.swish-file-dropzone:hover .swish-dropzone-icon,
			.swish-file-dropzone.drag-over .swish-dropzone-icon {
				color: var(--swish-primary, #6366f1);
			}
			.swish-dropzone-text {
				margin: 0;
				font-size: var(--swish-text-base, 16px);
				font-weight: var(--swish-font-medium, 500);
				color: var(--swish-text-primary, #111827);
			}
			.swish-dropzone-subtext {
				margin: 0;
				font-size: var(--swish-text-sm, 14px);
				color: var(--swish-text-tertiary, #9ca3af);
			}
			.swish-dropzone-hint {
				margin: var(--swish-space-2, 8px) 0 0 0;
				font-size: var(--swish-text-xs, 12px);
				color: var(--swish-text-tertiary, #9ca3af);
			}
			.swish-dropzone-selected {
				display: flex;
				align-items: center;
				gap: var(--swish-space-3, 12px);
				text-align: left;
			}
			.swish-dropzone-selected .swish-file-icon {
				font-size: 40px;
				color: var(--swish-success, #22c55e);
			}
			.swish-file-info {
				flex: 1;
				display: flex;
				flex-direction: column;
				gap: 2px;
				min-width: 0;
			}
			.swish-file-name {
				font-weight: var(--swish-font-medium, 500);
				color: var(--swish-text-primary, #111827);
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
			}
			.swish-file-size {
				font-size: var(--swish-text-sm, 14px);
				color: var(--swish-text-secondary, #6b7280);
			}
			.swish-btn-icon {
				padding: var(--swish-space-2, 8px);
				border-radius: var(--swish-radius-full, 50%);
				background: transparent;
				border: none;
				cursor: pointer;
				color: var(--swish-text-tertiary, #9ca3af);
				transition: all 0.2s ease;
			}
			.swish-btn-icon:hover {
				background: var(--swish-error-container, #fef2f2);
				color: var(--swish-error, #ef4444);
			}
		</style>
		<script>
		jQuery( document ).ready( function( $ ) {
			// Use localized variables or fallback to globals.
			const ajaxUrl = ( typeof swishBackupPro !== 'undefined' && swishBackupPro.ajaxUrl )
				? swishBackupPro.ajaxUrl
				: ( typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>' );
			const nonce = ( typeof swishBackupPro !== 'undefined' && swishBackupPro.nonce )
				? swishBackupPro.nonce
				: '<?php echo esc_js( wp_create_nonce( 'swish_backup_pro_nonce' ) ); ?>';

			// PHP upload limits for client-side validation.
			const phpLimits = {
				postMaxSize: <?php echo (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ); ?>,
				uploadMaxFilesize: <?php echo (int) wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ); ?>,
				postMaxSizeHuman: '<?php echo esc_js( ini_get( 'post_max_size' ) ); ?>',
				uploadMaxFilesizeHuman: '<?php echo esc_js( ini_get( 'upload_max_filesize' ) ); ?>'
			};
			const maxUploadSize = Math.min( phpLimits.postMaxSize, phpLimits.uploadMaxFilesize );

			// File upload dropzone handling.
			const $dropzone = $( '#swish-file-dropzone' );
			const $fileInput = $( '#backup_file' );
			const $dropzoneContent = $( '#swish-dropzone-content' );
			const $dropzoneSelected = $( '#swish-dropzone-selected' );
			const $chooseFileBtn = $( '#swish-choose-file-btn' );
			const $removeFileBtn = $( '#swish-remove-file' );
			const $selectedFilename = $( '#swish-selected-filename' );
			const $selectedFilesize = $( '#swish-selected-filesize' );

			// Store dropped file separately (setting input.files doesn't always work).
			let droppedFile = null;

			function formatFileSize( bytes ) {
				if ( bytes === 0 ) return '0 Bytes';
				const k = 1024;
				const sizes = [ 'Bytes', 'KB', 'MB', 'GB' ];
				const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
				return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[i];
			}

			// Inline Upload Error Controller
			const SwishUploadError = {
				show: function( title, content ) {
					$( '#swish-upload-error-title' ).text( title );
					$( '#swish-upload-error-content' ).html( content );
					$( '#swish-upload-error' ).slideDown( 200 );
					// Scroll to error
					$( 'html, body' ).animate( {
						scrollTop: $( '#swish-upload-error' ).offset().top - 100
					}, 300 );
				},
				hide: function() {
					$( '#swish-upload-error' ).slideUp( 200 );
				},
				showFileSizeError: function( fileSize, serverLimit ) {
					const content =
						'<div class="swish-error-details">' +
							'<div class="swish-error-row">' +
								'<span class="swish-error-label"><?php esc_html_e( 'Your file size:', 'swish-migrate-and-backup' ); ?></span>' +
								'<span class="swish-error-value">' + formatFileSize( fileSize ) + '</span>' +
							'</div>' +
							'<div class="swish-error-row">' +
								'<span class="swish-error-label"><?php esc_html_e( 'Server limit:', 'swish-migrate-and-backup' ); ?></span>' +
								'<span class="swish-error-value">' + formatFileSize( serverLimit ) + '</span>' +
							'</div>' +
							'<div style="margin-top: var(--swish-space-3);">' +
								'<strong><?php esc_html_e( 'To fix this:', 'swish-migrate-and-backup' ); ?></strong>' +
								'<ol class="swish-error-list">' +
									'<li><?php esc_html_e( 'Increase post_max_size and upload_max_filesize in php.ini', 'swish-migrate-and-backup' ); ?></li>' +
									'<li><?php esc_html_e( 'Ask your host to increase PHP upload limits', 'swish-migrate-and-backup' ); ?></li>' +
									'<li><?php esc_html_e( 'Upload via SFTP/FTP to', 'swish-migrate-and-backup' ); ?> <code>wp-content/swish-backups/</code> <?php esc_html_e( 'then select from existing backups', 'swish-migrate-and-backup' ); ?></li>' +
									'<li><?php esc_html_e( 'Use a smaller backup (e.g., database-only)', 'swish-migrate-and-backup' ); ?></li>' +
								'</ol>' +
							'</div>' +
						'</div>';
					this.show( '<?php echo esc_js( __( 'File Too Large', 'swish-migrate-and-backup' ) ); ?>', content );
				},
				showUploadBlockedError: function( fileSize ) {
					const sizeRow = fileSize
						? '<div class="swish-error-row">' +
								'<span class="swish-error-label"><?php esc_html_e( 'Your file size:', 'swish-migrate-and-backup' ); ?></span>' +
								'<span class="swish-error-value">' + formatFileSize( fileSize ) + '</span>' +
							'</div>'
						: '';
					const content =
						'<p><?php echo esc_js( __( 'The upload was blocked before it reached WordPress. This usually means the file is larger than an upload limit set by your host, server, or a CDN/proxy in front of your site.', 'swish-migrate-and-backup' ) ); ?></p>' +
						'<p><?php echo esc_js( __( 'For example, Cloudflare rejects request bodies larger than about 100 MB and closes the connection before the upload finishes.', 'swish-migrate-and-backup' ) ); ?></p>' +
						'<div class="swish-error-details">' +
							sizeRow +
							'<div style="margin-top: var(--swish-space-3);">' +
								'<strong><?php echo esc_js( __( 'Recommended workaround (no upload needed):', 'swish-migrate-and-backup' ) ); ?></strong>' +
								'<ol class="swish-error-list">' +
									'<li><?php esc_html_e( 'Copy the backup file to', 'swish-migrate-and-backup' ); ?> <code>wp-content/swish-backups/</code> <?php esc_html_e( 'on the server (via SFTP/FTP or your host file manager)', 'swish-migrate-and-backup' ); ?></li>' +
									'<li><?php esc_html_e( 'Then pick it from the "Or Select Existing Backup" dropdown above and click Continue', 'swish-migrate-and-backup' ); ?></li>' +
								'</ol>' +
							'</div>' +
						'</div>';
					this.show( '<?php echo esc_js( __( 'Upload Blocked by a Size Limit', 'swish-migrate-and-backup' ) ); ?>', content );
				}
			};

			// Inline error close handler
			$( '#swish-upload-error-close' ).on( 'click', function() {
				SwishUploadError.hide();
			});

			function showSelectedFile( file ) {
				$selectedFilename.text( file.name );
				$selectedFilesize.text( formatFileSize( file.size ) );
				$dropzoneContent.hide();
				$dropzoneSelected.show();
				$dropzone.addClass( 'has-file' );
				SwishUploadError.hide(); // Hide any previous error
			}

			function clearSelectedFile() {
				$fileInput.val( '' );
				droppedFile = null;
				$dropzoneContent.show();
				$dropzoneSelected.hide();
				$dropzone.removeClass( 'has-file' );
				SwishUploadError.hide(); // Hide any previous error
			}

			// Helper to get the selected file (from input or dropped).
			function getSelectedFile() {
				if ( droppedFile ) {
					return droppedFile;
				}
				const files = $fileInput[0].files;
				return files && files.length > 0 ? files[0] : null;
			}

			// Click on dropzone or choose button opens file dialog.
			$dropzone.on( 'click', function( e ) {
				// Don't trigger if clicking on the file input itself, remove button, or if file is selected.
				if ( $( e.target ).is( $fileInput ) || $( e.target ).closest( '#swish-remove-file' ).length ) {
					return;
				}
				if ( ! $dropzone.hasClass( 'has-file' ) ) {
					$fileInput[0].click(); // Use native click to avoid jQuery event loop.
				}
			} );

			$chooseFileBtn.on( 'click', function( e ) {
				e.stopPropagation();
				$fileInput[0].click(); // Use native click to avoid jQuery event loop.
			} );

			// File input change (when using "Choose File" button).
			$fileInput.on( 'change', function() {
				const files = this.files;
				if ( files && files.length > 0 ) {
					// Clear any previously dropped file since user chose a new one.
					droppedFile = null;
					showSelectedFile( files[0] );
				}
			} );

			// Remove file button.
			$removeFileBtn.on( 'click', function( e ) {
				e.stopPropagation();
				clearSelectedFile();
			} );

			// Drag and drop events.
			$dropzone.on( 'dragenter dragover', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.addClass( 'drag-over' );
			} );

			$dropzone.on( 'dragleave drop', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.removeClass( 'drag-over' );
			} );

			$dropzone.on( 'drop', function( e ) {
				const files = e.originalEvent.dataTransfer.files;
				if ( files && files.length > 0 ) {
					const file = files[0];
					// Validate file type.
					const validTypes = [ '.zip', '.swish' ];
					const fileName = file.name.toLowerCase();
					const isValid = validTypes.some( ext => fileName.endsWith( ext ) );

					if ( ! isValid ) {
						alert( '<?php echo esc_js( __( 'Please select a .zip or .swish backup file.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}

					// Store the dropped file.
					droppedFile = file;

					// Clear existing backup selection.
					$( '#existing_backup' ).val( '' );

					// Reset validation state.
					$( '#swish-import-options' ).hide();
					$( '#swish-multisite-required' ).hide();
					$( '#swish-site-selector' ).hide();
					$( '#swish-start-import' ).hide();
					$( '#swish-validate-backup' ).show();
					$( '#swish-backup-preview' ).hide();
					backupValidated = false;
					requiresMultisite = false;
					availableSites = [];
					importAsSingleSite = false;

					showSelectedFile( file );
				}
			} );

			// Prevent default drag behaviors on document.
			$( document ).on( 'dragenter dragover drop', function( e ) {
				e.preventDefault();
			} );

			// Tab switching.
			$( '.swish-tab-btn' ).on( 'click', function() {
				const tab = $( this ).data( 'tab' );

				$( '.swish-tab-btn' ).removeClass( 'active' );
				$( this ).addClass( 'active' );

				$( '.swish-tab-panel' ).removeClass( 'active' );
				$( '#tab-' + tab ).addClass( 'active' );
			} );

			// Track validation state.
			let backupValidated = false;
			let requiresMultisite = false;
			let availableSites = [];
			let importAsSingleSite = false;

			// File selection - reset state.
			$( '#backup_file, #existing_backup' ).on( 'change', function() {
				$( '#swish-import-options' ).hide();
				$( '#swish-multisite-required' ).hide();
				$( '#swish-site-selector' ).hide();
				$( '#swish-start-import' ).hide();
				$( '#swish-validate-backup' ).show();
				$( '#swish-backup-preview' ).hide();
				backupValidated = false;
				requiresMultisite = false;
				availableSites = [];
				importAsSingleSite = false;

				// Clear the other input when one is selected.
				if ( $( this ).attr( 'id' ) === 'existing_backup' && $( this ).val() ) {
					// Existing backup selected - clear file upload.
					$fileInput.val( '' );
					droppedFile = null;
					$dropzoneContent.show();
					$dropzoneSelected.hide();
					$dropzone.removeClass( 'has-file' );
				} else if ( $( this ).attr( 'id' ) === 'backup_file' && $fileInput[0].files.length > 0 ) {
					// File uploaded - clear existing backup selection and dropped file.
					droppedFile = null;
					$( '#existing_backup' ).val( '' );
				}
			} );

			// Site selection change - update old URL field.
			$( '#swish-site-select' ).on( 'change', function() {
				const siteId = $( this ).val();
				if ( siteId && availableSites.length > 0 ) {
					const selectedSite = availableSites.find( s => String( s.site_id ) === String( siteId ) );
					if ( selectedSite && selectedSite.site_url ) {
						$( '#swish-search-url' ).val( selectedSite.site_url );
					}
				}
			} );

			// Validate backup (Continue button).
			$( '#swish-validate-backup' ).on( 'click', function() {
				const $button = $( this );
				const file = getSelectedFile();
				const existingBackup = $( '#existing_backup' ).val();

				if ( ! file && ! existingBackup ) {
					alert( '<?php echo esc_js( __( 'Please select a backup file or choose an existing backup.', 'swish-migrate-and-backup' ) ); ?>' );
					return;
				}

				// Check file size against PHP limits before upload.
				// Only check if we have a valid maxUploadSize (> 1MB to avoid false positives).
				if ( file && maxUploadSize > 1048576 && file.size > maxUploadSize ) {
					SwishUploadError.showFileSizeError( file.size, maxUploadSize );
					return;
				}

				$button.prop( 'disabled', true );
				$button.find( '.material-symbols-outlined' ).text( 'hourglass_empty' ).addClass( 'swish-animate-spin' );

				const formData = new FormData();
				formData.append( 'action', 'swish_backup_validate_multisite_import' );
				formData.append( 'nonce', nonce );

				if ( file ) {
					formData.append( 'backup_file', file );
				} else {
					formData.append( 'existing_backup', existingBackup );
				}

				$.ajax( {
					url: ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					// Only time out file uploads; a stalled/half-open connection (e.g. a CDN
					// dropping an oversized body) would otherwise spin forever. Existing-backup
					// validation sends no body, so it is left without a timeout.
					timeout: file ? 120000 : 0,
					success: function( response ) {
						if ( response.success ) {
							backupValidated = true;
							requiresMultisite = false;
							importAsSingleSite = response.data.import_as_single_site || false;
							availableSites = response.data.available_sites || [];
							$( '#swish-multisite-required' ).hide();

							// Check if we need site selection (multisite backup with multiple sites on single WP).
							if ( response.data.requires_site_selection && availableSites.length > 1 ) {
								// Populate site selector.
								const $siteSelect = $( '#swish-site-select' );
								$siteSelect.find( 'option:not(:first)' ).remove();

								availableSites.forEach( function( site ) {
									const label = ( site.site_name || 'Site ' + site.site_id ) + ' (' + ( site.site_url || '' ) + ')';
									$siteSelect.append( '<option value="' + site.site_id + '">' + label + '</option>' );
								} );

								$( '#swish-site-selector' ).show();

								// Auto-select first site.
								if ( availableSites.length > 0 ) {
									$siteSelect.val( availableSites[0].site_id ).trigger( 'change' );
								}
							} else {
								$( '#swish-site-selector' ).hide();
							}

							$( '#swish-import-options' ).show();
							$( '#swish-validate-backup' ).hide();
							$( '#swish-start-import' ).show().prop( 'disabled', false );

							// For multisite-to-multisite imports, shared tables are required.
							// Check and disable the checkbox.
							const isMultisiteBackup = response.data.manifest && response.data.manifest.backup_type === 'multisite';
							const isCurrentSiteMultisite = <?php echo is_multisite() ? 'true' : 'false'; ?>;

							if ( isMultisiteBackup && isCurrentSiteMultisite && ! importAsSingleSite ) {
								$( '#swish-import-shared-tables' ).prop( 'checked', true ).prop( 'disabled', true );
								$( '#swish-shared-tables-help' ).text( '<?php echo esc_js( __( 'Required for multisite-to-multisite import.', 'swish-migrate-and-backup' ) ); ?>' );
								$( '#swish-shared-tables-group' ).addClass( 'swish-disabled' );
							} else {
								$( '#swish-import-shared-tables' ).prop( 'disabled', false );
								$( '#swish-shared-tables-help' ).text( '<?php echo esc_js( __( 'Warning: This will overwrite all user data across the network.', 'swish-migrate-and-backup' ) ); ?>' );
								$( '#swish-shared-tables-group' ).removeClass( 'swish-disabled' );
							}

							// Auto-fill old URL.
							let oldUrl = response.data.main_site_url || '';

							if ( ! oldUrl && response.data.manifest ) {
								const manifest = response.data.manifest;
								if ( manifest.sites && manifest.sites.length > 0 && manifest.sites[0].site_url ) {
									oldUrl = manifest.sites[0].site_url;
								} else if ( manifest.site_url ) {
									oldUrl = manifest.site_url;
								} else if ( manifest.network && manifest.network.site_url ) {
									oldUrl = manifest.network.site_url;
								} else if ( manifest.home_url ) {
									oldUrl = manifest.home_url;
								} else if ( manifest.network && manifest.network.domain ) {
									const domain = manifest.network.domain;
									const path = manifest.network.path || '/';
									oldUrl = 'https://' + domain + ( path !== '/' ? path : '' );
								}
							}

							if ( oldUrl ) {
								$( '#swish-search-url' ).val( oldUrl );
								$( '#swish-replace-url' ).val( window.location.origin );
							}

							// Show backup preview.
							if ( response.data.manifest ) {
								let previewHtml = '<table class="swish-preview-table">';
								previewHtml += '<tr><th><?php esc_html_e( 'Backup Type', 'swish-migrate-and-backup' ); ?></th><td>' + ( response.data.manifest.backup_type || 'N/A' ) + '</td></tr>';
								previewHtml += '<tr><th><?php esc_html_e( 'Archive Mode', 'swish-migrate-and-backup' ); ?></th><td>' + ( response.data.manifest.archive_mode || 'N/A' ) + '</td></tr>';
								previewHtml += '<tr><th><?php esc_html_e( 'Created', 'swish-migrate-and-backup' ); ?></th><td>' + ( response.data.manifest.created_at || 'N/A' ) + '</td></tr>';
								previewHtml += '<tr><th><?php esc_html_e( 'Sites', 'swish-migrate-and-backup' ); ?></th><td>';

								if ( response.data.manifest.sites && response.data.manifest.sites.length > 0 ) {
									previewHtml += '<ul style="margin: 0; padding-left: 20px;">';
									response.data.manifest.sites.forEach( function( site ) {
										previewHtml += '<li><strong>' + ( site.site_name || 'Unnamed' ) + '</strong> (' + ( site.site_url || '' ) + ')</li>';
									} );
									previewHtml += '</ul>';
								} else {
									previewHtml += '<?php echo esc_js( __( 'No site information available', 'swish-migrate-and-backup' ) ); ?>';
								}

								previewHtml += '</td></tr></table>';

								$( '#swish-backup-preview-content' ).html( previewHtml );
								$( '#swish-backup-preview' ).show();
							}
						} else {
							if ( response.data && response.data.requires_multisite ) {
								requiresMultisite = true;
								backupValidated = false;
								$( '#swish-import-options' ).hide();
								$( '#swish-start-import' ).hide();
								$( '#swish-validate-backup' ).hide();
								$( '#swish-multisite-required' ).show();

								// Build instructions HTML.
								if ( response.data.multisite_instructions ) {
									const instructions = response.data.multisite_instructions;
									let html = '';

									if ( instructions.steps && instructions.steps.length > 0 ) {
										instructions.steps.forEach( function( step, index ) {
											html += '<div class="swish-multisite-step">';
											html += '<h4><span class="swish-step-number">' + ( index + 1 ) + '</span>' + step.title + '</h4>';
											html += '<p>' + step.description + '</p>';
											if ( step.code ) {
												html += '<pre>' + $( '<div>' ).text( step.code ).html() + '</pre>';
											}
											html += '</div>';
										} );
									}

									$( '#swish-multisite-instructions' ).html( html );
								}

								// Show backup preview if manifest is available.
								if ( response.data.manifest ) {
									let previewHtml = '<table class="swish-preview-table">';
									previewHtml += '<tr><th><?php esc_html_e( 'Backup Type', 'swish-migrate-and-backup' ); ?></th><td>' + ( response.data.manifest.backup_type || 'N/A' ) + '</td></tr>';
									if ( response.data.manifest.sites && response.data.manifest.sites.length > 0 ) {
										previewHtml += '<tr><th><?php esc_html_e( 'Sites in backup', 'swish-migrate-and-backup' ); ?></th><td>' + response.data.manifest.sites.length + '</td></tr>';
									}
									previewHtml += '</table>';
									$( '#swish-backup-preview-content' ).html( previewHtml );
									$( '#swish-backup-preview' ).show();
								}
							} else {
								const errorMessage = response.data ? response.data.message : '<?php echo esc_js( __( 'Unknown error', 'swish-migrate-and-backup' ) ); ?>';
								SwishUploadError.show(
									'<?php echo esc_js( __( 'Validation Failed', 'swish-migrate-and-backup' ) ); ?>',
									'<p>' + errorMessage + '</p>'
								);
							}
						}
					},
					error: function( xhr, status, error ) {
						console.error( 'Validation error:', status, error, xhr.responseText );

						const response = xhr.responseText || '';

						// Check for PHP upload limit errors (exact sizes reported by PHP).
						if ( response.indexOf( 'POST Content-Length' ) !== -1 && response.indexOf( 'exceeds the limit' ) !== -1 ) {
							// Parse the actual sizes from the error message.
							const match = response.match( /POST Content-Length of (\d+) bytes exceeds the limit of (\d+) bytes/ );

							if ( match ) {
								const fileSize = parseInt( match[1], 10 );
								const serverLimit = parseInt( match[2], 10 );
								SwishUploadError.showFileSizeError( fileSize, serverLimit );
							} else {
								// Fallback if we can't parse the sizes.
								SwishUploadError.showFileSizeError( 0, maxUploadSize );
							}
							return;
						}

						// Upload blocked/dropped before reaching WordPress: connection closed or
						// reset ( status 0 ), Payload Too Large ( 413 ), a request timeout, or an
						// HTML/plain-text proxy/CDN error page instead of the expected JSON.
						const looksLikeJson = response.trim().charAt( 0 ) === '{';
						if ( file && ( xhr.status === 0 || xhr.status === 413 || status === 'timeout' || ( response && ! looksLikeJson ) ) ) {
							SwishUploadError.showUploadBlockedError( file.size );
							return;
						}

						SwishUploadError.show(
							'<?php echo esc_js( __( 'Validation Error', 'swish-migrate-and-backup' ) ); ?>',
							'<p><?php esc_html_e( 'An error occurred while validating the backup.', 'swish-migrate-and-backup' ); ?></p>' +
							'<p style="color: var(--swish-text-tertiary); font-size: var(--swish-text-sm);"><?php esc_html_e( 'Check the browser console for more details.', 'swish-migrate-and-backup' ); ?></p>'
						);
					},
					complete: function() {
						$button.prop( 'disabled', false );
						$button.find( '.material-symbols-outlined' ).text( 'check_circle' ).removeClass( 'swish-animate-spin' );
					}
				} );
			} );

			// Import Progress Modal Controller
			const SwishImportModal = {
				jobId: null,
				pollInterval: null,
				isBackground: false,
				logEntries: {},
				consecutiveErrors: 0,
				maxConsecutiveErrors: 3,
				// Once the database restore has started, the site is expected to be
				// temporarily unreachable (half-restored tables can fatal destination
				// plugins), so polls are tolerated failing for much longer (~6 min).
				maxRestoreOutageErrors: 240,

				stepLabels: {
					init: { title: '<?php echo esc_js( __( 'Initializing', 'swish-migrate-and-backup' ) ); ?>' },
					extract: { title: '<?php echo esc_js( __( 'Extracting Backup', 'swish-migrate-and-backup' ) ); ?>' },
					shared: { title: '<?php echo esc_js( __( 'Shared Network Tables', 'swish-migrate-and-backup' ) ); ?>' },
					database: { title: '<?php echo esc_js( __( 'Importing Database', 'swish-migrate-and-backup' ) ); ?>' },
					files: { title: '<?php echo esc_js( __( 'Restoring Files', 'swish-migrate-and-backup' ) ); ?>' },
					search_replace: { title: '<?php echo esc_js( __( 'URL Replacement', 'swish-migrate-and-backup' ) ); ?>' },
					cleanup: { title: '<?php echo esc_js( __( 'Finalizing', 'swish-migrate-and-backup' ) ); ?>' },
					complete: { title: '<?php echo esc_js( __( 'Complete', 'swish-migrate-and-backup' ) ); ?>' }
				},

				show: function() {
					$( '#swish-import-modal' ).show().addClass( 'active' );
					$( '#swish-import-modal .swish-modal-content' ).removeClass( 'show-success show-error show-background show-session-expired' );
					$( '#swish-import-modal-success, #swish-import-modal-error, #swish-import-modal-background, #swish-import-modal-session-expired' ).hide();
					$( '#swish-import-modal .swish-modal-body, #swish-import-modal .swish-modal-footer' ).show();
					this.reset();
				},

				hide: function() {
					$( '#swish-import-modal' ).hide().removeClass( 'active' );
					this.stopPolling();
				},

				reset: function() {
					this.updateProgress( 0 );
					this.updateMessage( '<?php echo esc_js( __( 'Initializing import...', 'swish-migrate-and-backup' ) ); ?>' );
					this.logEntries = {};
					this.consecutiveErrors = 0;
					$( '#swish-import-log' ).empty();
				},

				updateProgress: function( percent ) {
					$( '#swish-import-progress-fill' ).css( 'width', percent + '%' );
					$( '#swish-import-progress-percent' ).text( Math.round( percent ) + '%' );
				},

				updateMessage: function( message ) {
					$( '#swish-import-progress-message' ).text( message );
				},

				addLogEntry: function( step, status, message ) {
					const $log = $( '#swish-import-log' );
					const stepInfo = this.stepLabels[ step ] || { title: step };
					let $entry = $log.find( '[data-step="' + step + '"]' );

					if ( $entry.length === 0 ) {
						const html = '<div class="swish-log-entry ' + status + '" data-step="' + step + '">' +
							'<span class="swish-log-icon"></span>' +
							'<div class="swish-log-content">' +
								'<div class="swish-log-title">' + stepInfo.title + '</div>' +
								( message ? '<div class="swish-log-detail">' + message + '</div>' : '' ) +
							'</div>' +
						'</div>';
						$log.append( html );
						$log.scrollTop( $log[0].scrollHeight );
					} else {
						$entry.removeClass( 'pending in-progress completed failed' ).addClass( status );
						if ( message ) {
							let $detail = $entry.find( '.swish-log-detail' );
							if ( $detail.length === 0 ) {
								$entry.find( '.swish-log-content' ).append( '<div class="swish-log-detail">' + message + '</div>' );
							} else {
								$detail.text( message );
							}
						}
					}
					this.logEntries[ step ] = status;
				},

				showSuccess: function( message ) {
					this.stopPolling();
					$( '#swish-import-modal .swish-modal-content' ).addClass( 'show-success' );
					$( '#swish-import-modal-success' ).show();
					if ( message ) {
						$( '#swish-import-success-message' ).text( message );
					}
				},

				showError: function( message ) {
					this.stopPolling();
					$( '#swish-import-modal .swish-modal-content' ).addClass( 'show-error' );
					$( '#swish-import-modal-error' ).show();
					$( '#swish-import-error-message' ).text( message );
				},

				showBackground: function() {
					this.isBackground = true;
					$( '#swish-import-modal .swish-modal-content' ).addClass( 'show-background' );
					$( '#swish-import-modal-background' ).show();
				},

				showSessionExpired: function() {
					this.stopPolling();
					$( '#swish-import-modal .swish-modal-content' ).addClass( 'show-session-expired' );
					$( '#swish-import-modal .swish-modal-body, #swish-import-modal .swish-modal-footer' ).hide();
					$( '#swish-import-modal-session-expired' ).show();
				},

				startPolling: function() {
					const self = this;
					this.pollInterval = setInterval( function() {
						self.checkProgress();
					}, 1500 );
				},

				stopPolling: function() {
					if ( this.pollInterval ) {
						clearInterval( this.pollInterval );
						this.pollInterval = null;
					}
				},

				checkProgress: function() {
					const self = this;
					if ( ! this.jobId ) return;

					$.ajax( {
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'swish_backup_check_import_progress',
							nonce: nonce,
							job_id: this.jobId
						},
						success: function( response ) {
							// Reset consecutive errors on successful response.
							self.consecutiveErrors = 0;

							if ( response.success ) {
								const data = response.data;
								self.updateProgress( data.progress || 0 );
								self.updateMessage( data.message || '' );

								if ( data.current_step ) {
									self.addLogEntry( data.current_step, 'in-progress', data.message );

									const steps = [ 'init', 'extract', 'shared', 'database', 'files', 'search_replace', 'cleanup', 'complete' ];
									const currentIndex = steps.indexOf( data.current_step );
									steps.forEach( function( step, index ) {
										if ( index < currentIndex && self.logEntries[ step ] !== 'completed' ) {
											self.addLogEntry( step, 'completed' );
										}
									} );
								}

								if ( data.status === 'completed' ) {
									self.updateProgress( 100 );
									setTimeout( function() {
										self.showSuccess( data.message );
									}, 500 );
								} else if ( data.status === 'failed' ) {
									self.showError( data.error || data.message || '<?php echo esc_js( __( 'Import failed.', 'swish-migrate-and-backup' ) ); ?>' );
								}
							}
						},
						error: function( xhr, status, error ) {
							self.consecutiveErrors++;
							console.log( 'Progress check failed (attempt ' + self.consecutiveErrors + '):', status, error, 'xhr.status:', xhr.status );

							// Has the database restore started? From that point the site is
							// expected to error temporarily: half-restored tables can fatal
							// other plugins (500s) and the restored usermeta invalidates the
							// admin session (0/400/401/403). The import keeps running in the
							// background, so keep polling — the progress endpoint answers
							// again once the restore finishes.
							const restoreStarted = [ 'shared', 'database', 'files', 'search_replace', 'cleanup' ].some( function( step ) {
								return self.logEntries[ step ] === 'completed' || self.logEntries[ step ] === 'in-progress';
							} );

							if ( restoreStarted ) {
								self.updateMessage( '<?php echo esc_js( __( 'Restoring database — the site may be briefly unreachable. Waiting for it to come back...', 'swish-migrate-and-backup' ) ); ?>' );

								if ( self.consecutiveErrors >= self.maxRestoreOutageErrors ) {
									self.stopPolling();
									self.showSessionExpired();
								}
								return;
							}

							// Restore has not started yet — these are real connection errors.
							if ( self.consecutiveErrors >= self.maxConsecutiveErrors ) {
								self.stopPolling();

								// Status 0 = network error (includes redirect loops, CORS errors).
								const isRedirectError = error && error.toLowerCase().indexOf( 'redirect' ) !== -1;
								const isNetworkError = xhr.status === 0;
								const isAuthError = xhr.status === 400 || xhr.status === 401 || xhr.status === 403;

								if ( isAuthError || isNetworkError || isRedirectError ) {
									self.showSessionExpired();
								} else {
									self.showError( '<?php echo esc_js( __( 'Lost connection to the server. The import may still be running in the background.', 'swish-migrate-and-backup' ) ); ?>' );
								}
							}
						}
					} );
				},

				startImport: function( formData ) {
					const self = this;
					this.show();
					this.addLogEntry( 'init', 'in-progress' );

					formData.append( 'action', 'swish_backup_import_multisite_async' );
					formData.append( 'nonce', nonce );

					$.ajax( {
						url: ajaxUrl,
						type: 'POST',
						data: formData,
						processData: false,
						contentType: false,
						success: function( response ) {
							if ( response.success && response.data.job_id ) {
								self.jobId = response.data.job_id;
								self.addLogEntry( 'init', 'completed' );
								self.startPolling();
							} else {
								self.showError( response.data ? response.data.message : '<?php echo esc_js( __( 'Failed to start import.', 'swish-migrate-and-backup' ) ); ?>' );
							}
						},
						error: function( xhr, status, error ) {
							console.error( 'Import start error:', status, error );
							self.showError( '<?php echo esc_js( __( 'Network error. Please try again.', 'swish-migrate-and-backup' ) ); ?>' );
						}
					} );
				}
			};

			// Import modal event handlers
			$( '#swish-import-run-background' ).on( 'click', function() {
				SwishImportModal.showBackground();
			} );

			$( '#swish-import-close-success' ).on( 'click', function() {
				SwishImportModal.hide();
				location.reload();
			} );

			$( '#swish-import-close-error, #swish-import-close-background, #swish-import-close-session-expired' ).on( 'click', function() {
				SwishImportModal.hide();
			} );

			$( '#swish-import-retry' ).on( 'click', function() {
				SwishImportModal.hide();
			} );

			$( '#swish-import-modal' ).on( 'click', function( e ) {
				if ( e.target === this && SwishImportModal.isBackground ) {
					SwishImportModal.hide();
				}
			} );

			// Start import.
			$( '#swish-import-form' ).on( 'submit', function( e ) {
				e.preventDefault();

				if ( ! backupValidated ) {
					alert( '<?php echo esc_js( __( 'Please click Continue to validate the backup first.', 'swish-migrate-and-backup' ) ); ?>' );
					return;
				}

				if ( requiresMultisite ) {
					SwishUploadError.show(
						'<?php echo esc_js( __( 'Multisite Required', 'swish-migrate-and-backup' ) ); ?>',
						'<p><?php esc_html_e( 'This is a multisite network backup and cannot be imported to a single site installation.', 'swish-migrate-and-backup' ); ?></p>' +
						'<p><?php esc_html_e( 'To import this backup, you need to set up WordPress multisite first.', 'swish-migrate-and-backup' ); ?></p>'
					);
					return;
				}

				// Check if site selection is required but not made.
				if ( importAsSingleSite && availableSites.length > 1 ) {
					const selectedSiteId = $( '#swish-site-select' ).val();
					if ( ! selectedSiteId ) {
						alert( '<?php echo esc_js( __( 'Please select a site to import.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}
				}

				if ( ! confirm( '<?php echo esc_js( __( 'Are you sure you want to import this backup? This will overwrite existing data.', 'swish-migrate-and-backup' ) ); ?>' ) ) {
					return;
				}

				const formData = new FormData( this );

				// Add dropped file if it exists (since it's not in the form input).
				const selectedFile = getSelectedFile();
				if ( selectedFile && droppedFile ) {
					formData.set( 'backup_file', droppedFile );
				}

				// Ensure import_shared_tables is included for multisite-to-multisite
				// (disabled inputs don't submit their values).
				if ( $( '#swish-import-shared-tables' ).prop( 'disabled' ) && $( '#swish-import-shared-tables' ).prop( 'checked' ) ) {
					formData.set( 'import_shared_tables', '1' );
				}

				// Add import_as_single_site flag if needed.
				if ( importAsSingleSite ) {
					formData.append( 'import_as_single_site', '1' );
				}

				SwishImportModal.startImport( formData );
			} );

			// Preview Changes button handler.
			$( '#swish-preview-changes' ).on( 'click', function() {
				const $button = $( this );
				const searchUrl = $( '#swish-search-url' ).val();
				const replaceUrl = $( '#swish-replace-url' ).val();

				if ( ! searchUrl ) {
					alert( '<?php echo esc_js( __( 'Please enter the old URL to search for.', 'swish-migrate-and-backup' ) ); ?>' );
					return;
				}

				$button.prop( 'disabled', true );
				$button.find( '.material-symbols-outlined' ).text( 'hourglass_empty' ).addClass( 'swish-animate-spin' );

				$.ajax( {
					url: ajaxUrl,
					type: 'POST',
					data: {
						action: 'swish_backup_preview_search_replace',
						nonce: nonce,
						search_url: searchUrl,
						replace_url: replaceUrl
					},
					success: function( response ) {
						if ( response.success ) {
							const data = response.data;
							let html = '<p><strong><?php esc_html_e( 'Total matches found:', 'swish-migrate-and-backup' ); ?></strong> ' + data.total_matches + '</p>';

							if ( data.preview && data.preview.length > 0 ) {
								html += '<table class="swish-preview-table" style="margin-top: 10px;"><thead><tr><th><?php esc_html_e( 'Table', 'swish-migrate-and-backup' ); ?></th><th><?php esc_html_e( 'Column', 'swish-migrate-and-backup' ); ?></th><th><?php esc_html_e( 'Before', 'swish-migrate-and-backup' ); ?></th><th><?php esc_html_e( 'After', 'swish-migrate-and-backup' ); ?></th></tr></thead><tbody>';

								data.preview.forEach( function( match ) {
									html += '<tr>';
									html += '<td><code>' + match.table + '</code></td>';
									html += '<td><code>' + match.column + '</code></td>';
									html += '<td style="word-break: break-all; max-width: 200px;">' + $( '<div>' ).text( match.before ).html() + '</td>';
									html += '<td style="word-break: break-all; max-width: 200px;">' + $( '<div>' ).text( match.after ).html() + '</td>';
									html += '</tr>';
								} );

								html += '</tbody></table>';

								if ( data.truncated ) {
									html += '<p style="font-style: italic; color: var(--swish-text-tertiary);"><?php esc_html_e( 'Showing first 50 matches only.', 'swish-migrate-and-backup' ); ?></p>';
								}
							} else if ( data.total_matches === 0 ) {
								html += '<p style="font-style: italic; color: var(--swish-text-tertiary);"><?php esc_html_e( 'No matches found for the specified URL.', 'swish-migrate-and-backup' ); ?></p>';
							}

							$( '#swish-preview-content' ).html( html );
							$( '#swish-preview-results' ).show();
						} else {
							alert( '<?php echo esc_js( __( 'Preview failed:', 'swish-migrate-and-backup' ) ); ?> ' + ( response.data ? response.data.message : 'Unknown error' ) );
						}
					},
					error: function( xhr, status, error ) {
						console.error( 'Preview error:', status, error );
						alert( '<?php echo esc_js( __( 'An error occurred during preview.', 'swish-migrate-and-backup' ) ); ?>' );
					},
					complete: function() {
						$button.prop( 'disabled', false );
						$button.find( '.material-symbols-outlined' ).text( 'preview' ).removeClass( 'swish-animate-spin' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}
}
