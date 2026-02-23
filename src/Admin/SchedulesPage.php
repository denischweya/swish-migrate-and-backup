<?php
/**
 * Schedules Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

use SwishMigrateAndBackup\Queue\Scheduler;

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
		<div class="wrap swish-backup-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Backup Schedules', 'swish-migrate-and-backup' ); ?></h1>
			<button type="button" class="page-title-action" id="swish-backup-add-schedule">
				<?php esc_html_e( 'Add Schedule', 'swish-migrate-and-backup' ); ?>
			</button>
			<hr class="wp-header-end">

			<!-- Schedule Form -->
			<div id="swish-backup-schedule-form" class="swish-backup-card" style="display:none;">
				<h2><?php esc_html_e( 'Add New Schedule', 'swish-migrate-and-backup' ); ?></h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'swish_backup_schedule', 'swish_backup_schedule_nonce' ); ?>
					<input type="hidden" name="schedule_id" id="schedule_id" value="">

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="schedule_name"><?php esc_html_e( 'Schedule Name', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<input type="text" name="schedule_name" id="schedule_name" class="regular-text" required>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="schedule_frequency"><?php esc_html_e( 'Frequency', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<select name="schedule_frequency" id="schedule_frequency">
									<option value="hourly"><?php esc_html_e( 'Hourly', 'swish-migrate-and-backup' ); ?></option>
									<option value="twicedaily"><?php esc_html_e( 'Twice Daily', 'swish-migrate-and-backup' ); ?></option>
									<option value="daily" selected><?php esc_html_e( 'Daily', 'swish-migrate-and-backup' ); ?></option>
									<option value="weekly"><?php esc_html_e( 'Weekly', 'swish-migrate-and-backup' ); ?></option>
									<option value="monthly"><?php esc_html_e( 'Monthly', 'swish-migrate-and-backup' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="backup_type"><?php esc_html_e( 'Backup Type', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<select name="backup_type" id="backup_type">
									<option value="full"><?php esc_html_e( 'Full Backup', 'swish-migrate-and-backup' ); ?></option>
									<option value="database"><?php esc_html_e( 'Database Only', 'swish-migrate-and-backup' ); ?></option>
									<option value="files"><?php esc_html_e( 'Files Only', 'swish-migrate-and-backup' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="retention_count"><?php esc_html_e( 'Keep Backups', 'swish-migrate-and-backup' ); ?></label>
							</th>
							<td>
								<input type="number" name="retention_count" id="retention_count" value="5" min="1" max="100" class="small-text">
								<p class="description"><?php esc_html_e( 'Number of backups to retain. Older backups will be automatically deleted.', 'swish-migrate-and-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Storage Destinations', 'swish-migrate-and-backup' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="storage_destinations[]" value="local" checked>
										<?php esc_html_e( 'Local Storage', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="storage_destinations[]" value="s3">
										<?php esc_html_e( 'Amazon S3', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="storage_destinations[]" value="dropbox">
										<?php esc_html_e( 'Dropbox', 'swish-migrate-and-backup' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="storage_destinations[]" value="googledrive">
										<?php esc_html_e( 'Google Drive', 'swish-migrate-and-backup' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'swish-migrate-and-backup' ); ?></button>
						<button type="button" class="button" id="swish-backup-cancel-schedule"><?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?></button>
					</p>
				</form>
			</div>

			<!-- Schedules List -->
			<?php if ( empty( $schedules ) ) : ?>
				<div class="swish-backup-empty-state">
					<span class="dashicons dashicons-calendar-alt"></span>
					<h2><?php esc_html_e( 'No Schedules', 'swish-migrate-and-backup' ); ?></h2>
					<p><?php esc_html_e( 'Create a backup schedule to automate your backups.', 'swish-migrate-and-backup' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'swish-migrate-and-backup' ); ?></th>
							<th><?php esc_html_e( 'Frequency', 'swish-migrate-and-backup' ); ?></th>
							<th><?php esc_html_e( 'Type', 'swish-migrate-and-backup' ); ?></th>
							<th><?php esc_html_e( 'Next Run', 'swish-migrate-and-backup' ); ?></th>
							<th><?php esc_html_e( 'Status', 'swish-migrate-and-backup' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $schedules as $schedule ) : ?>
							<tr data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
								<td><strong><?php echo esc_html( $schedule['name'] ); ?></strong></td>
								<td><?php echo esc_html( ucfirst( $schedule['frequency'] ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $schedule['backup_type'] ) ); ?></td>
								<td>
									<?php
									if ( $schedule['next_run'] ) {
										echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $schedule['next_run'] ) ) );
									} else {
										esc_html_e( 'Not scheduled', 'swish-migrate-and-backup' );
									}
									?>
								</td>
								<td>
									<?php if ( $schedule['is_active'] ) : ?>
										<span class="swish-backup-status-badge swish-backup-status-success"><?php esc_html_e( 'Active', 'swish-migrate-and-backup' ); ?></span>
									<?php else : ?>
										<span class="swish-backup-status-badge swish-backup-status-warning"><?php esc_html_e( 'Paused', 'swish-migrate-and-backup' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button type="button" class="button button-small swish-backup-run-schedule" data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
										<?php esc_html_e( 'Run Now', 'swish-migrate-and-backup' ); ?>
									</button>
									<button type="button" class="button button-small swish-backup-toggle-schedule" data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
										<?php echo $schedule['is_active'] ? esc_html__( 'Pause', 'swish-migrate-and-backup' ) : esc_html__( 'Activate', 'swish-migrate-and-backup' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete swish-backup-delete-schedule" data-schedule-id="<?php echo esc_attr( (string) $schedule['id'] ); ?>">
										<?php esc_html_e( 'Delete', 'swish-migrate-and-backup' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save schedule from form submission.
	 *
	 * @return void
	 */
	private function save_schedule(): void {
		$schedule_data = array(
			'name'                 => isset( $_POST['schedule_name'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_name'] ) ) : '',
			'frequency'            => isset( $_POST['schedule_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_frequency'] ) ) : 'daily',
			'backup_type'          => isset( $_POST['backup_type'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_type'] ) ) : 'full',
			'retention_count'      => isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : 5,
			'storage_destinations' => isset( $_POST['storage_destinations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['storage_destinations'] ) ) : array( 'local' ),
		);

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( $_POST['schedule_id'] ) : 0;

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
