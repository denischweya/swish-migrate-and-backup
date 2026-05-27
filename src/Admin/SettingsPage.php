<?php
/**
 * Settings Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Storage\StorageManager;
use SwishMigrateAndBackup\Security\Encryption;

/**
 * Settings page controller.
 */
final class SettingsPage {

	/**
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Encryption service.
	 *
	 * @var Encryption
	 */
	private Encryption $encryption;

	/**
	 * Constructor.
	 *
	 * @param StorageManager $storage_manager Storage manager.
	 * @param Encryption     $encryption      Encryption service.
	 */
	public function __construct( StorageManager $storage_manager, Encryption $encryption ) {
		$this->storage_manager = $storage_manager;
		$this->encryption      = $encryption;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Handle form submission.
		if ( isset( $_POST['swish_backup_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swish_backup_settings_nonce'] ) ), 'swish_backup_settings' ) ) {
			$this->save_settings();
		}

		$settings = get_option( 'swish_backup_settings', array() );
		$adapters = $this->storage_manager->get_all_adapters();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
		?>
		<div class="wrap swish-backup-wrap">
			<h1><?php esc_html_e( 'Swish Backup Settings', 'swish-migrate-and-backup' ); ?></h1>
			<hr class="wp-header-end">

			<?php AdminNav::render(); ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'general' ) ); ?>" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'swish-migrate-and-backup' ); ?>
				</a>
				<?php foreach ( $adapters as $id => $adapter ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $id ) ); ?>" class="nav-tab <?php echo $id === $active_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $adapter->get_name() ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="" id="swish-settings-form">
				<?php wp_nonce_field( 'swish_backup_settings', 'swish_backup_settings_nonce' ); ?>
				<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<?php if ( 'general' === $active_tab ) : ?>
					<?php $this->render_general_settings( $settings ); ?>
				<?php else : ?>
					<?php
					$adapter = $adapters[ $active_tab ] ?? null;
					if ( $adapter ) :
						$this->render_storage_settings( $adapter, $active_tab );
					endif;
					?>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>

		<?php if ( 'general' === $active_tab ) : ?>
			<?php $this->render_folder_explorer_script( $settings ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render general settings tab.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_general_settings( array $settings ): void {
		?>
		<!-- Performance Section -->
		<h2 class="title"><?php esc_html_e( 'Performance', 'swish-migrate-and-backup' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Adjust batch sizes based on your hosting environment. Lower values are safer for shared hosting.', 'swish-migrate-and-backup' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="pipeline_batch_size"><?php esc_html_e( 'Files per Request', 'swish-migrate-and-backup' ); ?></label>
				</th>
				<td>
					<div class="swish-range-wrapper">
						<input type="range" name="swish_backup_settings[pipeline_batch_size]" id="pipeline_batch_size"
							min="25" max="500" step="25"
							value="<?php echo esc_attr( $settings['pipeline_batch_size'] ?? 150 ); ?>"
							oninput="document.getElementById('pipeline_batch_size_value').textContent = this.value">
						<span id="pipeline_batch_size_value" class="swish-range-value"><?php echo esc_html( $settings['pipeline_batch_size'] ?? 150 ); ?></span>
					</div>
					<p class="description">
						<button type="button" class="button button-small" onclick="document.getElementById('pipeline_batch_size').value=50;document.getElementById('pipeline_batch_size_value').textContent='50';">
							<?php esc_html_e( 'Shared (50)', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="button button-small" onclick="document.getElementById('pipeline_batch_size').value=150;document.getElementById('pipeline_batch_size_value').textContent='150';">
							<?php esc_html_e( 'VPS (150)', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="button button-small" onclick="document.getElementById('pipeline_batch_size').value=300;document.getElementById('pipeline_batch_size_value').textContent='300';">
							<?php esc_html_e( 'Dedicated (300)', 'swish-migrate-and-backup' ); ?>
						</button>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="db_batch_size"><?php esc_html_e( 'Database Rows per Batch', 'swish-migrate-and-backup' ); ?></label>
				</th>
				<td>
					<div class="swish-range-wrapper">
						<input type="range" name="swish_backup_settings[db_batch_size]" id="db_batch_size"
							min="100" max="2000" step="100"
							value="<?php echo esc_attr( $settings['db_batch_size'] ?? 500 ); ?>"
							oninput="document.getElementById('db_batch_size_value').textContent = this.value">
						<span id="db_batch_size_value" class="swish-range-value"><?php echo esc_html( $settings['db_batch_size'] ?? 500 ); ?></span>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Quick Presets', 'swish-migrate-and-backup' ); ?></th>
				<td>
					<button type="button" class="button" onclick="swishSetPreset(50, 200);">
						<span class="dashicons dashicons-cloud" style="margin-top:4px;"></span>
						<?php esc_html_e( 'Shared Hosting', 'swish-migrate-and-backup' ); ?>
					</button>
					<button type="button" class="button" onclick="swishSetPreset(150, 500);">
						<span class="dashicons dashicons-desktop" style="margin-top:4px;"></span>
						<?php esc_html_e( 'VPS / Managed', 'swish-migrate-and-backup' ); ?>
					</button>
					<button type="button" class="button" onclick="swishSetPreset(300, 1000);">
						<span class="dashicons dashicons-building" style="margin-top:4px;"></span>
						<?php esc_html_e( 'Dedicated', 'swish-migrate-and-backup' ); ?>
					</button>
				</td>
			</tr>
		</table>

		<!-- Archive & Storage Section -->
		<h2 class="title"><?php esc_html_e( 'Archive & Storage', 'swish-migrate-and-backup' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="archive_format"><?php esc_html_e( 'Archive Format', 'swish-migrate-and-backup' ); ?></label>
				</th>
				<td>
					<select name="swish_backup_settings[archive_format]" id="archive_format">
						<option value="swish" selected><?php esc_html_e( 'SWISH - Streaming format with resume support', 'swish-migrate-and-backup' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'SWISH is our custom streaming format with true append support and resume capability.', 'swish-migrate-and-backup' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="compression_level"><?php esc_html_e( 'Compression Level', 'swish-migrate-and-backup' ); ?></label>
				</th>
				<td>
					<select name="swish_backup_settings[compression_level]" id="compression_level">
						<option value="0" <?php selected( $settings['compression_level'] ?? 6, 0 ); ?>><?php esc_html_e( 'None (fastest)', 'swish-migrate-and-backup' ); ?></option>
						<option value="1" <?php selected( $settings['compression_level'] ?? 6, 1 ); ?>><?php esc_html_e( 'Low', 'swish-migrate-and-backup' ); ?></option>
						<option value="6" <?php selected( $settings['compression_level'] ?? 6, 6 ); ?>><?php esc_html_e( 'Normal', 'swish-migrate-and-backup' ); ?></option>
						<option value="9" <?php selected( $settings['compression_level'] ?? 6, 9 ); ?>><?php esc_html_e( 'Maximum (slowest)', 'swish-migrate-and-backup' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="default_storage"><?php esc_html_e( 'Default Storage', 'swish-migrate-and-backup' ); ?></label>
				</th>
				<td>
					<select name="swish_backup_settings[default_storage]" id="default_storage">
						<?php foreach ( $this->storage_manager->get_all_adapters() as $id => $adapter ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['default_storage'] ?? 'local', $id ); ?>>
								<?php echo esc_html( $adapter->get_name() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<!-- Backup Contents Section -->
		<h2 class="title"><?php esc_html_e( 'Backup Contents', 'swish-migrate-and-backup' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Select what to include in backups. Click on each section to choose specific items.', 'swish-migrate-and-backup' ); ?>
		</p>

		<div id="swish-backup-contents" class="swish-backup-contents-settings">
			<!-- Database -->
			<div class="swish-content-section">
				<label class="swish-content-toggle">
					<input type="checkbox" name="swish_backup_settings[backup_database]" value="1" <?php checked( $settings['backup_database'] ?? true ); ?>>
					<span class="dashicons dashicons-database"></span>
					<?php esc_html_e( 'Database', 'swish-migrate-and-backup' ); ?>
				</label>
			</div>

			<!-- Plugins -->
			<div class="swish-content-section">
				<label class="swish-content-toggle">
					<input type="checkbox" name="swish_backup_settings[backup_plugins]" value="1" id="backup_plugins" <?php checked( $settings['backup_plugins'] ?? true ); ?>>
					<span class="dashicons dashicons-admin-plugins"></span>
					<?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?>
				</label>
				<div class="swish-folder-tree" id="plugins-tree" data-type="plugins">
					<button type="button" class="swish-tree-toggle button button-small">
						<span class="dashicons dashicons-arrow-right-alt2"></span>
						<?php esc_html_e( 'Select plugins to include', 'swish-migrate-and-backup' ); ?>
						<span class="swish-tree-count"></span>
					</button>
					<div class="swish-tree-items" style="display:none;">
						<div class="swish-tree-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading...', 'swish-migrate-and-backup' ); ?>
						</div>
					</div>
				</div>
				<input type="hidden" name="swish_backup_settings[exclude_plugins]" id="exclude_plugins" value="<?php echo esc_attr( wp_json_encode( $settings['exclude_plugins'] ?? array() ) ); ?>">
			</div>

			<!-- Themes -->
			<div class="swish-content-section">
				<label class="swish-content-toggle">
					<input type="checkbox" name="swish_backup_settings[backup_themes]" value="1" id="backup_themes" <?php checked( $settings['backup_themes'] ?? true ); ?>>
					<span class="dashicons dashicons-admin-appearance"></span>
					<?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?>
				</label>
				<div class="swish-folder-tree" id="themes-tree" data-type="themes">
					<button type="button" class="swish-tree-toggle button button-small">
						<span class="dashicons dashicons-arrow-right-alt2"></span>
						<?php esc_html_e( 'Select themes to include', 'swish-migrate-and-backup' ); ?>
						<span class="swish-tree-count"></span>
					</button>
					<div class="swish-tree-items" style="display:none;">
						<div class="swish-tree-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading...', 'swish-migrate-and-backup' ); ?>
						</div>
					</div>
				</div>
				<input type="hidden" name="swish_backup_settings[exclude_themes]" id="exclude_themes" value="<?php echo esc_attr( wp_json_encode( $settings['exclude_themes'] ?? array() ) ); ?>">
			</div>

			<!-- Uploads -->
			<div class="swish-content-section">
				<label class="swish-content-toggle">
					<input type="checkbox" name="swish_backup_settings[backup_uploads]" value="1" id="backup_uploads" <?php checked( $settings['backup_uploads'] ?? true ); ?>>
					<span class="dashicons dashicons-admin-media"></span>
					<?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?>
				</label>
				<div class="swish-folder-tree" id="uploads-tree" data-type="uploads">
					<button type="button" class="swish-tree-toggle button button-small">
						<span class="dashicons dashicons-arrow-right-alt2"></span>
						<?php esc_html_e( 'Select upload folders to include', 'swish-migrate-and-backup' ); ?>
						<span class="swish-tree-count"></span>
					</button>
					<div class="swish-tree-items" style="display:none;">
						<div class="swish-tree-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading...', 'swish-migrate-and-backup' ); ?>
						</div>
					</div>
				</div>
				<input type="hidden" name="swish_backup_settings[exclude_uploads]" id="exclude_uploads" value="<?php echo esc_attr( wp_json_encode( $settings['exclude_uploads'] ?? array() ) ); ?>">
			</div>

			<!-- Core Files -->
			<div class="swish-content-section">
				<label class="swish-content-toggle">
					<input type="checkbox" name="swish_backup_settings[backup_core_files]" value="1" <?php checked( $settings['backup_core_files'] ?? false ); ?>>
					<span class="dashicons dashicons-wordpress"></span>
					<?php esc_html_e( 'WordPress Core Files', 'swish-migrate-and-backup' ); ?>
				</label>
				<p class="description" style="margin-left: 28px;">
					<?php esc_html_e( 'Excludes wp-admin, wp-includes by default.', 'swish-migrate-and-backup' ); ?>
				</p>
			</div>
		</div>

		<!-- Exclusions Section -->
		<h2 class="title"><?php esc_html_e( 'File Exclusions', 'swish-migrate-and-backup' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="exclude_files"><?php esc_html_e( 'Exclude Files/Patterns', 'swish-migrate-and-backup' ); ?></label>
				</th>
				<td>
					<textarea name="swish_backup_settings[exclude_files]" id="exclude_files" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $settings['exclude_files'] ?? array() ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One pattern per line. Use * for wildcards. Example: *.log, cache/*', 'swish-migrate-and-backup' ); ?></p>
				</td>
			</tr>
		</table>

		<!-- Notifications Section -->
		<h2 class="title"><?php esc_html_e( 'Notifications', 'swish-migrate-and-backup' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email Notifications', 'swish-migrate-and-backup' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="swish_backup_settings[email_notifications]" value="1" <?php checked( $settings['email_notifications'] ?? false ); ?>>
						<?php esc_html_e( 'Send email after backup completes', 'swish-migrate-and-backup' ); ?>
					</label>
					<br><br>
					<label>
						<?php esc_html_e( 'Notification Email:', 'swish-migrate-and-backup' ); ?>
						<input type="email" name="swish_backup_settings[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ?? get_option( 'admin_email' ) ); ?>" class="regular-text">
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render storage adapter settings.
	 *
	 * @param object $adapter    Storage adapter.
	 * @param string $active_tab Active tab ID.
	 * @return void
	 */
	private function render_storage_settings( $adapter, string $active_tab ): void {
		$fields = $adapter->get_settings_fields();
		$adapter_settings = $adapter->get_settings();
		?>
		<table class="form-table">
			<?php foreach ( $fields as $field ) : ?>
				<?php if ( 'hidden' === ( $field['type'] ?? 'text' ) ) : ?>
					<input type="hidden" name="swish_backup_storage[<?php echo esc_attr( $active_tab ); ?>][<?php echo esc_attr( $field['name'] ); ?>]" value="<?php echo esc_attr( $adapter_settings[ $field['name'] ] ?? '' ); ?>">
				<?php else : ?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $field['name'] ); ?>">
								<?php echo esc_html( $field['label'] ); ?>
								<?php if ( ! empty( $field['required'] ) ) : ?>
									<span class="required">*</span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<?php
							$field_type = $field['type'] ?? 'text';
							$field_value = $adapter_settings[ $field['name'] ] ?? ( $field['default'] ?? '' );
							$field_name = "swish_backup_storage[{$active_tab}][{$field['name']}]";
							$field_id = $field['name'];

							switch ( $field_type ) :
								case 'select':
									?>
									<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>">
										<?php foreach ( $field['options'] as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field_value, $value ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php
									break;
								case 'password':
									?>
									<input type="password" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="" class="regular-text" autocomplete="new-password">
									<?php if ( ! empty( $field_value ) ) : ?>
										<p class="description"><?php esc_html_e( 'Leave blank to keep current value.', 'swish-migrate-and-backup' ); ?></p>
									<?php endif; ?>
									<?php
									break;
								case 'checkbox':
									?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="1" <?php checked( $field_value ); ?>>
										<?php echo esc_html( $field['description'] ?? '' ); ?>
									</label>
									<?php
									break;
								case 'number':
									?>
									<input type="number" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $field_value ); ?>" class="regular-text">
									<?php
									break;
								default:
									?>
									<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $field_value ); ?>" class="regular-text">
									<?php
							endswitch;

							if ( ! empty( $field['description'] ) && 'checkbox' !== $field_type ) :
								?>
								<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</table>

		<p>
			<button type="button" class="button" id="swish-backup-test-connection" data-adapter="<?php echo esc_attr( $active_tab ); ?>">
				<?php esc_html_e( 'Test Connection', 'swish-migrate-and-backup' ); ?>
			</button>
			<span id="swish-backup-connection-status"></span>
		</p>
		<?php
	}

	/**
	 * Render the folder explorer JavaScript.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_folder_explorer_script( array $settings ): void {
		?>
		<style>
			.swish-range-wrapper {
				display: flex;
				align-items: center;
				gap: 15px;
			}
			.swish-range-wrapper input[type="range"] {
				width: 300px;
			}
			.swish-range-value {
				font-weight: 600;
				min-width: 40px;
			}
			.swish-backup-contents-settings {
				background: #fff;
				border: 1px solid #c3c4c7;
				padding: 15px;
				max-width: 800px;
			}
			.swish-content-section {
				padding: 10px 0;
				border-bottom: 1px solid #f0f0f1;
			}
			.swish-content-section:last-child {
				border-bottom: none;
			}
			.swish-content-toggle {
				display: flex;
				align-items: center;
				gap: 8px;
				font-weight: 500;
				cursor: pointer;
			}
			.swish-content-toggle .dashicons {
				color: #2271b1;
			}
			.swish-folder-tree {
				margin-left: 28px;
				margin-top: 8px;
			}
			.swish-tree-toggle {
				display: flex;
				align-items: center;
				gap: 5px;
			}
			.swish-tree-toggle .dashicons {
				transition: transform 0.2s;
			}
			.swish-tree-toggle.expanded .dashicons {
				transform: rotate(90deg);
			}
			.swish-tree-count {
				color: #666;
				font-size: 12px;
				margin-left: 5px;
			}
			.swish-tree-items {
				margin-top: 10px;
				padding: 10px;
				background: #f9f9f9;
				border: 1px solid #e0e0e0;
				border-radius: 4px;
			}
			.swish-tree-actions {
				margin-bottom: 10px;
				display: flex;
				gap: 5px;
			}
			.swish-tree-item {
				display: flex;
				align-items: center;
				padding: 4px 0;
			}
			.swish-tree-item label {
				display: flex;
				align-items: center;
				gap: 8px;
				flex: 1;
				cursor: pointer;
			}
			.swish-tree-item .item-name {
				flex: 1;
			}
			.swish-tree-item .item-size {
				color: #666;
				font-size: 12px;
			}
			.swish-tree-item .active-badge {
				background: #00a32a;
				color: #fff;
				font-size: 10px;
				padding: 2px 6px;
				border-radius: 3px;
				margin-left: 5px;
			}
			.swish-tree-loading {
				display: flex;
				align-items: center;
				gap: 10px;
				color: #666;
			}
			.swish-year-toggle {
				margin-left: 20px;
				margin-top: 5px;
				font-size: 12px;
				color: #2271b1;
				cursor: pointer;
				background: none;
				border: none;
				padding: 0;
			}
			.swish-year-toggle:hover {
				text-decoration: underline;
			}
			.swish-month-items {
				margin-left: 30px;
				margin-top: 5px;
			}
			.swish-month-item {
				display: flex;
				align-items: center;
				gap: 8px;
				padding: 2px 0;
				font-size: 13px;
			}
		</style>

		<script>
		(function() {
			let foldersData = null;
			let foldersLoaded = false;

			// Format bytes to human readable
			function formatBytes(bytes) {
				if (bytes === 0) return '0 B';
				const k = 1024;
				const sizes = ['B', 'KB', 'MB', 'GB'];
				const i = Math.floor(Math.log(bytes) / Math.log(k));
				return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
			}

			// Load folders data
			async function loadFolders() {
				if (foldersLoaded) return foldersData;

				try {
					const response = await fetch('<?php echo esc_url( rest_url( 'swish-backup/v1/folders' ) ); ?>', {
						headers: {
							'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
						}
					});
					foldersData = await response.json();
					foldersLoaded = true;
					return foldersData;
				} catch (error) {
					console.error('Failed to load folders:', error);
					return null;
				}
			}

			// Get excluded items from hidden input
			function getExcluded(type) {
				const input = document.getElementById('exclude_' + type);
				try {
					return JSON.parse(input.value) || [];
				} catch (e) {
					return [];
				}
			}

			// Set excluded items to hidden input
			function setExcluded(type, excluded) {
				const input = document.getElementById('exclude_' + type);
				input.value = JSON.stringify(excluded);
				updateTreeCount(type);
			}

			// Update tree count display
			function updateTreeCount(type) {
				const tree = document.getElementById(type + '-tree');
				const countSpan = tree.querySelector('.swish-tree-count');
				const excluded = getExcluded(type);
				const items = foldersData ? foldersData[type] || [] : [];

				if (excluded.length > 0) {
					countSpan.textContent = '(' + (items.length - excluded.length) + '/' + items.length + ' selected)';
				} else {
					countSpan.textContent = '(' + items.length + ' items)';
				}
			}

			// Render tree items
			function renderTreeItems(container, items, type) {
				container.innerHTML = '';

				// Actions
				const actions = document.createElement('div');
				actions.className = 'swish-tree-actions';
				actions.innerHTML = `
					<button type="button" class="button button-small" data-action="select-all"><?php esc_html_e( 'Select All', 'swish-migrate-and-backup' ); ?></button>
					<button type="button" class="button button-small" data-action="select-none"><?php esc_html_e( 'Select None', 'swish-migrate-and-backup' ); ?></button>
				`;
				container.appendChild(actions);

				actions.querySelector('[data-action="select-all"]').addEventListener('click', function() {
					setExcluded(type, []);
					renderTreeItems(container, items, type);
				});

				actions.querySelector('[data-action="select-none"]').addEventListener('click', function() {
					const allKeys = items.map(item => item.path || item.slug);
					setExcluded(type, allKeys);
					renderTreeItems(container, items, type);
				});

				const excluded = getExcluded(type);

				items.forEach(function(item) {
					const itemKey = item.path || item.slug;
					const isExcluded = excluded.includes(itemKey);

					const div = document.createElement('div');
					div.className = 'swish-tree-item';
					div.innerHTML = `
						<label>
							<input type="checkbox" ${!isExcluded ? 'checked' : ''}>
							<span class="item-name">
								${item.name}
								${item.active ? '<span class="active-badge"><?php esc_html_e( 'Active', 'swish-migrate-and-backup' ); ?></span>' : ''}
							</span>
							<span class="item-size">${formatBytes(item.size || 0)}</span>
						</label>
					`;

					const checkbox = div.querySelector('input[type="checkbox"]');
					checkbox.addEventListener('change', function() {
						let newExcluded = getExcluded(type);
						if (this.checked) {
							newExcluded = newExcluded.filter(e => e !== itemKey);
						} else {
							newExcluded.push(itemKey);
						}
						setExcluded(type, newExcluded);
					});

					container.appendChild(div);

					// Render year/month subfolders for uploads
					if (item.children && item.children.length > 0) {
						const yearToggle = document.createElement('button');
						yearToggle.type = 'button';
						yearToggle.className = 'swish-year-toggle';
						yearToggle.innerHTML = '<span class="dashicons dashicons-arrow-right-alt2"></span> <?php esc_html_e( 'Show months', 'swish-migrate-and-backup' ); ?> (' + item.children.length + ')';

						const monthsContainer = document.createElement('div');
						monthsContainer.className = 'swish-month-items';
						monthsContainer.style.display = 'none';

						yearToggle.addEventListener('click', function() {
							const isHidden = monthsContainer.style.display === 'none';
							monthsContainer.style.display = isHidden ? 'block' : 'none';
							this.querySelector('.dashicons').style.transform = isHidden ? 'rotate(90deg)' : '';
						});

						item.children.forEach(function(month) {
							const monthExcluded = excluded.includes(month.path);
							const monthDiv = document.createElement('div');
							monthDiv.className = 'swish-month-item';
							monthDiv.innerHTML = `
								<label>
									<input type="checkbox" ${!monthExcluded ? 'checked' : ''}>
									<span>${month.name}</span>
									<span class="item-size">${formatBytes(month.size || 0)}</span>
								</label>
							`;

							const monthCheckbox = monthDiv.querySelector('input[type="checkbox"]');
							monthCheckbox.addEventListener('change', function() {
								let newExcluded = getExcluded(type);
								if (this.checked) {
									newExcluded = newExcluded.filter(e => e !== month.path);
								} else {
									newExcluded.push(month.path);
								}
								setExcluded(type, newExcluded);
							});

							monthsContainer.appendChild(monthDiv);
						});

						container.appendChild(yearToggle);
						container.appendChild(monthsContainer);
					}
				});

				updateTreeCount(type);
			}

			// Initialize tree toggles
			document.querySelectorAll('.swish-tree-toggle').forEach(function(toggle) {
				toggle.addEventListener('click', async function() {
					const tree = this.closest('.swish-folder-tree');
					const type = tree.dataset.type;
					const itemsContainer = tree.querySelector('.swish-tree-items');
					const isHidden = itemsContainer.style.display === 'none';

					if (isHidden) {
						this.classList.add('expanded');
						itemsContainer.style.display = 'block';

						// Load folders if not loaded
						if (!foldersLoaded) {
							await loadFolders();
						}

						if (foldersData && foldersData[type]) {
							renderTreeItems(itemsContainer, foldersData[type], type);
						} else {
							itemsContainer.innerHTML = '<p><?php esc_html_e( 'No items found.', 'swish-migrate-and-backup' ); ?></p>';
						}
					} else {
						this.classList.remove('expanded');
						itemsContainer.style.display = 'none';
					}
				});
			});

			// Initialize counts on page load
			loadFolders().then(function() {
				['plugins', 'themes', 'uploads'].forEach(updateTreeCount);
			});

			// Preset function
			window.swishSetPreset = function(files, db) {
				document.getElementById('pipeline_batch_size').value = files;
				document.getElementById('pipeline_batch_size_value').textContent = files;
				document.getElementById('db_batch_size').value = db;
				document.getElementById('db_batch_size_value').textContent = db;
			};
		})();
		</script>
		<?php
	}

	/**
	 * Save settings from form submission.
	 *
	 * Nonce verification is performed in render() before this method is called.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render() method.
		$active_tab = isset( $_POST['active_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['active_tab'] ) ) : 'general';

		if ( 'general' === $active_tab ) {
			// Save general settings.
			$settings = get_option( 'swish_backup_settings', array() );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render() method.
			if ( isset( $_POST['swish_backup_settings'] ) && is_array( $_POST['swish_backup_settings'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
				$input = wp_unslash( $_POST['swish_backup_settings'] );

				// Performance settings.
				$settings['pipeline_batch_size'] = absint( $input['pipeline_batch_size'] ?? 150 );
				$settings['db_batch_size'] = absint( $input['db_batch_size'] ?? 500 );

				// Archive & storage settings.
				$settings['archive_format'] = 'swish';
				$settings['compression_level'] = absint( $input['compression_level'] ?? 6 );
				$settings['default_storage'] = sanitize_text_field( $input['default_storage'] ?? 'local' );

				// Backup contents.
				$settings['backup_database'] = ! empty( $input['backup_database'] );
				$settings['backup_plugins'] = ! empty( $input['backup_plugins'] );
				$settings['backup_themes'] = ! empty( $input['backup_themes'] );
				$settings['backup_uploads'] = ! empty( $input['backup_uploads'] );
				$settings['backup_core_files'] = ! empty( $input['backup_core_files'] );

				// Exclusions (from JSON hidden fields).
				$settings['exclude_plugins'] = $this->parse_json_array( $input['exclude_plugins'] ?? '[]' );
				$settings['exclude_themes'] = $this->parse_json_array( $input['exclude_themes'] ?? '[]' );
				$settings['exclude_uploads'] = $this->parse_json_array( $input['exclude_uploads'] ?? '[]' );

				// File pattern exclusions.
				$exclude_files = $input['exclude_files'] ?? '';
				$settings['exclude_files'] = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( "\n", $exclude_files ) ) ) );

				// Notifications.
				$settings['email_notifications'] = ! empty( $input['email_notifications'] );
				$settings['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );
			}

			update_option( 'swish_backup_settings', $settings );

			add_settings_error(
				'swish_backup_settings',
				'settings_updated',
				__( 'Settings saved.', 'swish-migrate-and-backup' ),
				'success'
			);
		} else {
			// Save storage adapter settings.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render() method.
			if ( isset( $_POST['swish_backup_storage'][ $active_tab ] ) && is_array( $_POST['swish_backup_storage'][ $active_tab ] ) ) {
				$adapter = $this->storage_manager->get_adapter( $active_tab );

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
				$input = wp_unslash( $_POST['swish_backup_storage'][ $active_tab ] );

				// Sanitize input.
				$sanitized = array();
				foreach ( $input as $key => $value ) {
					$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( $value );
				}

				// Keep existing password fields if not provided.
				$existing = $adapter->get_settings();
				foreach ( $adapter->get_settings_fields() as $field ) {
					if ( 'password' === ( $field['type'] ?? 'text' ) ) {
						if ( empty( $sanitized[ $field['name'] ] ) && ! empty( $existing[ $field['name'] ] ) ) {
							$sanitized[ $field['name'] ] = $existing[ $field['name'] ];
						}
					}
				}

				$adapter->save_settings( $sanitized );

				add_settings_error(
					'swish_backup_settings',
					'settings_updated',
					/* translators: %s: storage adapter name */
					sprintf( __( '%s settings saved.', 'swish-migrate-and-backup' ), $adapter->get_name() ),
					'success'
				);
			}
		}

		settings_errors( 'swish_backup_settings' );
	}

	/**
	 * Parse JSON array string safely.
	 *
	 * @param string $json JSON string.
	 * @return array Parsed array.
	 */
	private function parse_json_array( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( is_array( $decoded ) ) {
			return array_map( 'sanitize_text_field', $decoded );
		}
		return array();
	}
}
