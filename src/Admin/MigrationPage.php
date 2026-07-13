<?php
/**
 * Migration Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Migration\Migrator;

/**
 * Migration page controller.
 */
final class MigrationPage {

	/**
	 * Migrator.
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * Constructor.
	 *
	 * @param Migrator $migrator Migrator.
	 */
	public function __construct( Migrator $migrator ) {
		$this->migrator = $migrator;
	}

	/**
	 * Render the migration page.
	 *
	 * @return void
	 */
	public function render(): void {
		$current_url = get_site_url();
		?>
		<?php
		AdminNav::render_start(
			__( 'Site Migration', 'swish-migrate-and-backup' )
		);
		?>
			<div class="swish-backup-migration-wizard">
				<!-- Step 1: Choose Method -->
				<div class="swish-card swish-backup-migration-step" id="migration-step-1">
					<div class="swish-card-header">
						<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Step 1: Choose Migration Method', 'swish-migrate-and-backup' ); ?></h4>
					</div>
					<div class="swish-card-body">
						<div class="swish-backup-migration-options">
							<div class="swish-backup-migration-option" data-method="import">
								<span class="material-symbols-outlined">upload</span>
								<h3><?php esc_html_e( 'Import Backup', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Import a backup file from another site', 'swish-migrate-and-backup' ); ?></p>
							</div>
							<div class="swish-backup-migration-option" data-method="export">
								<span class="material-symbols-outlined">download</span>
								<h3><?php esc_html_e( 'Export for Migration', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Create a migration package for this site', 'swish-migrate-and-backup' ); ?></p>
							</div>
							<div class="swish-backup-migration-option" data-method="search-replace">
								<span class="material-symbols-outlined">search</span>
								<h3><?php esc_html_e( 'Search & Replace', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Replace URLs or strings in the database', 'swish-migrate-and-backup' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Step 2: Import -->
				<div class="swish-card swish-mt-4 swish-backup-migration-step" id="migration-step-import" style="display:none;">
					<div class="swish-card-header">
						<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Step 2: Upload Backup File', 'swish-migrate-and-backup' ); ?></h4>
					</div>
					<div class="swish-card-body">
						<form id="swish-backup-import-form" enctype="multipart/form-data">
							<?php wp_nonce_field( 'swish_backup_import', 'swish_backup_import_nonce' ); ?>
							<div class="swish-backup-upload-area" id="swish-backup-drop-zone">
								<span class="material-symbols-outlined">cloud_upload</span>
								<p><?php esc_html_e( 'Drag and drop a backup file here, or click to select', 'swish-migrate-and-backup' ); ?></p>
								<input type="file" name="backup_file" id="backup_file" accept=".zip,.tar.gz,.tgz,.swish" style="display:none;">
								<button type="button" class="swish-btn swish-btn-secondary" id="swish-backup-select-file">
									<?php esc_html_e( 'Select File', 'swish-migrate-and-backup' ); ?>
								</button>
								<p class="swish-help-text" style="margin-top: 10px;">
									<?php
									printf(
										/* translators: %s: upload limit */
										esc_html__( 'Max upload size: %s', 'swish-migrate-and-backup' ),
										esc_html( size_format( wp_max_upload_size() ) )
									);
									?>
								</p>
							</div>
							<div id="swish-backup-file-info" style="display:none;">
								<p><strong><?php esc_html_e( 'Selected file:', 'swish-migrate-and-backup' ); ?></strong> <span id="selected-file-name"></span></p>
							</div>
						</form>

						<!-- Server Files Import (for large files) -->
						<div class="swish-backup-server-files-section" style="margin-top: var(--swish-space-6); padding-top: var(--swish-space-6); border-top: 1px solid var(--swish-border);">
							<h4 style="font-size: var(--swish-text-base); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Or Import from Server', 'swish-migrate-and-backup' ); ?></h4>
							<p class="swish-help-text">
								<?php esc_html_e( 'For large backups (1GB+), upload via FTP/SFTP to wp-content/swish-backups/ then select from the list below.', 'swish-migrate-and-backup' ); ?>
							</p>
							<div id="swish-backup-server-files-list" style="margin-top: 15px;">
								<button type="button" class="swish-btn swish-btn-secondary" id="swish-backup-browse-server">
									<span class="material-symbols-outlined" style="font-size: 18px;">folder_open</span>
									<?php esc_html_e( 'Browse Server Files', 'swish-migrate-and-backup' ); ?>
								</button>
							</div>
							<div id="swish-backup-server-files-table" style="display: none; margin-top: 15px;">
								<table class="swish-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'File', 'swish-migrate-and-backup' ); ?></th>
											<th><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
											<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
											<th><?php esc_html_e( 'Date', 'swish-migrate-and-backup' ); ?></th>
											<th><?php esc_html_e( 'Action', 'swish-migrate-and-backup' ); ?></th>
										</tr>
									</thead>
									<tbody id="swish-backup-server-files-tbody">
									</tbody>
								</table>
								<p class="swish-help-text" style="margin-top: 10px;">
									<?php
									printf(
										/* translators: %s: path */
										esc_html__( 'Files from: %s', 'swish-migrate-and-backup' ),
										'<code>wp-content/swish-backups/</code>'
									);
									?>
								</p>
							</div>
						</div>
						<div id="swish-backup-import-analysis" style="display:none;">
							<h4 style="font-size: var(--swish-text-base); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Backup Analysis', 'swish-migrate-and-backup' ); ?></h4>
							<div id="swish-backup-analysis-content"></div>
						</div>
						<p class="swish-backup-migration-nav swish-flex swish-gap-2" style="margin-top: var(--swish-space-4);">
							<button type="button" class="swish-btn swish-btn-secondary" data-goto="1">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
							<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-continue-import" disabled>
								<?php esc_html_e( 'Continue', 'swish-migrate-and-backup' ); ?> &rarr;
							</button>
						</p>
					</div>
				</div>

				<!-- Step 3: URL Replacement -->
				<div class="swish-card swish-mt-4 swish-backup-migration-step" id="migration-step-url" style="display:none;">
					<div class="swish-card-header">
						<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Step 3: URL Configuration', 'swish-migrate-and-backup' ); ?></h4>
					</div>
					<div class="swish-card-body">
						<p class="swish-help-text"><?php esc_html_e( 'Configure URL replacement to update all references in the database.', 'swish-migrate-and-backup' ); ?></p>
						<div class="swish-form-group">
							<label class="swish-form-label" for="old_url"><?php esc_html_e( 'Old Site URL', 'swish-migrate-and-backup' ); ?></label>
							<input type="url" name="old_url" id="old_url" class="swish-input" placeholder="https://old-site.com">
							<p class="swish-help-text"><?php esc_html_e( 'The URL of the site where the backup was created.', 'swish-migrate-and-backup' ); ?></p>
						</div>
						<div class="swish-form-group">
							<label class="swish-form-label" for="new_url"><?php esc_html_e( 'New Site URL', 'swish-migrate-and-backup' ); ?></label>
							<input type="url" name="new_url" id="new_url" class="swish-input" value="<?php echo esc_attr( $current_url ); ?>">
							<p class="swish-help-text"><?php esc_html_e( 'The URL of this site.', 'swish-migrate-and-backup' ); ?></p>
						</div>
						<div id="swish-backup-url-preview" style="display:none;">
							<h4 style="font-size: var(--swish-text-base); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Preview Changes', 'swish-migrate-and-backup' ); ?></h4>
							<div id="swish-backup-preview-content"></div>
						</div>
						<p style="margin-top: var(--swish-space-4);">
							<button type="button" class="swish-btn swish-btn-secondary" id="swish-backup-preview-url">
								<?php esc_html_e( 'Preview Changes', 'swish-migrate-and-backup' ); ?>
							</button>
						</p>
						<p class="swish-backup-migration-nav swish-flex swish-gap-2">
							<button type="button" class="swish-btn swish-btn-secondary" data-goto="import">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
							<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-start-migration">
								<?php esc_html_e( 'Start Migration', 'swish-migrate-and-backup' ); ?>
							</button>
						</p>
					</div>
				</div>

				<!-- Export Step -->
				<div class="swish-card swish-mt-4 swish-backup-migration-step" id="migration-step-export" style="display:none;">
					<div class="swish-card-header">
						<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Export for Migration', 'swish-migrate-and-backup' ); ?></h4>
					</div>
					<div class="swish-card-body">
						<p class="swish-help-text"><?php esc_html_e( 'Create a migration package that can be imported on another site.', 'swish-migrate-and-backup' ); ?></p>
						<div class="swish-form-group">
							<label class="swish-form-label"><?php esc_html_e( 'Include in Export', 'swish-migrate-and-backup' ); ?></label>
							<div class="swish-checkbox-wrapper">
								<label class="swish-checkbox-label"><input type="checkbox" name="export_database" class="swish-checkbox" checked> <span class="swish-checkbox-text"><?php esc_html_e( 'Database', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="export_plugins" class="swish-checkbox" checked> <span class="swish-checkbox-text"><?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="export_themes" class="swish-checkbox" checked> <span class="swish-checkbox-text"><?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="export_uploads" class="swish-checkbox" checked> <span class="swish-checkbox-text"><?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="export_core" class="swish-checkbox"> <span class="swish-checkbox-text"><?php esc_html_e( 'WordPress Core (not recommended)', 'swish-migrate-and-backup' ); ?></span></label>
							</div>
						</div>
						<p class="swish-backup-migration-nav swish-flex swish-gap-2">
							<button type="button" class="swish-btn swish-btn-secondary" data-goto="1">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
							<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-start-export">
								<?php esc_html_e( 'Create Export', 'swish-migrate-and-backup' ); ?>
							</button>
						</p>
					</div>
				</div>

				<!-- Search & Replace Step -->
				<div class="swish-card swish-mt-4 swish-backup-migration-step" id="migration-step-search-replace" style="display:none;">
					<div class="swish-card-header">
						<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Search & Replace', 'swish-migrate-and-backup' ); ?></h4>
					</div>
					<div class="swish-card-body">
						<p class="swish-help-text"><?php esc_html_e( 'Search and replace text in your database. Supports serialized data.', 'swish-migrate-and-backup' ); ?></p>
						<div class="swish-form-group">
							<label class="swish-form-label" for="search_string"><?php esc_html_e( 'Search For', 'swish-migrate-and-backup' ); ?></label>
							<input type="text" name="search_string" id="search_string" class="swish-input">
						</div>
						<div class="swish-form-group">
							<label class="swish-form-label" for="replace_string"><?php esc_html_e( 'Replace With', 'swish-migrate-and-backup' ); ?></label>
							<input type="text" name="replace_string" id="replace_string" class="swish-input">
						</div>
						<div id="swish-backup-search-preview" style="display:none;">
							<h4 style="font-size: var(--swish-text-base); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Preview', 'swish-migrate-and-backup' ); ?></h4>
							<div id="swish-backup-search-preview-content"></div>
						</div>
						<p style="margin-top: var(--swish-space-4);">
							<button type="button" class="swish-btn swish-btn-secondary" id="swish-backup-preview-search">
								<?php esc_html_e( 'Preview', 'swish-migrate-and-backup' ); ?>
							</button>
						</p>
						<p class="swish-backup-migration-nav swish-flex swish-gap-2">
							<button type="button" class="swish-btn swish-btn-secondary" data-goto="1">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
							<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-run-search-replace">
								<?php esc_html_e( 'Run Search & Replace', 'swish-migrate-and-backup' ); ?>
							</button>
						</p>
					</div>
				</div>

				<!-- Progress/Result Step -->
				<div class="swish-card swish-mt-4 swish-backup-migration-step" id="migration-step-progress" style="display:none;">
					<div class="swish-card-header">
						<h4 id="migration-progress-title" style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Migration in Progress', 'swish-migrate-and-backup' ); ?></h4>
					</div>
					<div class="swish-card-body">
						<div class="swish-backup-progress-bar">
							<div class="swish-backup-progress-bar-inner" style="width: 0%;"></div>
						</div>
						<p class="swish-backup-progress-status"><?php esc_html_e( 'Initializing...', 'swish-migrate-and-backup' ); ?></p>

						<!-- Migration Log -->
						<div class="swish-backup-log-container" id="migration-log-container">
							<h4 class="swish-backup-log-title"><?php esc_html_e( 'Migration Progress', 'swish-migrate-and-backup' ); ?></h4>
							<div class="swish-backup-log" id="migration-log"></div>
						</div>

						<div id="migration-result" style="display:none;">
							<div class="swish-backup-success-message">
								<span class="material-symbols-outlined" style="color: var(--swish-success-600);">check_circle</span>
								<p><?php esc_html_e( 'Migration completed successfully!', 'swish-migrate-and-backup' ); ?></p>
							</div>
							<p class="swish-flex swish-gap-2">
								<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" class="swish-btn swish-btn-primary">
									<?php esc_html_e( 'View Site', 'swish-migrate-and-backup' ); ?>
								</a>
								<a href="<?php echo esc_url( admin_url() ); ?>" class="swish-btn swish-btn-secondary">
									<?php esc_html_e( 'Go to Dashboard', 'swish-migrate-and-backup' ); ?>
								</a>
							</p>
						</div>
					</div>
				</div>
			</div>
		<?php
		AdminNav::render_end();
	}
}
