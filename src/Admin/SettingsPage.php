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

			<form method="post" action="">
				<?php wp_nonce_field( 'swish_backup_settings', 'swish_backup_settings_nonce' ); ?>
				<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<?php if ( 'general' === $active_tab ) : ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="default_storage"><?php esc_html_e( 'Default Storage', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<select name="swish_backup_settings[default_storage]" id="default_storage">
									<?php foreach ( $adapters as $id => $adapter ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['default_storage'] ?? 'local', $id ); ?>>
											<?php echo esc_html( $adapter->get_name() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
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
							<th scope="row"><?php esc_html_e( 'Backup Contents', 'swish-migrate-and-backup' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="swish_backup_settings[backup_database]" value="1" <?php checked( $settings['backup_database'] ?? true ); ?>>
										<?php esc_html_e( 'Database', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="swish_backup_settings[backup_plugins]" value="1" <?php checked( $settings['backup_plugins'] ?? true ); ?>>
										<?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="swish_backup_settings[backup_themes]" value="1" <?php checked( $settings['backup_themes'] ?? true ); ?>>
										<?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="swish_backup_settings[backup_uploads]" value="1" <?php checked( $settings['backup_uploads'] ?? true ); ?>>
										<?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="swish_backup_settings[backup_core_files]" value="1" <?php checked( $settings['backup_core_files'] ?? true ); ?>>
										<?php esc_html_e( 'WordPress Core Files', 'swish-migrate-and-backup' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="exclude_files"><?php esc_html_e( 'Exclude Files/Patterns', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<textarea name="swish_backup_settings[exclude_files]" id="exclude_files" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $settings['exclude_files'] ?? array() ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One pattern per line. Use * for wildcards. Example: *.log, cache/*', 'swish-migrate-and-backup' ); ?></p>
							</td>
						</tr>
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
				<?php else : ?>
					<?php
					$adapter = $adapters[ $active_tab ] ?? null;
					if ( $adapter ) :
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
					<?php endif; ?>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings from form submission.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		$active_tab = isset( $_POST['active_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['active_tab'] ) ) : 'general';

		if ( 'general' === $active_tab ) {
			// Save general settings.
			$settings = array();

			if ( isset( $_POST['swish_backup_settings'] ) && is_array( $_POST['swish_backup_settings'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$input = wp_unslash( $_POST['swish_backup_settings'] );

				$settings['default_storage'] = sanitize_text_field( $input['default_storage'] ?? 'local' );
				$settings['compression_level'] = absint( $input['compression_level'] ?? 6 );
				$settings['backup_database'] = ! empty( $input['backup_database'] );
				$settings['backup_plugins'] = ! empty( $input['backup_plugins'] );
				$settings['backup_themes'] = ! empty( $input['backup_themes'] );
				$settings['backup_uploads'] = ! empty( $input['backup_uploads'] );
				$settings['backup_core_files'] = ! empty( $input['backup_core_files'] );
				$settings['email_notifications'] = ! empty( $input['email_notifications'] );
				$settings['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );

				$exclude_files = $input['exclude_files'] ?? '';
				$settings['exclude_files'] = array_filter( array_map( 'trim', explode( "\n", $exclude_files ) ) );
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
			if ( isset( $_POST['swish_backup_storage'][ $active_tab ] ) && is_array( $_POST['swish_backup_storage'][ $active_tab ] ) ) {
				$adapter = $this->storage_manager->get_adapter( $active_tab );

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
}
