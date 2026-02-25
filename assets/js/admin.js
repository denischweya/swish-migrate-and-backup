/**
 * Swish Migrate and Backup - Admin JavaScript
 */

(function($) {
	'use strict';

	const SwishBackup = {
		/**
		 * Initialize.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Backup buttons
			$(document).on('click', '#swish-backup-now, #swish-backup-first', this.showBackupTypeSelector);
			$(document).on('click', '.swish-backup-type-option', this.startBackup);

			// Backup actions
			$(document).on('click', '.swish-backup-restore', this.showRestoreModal);
			$(document).on('click', '#swish-backup-restore-confirm', this.confirmRestore);
			$(document).on('click', '.swish-backup-download', this.downloadBackup);
			$(document).on('click', '.swish-backup-delete', this.deleteBackup);

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
			$(document).on('click', '#swish-backup-add-schedule', this.showScheduleForm);
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
		 * Start backup.
		 */
		startBackup: function() {
			const type = $(this).data('type');

			$('.swish-backup-type-option').removeClass('selected');
			$(this).addClass('selected');

			SwishBackup.showProgressModal(swishBackup.i18n.backupStarted);

			wp.apiFetch({
				path: '/swish-backup/v1/backup',
				method: 'POST',
				data: { type: type }
			}).then(function(response) {
				SwishBackup.updateProgress(100, swishBackup.i18n.backupComplete);
				setTimeout(function() {
					location.reload();
				}, 1500);
			}).catch(function(error) {
				SwishBackup.showError(swishBackup.i18n.backupFailed);
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
				});
			});
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
			SwishBackup.showProgressModal('Running scheduled backup...');

			wp.apiFetch({
				path: '/swish-backup/v1/backup',
				method: 'POST',
				data: { type: 'full', schedule_id: scheduleId }
			}).then(function(response) {
				SwishBackup.updateProgress(100, swishBackup.i18n.backupComplete);
				setTimeout(function() {
					location.reload();
				}, 1500);
			});
		},

		/**
		 * Toggle schedule.
		 */
		toggleSchedule: function() {
			const scheduleId = $(this).data('schedule-id');
			const button = $(this);

			// This would require a toggle endpoint
			button.prop('disabled', true);
			setTimeout(function() {
				location.reload();
			}, 500);
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

			// This would require a delete endpoint
			row.fadeOut(function() {
				$(this).remove();
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
		 * Handle file select.
		 */
		handleFileSelect: function() {
			const file = $('#backup_file')[0].files[0];
			if (file) {
				$('#selected-file-name').text(file.name);
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

			// Create FormData for file upload.
			const formData = new FormData();
			formData.append('backup_file', file);

			// Upload via REST API.
			$.ajax({
				url: swishBackup.apiUrl + '/import',
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', swishBackup.nonce);
				},
				success: function(response) {
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
				error: function(xhr) {
					let errorMessage = 'Upload failed';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMessage = xhr.responseJSON.message;
					}
					alert(errorMessage);
					$button.prop('disabled', false).text(originalText);
				}
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
		 */
		migrationStages: {
			init: { title: 'Initializing', detail: 'Preparing migration environment' },
			extract: { title: 'Extracting Backup', detail: 'Unpacking backup archive' },
			database: { title: 'Restoring Database', detail: 'Importing database tables' },
			files: { title: 'Restoring Files', detail: 'Copying files to destination' },
			urls: { title: 'Updating URLs', detail: 'Replacing old URLs with new URLs' },
			cleanup: { title: 'Finalizing', detail: 'Cleaning up temporary files' }
		},

		/**
		 * Add migration log entry.
		 */
		addMigrationLog: function(stage, status, detail) {
			const stageConfig = this.migrationStages[stage] || { title: stage, detail: '' };
			const $log = $('#migration-log');
			const statusClass = status === 'in-progress' ? 'in-progress' : status;

			// Check if entry already exists.
			let $entry = $log.find('[data-stage="' + stage + '"]');

			if ($entry.length === 0) {
				// Create new entry.
				const html = '<div class="swish-log-entry swish-log-' + statusClass + '" data-stage="' + stage + '">' +
					'<div class="swish-log-icon">' + this.getLogIcon(status) + '</div>' +
					'<div class="swish-log-content">' +
						'<div class="swish-log-title">' + stageConfig.title + '</div>' +
						'<div class="swish-log-detail">' + (detail || stageConfig.detail) + '</div>' +
					'</div>' +
				'</div>';
				$log.append(html);
				$entry = $log.find('[data-stage="' + stage + '"]');
			} else {
				// Update existing entry.
				$entry.removeClass('swish-log-pending swish-log-in-progress swish-log-completed swish-log-failed')
					.addClass('swish-log-' + statusClass);
				$entry.find('.swish-log-icon').html(this.getLogIcon(status));
				if (detail) {
					$entry.find('.swish-log-detail').text(detail);
				}
			}

			// Scroll to bottom.
			$log.scrollTop($log[0].scrollHeight);
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
		 * Start migration.
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

			$('.swish-backup-migration-step').hide();
			$('#migration-step-progress').show();
			$('#migration-progress-title').text('Migration in Progress');
			$('.swish-backup-progress-bar-inner').css('width', '0%');
			$('.swish-backup-progress-status').text('Starting migration...');

			// Clear and initialize log.
			$('#migration-log').empty();
			$('#migration-log-container').show();

			const data = {
				old_url: oldUrl,
				new_url: newUrl
			};

			// Include backup path if we imported a file.
			const hasBackup = !!SwishBackup.importedBackupPath;
			if (hasBackup) {
				data.backup_path = SwishBackup.importedBackupPath;
			}

			// Add initial stages.
			SwishBackup.addMigrationLog('init', 'in-progress');

			// Simulate stage progression.
			let currentStageIndex = 0;
			const stages = hasBackup
				? ['init', 'extract', 'database', 'files', 'urls', 'cleanup']
				: ['init', 'urls', 'cleanup'];

			const stageProgress = {
				init: 10,
				extract: 25,
				database: 50,
				files: 70,
				urls: 85,
				cleanup: 95
			};

			// Progress through stages.
			SwishBackup.progressInterval = setInterval(function() {
				if (currentStageIndex < stages.length - 1) {
					// Complete current stage.
					SwishBackup.addMigrationLog(stages[currentStageIndex], 'completed');
					currentStageIndex++;
					// Start next stage.
					SwishBackup.addMigrationLog(stages[currentStageIndex], 'in-progress');
					SwishBackup.updateProgress(stageProgress[stages[currentStageIndex]]);
					$('.swish-backup-progress-status').text(SwishBackup.migrationStages[stages[currentStageIndex]].detail);
				}
			}, 1500);

			wp.apiFetch({
				path: '/swish-backup/v1/migrate',
				method: 'POST',
				data: data
			}).then(function(response) {
				clearInterval(SwishBackup.progressInterval);

				// Mark all stages as completed.
				stages.forEach(function(stage) {
					SwishBackup.addMigrationLog(stage, 'completed');
				});

				SwishBackup.updateProgress(100, 'Migration complete!');
				$('#migration-result').show();
				// Clear the stored path.
				SwishBackup.importedBackupPath = null;
			}).catch(function(error) {
				clearInterval(SwishBackup.progressInterval);

				// Mark current stage as failed.
				if (currentStageIndex < stages.length) {
					SwishBackup.addMigrationLog(stages[currentStageIndex], 'failed', error.message || 'An error occurred');
				}

				SwishBackup.showError('Migration failed: ' + (error.message || 'Unknown error'));
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
