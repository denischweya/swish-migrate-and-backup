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
		<div class="wrap swish-backup-wrap">
			<?php AdminNav::render(); ?>

			<h1><?php esc_html_e( 'Site Migration', 'swish-migrate-and-backup' ); ?></h1>

			<div class="swish-backup-migration-wizard">
				<!-- Step 1: Choose Method -->
				<div class="swish-backup-card swish-backup-migration-step" id="migration-step-1">
					<h2><?php esc_html_e( 'Step 1: Choose Migration Method', 'swish-migrate-and-backup' ); ?></h2>
					<div class="swish-backup-migration-options">
						<div class="swish-backup-migration-option" data-method="import">
							<span class="dashicons dashicons-upload"></span>
							<h3><?php esc_html_e( 'Import Backup', 'swish-migrate-and-backup' ); ?></h3>
							<p><?php esc_html_e( 'Import a backup file from another site', 'swish-migrate-and-backup' ); ?></p>
						</div>
						<div class="swish-backup-migration-option" data-method="export">
							<span class="dashicons dashicons-download"></span>
							<h3><?php esc_html_e( 'Export for Migration', 'swish-migrate-and-backup' ); ?></h3>
							<p><?php esc_html_e( 'Create a migration package for this site', 'swish-migrate-and-backup' ); ?></p>
						</div>
						<div class="swish-backup-migration-option" data-method="search-replace">
							<span class="dashicons dashicons-search"></span>
							<h3><?php esc_html_e( 'Search & Replace', 'swish-migrate-and-backup' ); ?></h3>
							<p><?php esc_html_e( 'Replace URLs or strings in the database', 'swish-migrate-and-backup' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Step 2: Import -->
				<div class="swish-backup-card swish-backup-migration-step" id="migration-step-import" style="display:none;">
					<h2><?php esc_html_e( 'Step 2: Upload Backup File', 'swish-migrate-and-backup' ); ?></h2>
					<form id="swish-backup-import-form" enctype="multipart/form-data">
						<?php wp_nonce_field( 'swish_backup_import', 'swish_backup_import_nonce' ); ?>
						<div class="swish-backup-upload-area" id="swish-backup-drop-zone">
							<span class="dashicons dashicons-cloud-upload"></span>
							<p><?php esc_html_e( 'Drag and drop a backup file here, or click to select', 'swish-migrate-and-backup' ); ?></p>
							<input type="file" name="backup_file" id="backup_file" accept=".zip" style="display:none;">
							<button type="button" class="button" id="swish-backup-select-file">
								<?php esc_html_e( 'Select File', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
						<div id="swish-backup-file-info" style="display:none;">
							<p><strong><?php esc_html_e( 'Selected file:', 'swish-migrate-and-backup' ); ?></strong> <span id="selected-file-name"></span></p>
						</div>
					</form>
					<div id="swish-backup-import-analysis" style="display:none;">
						<h3><?php esc_html_e( 'Backup Analysis', 'swish-migrate-and-backup' ); ?></h3>
						<div id="swish-backup-analysis-content"></div>
					</div>
					<p class="swish-backup-migration-nav">
						<button type="button" class="button" data-goto="1">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
						<button type="button" class="button button-primary" id="swish-backup-continue-import" disabled>
							<?php esc_html_e( 'Continue', 'swish-migrate-and-backup' ); ?> &rarr;
						</button>
					</p>
				</div>

				<!-- Step 3: URL Replacement -->
				<div class="swish-backup-card swish-backup-migration-step" id="migration-step-url" style="display:none;">
					<h2><?php esc_html_e( 'Step 3: URL Configuration', 'swish-migrate-and-backup' ); ?></h2>
					<p><?php esc_html_e( 'Configure URL replacement to update all references in the database.', 'swish-migrate-and-backup' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="old_url"><?php esc_html_e( 'Old Site URL', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<input type="url" name="old_url" id="old_url" class="regular-text" placeholder="https://old-site.com">
								<p class="description"><?php esc_html_e( 'The URL of the site where the backup was created.', 'swish-migrate-and-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="new_url"><?php esc_html_e( 'New Site URL', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<input type="url" name="new_url" id="new_url" class="regular-text" value="<?php echo esc_attr( $current_url ); ?>">
								<p class="description"><?php esc_html_e( 'The URL of this site.', 'swish-migrate-and-backup' ); ?></p>
							</td>
						</tr>
					</table>
					<div id="swish-backup-url-preview" style="display:none;">
						<h4><?php esc_html_e( 'Preview Changes', 'swish-migrate-and-backup' ); ?></h4>
						<div id="swish-backup-preview-content"></div>
					</div>
					<p>
						<button type="button" class="button" id="swish-backup-preview-url">
							<?php esc_html_e( 'Preview Changes', 'swish-migrate-and-backup' ); ?>
						</button>
					</p>
					<p class="swish-backup-migration-nav">
						<button type="button" class="button" data-goto="import">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
						<button type="button" class="button button-primary" id="swish-backup-start-migration">
							<?php esc_html_e( 'Start Migration', 'swish-migrate-and-backup' ); ?>
						</button>
					</p>
				</div>

				<!-- Export Step -->
				<div class="swish-backup-card swish-backup-migration-step" id="migration-step-export" style="display:none;">
					<h2><?php esc_html_e( 'Export for Migration', 'swish-migrate-and-backup' ); ?></h2>
					<p><?php esc_html_e( 'Create a migration package that can be imported on another site.', 'swish-migrate-and-backup' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Include in Export', 'swish-migrate-and-backup' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="export_database" checked> <?php esc_html_e( 'Database', 'swish-migrate-and-backup' ); ?></label><br>
									<label><input type="checkbox" name="export_plugins" checked> <?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?></label><br>
									<label><input type="checkbox" name="export_themes" checked> <?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?></label><br>
									<label><input type="checkbox" name="export_uploads" checked> <?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?></label><br>
									<label><input type="checkbox" name="export_core"> <?php esc_html_e( 'WordPress Core (not recommended)', 'swish-migrate-and-backup' ); ?></label>
								</fieldset>
							</td>
						</tr>
					</table>
					<p class="swish-backup-migration-nav">
						<button type="button" class="button" data-goto="1">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
						<button type="button" class="button button-primary" id="swish-backup-start-export">
							<?php esc_html_e( 'Create Export', 'swish-migrate-and-backup' ); ?>
						</button>
					</p>
				</div>

				<!-- Search & Replace Step -->
				<div class="swish-backup-card swish-backup-migration-step" id="migration-step-search-replace" style="display:none;">
					<h2><?php esc_html_e( 'Search & Replace', 'swish-migrate-and-backup' ); ?></h2>
					<p><?php esc_html_e( 'Search and replace text in your database. Supports serialized data.', 'swish-migrate-and-backup' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="search_string"><?php esc_html_e( 'Search For', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<input type="text" name="search_string" id="search_string" class="regular-text">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="replace_string"><?php esc_html_e( 'Replace With', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<input type="text" name="replace_string" id="replace_string" class="regular-text">
							</td>
						</tr>
					</table>
					<div id="swish-backup-search-preview" style="display:none;">
						<h4><?php esc_html_e( 'Preview', 'swish-migrate-and-backup' ); ?></h4>
						<div id="swish-backup-search-preview-content"></div>
					</div>
					<p>
						<button type="button" class="button" id="swish-backup-preview-search">
							<?php esc_html_e( 'Preview', 'swish-migrate-and-backup' ); ?>
						</button>
					</p>
					<p class="swish-backup-migration-nav">
						<button type="button" class="button" data-goto="1">&larr; <?php esc_html_e( 'Back', 'swish-migrate-and-backup' ); ?></button>
						<button type="button" class="button button-primary" id="swish-backup-run-search-replace">
							<?php esc_html_e( 'Run Search & Replace', 'swish-migrate-and-backup' ); ?>
						</button>
					</p>
				</div>

				<!-- Progress/Result Step -->
				<div class="swish-backup-card swish-backup-migration-step" id="migration-step-progress" style="display:none;">
					<h2 id="migration-progress-title"><?php esc_html_e( 'Migration in Progress', 'swish-migrate-and-backup' ); ?></h2>
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
							<span class="dashicons dashicons-yes-alt"></span>
							<p><?php esc_html_e( 'Migration completed successfully!', 'swish-migrate-and-backup' ); ?></p>
						</div>
						<p>
							<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" class="button button-primary">
								<?php esc_html_e( 'View Site', 'swish-migrate-and-backup' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url() ); ?>" class="button">
								<?php esc_html_e( 'Go to Dashboard', 'swish-migrate-and-backup' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
