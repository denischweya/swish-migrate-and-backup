<?php
/**
 * Logs Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * Logs page controller.
 */
final class LogsPage {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Render the logs page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Handle form submissions.
		$this->handle_form_submissions();

		$settings        = get_option( 'swish_backup_settings', array() );
		$logging_enabled = ! empty( $settings['logging_enabled'] );

		// Get filter from query string.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$level_filter = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : 'all';

		// Get logs if enabled.
		$logs = array();
		if ( $logging_enabled ) {
			$min_level = 'all' === $level_filter ? Logger::DEBUG : $level_filter;
			$logs      = $this->logger->get_recent_logs( 200, $min_level );
		}
		?>
		<?php
		AdminNav::render_start(
			__( 'Logs', 'swish-migrate-and-backup' )
		);
		?>
			<div class="swish-logs-container">
				<!-- Logging Toggle -->
				<div class="swish-logs-toggle-section">
					<form method="post" action="">
						<?php wp_nonce_field( 'swish_logs_toggle', 'swish_logs_toggle_nonce' ); ?>
						<label class="swish-toggle-label">
							<span class="swish-toggle-text"><?php esc_html_e( 'Enable Logging', 'swish-migrate-and-backup' ); ?></span>
							<input type="hidden" name="logging_enabled" value="0">
							<input type="checkbox" name="logging_enabled" value="1" <?php checked( $logging_enabled ); ?> onchange="this.form.submit()">
							<span class="swish-toggle-slider"></span>
						</label>
					</form>
					<p class="description">
						<?php if ( $logging_enabled ) : ?>
							<?php esc_html_e( 'Logging is enabled. Backup operations will be recorded.', 'swish-migrate-and-backup' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Logging is disabled. Enable to record backup operations for debugging.', 'swish-migrate-and-backup' ); ?>
						<?php endif; ?>
					</p>
				</div>

				<?php if ( $logging_enabled ) : ?>
					<!-- Filter and Actions -->
					<div class="swish-logs-actions">
						<form method="get" action="" class="swish-logs-filter-form">
							<input type="hidden" name="page" value="swish-backup-logs">
							<label for="level-filter"><?php esc_html_e( 'Filter by level:', 'swish-migrate-and-backup' ); ?></label>
							<select name="level" id="level-filter" onchange="this.form.submit()">
								<option value="all" <?php selected( $level_filter, 'all' ); ?>><?php esc_html_e( 'All Levels', 'swish-migrate-and-backup' ); ?></option>
								<option value="error" <?php selected( $level_filter, 'error' ); ?>><?php esc_html_e( 'Error', 'swish-migrate-and-backup' ); ?></option>
								<option value="warning" <?php selected( $level_filter, 'warning' ); ?>><?php esc_html_e( 'Warning', 'swish-migrate-and-backup' ); ?></option>
								<option value="info" <?php selected( $level_filter, 'info' ); ?>><?php esc_html_e( 'Info', 'swish-migrate-and-backup' ); ?></option>
								<option value="debug" <?php selected( $level_filter, 'debug' ); ?>><?php esc_html_e( 'Debug', 'swish-migrate-and-backup' ); ?></option>
							</select>
						</form>

						<form method="post" action="" class="swish-logs-delete-form">
							<?php wp_nonce_field( 'swish_logs_delete', 'swish_logs_delete_nonce' ); ?>
							<button type="submit" name="delete_logs" value="1" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete all logs?', 'swish-migrate-and-backup' ); ?>');">
								<span class="dashicons dashicons-trash"></span>
								<?php esc_html_e( 'Delete All Logs', 'swish-migrate-and-backup' ); ?>
							</button>
						</form>
					</div>

