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
			}
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
		 * Start migration.
		 */
		startMigration: function() {
			const oldUrl = $('#old_url').val();
			const newUrl = $('#new_url').val();

			$('.swish-backup-migration-step').hide();
			$('#migration-step-progress').show();

			wp.apiFetch({
				path: '/swish-backup/v1/migrate',
				method: 'POST',
				data: {
					old_url: oldUrl,
					new_url: newUrl
				}
			}).then(function(response) {
				SwishBackup.updateProgress(100, 'Migration complete!');
				$('#migration-result').show();
			}).catch(function(error) {
				SwishBackup.showError('Migration failed: ' + error.message);
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
