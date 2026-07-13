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
use SwishMigrateAndBackup\Admin\Multisite\AdminLayout;

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
				<div class="swish-card">
					<div class="swish-card-body">
						<form method="post" action="">
							<?php wp_nonce_field( 'swish_logs_toggle', 'swish_logs_toggle_nonce' ); ?>
							<label class="swish-toggle-label">
								<span class="swish-toggle-text"><?php esc_html_e( 'Enable Logging', 'swish-migrate-and-backup' ); ?></span>
								<input type="hidden" name="logging_enabled" value="0">
								<input type="checkbox" name="logging_enabled" value="1" <?php checked( $logging_enabled ); ?> onchange="this.form.submit()">
								<span class="swish-toggle-slider"></span>
							</label>
						</form>
						<p class="swish-help-text" style="margin-bottom: 0;">
							<?php if ( $logging_enabled ) : ?>
								<?php esc_html_e( 'Logging is enabled. Backup operations will be recorded.', 'swish-migrate-and-backup' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Logging is disabled. Enable to record backup operations for debugging.', 'swish-migrate-and-backup' ); ?>
							<?php endif; ?>
						</p>
					</div>
				</div>

				<?php if ( $logging_enabled ) : ?>
					<!-- Filter and Actions -->
					<div class="swish-logs-actions swish-flex swish-items-center swish-mt-4" style="justify-content: space-between; gap: var(--swish-space-4);">
						<form method="get" action="" class="swish-logs-filter-form swish-flex swish-items-center swish-gap-2">
							<input type="hidden" name="page" value="swish-backup-logs">
							<label for="level-filter"><?php esc_html_e( 'Filter by level:', 'swish-migrate-and-backup' ); ?></label>
							<div class="swish-select-wrapper">
								<select name="level" id="level-filter" class="swish-select" onchange="this.form.submit()">
									<option value="all" <?php selected( $level_filter, 'all' ); ?>><?php esc_html_e( 'All Levels', 'swish-migrate-and-backup' ); ?></option>
									<option value="error" <?php selected( $level_filter, 'error' ); ?>><?php esc_html_e( 'Error', 'swish-migrate-and-backup' ); ?></option>
									<option value="warning" <?php selected( $level_filter, 'warning' ); ?>><?php esc_html_e( 'Warning', 'swish-migrate-and-backup' ); ?></option>
									<option value="info" <?php selected( $level_filter, 'info' ); ?>><?php esc_html_e( 'Info', 'swish-migrate-and-backup' ); ?></option>
									<option value="debug" <?php selected( $level_filter, 'debug' ); ?>><?php esc_html_e( 'Debug', 'swish-migrate-and-backup' ); ?></option>
								</select>
							</div>
						</form>

						<form method="post" action="" class="swish-logs-delete-form">
							<?php wp_nonce_field( 'swish_logs_delete', 'swish_logs_delete_nonce' ); ?>
							<button type="submit" name="delete_logs" value="1" class="swish-btn swish-btn-secondary swish-btn-danger" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete all logs?', 'swish-migrate-and-backup' ); ?>');">
								<span class="material-symbols-outlined" style="font-size: 18px;">delete</span>
								<?php esc_html_e( 'Delete All Logs', 'swish-migrate-and-backup' ); ?>
							</button>
						</form>
					</div>

					<!-- Logs Table -->
					<div class="swish-card swish-mt-4">
						<?php if ( empty( $logs ) ) : ?>
							<div class="swish-card-body">
								<?php
								AdminLayout::render_empty_state(
									__( 'No Logs Found', 'swish-migrate-and-backup' ),
									__( 'Logs will appear here after backup operations are performed.', 'swish-migrate-and-backup' ),
									'info'
								);
								?>
							</div>
						<?php else : ?>
							<div class="swish-card-body" style="padding: 0;">
								<table class="swish-table swish-logs-table">
									<thead>
										<tr>
											<th class="swish-log-time"><?php esc_html_e( 'Time', 'swish-migrate-and-backup' ); ?></th>
											<th class="swish-log-level"><?php esc_html_e( 'Level', 'swish-migrate-and-backup' ); ?></th>
											<th class="swish-log-message"><?php esc_html_e( 'Message', 'swish-migrate-and-backup' ); ?></th>
											<th class="swish-log-job"><?php esc_html_e( 'Job ID', 'swish-migrate-and-backup' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										$level_variants = array(
											'error'     => 'swish-badge-danger',
											'critical'  => 'swish-badge-danger',
											'alert'     => 'swish-badge-danger',
											'emergency' => 'swish-badge-danger',
											'warning'   => 'swish-badge-warning',
											'info'      => 'swish-badge-info',
											'notice'    => 'swish-badge-success',
											'debug'     => 'swish-badge-neutral',
										);
										?>
										<?php foreach ( $logs as $log ) : ?>
											<?php
											$level_variant = $level_variants[ strtolower( $log['level'] ) ] ?? 'swish-badge-neutral';
											$job_id        = $log['job_id'] ?? '-';
											$context       = ! empty( $log['context'] ) ? json_decode( $log['context'], true ) : array();
											?>
											<tr>
												<td class="swish-log-time">
													<span class="swish-table-cell-secondary"><?php echo esc_html( $this->format_timestamp( $log['created_at'] ) ); ?></span>
												</td>
												<td class="swish-log-level">
													<span class="swish-badge <?php echo esc_attr( $level_variant ); ?>">
														<?php echo esc_html( strtoupper( $log['level'] ) ); ?>
													</span>
												</td>
												<td class="swish-log-message">
													<?php echo esc_html( $log['message'] ); ?>
													<?php if ( ! empty( $context ) && is_array( $context ) ) : ?>
														<button type="button" class="swish-log-context-toggle" onclick="this.nextElementSibling.classList.toggle('hidden');">
															<span class="material-symbols-outlined">expand_more</span>
														</button>
														<pre class="swish-log-context hidden"><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT ) ); ?></pre>
													<?php endif; ?>
												</td>
												<td class="swish-log-job">
													<span class="swish-table-cell-mono"><?php echo esc_html( substr( $job_id, 0, 8 ) ); ?></span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<div class="swish-card-footer">
								<span class="swish-pagination-text">
									<?php
									printf(
										/* translators: %d: number of logs */
										esc_html__( 'Showing %d most recent log entries', 'swish-migrate-and-backup' ),
										count( $logs )
									);
									?>
								</span>
							</div>
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
			}
			.swish-toggle-label {
				display: flex;
				align-items: center;
				gap: 12px;
				cursor: pointer;
			}
			.swish-toggle-text {
				font-weight: var(--swish-font-semibold);
				font-size: var(--swish-text-sm);
			}
			.swish-toggle-label input[type="checkbox"] {
				position: relative;
				width: 48px;
				height: 24px;
				appearance: none;
				background: var(--swish-border);
				border-radius: 24px;
				cursor: pointer;
				transition: background 0.2s;
			}
			.swish-toggle-label input[type="checkbox"]:checked {
				background: var(--swish-primary-600);
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
			.swish-log-context-toggle {
				background: none;
				border: none;
				cursor: pointer;
				padding: 0;
				margin-left: 5px;
				vertical-align: middle;
				color: var(--swish-text-tertiary);
			}
			.swish-log-context-toggle .material-symbols-outlined {
				font-size: 18px;
			}
			.swish-log-context {
				margin-top: 10px;
				padding: 10px;
				background: var(--swish-surface-2, var(--swish-bg));
				border-radius: var(--swish-radius);
				font-size: 12px;
				max-height: 200px;
				overflow: auto;
			}
			.swish-log-context.hidden {
				display: none;
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