					<!-- Logs Table -->
					<div class="swish-logs-table-wrapper">
						<?php if ( empty( $logs ) ) : ?>
							<div class="swish-logs-empty">
								<span class="dashicons dashicons-info"></span>
								<p><?php esc_html_e( 'No logs found. Logs will appear here after backup operations are performed.', 'swish-migrate-and-backup' ); ?></p>
							</div>
						<?php else : ?>
							<table class="widefat striped swish-logs-table">
								<thead>
									<tr>
										<th class="swish-log-time"><?php esc_html_e( 'Time', 'swish-migrate-and-backup' ); ?></th>
										<th class="swish-log-level"><?php esc_html_e( 'Level', 'swish-migrate-and-backup' ); ?></th>
										<th class="swish-log-message"><?php esc_html_e( 'Message', 'swish-migrate-and-backup' ); ?></th>
										<th class="swish-log-job"><?php esc_html_e( 'Job ID', 'swish-migrate-and-backup' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $logs as $log ) : ?>
										<?php
										$level_class = 'swish-log-level-' . strtolower( $log['level'] );
										$job_id      = $log['job_id'] ?? '-';
										$context     = ! empty( $log['context'] ) ? json_decode( $log['context'], true ) : array();
										?>
										<tr>
											<td class="swish-log-time">
												<?php echo esc_html( $this->format_timestamp( $log['created_at'] ) ); ?>
											</td>
											<td class="swish-log-level">
												<span class="swish-log-badge <?php echo esc_attr( $level_class ); ?>">
													<?php echo esc_html( strtoupper( $log['level'] ) ); ?>
												</span>
											</td>
											<td class="swish-log-message">
												<?php echo esc_html( $log['message'] ); ?>
												<?php if ( ! empty( $context ) && is_array( $context ) ) : ?>
													<button type="button" class="swish-log-context-toggle" onclick="this.nextElementSibling.classList.toggle('hidden');">
														<span class="dashicons dashicons-arrow-down-alt2"></span>
													</button>
													<pre class="swish-log-context hidden"><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT ) ); ?></pre>
												<?php endif; ?>
											</td>
											<td class="swish-log-job">
												<code><?php echo esc_html( substr( $job_id, 0, 8 ) ); ?></code>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="swish-logs-count">
								<?php
								printf(
									/* translators: %d: number of logs */
									esc_html__( 'Showing %d most recent log entries', 'swish-migrate-and-backup' ),
									count( $logs )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php
		AdminNav::render_end();
		?>

		<style>
			.swish-logs-container {
				max-width: 1200px;
				margin-top: 20px;
			}
			.swish-logs-toggle-section {
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				margin-bottom: 20px;
			}
			.swish-toggle-label {
				display: flex;
				align-items: center;
				gap: 12px;
				cursor: pointer;
			}
			.swish-toggle-text {
				font-weight: 600;
				font-size: 14px;
			}
			.swish-toggle-label input[type="checkbox"] {
				position: relative;
				width: 48px;
				height: 24px;
				appearance: none;
				background: #ccc;
				border-radius: 24px;
				cursor: pointer;
				transition: background 0.2s;
			}
			.swish-toggle-label input[type="checkbox"]:checked {
				background: #2271b1;
			}
			.swish-toggle-label input[type="checkbox"]::before {
				content: '';
				position: absolute;
				top: 2px;
				left: 2px;
				width: 20px;
				height: 20px;
				background: #fff;
				border-radius: 50%;
				transition: transform 0.2s;
			}
			.swish-toggle-label input[type="checkbox"]:checked::before {
				transform: translateX(24px);
			}
			.swish-logs-actions {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 15px;
				gap: 15px;
			}
			.swish-logs-filter-form {
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.swish-logs-filter-form select {
				min-width: 150px;
			}
			.swish-logs-delete-form .button .dashicons {
				margin-right: 4px;
				line-height: 1.4;
			}
			.swish-logs-table-wrapper {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}
			.swish-logs-empty {
				padding: 40px;
				text-align: center;
				color: #646970;
			}
			.swish-logs-empty .dashicons {
				font-size: 48px;
				width: 48px;
				height: 48px;
				margin-bottom: 10px;
			}
			.swish-logs-table {
				margin: 0;
				border: none;
			}
			.swish-logs-table th {
				font-weight: 600;
			}
			.swish-log-time {
				width: 150px;
				white-space: nowrap;
			}
			.swish-log-level {
				width: 80px;
			}
			.swish-log-job {
				width: 100px;
			}
			.swish-log-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.swish-log-level-error {
				background: #f8d7da;
				color: #842029;
			}
			.swish-log-level-warning {
				background: #fff3cd;
				color: #664d03;
			}
			.swish-log-level-info {
				background: #cfe2ff;
				color: #084298;
			}
			.swish-log-level-debug {
				background: #e2e3e5;
				color: #41464b;
			}
			.swish-log-level-notice {
				background: #d1e7dd;
				color: #0f5132;
			}
			.swish-log-level-critical,
			.swish-log-level-alert,
			.swish-log-level-emergency {
				background: #dc3545;
				color: #fff;
			}
			.swish-log-context-toggle {
				background: none;
				border: none;
				cursor: pointer;
				padding: 0;
				margin-left: 5px;
				vertical-align: middle;
			}
			.swish-log-context-toggle .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			.swish-log-context {
				margin-top: 10px;
				padding: 10px;
				background: #f6f7f7;
				border-radius: 4px;
				font-size: 12px;
				max-height: 200px;
				overflow: auto;
			}
			.swish-log-context.hidden {
				display: none;
			}
			.swish-logs-count {
				padding: 10px 15px;
				margin: 0;
				color: #646970;
				font-style: italic;
				border-top: 1px solid #c3c4c7;
			}
		</style>
		<?php
	}

	/**
	 * Handle form submissions.
	 *
	 * @return void
	 */
	private function handle_form_submissions(): void {
		// Handle logging toggle.
		if ( isset( $_POST['swish_logs_toggle_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swish_logs_toggle_nonce'] ) ), 'swish_logs_toggle' ) ) {
			$settings                    = get_option( 'swish_backup_settings', array() );
			$settings['logging_enabled'] = ! empty( $_POST['logging_enabled'] );
			update_option( 'swish_backup_settings', $settings );

			// Show admin notice.
			$message = $settings['logging_enabled']
				? __( 'Logging has been enabled.', 'swish-migrate-and-backup' )
				: __( 'Logging has been disabled.', 'swish-migrate-and-backup' );

			add_settings_error( 'swish_logs', 'logging_toggled', $message, 'success' );
		}

		// Handle delete logs.
		if ( isset( $_POST['swish_logs_delete_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swish_logs_delete_nonce'] ) ), 'swish_logs_delete' ) ) {
			if ( isset( $_POST['delete_logs'] ) ) {
				$this->delete_all_logs();
				add_settings_error( 'swish_logs', 'logs_deleted', __( 'All logs have been deleted.', 'swish-migrate-and-backup' ), 'success' );
			}
		}

		// Display any settings errors/notices.
		settings_errors( 'swish_logs' );
	}

	/**
	 * Delete all logs from database and files.
	 *
	 * @return void
	 */
	private function delete_all_logs(): void {
		global $wpdb;

		// Delete from database.
		$table = $wpdb->prefix . 'swish_backup_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		// Delete log files.
		$log_dir = WP_CONTENT_DIR . '/swish-backups/logs';
		if ( is_dir( $log_dir ) ) {
			$files = glob( $log_dir . '/backup-*.log' );
			if ( $files ) {
				foreach ( $files as $file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $file );
				}
			}
		}
	}

	/**
	 * Format timestamp for display.
	 *
	 * @param string $timestamp MySQL timestamp.
	 * @return string Formatted timestamp.
	 */
	private function format_timestamp( string $timestamp ): string {
		$datetime = strtotime( $timestamp );
		return gmdate( 'Y-m-d H:i:s', $datetime );
	}
}
