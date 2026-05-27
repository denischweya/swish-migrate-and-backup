/**
 * Swish Migrate and Backup - Admin JavaScript
 */

(function($) {
	'use strict';

	const SwishBackup = {
		/**
		 * Active jobs being tracked.
		 */
		activeJobs: {},

		/**
		 * Initialize.
		 */
		init: function() {
			this.bindEvents();
			this.checkForActiveJobs();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Backup buttons
			$(document).on('click', '#swish-backup-now, #swish-backup-first', this.showBackupTypeSelector);
			$(document).on('click', '.swish-backup-type-option', this.startBackup);

			// Settings modal
			$(document).on('click', '#swish-backup-open-settings', this.openSettingsModal);
			$(document).on('click', '#swish-backup-save-settings', this.saveSettings);
			$(document).on('input', '#swish-backup-settings-modal input[type="range"]', this.updateRangeValue);
			$(document).on('click', '#swish-backup-settings-modal .swish-preset-buttons button', this.applyPresetValue);
			$(document).on('click', '#swish-backup-settings-modal .swish-hosting-presets button', this.applyHostingPreset);

			// Backup actions
			$(document).on('click', '.swish-backup-restore', this.showRestoreModal);
			$(document).on('click', '#swish-backup-restore-confirm', this.confirmRestore);
			$(document).on('click', '.swish-backup-download', this.downloadBackup);
			$(document).on('click', '.swish-backup-cli-download', this.showCliDownload);
			$(document).on('click', '.swish-cli-tab', this.switchCliTool);
			$(document).on('click', '.swish-backup-cli-copy', this.copyCliCommand);
			$(document).on('click', '.swish-backup-delete', this.deleteBackup);

			// Bulk selection
			$(document).on('change', '#swish-backup-select-all', this.toggleSelectAll);
			$(document).on('change', '.swish-backup-checkbox', this.updateBulkSelection);
			$(document).on('click', '#swish-backup-bulk-download', this.bulkDownload);
			$(document).on('click', '#swish-backup-bulk-delete', this.bulkDelete);

			// Cancel job
			$(document).on('click', '.swish-cancel-job-btn', this.cancelJob);

			// Modal
			$(document).on('click', '.swish-backup-modal-cancel', this.hideModals);
			$(document).on('click', '.swish-backup-modal', function(e) {
				if (e.target === this) {
					SwishBackup.hideModals();
				}
			});

			// Storage test
			$(document).on('click', '#swish-backup-test-connection', this.testStorageConnection);

			// Schedule
			$(document).on('click', '#swish-backup-add-schedule, #swish-backup-add-schedule-empty', this.showScheduleForm);
			$(document).on('click', '#swish-backup-cancel-schedule', this.hideScheduleForm);
			$(document).on('click', '.swish-backup-run-schedule', this.runSchedule);
			$(document).on('click', '.swish-backup-toggle-schedule', this.toggleSchedule);
			$(document).on('click', '.swish-backup-delete-schedule', this.deleteSchedule);

			// Migration
			$(document).on('click', '.swish-backup-migration-option', this.selectMigrationMethod);
			$(document).on('click', '[data-goto]', this.navigateMigrationStep);
			$(document).on('click', '#swish-backup-select-file', function() {
				$('#backup_file').click();
			});
			$(document).on('change', '#backup_file', this.handleFileSelect);
			$(document).on('click', '#swish-backup-continue-import', this.uploadAndAnalyzeBackup);
			$(document).on('click', '#swish-backup-browse-server', this.browseServerFiles);
			$(document).on('click', '.swish-backup-select-server-file', this.selectServerFile);
			$(document).on('click', '#swish-backup-preview-url', this.previewUrlReplacement);
			$(document).on('click', '#swish-backup-start-migration', this.startMigration);
			$(document).on('click', '#swish-backup-start-export', this.startExport);
			$(document).on('click', '#swish-backup-preview-search', this.previewSearchReplace);
			$(document).on('click', '#swish-backup-run-search-replace', this.runSearchReplace);

			// Drag and drop
			const dropZone = $('#swish-backup-drop-zone');
			if (dropZone.length) {
				dropZone.on('dragover', function(e) {
					e.preventDefault();
					$(this).addClass('dragover');
				}).on('dragleave drop', function(e) {
					e.preventDefault();
					$(this).removeClass('dragover');
				}).on('drop', function(e) {
					const files = e.originalEvent.dataTransfer.files;
					if (files.length) {
						$('#backup_file')[0].files = files;
						SwishBackup.handleFileSelect();
					}
				});
			}
		},

		/**
		 * Show backup type selector.
		 */
		showBackupTypeSelector: function() {
			$('#swish-backup-type-selector').slideDown();
		},

		/**
		 * Start backup using pipeline API for reliable chunked processing.
		 * This ensures complete file scanning even for large sites.
		 */
		startBackup: function() {
			const type = $(this).data('type');
			const btn = $(this);

			$('.swish-backup-type-option').removeClass('selected');
			btn.addClass('selected');

			// Disable the button and show loading state
			btn.prop('disabled', true);
			$('.swish-backup-type-option').prop('disabled', true);

			// Hide the backup type selector
			$('#swish-backup-type-selector').slideUp();

			// Show immediate "Starting..." UI feedback
			const tempJobId = 'starting_' + Date.now();
			SwishBackup.showStartingUI(tempJobId, type);

			// Start the backup using pipeline API (chunked, resumable)
			console.log('[SwishBackup] Starting backup type:', type);
			wp.apiFetch({
				path: '/swish-backup/v1/pipeline/start',
				method: 'POST',
				data: { type: type }
			}).then(function(response) {
				console.log('[SwishBackup] Pipeline start response:', response);

				// Remove the temporary "starting" UI
				SwishBackup.removeStartingUI(tempJobId);

				if (response.job_id) {
					// Store job in localStorage for persistence across page refreshes
					// Mark as pipeline job so we use the correct polling method
					SwishBackup.storeActiveJob(response.job_id, type, true);
					console.log('[SwishBackup] Job stored:', response.job_id);

					// Redirect to backups page if not already there
					const backupsPageUrl = swishBackup.backupsPageUrl || 'admin.php?page=swish-backup-backups';
					if (window.location.href.indexOf('swish-backup-backups') === -1) {
						window.location.href = backupsPageUrl;
					} else {
						// Already on backups page, render the active job
						SwishBackup.renderActiveJobs();
						SwishBackup.startJobPolling(response.job_id);
					}
				} else if (response.success || response.filename) {
					// Synchronous backup completed immediately
					location.reload();
				} else {
					SwishBackup.enableBackupButtons();
					alert(swishBackup.i18n.backupFailed);
				}
			}).catch(function(error) {
				// Remove the temporary "starting" UI
				SwishBackup.removeStartingUI(tempJobId);
				SwishBackup.enableBackupButtons();
				alert(error.message || swishBackup.i18n.backupFailed);
			});
		},

		/**
		 * Show immediate "Starting backup..." UI feedback.
		 */
		showStartingUI: function(tempJobId, type) {
			const container = $('#swish-active-jobs-container');
			if (container.length === 0) return;

			const typeLabel = (type || 'full').charAt(0).toUpperCase() + (type || 'full').slice(1);

			let html = '<div class="swish-active-jobs">';
			html += '<h2 class="swish-active-jobs-title">Active Backups</h2>';
			html += '<div class="swish-active-job-card swish-job-starting" data-job-id="' + tempJobId + '">';
			html += '  <div class="swish-job-progress-circle">';
			html += '    <svg class="swish-progress-ring" viewBox="0 0 120 120">';
			html += '      <circle class="swish-progress-ring-bg" cx="60" cy="60" r="52" />';
			html += '      <circle class="swish-progress-ring-fill swish-progress-indeterminate" cx="60" cy="60" r="52" stroke-dasharray="326.73" stroke-dashoffset="290" />';
			html += '    </svg>';
			html += '    <div class="swish-progress-text">';
			html += '      <span class="swish-progress-icon"><span class="dashicons dashicons-update spin"></span></span>';
			html += '    </div>';
			html += '  </div>';
			html += '  <div class="swish-job-details">';
			html += '    <div class="swish-job-header">';
			html += '      <span class="swish-job-type-badge swish-backup-type-' + type + '">' + typeLabel + ' Backup</span>';
			html += '      <span class="swish-job-status swish-job-status-starting">Starting...</span>';
			html += '    </div>';
			html += '    <div class="swish-job-step">';
			html += '      <span class="swish-job-step-icon"></span>';
			html += '      <span class="swish-job-step-text">Initializing backup job...</span>';
			html += '    </div>';
			html += '    <div class="swish-job-meta">';
			html += '      <span class="swish-job-started">Starting now...</span>';
			html += '    </div>';
			html += '  </div>';
			html += '</div>';
			html += '</div>';

			container.html(html);
		},

		/**
		 * Remove the temporary "Starting..." UI.
		 */
		removeStartingUI: function(tempJobId) {
			$('.swish-active-job-card[data-job-id="' + tempJobId + '"]').remove();
			// If no more jobs, clear the container (it will be re-rendered with real jobs)
			if ($('.swish-active-job-card').length === 0) {
				$('#swish-active-jobs-container').empty();
			}
		},

		/**
		 * Re-enable backup buttons after error.
		 */
		enableBackupButtons: function() {
			$('.swish-backup-type-option').prop('disabled', false).removeClass('selected');
		},

		/**
		 * Store active job in localStorage.
		 */
		storeActiveJob: function(jobId, type, isPipeline) {
			const jobs = JSON.parse(localStorage.getItem('swish_active_jobs') || '{}');

			// Build initial steps based on job type
			const initialSteps = isPipeline
				? [
					{ name: 'Scanning files', status: 'pending', detail: '' },
					{ name: 'Archiving files', status: 'pending', detail: '' },
					{ name: 'Finalizing', status: 'pending', detail: '' }
				]
				: [
					{ name: 'Initialize', status: 'pending', detail: '' },
					{ name: 'Database backup', status: 'pending', detail: '' },
					{ name: 'File archiving', status: 'pending', detail: '' },
					{ name: 'Finalizing', status: 'pending', detail: '' }
				];

			jobs[jobId] = {
				id: jobId,
				type: type,
				isPipeline: isPipeline || false,
				startedAt: Date.now(),
				progress: 0,
				status: 'pending',
				step: 'Initializing...',
				steps: initialSteps
			};
			localStorage.setItem('swish_active_jobs', JSON.stringify(jobs));
		},

		/**
		 * Get active jobs from localStorage.
		 */
		getActiveJobs: function() {
			return JSON.parse(localStorage.getItem('swish_active_jobs') || '{}');
		},

		/**
		 * Remove job from localStorage.
		 */
		removeActiveJob: function(jobId) {
			const jobs = this.getActiveJobs();
			delete jobs[jobId];
			localStorage.setItem('swish_active_jobs', JSON.stringify(jobs));
		},

		/**
		 * Check for active jobs on page load.
		 */
		checkForActiveJobs: function() {
			// Clean up stale jobs first (older than 1 hour)
			this.cleanupStaleJobs();

			const jobs = this.getActiveJobs();
			const jobIds = Object.keys(jobs);

			console.log('[SwishBackup] Checking for active jobs:', jobIds.length, jobs);

			if (jobIds.length > 0) {
				this.renderActiveJobs();

				// Start polling for each active job
				jobIds.forEach(function(jobId) {
					console.log('[SwishBackup] Starting polling for:', jobId);
					SwishBackup.startJobPolling(jobId);
				});
			}
		},

		/**
		 * Clean up stale jobs from localStorage.
		 * Jobs older than 1 hour are considered stale.
		 */
		cleanupStaleJobs: function() {
			const jobs = this.getActiveJobs();
			const jobIds = Object.keys(jobs);
			const oneHourAgo = Date.now() - (60 * 60 * 1000);
			let cleaned = false;

			jobIds.forEach(function(jobId) {
				const job = jobs[jobId];
				if (job.startedAt && job.startedAt < oneHourAgo) {
					console.log('Removing stale job:', jobId);
					delete jobs[jobId];
					cleaned = true;
				}
			});

			if (cleaned) {
				localStorage.setItem('swish_active_jobs', JSON.stringify(jobs));
			}
		},

		/**
		 * Render active jobs UI.
		 */
		renderActiveJobs: function() {
			const container = $('#swish-active-jobs-container');
			console.log('[SwishBackup] Render container found:', container.length > 0);
			if (container.length === 0) return;

			const jobs = this.getActiveJobs();
			const jobIds = Object.keys(jobs);

			if (jobIds.length === 0) {
				container.empty();
				return;
			}

			let html = '<div class="swish-active-jobs">';
			html += '<h2 class="swish-active-jobs-title">Active Backups</h2>';

			jobIds.forEach(function(jobId) {
				const job = jobs[jobId];
				const progress = job.progress || 0;
				const progressDisplay = progress.toFixed(2);

				html += '<div class="swish-active-job-card" data-job-id="' + jobId + '">';
				html += '  <div class="swish-job-progress-circle">';
				html += '    <svg class="swish-progress-ring" viewBox="0 0 120 120">';
				html += '      <circle class="swish-progress-ring-bg" cx="60" cy="60" r="52" />';
				html += '      <circle class="swish-progress-ring-fill" cx="60" cy="60" r="52" stroke-dasharray="326.73" stroke-dashoffset="' + (326.73 - (326.73 * progress / 100)) + '" />';
				html += '    </svg>';
				html += '    <div class="swish-progress-text">';
				html += '      <span class="swish-progress-percent">' + progressDisplay + '%</span>';
				html += '    </div>';
				html += '  </div>';
				html += '  <div class="swish-job-details">';
				html += '    <div class="swish-job-header">';
				html += '      <span class="swish-job-type-badge swish-backup-type-' + (job.type || 'full') + '">' + (job.type || 'Full').charAt(0).toUpperCase() + (job.type || 'full').slice(1) + ' Backup</span>';
				html += '      <span class="swish-job-status swish-job-status-' + (job.status || 'pending') + '">' + SwishBackup.formatJobStatus(job.status) + '</span>';
				html += '    </div>';
				html += '    <div class="swish-job-step">';
				html += '      <span class="swish-job-step-icon"></span>';
				html += '      <span class="swish-job-step-text">' + (job.step || 'Initializing...') + '</span>';
				html += '    </div>';
				html += '    <div class="swish-job-meta">';
				html += '      <span class="swish-job-started">Started: ' + SwishBackup.formatTimeAgo(job.startedAt) + '</span>';
				if (job.filesProcessed) {
					html += '      <span class="swish-job-files">' + job.filesProcessed + ' files processed</span>';
				}
				if (job.currentSize) {
					html += '      <span class="swish-job-size">' + SwishBackup.formatFileSize(job.currentSize) + '</span>';
				}
				html += '    </div>';
				html += '    <div class="swish-job-steps-log" id="swish-job-log-' + jobId + '">';
				if (job.steps && job.steps.length > 0) {
					job.steps.forEach(function(step) {
						html += '<div class="swish-step-entry swish-step-' + step.status + '">';
						html += '  <span class="swish-step-icon"></span>';
						html += '  <span class="swish-step-name">' + step.name + '</span>';
						if (step.detail) {
							html += '  <span class="swish-step-detail">' + step.detail + '</span>';
						}
						html += '</div>';
					});
				}
				html += '    </div>';
				html += '    <div class="swish-job-actions">';
				html += '      <button type="button" class="button swish-cancel-job-btn" data-job-id="' + jobId + '">';
				html += '        <span class="dashicons dashicons-no-alt"></span> Cancel';
				html += '      </button>';
				html += '    </div>';
				html += '  </div>';
				html += '</div>';
			});

			html += '</div>';
			container.html(html);
		},

		/**
		 * Update active job UI.
		 */
		updateActiveJobUI: function(jobId, jobData) {
			const jobs = this.getActiveJobs();
			if (!jobs[jobId]) return;

			// Update stored job data
			jobs[jobId] = Object.assign(jobs[jobId], jobData);
			localStorage.setItem('swish_active_jobs', JSON.stringify(jobs));

			// Update UI
			const card = $('.swish-active-job-card[data-job-id="' + jobId + '"]');
			if (card.length === 0) {
				this.renderActiveJobs();
				return;
			}

			const progress = jobData.progress || 0;
			const progressDisplay = progress.toFixed(2);

			// Update progress circle
			const circumference = 326.73;
			const offset = circumference - (circumference * progress / 100);
			card.find('.swish-progress-ring-fill').attr('stroke-dashoffset', offset);
			card.find('.swish-progress-percent').text(progressDisplay + '%');

			// Update status
			card.find('.swish-job-status')
				.removeClass('swish-job-status-pending swish-job-status-processing swish-job-status-completed swish-job-status-failed')
				.addClass('swish-job-status-' + jobData.status)
				.text(this.formatJobStatus(jobData.status));

			// Update current step
			if (jobData.step) {
				card.find('.swish-job-step-text').text(jobData.step);
			}

			// Update meta info
			if (jobData.filesProcessed) {
				let filesSpan = card.find('.swish-job-files');
				if (filesSpan.length === 0) {
					card.find('.swish-job-meta').append('<span class="swish-job-files">' + jobData.filesProcessed + ' files processed</span>');
				} else {
					filesSpan.text(jobData.filesProcessed + ' files processed');
				}
			}

			if (jobData.currentSize) {
				let sizeSpan = card.find('.swish-job-size');
				if (sizeSpan.length === 0) {
					card.find('.swish-job-meta').append('<span class="swish-job-size">' + this.formatFileSize(jobData.currentSize) + '</span>');
				} else {
					sizeSpan.text(this.formatFileSize(jobData.currentSize));
				}
			}

			// Update steps log
			if (jobData.steps) {
				const log = card.find('.swish-job-steps-log');
				let stepsHtml = '';
				jobData.steps.forEach(function(step) {
					stepsHtml += '<div class="swish-step-entry swish-step-' + step.status + '">';
					stepsHtml += '  <span class="swish-step-icon"></span>';
					stepsHtml += '  <span class="swish-step-name">' + step.name + '</span>';
					if (step.detail) {
						stepsHtml += '  <span class="swish-step-detail">' + step.detail + '</span>';
					}
					stepsHtml += '</div>';
				});
				log.html(stepsHtml);
			}
		},

		/**
		 * Start polling for job status.
		 */
		startJobPolling: function(jobId) {
			if (this.activeJobs[jobId]) return; // Already polling

			this.activeJobs[jobId] = {
				pollCount: 0,
				processTried: false
			};

			this.pollJobStatusNew(jobId);
		},

		/**
		 * Poll job status (new implementation with detailed progress).
		 * Uses pipeline API for pipeline jobs, legacy API for others.
		 */
		pollJobStatusNew: function(jobId) {
			const self = this;
			const jobState = this.activeJobs[jobId];
			if (!jobState) {
				console.log('[SwishBackup] No job state for:', jobId);
				return;
			}

			jobState.pollCount++;
			console.log('[SwishBackup] Polling job:', jobId, 'count:', jobState.pollCount);

			// Check if this is a pipeline job
			const storedJobs = this.getActiveJobs();
			const storedJob = storedJobs[jobId];
			const isPipeline = storedJob && storedJob.isPipeline;

			// Pipeline jobs use /pipeline/continue which advances AND returns status
			// Legacy jobs use /job/{id} to get status
			const apiPath = isPipeline
				? '/swish-backup/v1/pipeline/continue/' + jobId
				: '/swish-backup/v1/job/' + jobId;
			const apiMethod = isPipeline ? 'POST' : 'GET';

			wp.apiFetch({
				path: apiPath,
				method: apiMethod
			}).then(function(job) {
				console.log('[SwishBackup] API response:', job);
				// Normalize response - pipeline API uses different field names
				const isComplete = job.completed === true || job.status === 'completed' || job.phase === 'complete';
				const isFailed = job.status === 'failed' || job.phase === 'failed';
				const normalizedStatus = isComplete ? 'completed' : (isFailed ? 'failed' : (job.status || job.phase || 'processing'));

				const jobData = {
					status: normalizedStatus,
					progress: job.progress || job.overall_progress || 0,
					step: self.getStepDescription(job),
					filesProcessed: job.files_processed || job.processed_files || (job.stats && job.stats.completed),
					currentSize: job.current_size || job.size || job.file_size,
					steps: self.buildStepsList(job)
				};

				self.updateActiveJobUI(jobId, jobData);

				if (isComplete) {
					// Mark as completed and reload after a short delay
					setTimeout(function() {
						self.removeActiveJob(jobId);
						delete self.activeJobs[jobId];
						location.reload();
					}, 2000);
				} else if (isFailed) {
					jobData.step = job.error || job.message || 'Backup failed';
					self.updateActiveJobUI(jobId, jobData);
					self.removeActiveJob(jobId);
					delete self.activeJobs[jobId];
				} else if (!isPipeline && job.status === 'pending' && !jobState.processTried && jobState.pollCount >= 3) {
					// Try to process directly (fallback for slow cron) - only for legacy jobs
					jobState.processTried = true;
					wp.apiFetch({
						path: '/swish-backup/v1/job/' + jobId + '/process',
						method: 'POST'
					}).then(function(result) {
						// Continue polling regardless of result
						setTimeout(function() {
							self.pollJobStatusNew(jobId);
						}, 1000);
					}).catch(function() {
						setTimeout(function() {
							self.pollJobStatusNew(jobId);
						}, 1000);
					});
				} else {
					// Continue polling - pipeline jobs need continuous polling to advance
					setTimeout(function() {
						self.pollJobStatusNew(jobId);
					}, isPipeline ? 500 : 1000); // Poll faster for pipeline jobs
				}
			}).catch(function(error) {
				console.error('[SwishBackup] API error:', error);

				// Helper to clean up job from UI and localStorage
				const cleanupJobUI = function() {
					self.removeActiveJob(jobId);
					delete self.activeJobs[jobId];

					// Remove the job card from UI
					$('.swish-active-job-card[data-job-id="' + jobId + '"]').fadeOut(300, function() {
						$(this).remove();
						if ($('.swish-active-job-card').length === 0) {
							$('#swish-active-jobs-container').empty();
						}
					});
				};

				// Check if job not found (404) - clean up immediately
				if (error && (error.code === 'job_not_found' || error.code === 'rest_no_route')) {
					console.log('[SwishBackup] Job not found, cleaning up:', jobId);
					cleanupJobUI();
					return;
				}

				// For other errors, retry with a limit
				if (jobState.pollCount < 30) { // Max 30 seconds for transient errors
					setTimeout(function() {
						self.pollJobStatusNew(jobId);
					}, 1000);
				} else {
					// Give up after 30 seconds of errors
					console.log('[SwishBackup] Giving up on job after too many errors:', jobId);
					cleanupJobUI();
				}
			});
		},

		/**
		 * Get step description from job data.
		 */
		getStepDescription: function(job) {
			if (job.current_step) return job.current_step;
			if (job.message) return job.message;

			switch (job.phase || job.status) {
				case 'pending':
					return 'Waiting to start...';
				case 'initializing':
				case 'init':
					return 'Initializing backup environment...';
				case 'indexing':
					// Pipeline: scanning files
					const indexed = job.stats ? job.stats.total : 0;
					return indexed > 0 ? 'Scanning files... (' + indexed + ' found)' : 'Scanning files...';
				case 'database':
					return 'Backing up database tables...';
				case 'processing':
					// Pipeline: archiving files
					const stats = job.stats || {};
					if (stats.completed && stats.total) {
						return 'Archiving files... ' + stats.completed + '/' + stats.total;
					}
					return 'Archiving files...';
				case 'files':
					return 'Archiving files...';
				case 'uploading':
					return 'Uploading to storage...';
				case 'finalizing':
					return 'Finalizing backup archive...';
				case 'complete':
				case 'completed':
					return 'Backup completed successfully!';
				case 'failed':
					return job.error || 'Backup failed';
				default:
					return 'Processing...';
			}
		},

		/**
		 * Build steps list for display.
		 * Handles both pipeline phases (indexing, processing, finalizing, complete)
		 * and legacy phases (initializing, database, files, finalizing).
		 */
		buildStepsList: function(job) {
			const steps = [];
			const currentPhase = job.phase || job.status || '';
			const isPipeline = !!job.stats || currentPhase === 'indexing' || currentPhase === 'processing';

			// Use appropriate phases based on job type
			const phases = isPipeline
				? ['indexing', 'processing', 'finalizing']
				: ['initializing', 'database', 'files', 'finalizing'];

			const phaseNames = {
				// Pipeline phases
				'indexing': 'Scanning files',
				'processing': 'Archiving files',
				// Legacy phases
				'initializing': 'Initialize',
				'database': 'Database backup',
				'files': 'File archiving',
				// Common
				'finalizing': 'Finalizing'
			};

			let reachedCurrent = false;
			const isComplete = currentPhase === 'complete' || currentPhase === 'completed' || job.completed === true;
			const isFailed = currentPhase === 'failed' || job.status === 'failed';

			phases.forEach(function(phase) {
				let status = 'pending';
				let detail = '';

				if (isComplete) {
					status = 'completed';
				} else if (isFailed && currentPhase === phase) {
					status = 'failed';
					detail = job.error || '';
				} else if (currentPhase === phase) {
					status = 'in-progress';
					reachedCurrent = true;

					// Add details based on phase
					if (phase === 'indexing' && job.stats) {
						detail = (job.stats.total || 0) + ' files found';
					} else if (phase === 'processing' && job.stats) {
						const completed = (job.stats.completed || 0) + (job.stats.skipped || 0);
						const total = job.stats.total || 0;
						detail = completed + '/' + total + ' files';
					} else if (phase === 'database' && job.tables_processed) {
						detail = job.tables_processed + ' tables';
					} else if (phase === 'files' && job.files_processed) {
						detail = job.files_processed + ' files';
					}
				} else if (!reachedCurrent && currentPhase) {
					// Phases before current are completed
					const phaseOrder = phases.indexOf(phase);
					const currentOrder = phases.indexOf(currentPhase);
					if (phaseOrder < currentOrder || currentOrder === -1) {
						status = 'completed';
					}
				}

				steps.push({
					name: phaseNames[phase] || phase,
					status: status,
					detail: detail
				});
			});

			return steps;
		},

		/**
		 * Format job status for display.
		 */
		formatJobStatus: function(status) {
			const statusMap = {
				'pending': 'Queued',
				'processing': 'In Progress',
				'completed': 'Completed',
				'failed': 'Failed'
			};
			return statusMap[status] || status;
		},

		/**
		 * Format time ago.
		 */
		formatTimeAgo: function(timestamp) {
			if (!timestamp) return 'Just now';

			const seconds = Math.floor((Date.now() - timestamp) / 1000);

			if (seconds < 60) return seconds + 's ago';
			if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
			if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
			return Math.floor(seconds / 86400) + 'd ago';
		},

		/**
		 * Cancel a backup job.
		 */
		cancelJob: function() {
			const jobId = $(this).data('job-id');

			if (!confirm('Are you sure you want to cancel this backup?')) {
				return;
			}

			const btn = $(this);
			btn.prop('disabled', true).text('Cancelling...');

			// Helper function to clean up the job from UI and localStorage
			const cleanupJob = function() {
				// Remove job from localStorage and stop polling
				SwishBackup.removeActiveJob(jobId);
				delete SwishBackup.activeJobs[jobId];

				// Remove the job card from UI
				$('.swish-active-job-card[data-job-id="' + jobId + '"]').fadeOut(300, function() {
					$(this).remove();

					// If no more jobs, remove the container
					if ($('.swish-active-job-card').length === 0) {
						$('#swish-active-jobs-container').empty();
					}
				});
			};

			wp.apiFetch({
				path: '/swish-backup/v1/job/' + jobId + '/cancel',
				method: 'POST'
			}).then(function(response) {
				// Always clean up, regardless of response
				cleanupJob();
			}).catch(function(error) {
				// If job not found, it's already gone - just clean up locally
				// For any error, clean up the local state since user wants to cancel
				console.log('[SwishBackup] Cancel error (cleaning up anyway):', error.message || error);
				cleanupJob();
			});
		},

		/**
		 * Show restore modal.
		 */
		showRestoreModal: function() {
			const backupId = $(this).data('backup-id');
			$('#swish-backup-restore-modal').data('backup-id', backupId).show();
		},

		/**
		 * Confirm restore.
		 */
		confirmRestore: function() {
			if (!confirm(swishBackup.i18n.confirmRestore)) {
				return;
			}

			const backupId = $('#swish-backup-restore-modal').data('backup-id');
			const options = {
				restore_database: $('[name="restore_database"]').is(':checked'),
				restore_files: $('[name="restore_files"]').is(':checked')
			};

			$('#swish-backup-restore-modal').hide();
			SwishBackup.showProgressModal(swishBackup.i18n.restoreStarted);

			wp.apiFetch({
				path: '/swish-backup/v1/restore',
				method: 'POST',
				data: {
					backup_id: backupId,
					...options
				}
			}).then(function(response) {
				SwishBackup.updateProgress(100, swishBackup.i18n.restoreComplete);
				setTimeout(function() {
					location.reload();
				}, 1500);
			}).catch(function(error) {
				SwishBackup.showError(swishBackup.i18n.restoreFailed);
			});
		},

		/**
		 * Download backup.
		 */
		downloadBackup: function() {
			const backupId = $(this).data('backup-id');

			wp.apiFetch({
				path: `/swish-backup/v1/backup/${backupId}/download`,
				method: 'GET'
			}).then(function(response) {
				if (response.url) {
					window.location.href = response.url;
				}
			});
		},

		/**
		 * Show CLI download modal with curl and aria2c commands.
		 */
		showCliDownload: function() {
			const backupId = $(this).data('backup-id');
			const filename = $(this).data('filename');
			const $modal = $('#swish-backup-cli-modal');
			const $curlCommand = $('#swish-backup-cli-command');
			const $aria2cCommand = $('#swish-backup-aria2c-command');

			$curlCommand.text('Loading...');
			$aria2cCommand.text('Loading...');
			$modal.show();

			// Reset to curl tab
			$('.swish-cli-tab').removeClass('active');
			$('.swish-cli-tab[data-tool="curl"]').addClass('active');
			$('#swish-cli-curl').show();
			$('#swish-cli-aria2c').hide();

			wp.apiFetch({
				path: `/swish-backup/v1/backup/${backupId}/download`,
				method: 'GET'
			}).then(function(response) {
				if (response.url) {
					// Single-line commands so copy/paste works on bash, zsh, PowerShell and CMD.
					const curlCommand = `curl -L -C - --retry 100 --retry-delay 5 --retry-all-errors --connect-timeout 30 --max-time 0 -o "${filename}" "${response.url}"`;
					$curlCommand.text(curlCommand);

					const aria2cCommand = `aria2c --continue=true --max-tries=100 --retry-wait=5 --timeout=60 --connect-timeout=30 --max-connection-per-server=1 --split=1 --http-no-cache=true --auto-file-renaming=false -o "${filename}" "${response.url}"`;
					$aria2cCommand.text(aria2cCommand);
				} else {
					$curlCommand.text('Error: Could not generate download URL');
					$aria2cCommand.text('Error: Could not generate download URL');
				}
			}).catch(function(error) {
				const errorMsg = 'Error: ' + (error.message || 'Could not generate download URL');
				$curlCommand.text(errorMsg);
				$aria2cCommand.text(errorMsg);
			});
		},

		/**
		 * Handle CLI tool tab switching.
		 */
		switchCliTool: function() {
			const tool = $(this).data('tool');

			// Update active tab
			$('.swish-cli-tab').removeClass('active');
			$(this).addClass('active');

			// Show/hide tool sections
			$('.swish-cli-tool-section').hide();
			$('#swish-cli-' + tool).show();
		},

		/**
		 * Copy CLI command to clipboard.
		 */
		copyCliCommand: function() {
			const $button = $(this);
			const targetId = $button.data('target') || 'swish-backup-cli-command';
			const $command = $('#' + targetId);
			const text = $command.text();

			navigator.clipboard.writeText(text).then(function() {
				const originalHtml = $button.html();
				$button.html('<span class="dashicons dashicons-yes"></span> Copied!');
				setTimeout(function() {
					$button.html(originalHtml);
				}, 2000);
			}).catch(function() {
				// Fallback for older browsers
				const textarea = document.createElement('textarea');
				textarea.value = text;
				document.body.appendChild(textarea);
				textarea.select();
				document.execCommand('copy');
				document.body.removeChild(textarea);

				const originalHtml = $button.html();
				$button.html('<span class="dashicons dashicons-yes"></span> Copied!');
				setTimeout(function() {
					$button.html(originalHtml);
				}, 2000);
			});
		},

		/**
		 * Delete backup.
		 */
		deleteBackup: function() {
			if (!confirm(swishBackup.i18n.confirmDelete)) {
				return;
			}

			const backupId = $(this).data('backup-id');
			const row = $(this).closest('tr');

			wp.apiFetch({
				path: `/swish-backup/v1/backup/${backupId}`,
				method: 'DELETE'
			}).then(function(response) {
				row.fadeOut(function() {
					$(this).remove();
					SwishBackup.updateBulkSelection();
				});
			});
		},

		/**
		 * Toggle select all checkboxes.
		 */
		toggleSelectAll: function() {
			const isChecked = $(this).is(':checked');
			$('.swish-backup-checkbox').prop('checked', isChecked);
			SwishBackup.updateBulkSelection();
		},

		/**
		 * Update bulk selection UI.
		 */
		updateBulkSelection: function() {
			const $checkboxes = $('.swish-backup-checkbox');
			const $checked = $('.swish-backup-checkbox:checked');
			const count = $checked.length;

			// Update count display
			$('#swish-backup-selected-count').text(count);

			// Show/hide bulk actions bar
			if (count > 0) {
				$('#swish-backup-bulk-actions').removeClass('hidden');
			} else {
				$('#swish-backup-bulk-actions').addClass('hidden');
			}

			// Update select all checkbox state
			if (count === 0) {
				$('#swish-backup-select-all').prop('checked', false).prop('indeterminate', false);
			} else if (count === $checkboxes.length) {
				$('#swish-backup-select-all').prop('checked', true).prop('indeterminate', false);
			} else {
				$('#swish-backup-select-all').prop('checked', false).prop('indeterminate', true);
			}
		},

		/**
		 * Bulk download selected backups.
		 */
		bulkDownload: function() {
			const $checked = $('.swish-backup-checkbox:checked');
			if ($checked.length === 0) {
				return;
			}

			// Download each backup sequentially
			const backupIds = $checked.map(function() {
				return $(this).val();
			}).get();

			let index = 0;

			function downloadNext() {
				if (index >= backupIds.length) {
					return;
				}

				const backupId = backupIds[index];
				index++;

				wp.apiFetch({
					path: `/swish-backup/v1/backup/${backupId}/download`,
					method: 'GET'
				}).then(function(response) {
					if (response.url) {
						// Create hidden iframe to trigger download
						const iframe = document.createElement('iframe');
						iframe.style.display = 'none';
						iframe.src = response.url;
						document.body.appendChild(iframe);

						// Download next after a short delay
						setTimeout(downloadNext, 1000);
					} else {
						downloadNext();
					}
				}).catch(function() {
					downloadNext();
				});
			}

			downloadNext();
		},

		/**
		 * Bulk delete selected backups.
		 */
		bulkDelete: function() {
			const $checked = $('.swish-backup-checkbox:checked');
			const count = $checked.length;

			if (count === 0) {
				return;
			}

			const confirmMessage = count === 1
				? swishBackup.i18n.confirmDelete
				: 'Are you sure you want to delete ' + count + ' backups? This cannot be undone.';

			if (!confirm(confirmMessage)) {
				return;
			}

			const backupIds = $checked.map(function() {
				return $(this).val();
			}).get();

			// Show progress
			const $button = $('#swish-backup-bulk-delete');
			const originalText = $button.html();
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Deleting...');

			let deleted = 0;
			let failed = 0;

			function deleteNext(index) {
				if (index >= backupIds.length) {
					// All done
					$button.prop('disabled', false).html(originalText);
					SwishBackup.updateBulkSelection();

					if (failed > 0) {
						alert('Deleted ' + deleted + ' backup(s). ' + failed + ' failed.');
					}
					return;
				}

				const backupId = backupIds[index];
				const $row = $('tr[data-backup-id="' + backupId + '"]');

				wp.apiFetch({
					path: `/swish-backup/v1/backup/${backupId}`,
					method: 'DELETE'
				}).then(function() {
					deleted++;
					$row.fadeOut(function() {
						$(this).remove();
					});
					deleteNext(index + 1);
				}).catch(function() {
					failed++;
					deleteNext(index + 1);
				});
			}

			deleteNext(0);
		},

		/**
		 * Test storage connection.
		 */
		testStorageConnection: function() {
			const adapter = $(this).data('adapter');
			const statusEl = $('#swish-backup-connection-status');

			statusEl.text('Testing...');

			wp.apiFetch({
				path: '/swish-backup/v1/storage/test',
				method: 'POST',
				data: { adapter: adapter }
			}).then(function(response) {
				statusEl.text(response.message);
				statusEl.css('color', response.success ? 'green' : 'red');
			});
		},

		/**
		 * Show schedule form.
		 */
		showScheduleForm: function() {
			$('#swish-backup-schedule-form').slideDown();
		},

		/**
		 * Hide schedule form.
		 */
		hideScheduleForm: function() {
			$('#swish-backup-schedule-form').slideUp();
		},

		/**
		 * Run schedule now.
		 */
		runSchedule: function() {
			const scheduleId = $(this).data('schedule-id');
			const button = $(this);
			button.prop('disabled', true).text('Running...');

			wp.apiFetch({
				path: '/swish-backup/v1/schedule/' + scheduleId + '/run',
				method: 'POST'
			}).then(function(response) {
				if (response.success) {
					// Redirect to backups page to see progress
					window.location.href = swishBackup.adminUrl + '?page=swish-backup-backups';
				} else {
					alert(response.message || 'Failed to start backup');
					button.prop('disabled', false).text('Run Now');
				}
			}).catch(function(error) {
				button.prop('disabled', false).text('Run Now');
				alert('Failed to run schedule: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Toggle schedule.
		 */
		toggleSchedule: function() {
			const scheduleId = $(this).data('schedule-id');
			const button = $(this);
			button.prop('disabled', true);

			wp.apiFetch({
				path: '/swish-backup/v1/schedule/' + scheduleId + '/toggle',
				method: 'POST'
			}).then(function(response) {
				location.reload();
			}).catch(function(error) {
				button.prop('disabled', false);
				alert('Failed to toggle schedule: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Delete schedule.
		 */
		deleteSchedule: function() {
			if (!confirm('Are you sure you want to delete this schedule?')) {
				return;
			}

			const scheduleId = $(this).data('schedule-id');
			const row = $(this).closest('tr');

			wp.apiFetch({
				path: '/swish-backup/v1/schedule/' + scheduleId,
				method: 'DELETE'
			}).then(function(response) {
				row.fadeOut(function() {
					$(this).remove();
				});
			}).catch(function(error) {
				alert('Failed to delete schedule: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Select migration method.
		 */
		selectMigrationMethod: function() {
			const method = $(this).data('method');
			$('.swish-backup-migration-step').hide();
			$('#migration-step-' + method).show();
		},

		/**
		 * Navigate migration step.
		 */
		navigateMigrationStep: function() {
			const target = $(this).data('goto');
			$('.swish-backup-migration-step').hide();

			if (target === '1' || target === 1) {
				$('#migration-step-1').show();
			} else {
				$('#migration-step-' + target).show();
			}
		},

		/**
		 * Format file size.
		 */
		formatFileSize: function(bytes) {
			if (bytes === 0) return '0 Bytes';
			const k = 1024;
			const sizes = ['Bytes', 'KB', 'MB', 'GB'];
			const i = Math.floor(Math.log(bytes) / Math.log(k));
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
		},

		/**
		 * Handle file select.
		 */
		handleFileSelect: function() {
			const file = $('#backup_file')[0].files[0];
			if (file) {
				// Check file size against server limits.
				const maxUploadSize = swishBackup.maxUploadSize || 0;
				const postMaxSize = swishBackup.postMaxSize || 0;
				const effectiveLimit = Math.min(maxUploadSize, postMaxSize) || maxUploadSize || postMaxSize;

				if (effectiveLimit > 0 && file.size > effectiveLimit) {
					const fileSize = SwishBackup.formatFileSize(file.size);
					const limitSize = SwishBackup.formatFileSize(effectiveLimit);

					$('#swish-backup-file-info').html(
						'<div class="swish-backup-error-notice">' +
						'<span class="dashicons dashicons-warning"></span>' +
						'<p><strong>File too large:</strong> The selected file (' + fileSize + ') exceeds your server\'s upload limit (' + limitSize + ').</p>' +
						'<p>To upload larger files, please contact your hosting provider to increase:</p>' +
						'<ul>' +
						'<li><code>upload_max_filesize</code> (currently: ' + (swishBackup.maxUploadSizeFormatted || 'unknown') + ')</li>' +
						'<li><code>post_max_size</code> (currently: ' + (swishBackup.postMaxSizeFormatted || 'unknown') + ')</li>' +
						'</ul>' +
						'</div>'
					).show();
					$('#swish-backup-continue-import').prop('disabled', true);
					$('#backup_file').val(''); // Clear the file input.
					return;
				}

				$('#selected-file-name').text(file.name + ' (' + SwishBackup.formatFileSize(file.size) + ')');
				$('#swish-backup-file-info').show();
				$('#swish-backup-continue-import').prop('disabled', false);
				// Hide any previous analysis.
				$('#swish-backup-import-analysis').hide();
			}
		},

		/**
		 * Upload and analyze backup file.
		 */
		uploadAndAnalyzeBackup: function() {
			const file = $('#backup_file')[0].files[0];
			if (!file) {
				alert('Please select a backup file first.');
				return;
			}

			const $button = $('#swish-backup-continue-import');
			const originalText = $button.text();

			// Disable button and show uploading state.
			$button.prop('disabled', true).text('Uploading...');
			$('#swish-backup-import-analysis').hide();

			// Add progress bar below button.
			let $progressContainer = $('#swish-upload-progress');
			if ($progressContainer.length === 0) {
				$button.after(
					'<div id="swish-upload-progress" class="swish-upload-progress">' +
					'<div class="swish-upload-progress-bar"><div class="swish-upload-progress-bar-inner"></div></div>' +
					'<div class="swish-upload-progress-text">0%</div>' +
					'</div>'
				);
				$progressContainer = $('#swish-upload-progress');
			}
			$progressContainer.show();
			$progressContainer.find('.swish-upload-progress-bar-inner').css('width', '0%');
			$progressContainer.find('.swish-upload-progress-text').text('0%');

			// Create FormData for file upload.
			const formData = new FormData();
			formData.append('backup_file', file);

			// Upload via REST API with progress tracking.
			$.ajax({
				url: swishBackup.apiUrl + '/import',
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				xhr: function() {
					const xhr = new window.XMLHttpRequest();
					xhr.upload.addEventListener('progress', function(e) {
						if (e.lengthComputable) {
							const percent = Math.round((e.loaded / e.total) * 100);
							$progressContainer.find('.swish-upload-progress-bar-inner').css('width', percent + '%');
							$progressContainer.find('.swish-upload-progress-text').text(percent + '%');

							// Update button text with percentage.
							if (percent < 100) {
								$button.text('Uploading... ' + percent + '%');
							} else {
								$button.text('Analyzing...');
							}
						}
					}, false);
					return xhr;
				},
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', swishBackup.nonce);
				},
				success: function(response) {
					// Hide progress bar.
					$('#swish-upload-progress').hide();

					if (response.success) {
						// Store backup path for migration.
						SwishBackup.importedBackupPath = response.backup_path;

						// Show analysis.
						let analysisHtml = '<div class="swish-backup-analysis-results">';

						if (response.analysis && response.analysis.backup) {
							const backup = response.analysis.backup;
							analysisHtml += '<p><strong>Backup Type:</strong> ' + (backup.type || 'Full') + '</p>';
							analysisHtml += '<p><strong>Created:</strong> ' + (backup.created_at || 'Unknown') + '</p>';
							if (backup.wordpress_version) {
								analysisHtml += '<p><strong>WordPress Version:</strong> ' + backup.wordpress_version + '</p>';
							}
						}

						if (response.analysis && response.analysis.backup_url) {
							analysisHtml += '<p><strong>Original Site URL:</strong> ' + response.analysis.backup_url + '</p>';
							// Pre-fill the old URL field.
							$('#old_url').val(response.analysis.backup_url);
							// Add visual indicator that URL was auto-detected.
							$('#old_url').closest('td').find('.swish-auto-detected').remove();
							$('#old_url').after('<span class="swish-auto-detected"><span class="dashicons dashicons-yes-alt"></span> Auto-detected from backup</span>');
						}

						// Show warnings.
						if (response.analysis && response.analysis.warnings && response.analysis.warnings.length) {
							analysisHtml += '<div class="swish-backup-warning"><span class="dashicons dashicons-warning"></span>';
							analysisHtml += '<ul>';
							response.analysis.warnings.forEach(function(warning) {
								analysisHtml += '<li>' + warning + '</li>';
							});
							analysisHtml += '</ul></div>';
						}

						// Show recommendations.
						if (response.analysis && response.analysis.recommendations && response.analysis.recommendations.length) {
							analysisHtml += '<div class="swish-docs-tip"><span class="dashicons dashicons-lightbulb"></span>';
							analysisHtml += '<ul>';
							response.analysis.recommendations.forEach(function(rec) {
								analysisHtml += '<li>' + rec + '</li>';
							});
							analysisHtml += '</ul></div>';
						}

						analysisHtml += '</div>';

						$('#swish-backup-analysis-content').html(analysisHtml);
						$('#swish-backup-import-analysis').show();

						// Navigate to URL step.
						$('.swish-backup-migration-step').hide();
						$('#migration-step-url').show();
					} else {
						alert('Upload failed: ' + (response.message || 'Unknown error'));
						$button.prop('disabled', false).text(originalText);
					}
				},
				error: function(xhr, textStatus, errorThrown) {
					// Hide progress bar.
					$('#swish-upload-progress').hide();

					let errorMessage = 'Upload failed';
					let errorDetail = '';

					// Check for specific error responses.
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMessage = xhr.responseJSON.message;
					} else if (xhr.status === 413) {
						// Request Entity Too Large.
						errorMessage = 'File too large';
						errorDetail = 'The file exceeds your server\'s upload limit. Please contact your hosting provider to increase upload_max_filesize and post_max_size.';
					} else if (xhr.status === 0 || textStatus === 'error') {
						// Connection error - often caused by file size limits.
						errorMessage = 'Upload failed - connection error';
						errorDetail = 'This often happens when the file exceeds your server\'s upload limit (' + (swishBackup.maxUploadSizeFormatted || 'unknown') + '). Please contact your hosting provider to increase the limit, or try a smaller backup file.';
					} else if (xhr.status === 500) {
						errorMessage = 'Server error during upload';
						errorDetail = 'The server encountered an error. This may be due to memory limits or timeout settings.';
					}

					// Show error in the file info area instead of alert.
					$('#swish-backup-file-info').html(
						'<div class="swish-backup-error-notice">' +
						'<span class="dashicons dashicons-warning"></span>' +
						'<p><strong>' + errorMessage + '</strong></p>' +
						(errorDetail ? '<p>' + errorDetail + '</p>' : '') +
						'</div>'
					).show();

					$button.prop('disabled', false).text(originalText);
				}
			});
		},

		/**
		 * Browse server files for import.
		 */
		browseServerFiles: function() {
			const $button = $('#swish-backup-browse-server');
			const $table = $('#swish-backup-server-files-table');
			const $tbody = $('#swish-backup-server-files-tbody');
			const originalHtml = $button.html();

			$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="vertical-align: middle;"></span> Loading...');

			wp.apiFetch({
				path: '/swish-backup/v1/import/list',
				method: 'GET'
			}).then(function(response) {
				$tbody.empty();

				if (response.files && response.files.length > 0) {
					response.files.forEach(function(file) {
						const date = new Date(file.modified * 1000);
						const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
						const typeLabel = file.type.charAt(0).toUpperCase() + file.type.slice(1);

						$tbody.append(
							'<tr>' +
							'<td><strong>' + file.filename + '</strong></td>' +
							'<td><span class="swish-backup-type-badge swish-backup-type-' + file.type + '">' + typeLabel + '</span></td>' +
							'<td>' + SwishBackup.formatFileSize(file.size) + '</td>' +
							'<td>' + dateStr + '</td>' +
							'<td><button type="button" class="button button-small swish-backup-select-server-file" data-path="' + file.path + '">Select</button></td>' +
							'</tr>'
						);
					});
					$table.show();
				} else {
					$tbody.append(
						'<tr><td colspan="5" style="text-align: center; padding: 20px;">' +
						'<em>No backup files found. Upload files via FTP/SFTP to wp-content/swish-backups/</em>' +
						'</td></tr>'
					);
					$table.show();
				}

				$button.prop('disabled', false).html(originalHtml);
			}).catch(function(error) {
				alert('Failed to load files: ' + (error.message || 'Unknown error'));
				$button.prop('disabled', false).html(originalHtml);
			});
		},

		/**
		 * Select a server file for import.
		 */
		selectServerFile: function() {
			const serverPath = $(this).data('path');
			const $button = $(this);
			const originalText = $button.text();

			// Disable all select buttons.
			$('.swish-backup-select-server-file').prop('disabled', true);
			$button.text('Analyzing...');

			wp.apiFetch({
				path: '/swish-backup/v1/import',
				method: 'POST',
				data: {
					server_path: serverPath
				}
			}).then(function(response) {
				if (response.success) {
					// Store backup path for migration.
					SwishBackup.importedBackupPath = response.backup_path;

					// Show analysis.
					let analysisHtml = '<div class="swish-backup-analysis-results">';
					analysisHtml += '<p><strong>File:</strong> ' + response.filename + ' (' + SwishBackup.formatFileSize(response.size) + ')</p>';

					if (response.analysis && response.analysis.backup) {
						const backup = response.analysis.backup;
						analysisHtml += '<p><strong>Backup Type:</strong> ' + (backup.type || 'Full') + '</p>';
						analysisHtml += '<p><strong>Created:</strong> ' + (backup.created_at || 'Unknown') + '</p>';
						if (backup.wordpress_version) {
							analysisHtml += '<p><strong>WordPress Version:</strong> ' + backup.wordpress_version + '</p>';
						}
					}

					if (response.analysis && response.analysis.backup_url) {
						analysisHtml += '<p><strong>Original Site URL:</strong> ' + response.analysis.backup_url + '</p>';
						$('#old_url').val(response.analysis.backup_url);
						$('#old_url').closest('td').find('.swish-auto-detected').remove();
						$('#old_url').after('<span class="swish-auto-detected"><span class="dashicons dashicons-yes-alt"></span> Auto-detected from backup</span>');
					}

					if (response.analysis && response.analysis.warnings && response.analysis.warnings.length) {
						analysisHtml += '<div class="swish-backup-warning"><span class="dashicons dashicons-warning"></span><ul>';
						response.analysis.warnings.forEach(function(warning) {
							analysisHtml += '<li>' + warning + '</li>';
						});
						analysisHtml += '</ul></div>';
					}

					analysisHtml += '</div>';

					$('#swish-backup-analysis-content').html(analysisHtml);
					$('#swish-backup-import-analysis').show();

					// Navigate to URL step.
					$('.swish-backup-migration-step').hide();
					$('#migration-step-url').show();
				} else {
					alert('Import failed: ' + (response.message || 'Unknown error'));
					$('.swish-backup-select-server-file').prop('disabled', false);
					$button.text(originalText);
				}
			}).catch(function(error) {
				alert('Import failed: ' + (error.message || 'Unknown error'));
				$('.swish-backup-select-server-file').prop('disabled', false);
				$button.text(originalText);
			});
		},

		/**
		 * Preview URL replacement.
		 */
		previewUrlReplacement: function() {
			const oldUrl = $('#old_url').val();
			const newUrl = $('#new_url').val();

			if (!oldUrl || !newUrl) {
				alert('Please enter both URLs.');
				return;
			}

			// If we're importing a backup, show an informative message instead of
			// searching the current database (which would return 0 matches since
			// the backup hasn't been restored yet).
			if (SwishBackup.importedBackupPath) {
				let html = '<div class="notice notice-info inline" style="margin: 0; padding: 10px;">';
				html += '<p><strong>URL Replacement Preview</strong></p>';
				html += '<p>During migration, all occurrences of:</p>';
				html += '<p><code>' + oldUrl + '</code></p>';
				html += '<p>will be replaced with:</p>';
				html += '<p><code>' + newUrl + '</code></p>';
				html += '<p>This replacement will be performed on the imported database after it is restored, including serialized data in options, post meta, and other tables.</p>';
				html += '</div>';
				$('#swish-backup-preview-content').html(html);
				$('#swish-backup-url-preview').show();
				return;
			}

			// For standalone search-replace (no import), preview against current database.
			wp.apiFetch({
				path: '/swish-backup/v1/search-replace',
				method: 'POST',
				data: {
					search: oldUrl,
					replace: newUrl,
					dry_run: true
				}
			}).then(function(response) {
				let html = '<p>Found ' + response.total_matches + ' matches.</p>';
				if (response.preview && response.preview.length) {
					html += '<table class="widefat"><thead><tr><th>Table</th><th>Before</th><th>After</th></tr></thead><tbody>';
					response.preview.forEach(function(match) {
						html += '<tr><td>' + match.table + '</td><td>' + match.before + '</td><td>' + match.after + '</td></tr>';
					});
					html += '</tbody></table>';
				}
				$('#swish-backup-preview-content').html(html);
				$('#swish-backup-url-preview').show();
			});
		},

		/**
		 * Migration stages configuration.
		 * These are used as fallbacks when phase_label is not provided by backend.
		 */
		migrationStages: {
			init: { title: 'Initializing', detail: 'Preparing migration environment' },
			validate: { title: 'Validating Backup', detail: 'Checking backup file integrity' },
			extract: { title: 'Extracting Archive', detail: 'Unpacking backup archive' },
			enumerate: { title: 'Analyzing Contents', detail: 'Counting files and database size' },
			confirm: { title: 'Preparing Migration', detail: 'All pre-checks passed' },
			preserve: { title: 'Preserving Settings', detail: 'Saving critical WordPress options' },
			database: { title: 'Restoring Database', detail: 'Importing database tables' },
			content: { title: 'Restoring Files', detail: 'Copying files to wp-content' },
			url_replace: { title: 'Updating URLs', detail: 'Replacing URLs in database' },
			finalize: { title: 'Finalizing', detail: 'Flushing caches and rewrite rules' },
			cleanup: { title: 'Cleaning Up', detail: 'Removing temporary files' }
		},

		/**
		 * Add migration log entry.
		 * Uses stage config as fallback when label is not provided.
		 */
		addMigrationLog: function(stage, status, detail) {
			const stageConfig = this.migrationStages[stage] || { title: this.formatStageName(stage), detail: '' };
			this.addMigrationLogWithLabel(stage, stageConfig.title, status, detail || stageConfig.detail);
		},

		/**
		 * Format a stage name to human-readable form.
		 */
		formatStageName: function(stage) {
			if (!stage) return 'Unknown';
			// Convert snake_case to Title Case
			return stage.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
		},

		/**
		 * Get log icon HTML.
		 */
		getLogIcon: function(status) {
			switch (status) {
				case 'completed':
					return '✓';
				case 'failed':
					return '✕';
				case 'in-progress':
					return '●';
				default:
					return '○';
			}
		},

		/**
		 * Active import session data.
		 */
		importSession: null,

		/**
		 * Start migration using chunked pipeline.
		 *
		 * This uses the new import pipeline which:
		 * 1. Uses secret key authentication (survives database restore)
		 * 2. Chunks operations across multiple HTTP requests
		 * 3. Preserves critical options during database restore
		 */
		startMigration: function() {
			const oldUrl = $('#old_url').val();
			const newUrl = $('#new_url').val();

			if (!oldUrl) {
				alert('Please enter the old site URL.');
				return;
			}

			if (!newUrl) {
				alert('Please enter the new site URL.');
				return;
			}

			// Check if we have a backup to import.
			const hasBackup = !!SwishBackup.importedBackupPath;
			if (!hasBackup) {
				// No backup - just do URL replacement (use old method).
				SwishBackup.startUrlReplacementOnly(oldUrl, newUrl);
				return;
			}

			$('.swish-backup-migration-step').hide();
			$('#migration-step-progress').show();
			$('#migration-progress-title').text('Migration in Progress');
			$('.swish-backup-progress-bar-inner').css('width', '0%');
			$('.swish-backup-progress-status').text('Initializing import pipeline...');

			// Clear and initialize log.
			$('#migration-log').empty();
			$('#migration-log-container').show();

			// Add initial stage.
			SwishBackup.addMigrationLog('init', 'in-progress');

			// Start the chunked import pipeline.
			SwishBackup.doStartImport(oldUrl, newUrl, false);
		},

		/**
		 * Actually start the import (can be called with force=true to clear stale sessions).
		 */
		doStartImport: function(oldUrl, newUrl, force) {
			wp.apiFetch({
				path: '/swish-backup/v1/import/start',
				method: 'POST',
				data: {
					backup_path: SwishBackup.importedBackupPath,
					old_url: oldUrl,
					new_url: newUrl,
					restore_database: true,
					restore_files: true,
					force: force
				}
			}).then(function(response) {
				if (response.success && response.secret_key) {
					// Store session for continuation.
					SwishBackup.importSession = {
						secretKey: response.secret_key,
						phase: response.phase,
						progress: response.progress || 0,
						currentPhase: null  // Will be set by updateImportProgress
					};

					// Clear the init log entry and update with real progress
					$('#migration-log').empty();
					SwishBackup.updateImportProgress(response);

					if (!response.completed) {
						// Continue the pipeline.
						SwishBackup.continueImportPipeline();
					} else {
						SwishBackup.completeImport();
					}
				} else {
					SwishBackup.addMigrationLog('init', 'failed', response.error || 'Failed to start import');
					SwishBackup.showError('Import failed: ' + (response.error || 'Unknown error'));
				}
			}).catch(function(error) {
				// Check for 409 Conflict (import already in progress).
				if (error.code === 'import_in_progress' && !force) {
					// Ask user if they want to force restart.
					if (confirm('A previous import was interrupted. Do you want to clear it and start fresh?')) {
						SwishBackup.addMigrationLog('init', 'in-progress', 'Clearing stale session...');
						SwishBackup.doStartImport(oldUrl, newUrl, true);
						return;
					}
				}
				SwishBackup.addMigrationLog('init', 'failed', error.message || 'An error occurred');
				SwishBackup.showError('Import failed: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Continue the import pipeline.
		 *
		 * This method calls the continue endpoint and recursively calls itself
		 * until the import is complete. Uses secret key authentication which
		 * survives database restore.
		 *
		 * IMPORTANT: We use raw fetch() instead of wp.apiFetch() because:
		 * 1. wp.apiFetch adds X-WP-Nonce header which becomes invalid after database restore
		 * 2. wp.apiFetch tries to refresh nonces, which fails when user is logged out
		 * 3. Our endpoint uses secret_key auth that survives database restore
		 */
		continueImportPipeline: function() {
			if (!SwishBackup.importSession || !SwishBackup.importSession.secretKey) {
				SwishBackup.showError('Import session lost. Please try again.');
				return;
			}

			// Reset retry count on each new attempt.
			const currentRetry = SwishBackup.importSession.retryCount || 0;

			// Use raw fetch() to bypass wp.apiFetch's nonce middleware.
			// This is critical - after database restore, WordPress nonces are invalid
			// but our secret_key (stored in file) remains valid.
			fetch(swishBackup.apiUrl + '/import/continue', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					secret_key: SwishBackup.importSession.secretKey
				}),
				credentials: 'same-origin'
			}).then(function(response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			}).then(function(response) {
				// Reset retry count on successful response.
				SwishBackup.importSession.retryCount = 0;
				SwishBackup.updateImportProgress(response);

				if (response.completed) {
					if (response.success) {
						SwishBackup.completeImport();
					} else {
						SwishBackup.addMigrationLog(response.phase || 'unknown', 'failed', response.error);
						SwishBackup.showError('Import failed: ' + (response.error || 'Unknown error'));
					}
				} else {
					// Continue polling with a small delay.
					setTimeout(function() {
						SwishBackup.continueImportPipeline();
					}, 100);
				}
			}).catch(function(error) {
				// Handle connection errors (may happen during database restore).
				// Keep retrying - don't give up on the import.
				SwishBackup.importSession.retryCount = currentRetry + 1;

				// Calculate delay with exponential backoff (max 10 seconds).
				const delay = Math.min(1000 * Math.pow(1.5, currentRetry), 10000);

				// Update status based on retry count.
				if (currentRetry < 5) {
					$('.swish-backup-progress-status').text('Reconnecting... (attempt ' + (currentRetry + 1) + ')');
				} else if (currentRetry < 30) {
					$('.swish-backup-progress-status').text('Server busy, waiting... (attempt ' + (currentRetry + 1) + ')');
				} else {
					// After many retries, check if import actually completed.
					$('.swish-backup-progress-status').text('Connection issues, checking status...');
				}

				// Keep retrying indefinitely - the import may still be running server-side.
				// The only way to truly fail is if the secret key becomes invalid.
				setTimeout(function() {
					SwishBackup.continueImportPipeline();
				}, delay);
			});
		},

		/**
		 * Update import progress UI.
		 */
		updateImportProgress: function(response) {
			const phase = response.phase || 'unknown';
			const phaseLabel = response.phase_label || this.migrationStages[phase]?.title || phase;
			const progress = response.progress || 0;
			const message = response.message || 'Processing...';
			const detail = response.detail || '';

			// Update progress bar with message.
			SwishBackup.updateProgress(progress, message);

			// Build display text: message + detail if available
			let displayText = message;
			if (detail && detail !== message) {
				displayText = detail;
			}

			// Track each phase individually for accurate progress display.
			if (SwishBackup.importSession.currentPhase !== phase) {
				// Mark previous phase as completed.
				if (SwishBackup.importSession.currentPhase) {
					SwishBackup.addMigrationLog(SwishBackup.importSession.currentPhase, 'completed');
				}
				// Start new phase with actual phase_label from backend.
				SwishBackup.addMigrationLogWithLabel(phase, phaseLabel, 'in-progress', displayText);
				SwishBackup.importSession.currentPhase = phase;
			} else {
				// Update current phase detail.
				const $entry = $('#migration-log').find('[data-stage="' + phase + '"]');
				if ($entry.length) {
					$entry.find('.swish-log-detail').text(displayText);
				}
			}

			// Store current state.
			SwishBackup.importSession.phase = phase;
			SwishBackup.importSession.progress = progress;
		},

		/**
		 * Add migration log entry with custom label.
		 */
		addMigrationLogWithLabel: function(stage, label, status, detail) {
			const $log = $('#migration-log');
			const statusClass = status === 'in-progress' ? 'in-progress' : status;

			// Check if entry already exists.
			let $entry = $log.find('[data-stage="' + stage + '"]');

			if ($entry.length === 0) {
				// Create new entry with provided label.
				const html = '<div class="swish-log-entry swish-log-' + statusClass + '" data-stage="' + stage + '">' +
					'<div class="swish-log-icon">' + this.getLogIcon(status) + '</div>' +
					'<div class="swish-log-content">' +
						'<div class="swish-log-title">' + label + '</div>' +
						'<div class="swish-log-detail">' + (detail || '') + '</div>' +
					'</div>' +
				'</div>';
				$log.append(html);
				$entry = $log.find('[data-stage="' + stage + '"]');
			} else {
				// Update existing entry.
				$entry.removeClass('swish-log-pending swish-log-in-progress swish-log-completed swish-log-failed')
					.addClass('swish-log-' + statusClass);
				$entry.find('.swish-log-icon').html(this.getLogIcon(status));
				$entry.find('.swish-log-title').text(label);
				if (detail) {
					$entry.find('.swish-log-detail').text(detail);
				}
			}

			// Scroll to bottom.
			$log.scrollTop($log[0].scrollHeight);
		},

		/**
		 * Complete the import successfully.
		 */
		completeImport: function() {
			// Mark the current phase as completed if it exists.
			if (SwishBackup.importSession && SwishBackup.importSession.currentPhase) {
				const $entry = $('#migration-log').find('[data-stage="' + SwishBackup.importSession.currentPhase + '"]');
				if ($entry.length) {
					$entry.removeClass('swish-log-in-progress').addClass('swish-log-completed');
					$entry.find('.swish-log-icon').html(SwishBackup.getLogIcon('completed'));
				}
			}

			SwishBackup.updateProgress(100, 'Migration complete!');
			$('#migration-result').show();

			// Clear session data.
			SwishBackup.importSession = null;
			SwishBackup.importedBackupPath = null;
		},

		/**
		 * Start URL replacement only (no backup import).
		 */
		startUrlReplacementOnly: function(oldUrl, newUrl) {
			$('.swish-backup-migration-step').hide();
			$('#migration-step-progress').show();
			$('#migration-progress-title').text('URL Replacement in Progress');
			$('.swish-backup-progress-bar-inner').css('width', '0%');
			$('.swish-backup-progress-status').text('Starting URL replacement...');

			// Clear and initialize log.
			$('#migration-log').empty();
			$('#migration-log-container').show();

			SwishBackup.addMigrationLog('init', 'in-progress');
			SwishBackup.addMigrationLog('urls', 'pending');

			wp.apiFetch({
				path: '/swish-backup/v1/migrate',
				method: 'POST',
				data: {
					old_url: oldUrl,
					new_url: newUrl
				}
			}).then(function(response) {
				SwishBackup.addMigrationLog('init', 'completed');
				SwishBackup.addMigrationLog('urls', 'completed');
				SwishBackup.updateProgress(100, 'URL replacement complete!');
				$('#migration-result').show();
			}).catch(function(error) {
				SwishBackup.addMigrationLog('init', 'completed');
				SwishBackup.addMigrationLog('urls', 'failed', error.message || 'An error occurred');
				SwishBackup.showError('URL replacement failed: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Start export.
		 */
		startExport: function() {
			$('.swish-backup-migration-step').hide();
			$('#migration-step-progress').show();
			$('#migration-progress-title').text('Creating Export...');

			wp.apiFetch({
				path: '/swish-backup/v1/backup',
				method: 'POST',
				data: { type: 'full' }
			}).then(function(response) {
				SwishBackup.updateProgress(100, 'Export created successfully!');
				$('#migration-result').show();
			});
		},

		/**
		 * Preview search replace.
		 */
		previewSearchReplace: function() {
			const search = $('#search_string').val();
			const replace = $('#replace_string').val();

			if (!search) {
				alert('Please enter a search string.');
				return;
			}

			wp.apiFetch({
				path: '/swish-backup/v1/search-replace',
				method: 'POST',
				data: {
					search: search,
					replace: replace,
					dry_run: true
				}
			}).then(function(response) {
				let html = '<p>Found ' + response.total_matches + ' matches.</p>';
				$('#swish-backup-search-preview-content').html(html);
				$('#swish-backup-search-preview').show();
			});
		},

		/**
		 * Run search replace.
		 */
		runSearchReplace: function() {
			if (!confirm('Are you sure? This cannot be undone.')) {
				return;
			}

			const search = $('#search_string').val();
			const replace = $('#replace_string').val();

			$('.swish-backup-migration-step').hide();
			$('#migration-step-progress').show();
			$('#migration-progress-title').text('Running Search & Replace...');

			wp.apiFetch({
				path: '/swish-backup/v1/search-replace',
				method: 'POST',
				data: {
					search: search,
					replace: replace,
					dry_run: false
				}
			}).then(function(response) {
				SwishBackup.updateProgress(100, 'Replaced ' + response.replacements_made + ' occurrences.');
				$('#migration-result').show();
			});
		},

		/**
		 * Show progress modal.
		 */
		showProgressModal: function(status) {
			$('.swish-backup-progress-bar-inner').css('width', '0%');
			$('.swish-backup-progress-status').text(status || 'Processing...');
			$('#swish-backup-progress-modal').show();

			// Simulate progress
			let progress = 0;
			SwishBackup.progressInterval = setInterval(function() {
				progress += Math.random() * 15;
				if (progress > 90) {
					progress = 90;
					clearInterval(SwishBackup.progressInterval);
				}
				SwishBackup.updateProgress(progress);
			}, 500);
		},

		/**
		 * Update progress.
		 */
		updateProgress: function(percent, status) {
			$('.swish-backup-progress-bar-inner').css('width', percent + '%');
			if (status) {
				$('.swish-backup-progress-status').text(status);
			}
			if (percent >= 100) {
				clearInterval(SwishBackup.progressInterval);
			}
		},

		/**
		 * Show error.
		 */
		showError: function(message) {
			clearInterval(SwishBackup.progressInterval);
			$('.swish-backup-progress-status').text(message).css('color', 'red');
		},

		/**
		 * Open settings modal and load current settings.
		 */
		openSettingsModal: function() {
			const $modal = $('#swish-backup-settings-modal');

			// Show modal with loading state
			$modal.show();

			// Load current settings
			wp.apiFetch({
				path: '/swish-backup/v1/settings',
				method: 'GET'
			}).then(function(settings) {
				// Populate form fields
				$('#swish-pipeline-batch-size').val(settings.pipeline_batch_size || 150);
				$('#swish-pipeline-batch-size').siblings('.swish-range-value').text(settings.pipeline_batch_size || 150);

				$('#swish-db-batch-size').val(settings.db_batch_size || 500);
				$('#swish-db-batch-size').siblings('.swish-range-value').text(settings.db_batch_size || 500);

				$('#swish-backup-database').prop('checked', settings.backup_database !== false);
				$('#swish-backup-plugins').prop('checked', settings.backup_plugins !== false);
				$('#swish-backup-themes').prop('checked', settings.backup_themes !== false);
				$('#swish-backup-uploads').prop('checked', settings.backup_uploads !== false);
				$('#swish-backup-core').prop('checked', settings.backup_core_files === true);
			}).catch(function(error) {
				console.error('Failed to load settings:', error);
			});
		},

		/**
		 * Save settings from modal.
		 */
		saveSettings: function() {
			const $button = $(this);
			const originalText = $button.text();
			$button.prop('disabled', true).text('Saving...');

			const settings = {
				pipeline_batch_size: parseInt($('#swish-pipeline-batch-size').val(), 10),
				db_batch_size: parseInt($('#swish-db-batch-size').val(), 10),
				backup_database: $('#swish-backup-database').is(':checked'),
				backup_plugins: $('#swish-backup-plugins').is(':checked'),
				backup_themes: $('#swish-backup-themes').is(':checked'),
				backup_uploads: $('#swish-backup-uploads').is(':checked'),
				backup_core_files: $('#swish-backup-core').is(':checked')
			};

			wp.apiFetch({
				path: '/swish-backup/v1/settings',
				method: 'POST',
				data: settings
			}).then(function(response) {
				$button.prop('disabled', false).text(originalText);
				if (response.success) {
					$('#swish-backup-settings-modal').hide();
				} else {
					alert('Failed to save settings');
				}
			}).catch(function(error) {
				$button.prop('disabled', false).text(originalText);
				alert('Failed to save settings: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Update range input display value.
		 */
		updateRangeValue: function() {
			$(this).siblings('.swish-range-value').text($(this).val());
		},

		/**
		 * Apply preset value to range input.
		 */
		applyPresetValue: function() {
			const value = $(this).data('value');
			const $rangeControl = $(this).closest('.swish-setting-row').find('input[type="range"]');
			$rangeControl.val(value).trigger('input');
		},

		/**
		 * Apply hosting preset to all settings.
		 */
		applyHostingPreset: function() {
			const preset = $(this).data('preset');
			let pipelineBatch, dbBatch;

			switch (preset) {
				case 'shared':
					pipelineBatch = 50;
					dbBatch = 200;
					break;
				case 'vps':
					pipelineBatch = 150;
					dbBatch = 500;
					break;
				case 'dedicated':
					pipelineBatch = 300;
					dbBatch = 1000;
					break;
				default:
					return;
			}

			$('#swish-pipeline-batch-size').val(pipelineBatch).trigger('input');
			$('#swish-db-batch-size').val(dbBatch).trigger('input');
		},

		/**
		 * Hide all modals.
		 */
		hideModals: function() {
			$('.swish-backup-modal').hide();
		}
	};

	$(document).ready(function() {
		SwishBackup.init();
	});

})(jQuery);
