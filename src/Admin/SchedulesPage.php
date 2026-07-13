<?php
/**
 * Schedules Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Queue\Scheduler;
use SwishMigrateAndBackup\Admin\Multisite\AdminLayout;

/**
 * Schedules page controller.
 */
final class SchedulesPage {

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Scheduler $scheduler ) {
		$this->scheduler = $scheduler;
	}

	/**
	 * Render the schedules page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Handle form submission.
		if ( isset( $_POST['swish_backup_schedule_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swish_backup_schedule_nonce'] ) ), 'swish_backup_schedule' ) ) {
			$this->save_schedule();
		}

		$schedules = $this->scheduler->get_schedules();
		?>
		<?php
		AdminNav::render_start(
			__( 'Backup Schedules', 'swish-migrate-and-backup' ),
			'',
			array(
				'<button type="button" class="swish-btn swish-btn-primary" id="swish-backup-add-schedule">'
					. '<span class="material-symbols-outlined" style="font-size: 18px;">add</span>'
					. '<span>' . esc_html__( 'Add Schedule', 'swish-migrate-and-backup' ) . '</span>'
					. '</button>',
			)
		);
		?>
			<!-- Schedule Form -->
			<div id="swish-backup-schedule-form" class="swish-card" style="display:none;">
				<div class="swish-card-header">
					<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);"><?php esc_html_e( 'Add New Schedule', 'swish-migrate-and-backup' ); ?></h4>
				</div>
				<div class="swish-card-body">
					<form method="post" action="">
						<?php wp_nonce_field( 'swish_backup_schedule', 'swish_backup_schedule_nonce' ); ?>
						<input type="hidden" name="schedule_id" id="schedule_id" value="">

						<div class="swish-form-group">
							<label class="swish-form-label" for="schedule_name"><?php esc_html_e( 'Schedule Name', 'swish-migrate-and-backup' ); ?></label>
							<input type="text" name="schedule_name" id="schedule_name" class="swish-input" required>
						</div>
						<div class="swish-form-group">
							<label class="swish-form-label" for="schedule_frequency"><?php esc_html_e( 'Frequency', 'swish-migrate-and-backup' ); ?></label>
							<div class="swish-select-wrapper">
								<select name="schedule_frequency" id="schedule_frequency" class="swish-select">
									<option value="hourly"><?php esc_html_e( 'Hourly', 'swish-migrate-and-backup' ); ?></option>
									<option value="twicedaily"><?php esc_html_e( 'Twice Daily', 'swish-migrate-and-backup' ); ?></option>
									<option value="daily" selected><?php esc_html_e( 'Daily', 'swish-migrate-and-backup' ); ?></option>
									<option value="weekly"><?php esc_html_e( 'Weekly', 'swish-migrate-and-backup' ); ?></option>
									<option value="monthly"><?php esc_html_e( 'Monthly', 'swish-migrate-and-backup' ); ?></option>
								</select>
							</div>
						</div>
						<div class="swish-form-group">
							<label class="swish-form-label" for="backup_type"><?php esc_html_e( 'Backup Type', 'swish-migrate-and-backup' ); ?></label>
							<div class="swish-select-wrapper">
								<select name="backup_type" id="backup_type" class="swish-select">
									<option value="full"><?php esc_html_e( 'Full Backup', 'swish-migrate-and-backup' ); ?></option>
									<option value="database"><?php esc_html_e( 'Database Only', 'swish-migrate-and-backup' ); ?></option>
									<option value="files"><?php esc_html_e( 'Files Only', 'swish-migrate-and-backup' ); ?></option>
								</select>
							</div>
						</div>
						<div class="swish-form-group">
							<label class="swish-form-label" for="retention_count"><?php esc_html_e( 'Keep Backups', 'swish-migrate-and-backup' ); ?></label>
							<input type="number" name="retention_count" id="retention_count" value="5" min="1" max="100" class="swish-input" style="max-width: 120px;">
							<p class="swish-help-text"><?php esc_html_e( 'Number of backups to retain. Older backups will be automatically deleted.', 'swish-migrate-and-backup' ); ?></p>
						</div>
						<div class="swish-form-group">
							<label class="swish-form-label"><?php esc_html_e( 'Storage Destinations', 'swish-migrate-and-backup' ); ?></label>
							<div class="swish-checkbox-wrapper">
								<label class="swish-checkbox-label"><input type="checkbox" name="storage_destinations[]" value="local" class="swish-checkbox" checked> <span class="swish-checkbox-text"><?php esc_html_e( 'Local Storage', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="storage_destinations[]" value="s3" class="swish-checkbox"> <span class="swish-checkbox-text"><?php esc_html_e( 'Amazon S3', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="storage_destinations[]" value="dropbox" class="swish-checkbox"> <span class="swish-checkbox-text"><?php esc_html_e( 'Dropbox', 'swish-migrate-and-backup' ); ?></span></label>
								<label class="swish-checkbox-label"><input type="checkbox" name="storage_destinations[]" value="googledrive" class="swish-checkbox"> <span class="swish-checkbox-text"><?php esc_html_e( 'Google Drive', 'swish-migrate-and-backup' ); ?></span></label>
							</div>
						</div>

						<p class="swish-flex swish-gap-2">
							<button type="submit" class="swish-btn swish-btn-primary"><?php esc_html_e( 'Save Schedule', 'swish-migrate-and-backup' ); ?></button>
							<button type="button" class="swish-btn swish-btn-secondary" id="swish-backup-cancel-schedule"><?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- Schedules List -->
			<?php if ( empty( $schedules ) ) : ?>
				<div class="swish-card swish-mt-4">
					<div class="swish-card-body">
						<?php
						AdminLayout::render_empty_state(
							__( 'No Schedules', 'swish-migrate-and-backup' ),
							__( 'Create a backup schedule to automate your backups.', 'swish-migrate-and-backup' ),
							'calendar_month',
							array(
								'label' => __( 'Schedule Backup', 'swish-migrate-and-backup' ),
								'icon'  => 'add',
								'id'    => 'swish-backup-add-schedule-empty',
							)
						);
						?>
					</div>
				</div>
			<?php else : ?>
				<div class="swish-card swish-mt-4">
					<div class="swish-card-body" style="padding: 0;">
						<table class="swish-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Frequency', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Next Run', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'Status', 'swish-migrate-and-backup' ); ?></th>
									<th style="text-align: right;"><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $schedules as $schedule ) : ?>
									<tr data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
										<td><span class="swish-table-cell-primary"><?php echo esc_html( $schedule['name'] ); ?></span></td>
										<td><span class="swish-table-cell-secondary"><?php echo esc_html( ucfirst( $schedule['frequency'] ) ); ?></span></td>
										<td><span class="swish-badge swish-badge-neutral"><?php echo esc_html( ucfirst( $schedule['backup_type'] ) ); ?></span></td>
										<td>
											<span class="swish-table-cell-secondary">
											<?php
											if ( $schedule['next_run'] ) {
												echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $schedule['next_run'] ) ) );
											} else {
												esc_html_e( 'Not scheduled', 'swish-migrate-and-backup' );
											}
											?>
											</span>
										</td>
										<td>
											<?php if ( $schedule['is_active'] ) : ?>
												<span class="swish-badge swish-badge-success"><?php esc_html_e( 'Active', 'swish-migrate-and-backup' ); ?></span>
											<?php else : ?>
												<span class="swish-badge swish-badge-warning"><?php esc_html_e( 'Paused', 'swish-migrate-and-backup' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<div class="swish-table-actions">
												<button type="button" class="swish-btn-icon swish-backup-run-schedule" title="<?php esc_attr_e( 'Run Now', 'swish-migrate-and-backup' ); ?>" data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
													<span class="material-symbols-outlined">play_circle</span>
												</button>
												<button type="button" class="swish-btn-icon swish-backup-toggle-schedule" title="<?php echo $schedule['is_active'] ? esc_attr__( 'Pause', 'swish-migrate-and-backup' ) : esc_attr__( 'Activate', 'swish-migrate-and-backup' ); ?>" data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
													<span class="material-symbols-outlined"><?php echo $schedule['is_active'] ? 'pause_circle' : 'play_arrow'; ?></span>
												</button>
												<button type="button" class="swish-btn-icon danger swish-backup-delete-schedule" title="<?php esc_attr_e( 'Delete', 'swish-migrate-and-backup' ); ?>" data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
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
		<?php
		AdminNav::render_end();
	}

	/**
	 * Save schedule from form submission.
	 *
	 * Nonce verification is performed in render() before this method is called.
	 *
	 * @return void
	 */
	private function save_schedule(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() method.
		$schedule_data = array(
			'name'                 => isset( $_POST['schedule_name'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_name'] ) ) : '',
			'frequency'            => isset( $_POST['schedule_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_frequency'] ) ) : 'daily',
			'backup_type'          => isset( $_POST['backup_type'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_type'] ) ) : 'full',
			'retention_count'      => isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : 5,
			'storage_destinations' => isset( $_POST['storage_destinations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['storage_destinations'] ) ) : array( 'local' ),
		);

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( $_POST['schedule_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $schedule_id ) {
			$this->scheduler->update_schedule( $schedule_id, $schedule_data );
		} else {
			$this->scheduler->create_schedule( $schedule_data );
		}

		add_settings_error(
			'swish_backup_schedule',
			'schedule_saved',
			__( 'Schedule saved successfully.', 'swish-migrate-and-backup' ),
			'success'
		);

		settings_errors( 'swish_backup_schedule' );
	}
}
