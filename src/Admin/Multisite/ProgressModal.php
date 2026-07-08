<?php
/**
 * Progress Modal Component.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders progress modal for backup operations.
 */
final class ProgressModal {

	/**
	 * Render progress modal HTML.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div id="swish-progress-modal" class="swish-modal-overlay" style="display: none;">
			<div class="swish-modal-content">
				<div class="swish-modal-header">
					<h3 class="swish-modal-title" id="swish-modal-title">
						<span class="material-symbols-outlined">backup</span>
						<?php esc_html_e( 'Creating Backup', 'swish-migrate-and-backup' ); ?>
					</h3>
				</div>
				<div class="swish-modal-body">
					<div class="swish-progress-container">
						<div class="swish-progress-bar">
							<div class="swish-progress-fill" id="swish-progress-fill" style="width: 0%;"></div>
						</div>
						<div style="text-align: center; margin-top: var(--swish-space-3);">
							<span id="swish-progress-percent" style="font-size: var(--swish-text-2xl); font-weight: var(--swish-font-bold); color: var(--swish-text-primary);">0%</span>
						</div>
					</div>

					<div style="text-align: center; margin: var(--swish-space-4) 0;">
						<p id="swish-progress-message" style="margin: 0; color: var(--swish-text-secondary);">
							<?php esc_html_e( 'Initializing backup...', 'swish-migrate-and-backup' ); ?>
						</p>
					</div>

					<div id="swish-progress-details" style="background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg); padding: var(--swish-space-4);">
						<h4 style="margin: 0 0 var(--swish-space-3) 0; font-size: var(--swish-text-sm); font-weight: var(--swish-font-semibold); text-transform: uppercase; letter-spacing: 0.5px;">
							<?php esc_html_e( 'Backup Progress', 'swish-migrate-and-backup' ); ?>
						</h4>
						<div class="swish-import-log" id="swish-backup-log"></div>
					</div>
				</div>
				<div class="swish-modal-footer">
					<button type="button" class="swish-btn swish-btn-secondary" id="swish-run-background">
						<span class="material-symbols-outlined">background_replace</span>
						<?php esc_html_e( 'Run in Background', 'swish-migrate-and-backup' ); ?>
					</button>
					<button type="button" class="swish-btn swish-btn-secondary" id="swish-cancel-backup" style="display: none;">
						<span class="material-symbols-outlined">cancel</span>
						<?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?>
					</button>
				</div>

				<!-- Success state -->
				<div class="swish-modal-state" id="swish-modal-success" style="display: none;">
					<div class="swish-modal-state-icon success">
						<span class="material-symbols-outlined">check_circle</span>
					</div>
					<h3><?php esc_html_e( 'Backup Complete!', 'swish-migrate-and-backup' ); ?></h3>
					<p id="swish-success-message"></p>
					<div class="swish-modal-state-actions">
						<a href="#" class="swish-btn swish-btn-primary" id="swish-download-backup">
							<span class="material-symbols-outlined">download</span>
							<?php esc_html_e( 'Download Backup', 'swish-migrate-and-backup' ); ?>
						</a>
						<button type="button" class="swish-btn swish-btn-secondary" id="swish-close-modal">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Error state -->
				<div class="swish-modal-state" id="swish-modal-error" style="display: none;">
					<div class="swish-modal-state-icon error">
						<span class="material-symbols-outlined">error</span>
					</div>
					<h3><?php esc_html_e( 'Backup Failed', 'swish-migrate-and-backup' ); ?></h3>
					<p id="swish-error-message"></p>
					<div class="swish-modal-state-actions">
						<button type="button" class="swish-btn swish-btn-primary" id="swish-retry-backup">
							<?php esc_html_e( 'Retry', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-btn swish-btn-secondary" id="swish-close-modal-error">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Background state -->
				<div class="swish-modal-state" id="swish-modal-background" style="display: none;">
					<div class="swish-modal-state-icon info">
						<span class="material-symbols-outlined">sync</span>
					</div>
					<h3><?php esc_html_e( 'Running in Background', 'swish-migrate-and-backup' ); ?></h3>
					<p><?php esc_html_e( 'The backup is now running in the background. You can safely navigate away from this page.', 'swish-migrate-and-backup' ); ?></p>
					<p style="font-size: var(--swish-text-sm); color: var(--swish-text-tertiary);">
						<?php esc_html_e( 'You will be notified when the backup is complete.', 'swish-migrate-and-backup' ); ?>
					</p>
					<div class="swish-modal-state-actions">
						<button type="button" class="swish-btn swish-btn-primary" id="swish-close-background">
							<?php esc_html_e( 'Got it', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render progress modal JavaScript.
	 *
	 * @return void
	 */
	public function render_scripts(): void {
		?>
		<script>
		( function( $ ) {
			'use strict';

			window.SwishProgressModal = {
				jobId: null,
				pollInterval: null,
				isBackground: false,
				backupOptions: {},
				logEntries: {},
				lastStep: null,

				/**
				 * Step definitions with labels.
				 */
				stepLabels: {
					init: {
						title: '<?php echo esc_js( __( 'Initializing backup', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Preparing backup environment', 'swish-migrate-and-backup' ) ); ?>'
					},
					core: {
						title: '<?php echo esc_js( __( 'WordPress Core Files', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'wp-admin, wp-includes, root files', 'swish-migrate-and-backup' ) ); ?>'
					},
					files: {
						title: '<?php echo esc_js( __( 'WP-Content Files', 'swish-migrate-and-backup' ) ); ?>',
						detail: ''
					},
					shared: {
						title: '<?php echo esc_js( __( 'Shared Network Tables', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'wp_users, wp_usermeta, wp_blogs', 'swish-migrate-and-backup' ) ); ?>'
					},
					sites: {
						title: '<?php echo esc_js( __( 'Site Databases', 'swish-migrate-and-backup' ) ); ?>',
						detail: ''
					},
					archive: {
						title: '<?php echo esc_js( __( 'Creating Archive', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Compressing backup files', 'swish-migrate-and-backup' ) ); ?>'
					},
					cleanup: {
						title: '<?php echo esc_js( __( 'Cleaning Up', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Removing temporary files', 'swish-migrate-and-backup' ) ); ?>'
					}
				},

				/**
				 * Show the modal.
				 */
				show: function() {
					$( '#swish-progress-modal' ).show().addClass( 'active' );
					$( '#swish-progress-modal .swish-modal-content' ).removeClass( 'show-success show-error show-background' );
					$( '#swish-modal-success, #swish-modal-error, #swish-modal-background' ).hide();
					$( '#swish-progress-modal .swish-modal-body, #swish-progress-modal .swish-modal-footer' ).show();
					this.reset();
				},

				/**
				 * Hide the modal.
				 */
				hide: function() {
					$( '#swish-progress-modal' ).hide().removeClass( 'active' );
					this.stopPolling();
				},

				/**
				 * Reset progress.
				 */
				reset: function() {
					this.updateProgress( 0 );
					this.updateMessage( '<?php echo esc_js( __( 'Initializing backup...', 'swish-migrate-and-backup' ) ); ?>' );
					this.logEntries = {};
					this.lastStep = null;
					$( '#swish-backup-log' ).empty();
				},

				/**
				 * Build the files detail based on backup options.
				 */
				getFilesDetail: function() {
					const parts = [];
					if ( this.backupOptions.include_themes ) parts.push( 'themes' );
					if ( this.backupOptions.include_plugins ) parts.push( 'plugins' );
					if ( this.backupOptions.include_uploads ) parts.push( 'uploads' );
					if ( this.backupOptions.include_mu_plugins ) parts.push( 'mu-plugins' );
					return parts.join( ', ' );
				},

				/**
				 * Get steps array based on backup options.
				 */
				getStepsArray: function() {
					let steps = [ 'init' ];

					if ( this.backupOptions.include_core_files ) {
						steps.push( 'core' );
					}

					// Add files step if any file options are enabled.
					const includesFiles = this.backupOptions.include_themes ||
						this.backupOptions.include_plugins ||
						this.backupOptions.include_uploads ||
						this.backupOptions.include_mu_plugins;

					if ( includesFiles ) {
						steps.push( 'files' );
					}

					steps.push( 'shared', 'sites', 'archive', 'cleanup' );

					return steps;
				},

				/**
				 * Add or update a log entry.
				 */
				addLogEntry: function( step, status, customDetail ) {
					const $log = $( '#swish-backup-log' );
					const stepInfo = this.stepLabels[ step ] || { title: step, detail: '' };
					let detail = customDetail || stepInfo.detail;

					// Special handling for files step.
					if ( step === 'files' && ! customDetail ) {
						detail = this.getFilesDetail();
					}

					// Check if entry exists.
					let $entry = $log.find( '[data-step="' + step + '"]' );

					if ( $entry.length === 0 ) {
						// Create new entry.
						const html = '<div class="swish-log-entry ' + status + '" data-step="' + step + '">' +
							'<span class="swish-log-icon"></span>' +
							'<div class="swish-log-content">' +
								'<div class="swish-log-title">' + stepInfo.title + '</div>' +
								( detail ? '<div class="swish-log-detail">' + detail + '</div>' : '' ) +
							'</div>' +
						'</div>';
						$log.append( html );
						$entry = $log.find( '[data-step="' + step + '"]' );

						// Auto-scroll to bottom.
						$log.scrollTop( $log[0].scrollHeight );
					} else {
						// Update existing entry.
						$entry.removeClass( 'pending in-progress completed failed' ).addClass( status );
						if ( customDetail ) {
							let $detail = $entry.find( '.swish-log-detail' );
							if ( $detail.length === 0 ) {
								$entry.find( '.swish-log-content' ).append( '<div class="swish-log-detail">' + customDetail + '</div>' );
							} else {
								$detail.text( customDetail );
							}
						}
					}

					this.logEntries[ step ] = status;
				},

				/**
				 * Update progress bar.
				 */
				updateProgress: function( percent ) {
					$( '#swish-progress-fill' ).css( 'width', percent + '%' );
					$( '#swish-progress-percent' ).text( Math.round( percent ) + '%' );
				},

				/**
				 * Update status message.
				 */
				updateMessage: function( message ) {
					$( '#swish-progress-message' ).text( message );
				},

				/**
				 * Show success state.
				 */
				showSuccess: function( message, downloadUrl ) {
					this.stopPolling();

					// Mark all entries as completed.
					$( '#swish-backup-log .swish-log-entry' ).each( function() {
						$( this ).removeClass( 'pending in-progress' ).addClass( 'completed' );
					} );

					$( '#swish-progress-modal .swish-modal-content' ).addClass( 'show-success' );
					$( '#swish-modal-success' ).show();
					$( '#swish-success-message' ).text( message );

					if ( downloadUrl ) {
						$( '#swish-download-backup' ).attr( 'href', downloadUrl ).show();
					} else {
						$( '#swish-download-backup' ).hide();
					}
				},

				/**
				 * Show error state.
				 */
				showError: function( message, failedStep ) {
					this.stopPolling();

					// Mark current step as failed.
					if ( failedStep ) {
						this.addLogEntry( failedStep, 'failed', message );
					} else if ( this.lastStep ) {
						$( '#swish-backup-log .swish-log-entry[data-step="' + this.lastStep + '"]' )
							.removeClass( 'in-progress' )
							.addClass( 'failed' );
					}

					$( '#swish-progress-modal .swish-modal-content' ).addClass( 'show-error' );
					$( '#swish-modal-error' ).show();
					$( '#swish-error-message' ).text( message );
				},

				/**
				 * Show background state.
				 */
				showBackground: function() {
					this.isBackground = true;
					$( '#swish-progress-modal .swish-modal-content' ).addClass( 'show-background' );
					$( '#swish-modal-background' ).show();
				},

				/**
				 * Start backup.
				 */
				startBackup: function( siteIds, archiveMode, backupOptions ) {
					const self = this;

					this.backupOptions = backupOptions || {};
					this.show();

					// Add initial log entry.
					this.addLogEntry( 'init', 'in-progress' );

					$.ajax( {
						url: swishBackupPro.ajaxUrl,
						type: 'POST',
						data: {
							action: 'swish_backup_start_multisite_backup_async',
							nonce: swishBackupPro.nonce,
							site_ids: siteIds,
							archive_mode: archiveMode,
							database_only: backupOptions.database_only ? 1 : 0,
							include_core_files: backupOptions.include_core_files ? 1 : 0,
							include_themes: backupOptions.include_themes ? 1 : 0,
							include_plugins: backupOptions.include_plugins ? 1 : 0,
							include_uploads: backupOptions.include_uploads ? 1 : 0,
							include_mu_plugins: backupOptions.include_mu_plugins ? 1 : 0
						},
						success: function( response ) {
							if ( response.success && response.data.job_id ) {
								self.jobId = response.data.job_id;
								self.addLogEntry( 'init', 'completed' );
								self.startPolling();
							} else {
								self.addLogEntry( 'init', 'failed' );
								self.showError( response.data.message || '<?php echo esc_js( __( 'Failed to start backup.', 'swish-migrate-and-backup' ) ); ?>' );
							}
						},
						error: function() {
							self.addLogEntry( 'init', 'failed' );
							self.showError( '<?php echo esc_js( __( 'Network error. Please try again.', 'swish-migrate-and-backup' ) ); ?>' );
						}
					} );
				},

				/**
				 * Start polling for progress.
				 */
				startPolling: function() {
					const self = this;

					this.pollInterval = setInterval( function() {
						self.checkProgress();
					}, 1000 );
				},

				/**
				 * Stop polling.
				 */
				stopPolling: function() {
					if ( this.pollInterval ) {
						clearInterval( this.pollInterval );
						this.pollInterval = null;
					}
				},

				/**
				 * Check backup progress.
				 */
				checkProgress: function() {
					const self = this;

					if ( ! this.jobId ) {
						return;
					}

					$.ajax( {
						url: swishBackupPro.ajaxUrl,
						type: 'POST',
						data: {
							action: 'swish_backup_check_progress',
							nonce: swishBackupPro.nonce,
							job_id: this.jobId
						},
						success: function( response ) {
							if ( response.success ) {
								const data = response.data;

								self.updateProgress( data.progress || 0 );
								self.updateMessage( data.message || '' );

								// Update log entries based on current step.
								if ( data.current_step ) {
									const steps = self.getStepsArray();
									const currentIndex = steps.indexOf( data.current_step );

									steps.forEach( function( step, index ) {
										if ( index < currentIndex ) {
											// Mark previous steps as completed.
											if ( self.logEntries[ step ] !== 'completed' ) {
												self.addLogEntry( step, 'completed' );
											}
										} else if ( index === currentIndex ) {
											// Mark current step as in-progress.
											self.addLogEntry( step, 'in-progress', data.message || null );
											self.lastStep = step;
										}
									} );
								}

								// Check for completion.
								if ( data.status === 'completed' ) {
									self.updateProgress( 100 );

									setTimeout( function() {
										self.showSuccess(
											data.message || '<?php echo esc_js( __( 'Backup completed successfully!', 'swish-migrate-and-backup' ) ); ?>',
											data.download_url || null
										);
									}, 500 );
								} else if ( data.status === 'failed' ) {
									self.showError( data.error || '<?php echo esc_js( __( 'Backup failed.', 'swish-migrate-and-backup' ) ); ?>', data.current_step );
								}
							}
						}
					} );
				},

				/**
				 * Run in background.
				 */
				runInBackground: function() {
					this.showBackground();
					// Polling continues but modal shows background state.
				}
			};

			// Event handlers.
			$( document ).ready( function() {
				// Run in background button.
				$( '#swish-run-background' ).on( 'click', function() {
					SwishProgressModal.runInBackground();
				} );

				// Close modal buttons.
				$( '#swish-close-modal, #swish-close-modal-error, #swish-close-background' ).on( 'click', function() {
					SwishProgressModal.hide();
					location.reload();
				} );

				// Retry button.
				$( '#swish-retry-backup' ).on( 'click', function() {
					// Re-submit the form.
					$( '#swish-multisite-backup-form' ).trigger( 'submit' );
				} );

				// Close on backdrop click (only if in background mode).
				$( '#swish-progress-modal' ).on( 'click', function( e ) {
					if ( e.target === this && SwishProgressModal.isBackground ) {
						SwishProgressModal.hide();
					}
				} );
			} );
		} )( jQuery );
		</script>
		<?php
	}
}
