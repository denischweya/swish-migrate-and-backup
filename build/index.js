/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/js/api/index.js"
/*!*****************************!*\
  !*** ./src/js/api/index.js ***!
  \*****************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createBackup: () => (/* binding */ createBackup),
/* harmony export */   deleteBackup: () => (/* binding */ deleteBackup),
/* harmony export */   getBackups: () => (/* binding */ getBackups),
/* harmony export */   getDownloadUrl: () => (/* binding */ getDownloadUrl),
/* harmony export */   getJobStatus: () => (/* binding */ getJobStatus),
/* harmony export */   getSettings: () => (/* binding */ getSettings),
/* harmony export */   getStats: () => (/* binding */ getStats),
/* harmony export */   pipelineContinue: () => (/* binding */ pipelineContinue),
/* harmony export */   pipelineStart: () => (/* binding */ pipelineStart),
/* harmony export */   pipelineStatus: () => (/* binding */ pipelineStatus),
/* harmony export */   processJob: () => (/* binding */ processJob),
/* harmony export */   restoreBackup: () => (/* binding */ restoreBackup),
/* harmony export */   searchReplace: () => (/* binding */ searchReplace),
/* harmony export */   updateSettings: () => (/* binding */ updateSettings)
/* harmony export */ });
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/api-fetch */ "@wordpress/api-fetch");
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__);
/**
 * API functions for Swish Backup.
 *
 * @package SwishMigrateAndBackup
 */



/**
 * Make an API request to the Swish Backup REST API.
 *
 * @param {string} endpoint - API endpoint path.
 * @param {Object} options  - Fetch options.
 * @return {Promise} API response.
 */
const apiRequest = async (endpoint, options = {}) => {
  const path = `/swish-backup/v1${endpoint}`;
  try {
    return await _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path,
      ...options
    });
  } catch (error) {
    if (error.message) {
      throw new Error(error.message);
    }
    throw error;
  }
};

/**
 * Get dashboard stats.
 *
 * @return {Promise} Stats data.
 */
const getStats = () => apiRequest('/stats');

/**
 * Get all backups.
 *
 * @return {Promise} Backups array.
 */
const getBackups = () => apiRequest('/backups');

/**
 * Create a new backup.
 *
 * @param {string} type    - Backup type (full, database, files).
 * @param {Object} options - Additional options.
 * @return {Promise} Backup job data.
 */
const createBackup = (type = 'full', options = {}) => apiRequest('/backup', {
  method: 'POST',
  data: {
    type,
    ...options
  }
});

/**
 * Delete a backup.
 *
 * @param {string} backupId - Backup ID.
 * @return {Promise} Deletion result.
 */
const deleteBackup = backupId => apiRequest(`/backup/${backupId}`, {
  method: 'DELETE'
});

/**
 * Get backup download URL.
 *
 * @param {string} backupId - Backup ID.
 * @return {Promise} Download URL data.
 */
const getDownloadUrl = backupId => apiRequest(`/backup/${backupId}/download`);

/**
 * Restore a backup.
 *
 * @param {string} backupId - Backup ID.
 * @param {Object} options  - Restore options.
 * @return {Promise} Restore result.
 */
const restoreBackup = (backupId, options = {}) => apiRequest('/restore', {
  method: 'POST',
  data: {
    backup_id: backupId,
    restore_database: true,
    restore_files: true,
    ...options
  }
});

/**
 * Run search and replace.
 *
 * @param {string}  search  - Search string.
 * @param {string}  replace - Replace string.
 * @param {boolean} dryRun  - Whether to do a dry run.
 * @return {Promise} Search/replace result.
 */
const searchReplace = (search, replace, dryRun = false) => apiRequest('/search-replace', {
  method: 'POST',
  data: {
    search,
    replace,
    dry_run: dryRun
  }
});

/**
 * Get job status.
 *
 * @param {string} jobId - Job ID.
 * @return {Promise} Job status data.
 */
const getJobStatus = jobId => apiRequest(`/job/${jobId}`);

/**
 * Process a pending job directly (fallback for hosts where WP Cron doesn't trigger).
 *
 * @param {string} jobId - Job ID.
 * @return {Promise} Job status data.
 */
const processJob = jobId => apiRequest(`/job/${jobId}/process`, {
  method: 'POST'
});

/**
 * Get settings.
 *
 * @return {Promise} Settings data.
 */
const getSettings = () => apiRequest('/settings');

/**
 * Update settings.
 *
 * @param {Object} settings - Settings to update.
 * @return {Promise} Updated settings.
 */
const updateSettings = settings => apiRequest('/settings', {
  method: 'POST',
  data: settings
});

// ============================================================================
// Pipeline-based backup API (queue-based, chunked processing)
// ============================================================================

/**
 * Start a pipeline-based backup.
 *
 * This uses the new queue-based architecture for reliable chunked processing.
 * Call pipelineContinue() repeatedly until completed.
 *
 * @param {string} type - Backup type (full, database, files).
 * @return {Promise} Pipeline job data with job_id, phase, stats.
 */
const pipelineStart = (type = 'full') => apiRequest('/pipeline/start', {
  method: 'POST',
  data: {
    type
  }
});

/**
 * Continue a pipeline-based backup.
 *
 * Call this repeatedly until the response shows completed: true.
 * Each call processes a small chunk with a hard time budget (~15 seconds).
 *
 * @param {string} jobId - Pipeline job ID from pipelineStart.
 * @return {Promise} Pipeline status with phase, progress, stats.
 */
const pipelineContinue = jobId => apiRequest(`/pipeline/continue/${jobId}`, {
  method: 'POST'
});

/**
 * Get pipeline backup status.
 *
 * @param {string} jobId - Pipeline job ID.
 * @return {Promise} Pipeline status with phase, progress, stats.
 */
const pipelineStatus = jobId => apiRequest(`/pipeline/status/${jobId}`);

/***/ },

/***/ "./src/js/components/App.js"
/*!**********************************!*\
  !*** ./src/js/components/App.js ***!
  \**********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _Dashboard__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./Dashboard */ "./src/js/components/Dashboard.js");
/* harmony import */ var _ProgressModal__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./ProgressModal */ "./src/js/components/ProgressModal.js");
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ../api */ "./src/js/api/index.js");

/**
 * Main App component.
 *
 * @package SwishMigrateAndBackup
 */







/**
 * Main App component.
 *
 * @return {JSX.Element} Component.
 */
const App = () => {
  const [stats, setStats] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [backups, setBackups] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)([]);
  const [settings, setSettings] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [currentJob, setCurrentJob] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [showProgress, setShowProgress] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const [isStartingBackup, setIsStartingBackup] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const [startingBackupType, setStartingBackupType] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useEffect)(() => {
    loadDashboardData();
  }, []);
  const loadDashboardData = async () => {
    try {
      setIsLoading(true);
      setError(null);
      const [statsData, backupsData, settingsData] = await Promise.all([(0,_api__WEBPACK_IMPORTED_MODULE_5__.getStats)(), (0,_api__WEBPACK_IMPORTED_MODULE_5__.getBackups)(), (0,_api__WEBPACK_IMPORTED_MODULE_5__.getSettings)()]);
      setStats(statsData);
      setBackups(backupsData);
      setSettings(settingsData);
    } catch (err) {
      setError(err.message || 'Failed to load dashboard data');
    } finally {
      setIsLoading(false);
    }
  };

  /**
   * Store active job in localStorage for persistence across page refreshes.
   */
  const storeActiveJob = (jobId, type) => {
    const jobs = JSON.parse(localStorage.getItem('swish_active_jobs') || '{}');
    jobs[jobId] = {
      id: jobId,
      type: type,
      startedAt: Date.now(),
      progress: 0,
      status: 'pending',
      step: 'Initializing...'
    };
    localStorage.setItem('swish_active_jobs', JSON.stringify(jobs));
  };
  const handleBackup = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async type => {
    try {
      // Show immediate feedback
      setIsStartingBackup(true);
      setStartingBackupType(type);

      // Start the backup job
      const result = await (0,_api__WEBPACK_IMPORTED_MODULE_5__.createBackup)(type, {
        db_batch_size: settings?.db_batch_size || 500,
        file_batch_size: settings?.file_batch_size || 100
      });
      if (result.job_id) {
        // Store job in localStorage for the backups page to pick up
        storeActiveJob(result.job_id, type);

        // Redirect to backups page to show progress
        const backupsPageUrl = window.swishBackupData?.backupsPageUrl || 'admin.php?page=swish-backup-backups';
        window.location.href = backupsPageUrl;
      } else if (result.success || result.filename) {
        // Synchronous backup completed immediately (rare)
        setIsStartingBackup(false);
        loadDashboardData();
      } else {
        setIsStartingBackup(false);
        alert('Backup failed to start');
      }
    } catch (err) {
      setIsStartingBackup(false);
      alert(err.message || 'Backup failed');
    }
  }, [settings]);

  // Legacy pipeline backup - now redirects to backups page instead
  const handlePipelineBackup = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async type => {
    // Redirect to backups page - the new flow handles everything there
    handleBackup(type);
  }, [handleBackup]);
  const runPipeline = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async jobId => {
    let completed = false;
    let consecutiveErrors = 0;
    const maxErrors = 3;
    while (!completed && consecutiveErrors < maxErrors) {
      try {
        const result = await (0,_api__WEBPACK_IMPORTED_MODULE_5__.pipelineContinue)(jobId);
        consecutiveErrors = 0; // Reset on success.

        // Use the overall progress from the backend.
        const progress = result.progress || 0;

        // Calculate completed files count.
        const completedFiles = (result.stats?.completed || 0) + (result.stats?.skipped || 0);
        const totalFiles = result.stats?.total || 0;
        const phase = result.phase || 'processing';

        // Build stages for progress display.
        const stages = [];
        if (phase === 'indexing') {
          stages.push({
            name: 'Indexing files',
            status: 'in_progress',
            detail: `${totalFiles} files found`
          });
        } else if (phase === 'processing') {
          stages.push({
            name: 'Indexing files',
            status: 'completed',
            detail: `${totalFiles} files`
          });
          stages.push({
            name: 'Creating archive',
            status: 'in_progress',
            detail: `${completedFiles}/${totalFiles} files`
          });
        } else if (phase === 'finalizing') {
          stages.push({
            name: 'Indexing files',
            status: 'completed',
            detail: `${totalFiles} files`
          });
          stages.push({
            name: 'Creating archive',
            status: 'completed',
            detail: `${completedFiles} files`
          });
          stages.push({
            name: 'Finalizing',
            status: 'in_progress',
            detail: 'Creating backup file'
          });
        } else if (phase === 'complete' || result.completed) {
          stages.push({
            name: 'Indexing files',
            status: 'completed'
          });
          stages.push({
            name: 'Creating archive',
            status: 'completed'
          });
          stages.push({
            name: 'Finalizing',
            status: 'completed'
          });
        }
        setCurrentJob({
          status: result.completed ? 'completed' : 'processing',
          progress: result.completed ? 100 : progress,
          message: result.message,
          stages
        });
        if (result.completed) {
          completed = true;
          setTimeout(() => {
            setShowProgress(false);
            loadDashboardData();
          }, 2000);
        } else {
          // Small delay between requests to avoid overwhelming the server.
          await new Promise(resolve => setTimeout(resolve, 500));
        }
      } catch (err) {
        consecutiveErrors++;
        console.warn(`Pipeline error (${consecutiveErrors}/${maxErrors}):`, err.message);
        if (consecutiveErrors >= maxErrors) {
          setCurrentJob({
            status: 'failed',
            progress: 0,
            message: `Backup failed after ${maxErrors} retries: ${err.message}`
          });
          break;
        }

        // Wait before retry.
        await new Promise(resolve => setTimeout(resolve, 2000));
      }
    }
  }, []);
  const pollJobStatus = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async jobId => {
    let pendingCount = 0;
    let hasTriggeredProcess = false;
    const poll = async () => {
      try {
        const jobData = await (0,_api__WEBPACK_IMPORTED_MODULE_5__.getJobStatus)(jobId);
        setCurrentJob({
          status: jobData.status,
          progress: jobData.progress,
          message: jobData.message || `Progress: ${jobData.progress}%`
        });
        if (jobData.status === 'completed') {
          setTimeout(() => {
            setShowProgress(false);
            loadDashboardData();
          }, 1500);
        } else if (jobData.status === 'failed') {
          // Don't continue polling on failure.
        } else if (jobData.status === 'pending') {
          pendingCount++;
          // If still pending after 3 seconds, trigger the process endpoint directly.
          // This is a fallback for hosts where WP Cron doesn't trigger immediately (like WP Engine).
          if (pendingCount >= 3 && !hasTriggeredProcess) {
            hasTriggeredProcess = true;
            setCurrentJob({
              status: 'processing',
              progress: 5,
              message: 'Starting backup process...'
            });
            // Call the process endpoint in the background - don't await it.
            // This will run the backup while we continue polling for status.
            (0,_api__WEBPACK_IMPORTED_MODULE_5__.processJob)(jobId).catch(processErr => {
              // If process fails (e.g., timeout), continue polling - the job may have started.
              console.warn('Process request returned:', processErr.message || 'Unknown error');
            });
          }
          setTimeout(poll, 1000);
        } else {
          // Processing - continue polling.
          setTimeout(poll, 1000);
        }
      } catch (err) {
        setCurrentJob({
          status: 'failed',
          progress: 0,
          message: err.message || 'Failed to get job status'
        });
      }
    };
    poll();
  }, []);
  const handleDelete = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async backupId => {
    if (!window.confirm('Are you sure you want to delete this backup?')) {
      return;
    }
    try {
      await (0,_api__WEBPACK_IMPORTED_MODULE_5__.deleteBackup)(backupId);
      loadDashboardData();
    } catch (err) {
      alert(err.message || 'Failed to delete backup');
    }
  }, []);
  const handleDownload = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async backupId => {
    try {
      const result = await (0,_api__WEBPACK_IMPORTED_MODULE_5__.getDownloadUrl)(backupId);
      if (result.url) {
        window.location.href = result.url;
      }
    } catch (err) {
      alert(err.message || 'Failed to get download URL');
    }
  }, []);
  const handleRestore = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(async backupId => {
    if (!window.confirm('Are you sure you want to restore this backup? This will overwrite your current site data.')) {
      return;
    }
    try {
      setShowProgress(true);
      setCurrentJob({
        status: 'processing',
        progress: 0,
        message: 'Restoring backup...'
      });
      await (0,_api__WEBPACK_IMPORTED_MODULE_5__.restoreBackup)(backupId);
      setCurrentJob({
        status: 'completed',
        progress: 100,
        message: 'Restore completed successfully!'
      });
      setTimeout(() => {
        window.location.reload();
      }, 2000);
    } catch (err) {
      setCurrentJob({
        status: 'failed',
        progress: 0,
        message: err.message || 'Restore failed'
      });
    }
  }, []);
  const handleCloseProgress = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useCallback)(() => {
    if (currentJob?.status !== 'processing') {
      setShowProgress(false);
      setCurrentJob(null);
    }
  }, [currentJob]);
  if (isLoading) {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "swish-loading"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Spinner, null), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, "Loading dashboard..."));
  }
  if (error) {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "swish-error"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, "Error: ", error), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
      onClick: loadDashboardData,
      className: "button"
    }, "Retry"));
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-dashboard-app"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_Dashboard__WEBPACK_IMPORTED_MODULE_3__["default"], {
    stats: stats,
    backups: backups,
    settings: settings,
    onBackup: handleBackup,
    onDelete: handleDelete,
    onDownload: handleDownload,
    onRestore: handleRestore
  }), showProgress && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_ProgressModal__WEBPACK_IMPORTED_MODULE_4__["default"], {
    job: currentJob,
    onClose: handleCloseProgress
  }), isStartingBackup && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal-overlay"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal swish-starting-modal"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-starting-content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-update spin"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", null, __('Starting Backup...', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, __('Initializing', 'swish-migrate-and-backup'), ' ', startingBackupType === 'full' && __('full', 'swish-migrate-and-backup'), startingBackupType === 'database' && __('database', 'swish-migrate-and-backup'), startingBackupType === 'files' && __('files', 'swish-migrate-and-backup'), ' ', __('backup job...', 'swish-migrate-and-backup'))))));
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (App);

/***/ },

/***/ "./src/js/components/BackupList.js"
/*!*****************************************!*\
  !*** ./src/js/components/BackupList.js ***!
  \*****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);

/**
 * BackupList component.
 *
 * @package SwishMigrateAndBackup
 */



/**
 * Get CSS class for backup type.
 *
 * @param {string} type - Backup type.
 * @return {string} CSS class.
 */
const getTypeClass = type => {
  switch (type) {
    case 'full':
      return 'type-full';
    case 'database':
      return 'type-database';
    case 'files':
      return 'type-files';
    default:
      return '';
  }
};

/**
 * Get label for backup type.
 *
 * @param {string} type - Backup type.
 * @return {string} Type label.
 */
const getTypeLabel = type => {
  switch (type) {
    case 'full':
      return (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Full Backup', 'swish-migrate-and-backup');
    case 'database':
      return (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Database', 'swish-migrate-and-backup');
    case 'files':
      return (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Files Only', 'swish-migrate-and-backup');
    default:
      return type;
  }
};

/**
 * Format file size.
 *
 * @param {number} bytes - Size in bytes.
 * @return {string} Formatted size.
 */
const formatSize = bytes => {
  if (bytes === 0) {
    return '0 Bytes';
  }
  const k = 1024;
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

/**
 * Format date.
 *
 * @param {string} dateString - Date string.
 * @return {string} Formatted date.
 */
const formatDate = dateString => {
  if (!dateString) {
    return '-';
  }
  const date = new Date(dateString);
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
};

/**
 * BackupList component.
 *
 * @param {Object}   props            - Component props.
 * @param {Array}    props.backups    - List of backups.
 * @param {Function} props.onDelete   - Delete handler.
 * @param {Function} props.onDownload - Download handler.
 * @param {Function} props.onRestore  - Restore handler.
 * @return {JSX.Element} Component.
 */
const BackupList = ({
  backups,
  onDelete,
  onDownload,
  onRestore
}) => {
  if (!backups || backups.length === 0) {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "swish-backup-list"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h2", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Recent Backups', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "swish-empty-state"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
      className: "dashicons dashicons-backup"
    }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No backups yet. Create your first backup to get started.', 'swish-migrate-and-backup'))));
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-backup-list"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h2", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Recent Backups', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("table", {
    className: "wp-list-table widefat fixed striped"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("thead", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", {
    className: "column-filename"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Backup', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", {
    className: "column-type"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Type', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", {
    className: "column-size"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Size', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", {
    className: "column-date"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Created', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", {
    className: "column-actions"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Actions', 'swish-migrate-and-backup')))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tbody", null, backups.map(backup => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", {
    key: backup.id
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "column-filename"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, backup.filename || backup.id)), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "column-type"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: `swish-backup-type ${getTypeClass(backup.type)}`
  }, getTypeLabel(backup.type))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "column-size"
  }, formatSize(backup.size)), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "column-date"
  }, formatDate(backup.completed_at || backup.created_at)), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "column-actions"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-action-buttons"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-small",
    onClick: () => onRestore(backup.id),
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Restore', 'swish-migrate-and-backup')
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-backup"
  }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Restore', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-small",
    onClick: () => onDownload(backup.id),
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Download', 'swish-migrate-and-backup')
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-download"
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-small button-link-delete",
    onClick: () => onDelete(backup.id),
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Delete', 'swish-migrate-and-backup')
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-trash"
  })))))))));
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (BackupList);

/***/ },

/***/ "./src/js/components/Dashboard.js"
/*!****************************************!*\
  !*** ./src/js/components/Dashboard.js ***!
  \****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _BackupList__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./BackupList */ "./src/js/components/BackupList.js");
/* harmony import */ var _MigrationPanel__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./MigrationPanel */ "./src/js/components/MigrationPanel.js");

/**
 * Dashboard component.
 *
 * @package SwishMigrateAndBackup
 */






/**
 * Format file size.
 *
 * @param {number} bytes - Size in bytes.
 * @return {string} Formatted size.
 */
const formatSize = bytes => {
  if (bytes === 0) {
    return '0 Bytes';
  }
  const k = 1024;
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

/**
 * Format date.
 *
 * @param {string} dateString - Date string.
 * @return {string} Formatted date.
 */
const formatDate = dateString => {
  if (!dateString) {
    return '-';
  }
  const date = new Date(dateString);
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
};

/**
 * Dashboard component.
 *
 * @param {Object}   props                - Component props.
 * @param {Object}   props.stats          - Dashboard stats.
 * @param {Array}    props.backups        - List of backups.
 * @param {Object}   props.settings       - Plugin settings.
 * @param {Function} props.onBackup       - Backup handler.
 * @param {Function} props.onDelete       - Delete handler.
 * @param {Function} props.onDownload     - Download handler.
 * @param {Function} props.onRestore      - Restore handler.
 * @return {JSX.Element} Component.
 */
const Dashboard = ({
  stats,
  backups,
  settings,
  onBackup,
  onDelete,
  onDownload,
  onRestore
}) => {
  const [showBackupTypes, setShowBackupTypes] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const [showMigration, setShowMigration] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const settingsUrl = window.swishBackupData?.settingsPageUrl || 'admin.php?page=swish-backup-settings';
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-dashboard"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-dashboard-header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("a", {
    href: settingsUrl,
    className: "button button-link"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-admin-settings"
  }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Settings', 'swish-migrate-and-backup'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-quick-actions"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-action-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Backup Now', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Create a new backup of your site.', 'swish-migrate-and-backup')), showBackupTypes ? (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-backup-types"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-primary",
    onClick: () => {
      setShowBackupTypes(false);
      onBackup('full');
    }
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Full Backup', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button",
    onClick: () => {
      setShowBackupTypes(false);
      onBackup('database');
    }
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Database Only', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button",
    onClick: () => {
      setShowBackupTypes(false);
      onBackup('files');
    }
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Files Only', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-link",
    onClick: () => setShowBackupTypes(false)
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Cancel', 'swish-migrate-and-backup'))) : (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-primary button-hero",
    onClick: () => setShowBackupTypes(true)
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-backup"
  }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Create Backup', 'swish-migrate-and-backup'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-action-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Search & Replace', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Search and replace URLs in the database.', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-hero",
    onClick: () => setShowMigration(!showMigration)
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-search"
  }), showMigration ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Hide Panel', 'swish-migrate-and-backup') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Open Panel', 'swish-migrate-and-backup'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-action-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Migrate Site', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Import a backup from another site.', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("a", {
    className: "button button-hero",
    href: window.location.href.split('?')[0] + '?page=swish-backup-migration'
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-migrate"
  }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Start Migration', 'swish-migrate-and-backup')))), showMigration && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_MigrationPanel__WEBPACK_IMPORTED_MODULE_4__["default"], {
    siteUrl: stats?.site_url
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stats-grid"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-backup"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-value"
  }, stats?.total_backups || 0), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-label"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Total Backups', 'swish-migrate-and-backup')))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-database"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-value"
  }, formatSize(stats?.total_size || 0)), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-label"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Storage Used', 'swish-migrate-and-backup')))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-calendar-alt"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-value"
  }, stats?.last_backup ? formatDate(stats.last_backup.completed_at) : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Never', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-label"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Last Backup', 'swish-migrate-and-backup')))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-card"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-performance"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-stat-content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-value"
  }, settings?.db_batch_size || 500, " /", ' ', settings?.file_batch_size || 100), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-stat-label"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Batch Size (DB/Files)', 'swish-migrate-and-backup'))))), stats?.storage && Object.keys(stats.storage).length > 0 && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-storage-status"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h2", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Storage Destinations', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-storage-grid"
  }, Object.entries(stats.storage).map(([key, storage]) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    key: key,
    className: `swish-storage-card ${storage.configured ? 'configured' : ''}`
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-storage-icon dashicons dashicons-cloud"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-storage-name"
  }, storage.name), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: `swish-storage-status-badge ${storage.configured ? 'active' : 'inactive'}`
  }, storage.configured ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Connected', 'swish-migrate-and-backup') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Not Configured', 'swish-migrate-and-backup')))))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_BackupList__WEBPACK_IMPORTED_MODULE_3__["default"], {
    backups: backups,
    onDelete: onDelete,
    onDownload: onDownload,
    onRestore: onRestore
  }));
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (Dashboard);

/***/ },

/***/ "./src/js/components/MigrationPanel.js"
/*!*********************************************!*\
  !*** ./src/js/components/MigrationPanel.js ***!
  \*********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../api */ "./src/js/api/index.js");

/**
 * MigrationPanel component.
 *
 * @package SwishMigrateAndBackup
 */





/**
 * MigrationPanel component for search and replace functionality.
 *
 * @param {Object} props         - Component props.
 * @param {string} props.siteUrl - Current site URL.
 * @return {JSX.Element} Component.
 */
const MigrationPanel = ({
  siteUrl
}) => {
  const [searchUrl, setSearchUrl] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)('');
  const [replaceUrl, setReplaceUrl] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(siteUrl || '');
  const [preview, setPreview] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const [result, setResult] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const handlePreview = async () => {
    if (!searchUrl || !replaceUrl) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Please enter both search and replace URLs.', 'swish-migrate-and-backup'));
      return;
    }
    setIsLoading(true);
    setError(null);
    setPreview(null);
    try {
      const data = await (0,_api__WEBPACK_IMPORTED_MODULE_3__.searchReplace)(searchUrl, replaceUrl, true);
      setPreview(data);
    } catch (err) {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Preview failed', 'swish-migrate-and-backup'));
    } finally {
      setIsLoading(false);
    }
  };
  const handleMigrate = async () => {
    if (!window.confirm((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('This will permanently modify your database. Are you sure you want to continue?', 'swish-migrate-and-backup'))) {
      return;
    }
    setIsLoading(true);
    setError(null);
    setResult(null);
    try {
      const data = await (0,_api__WEBPACK_IMPORTED_MODULE_3__.searchReplace)(searchUrl, replaceUrl, false);
      setResult(data);
      setPreview(null);
    } catch (err) {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Migration failed', 'swish-migrate-and-backup'));
    } finally {
      setIsLoading(false);
    }
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-migrate-panel"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Search and Replace URLs', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", {
    className: "description"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Replace old URLs with new ones across your entire database. This is useful when migrating to a new domain.', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-migrate-form"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-form-row"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("label", {
    htmlFor: "search_url"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Search for (old URL)', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("input", {
    type: "url",
    id: "search_url",
    className: "regular-text",
    placeholder: "https://old-domain.com",
    value: searchUrl,
    onChange: e => setSearchUrl(e.target.value)
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-form-row"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("label", {
    htmlFor: "replace_url"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Replace with (new URL)', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("input", {
    type: "url",
    id: "replace_url",
    className: "regular-text",
    placeholder: "https://new-domain.com",
    value: replaceUrl,
    onChange: e => setReplaceUrl(e.target.value)
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-form-actions"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button",
    onClick: handlePreview,
    disabled: isLoading || !searchUrl || !replaceUrl
  }, isLoading ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Loading...', 'swish-migrate-and-backup') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Preview Changes', 'swish-migrate-and-backup')), preview && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-primary",
    onClick: handleMigrate,
    disabled: isLoading
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Run Migration', 'swish-migrate-and-backup')))), error && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-notice swish-notice-error"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, error)), preview && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-preview-results"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h4", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Preview Results', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Found', 'swish-migrate-and-backup'), ' ', (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, preview.total_replacements || 0), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('replacements across', 'swish-migrate-and-backup'), ' ', (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, preview.tables_affected || 0), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('tables.', 'swish-migrate-and-backup')), preview.details && preview.details.length > 0 && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("table", {
    className: "widefat"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("thead", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Table', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Column', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Replacements', 'swish-migrate-and-backup')))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tbody", null, preview.details.slice(0, 10).map((detail, index) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", {
    key: index
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", null, detail.table), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", null, detail.column), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", null, detail.count)))))), result && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-notice swish-notice-success"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Migration completed!', 'swish-migrate-and-backup')), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Replaced', 'swish-migrate-and-backup'), ' ', (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, result.total_replacements || 0), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('occurrences.', 'swish-migrate-and-backup'))));
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (MigrationPanel);

/***/ },

/***/ "./src/js/components/ProgressModal.js"
/*!********************************************!*\
  !*** ./src/js/components/ProgressModal.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);

/**
 * ProgressModal component.
 *
 * @package SwishMigrateAndBackup
 */




/**
 * Stage information mapping.
 */
const STAGE_INFO = {
  // Legacy stages.
  init: {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Initializing backup', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Preparing backup environment', 'swish-migrate-and-backup')
  },
  database: {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Database Export', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Exporting WordPress database tables', 'swish-migrate-and-backup')
  },
  files: {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('File Backup', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Copying WordPress files', 'swish-migrate-and-backup')
  },
  archive: {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Creating Archive', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Compressing backup files', 'swish-migrate-and-backup')
  },
  upload: {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Finalizing', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Saving backup and cleaning up', 'swish-migrate-and-backup')
  },
  // Pipeline stages.
  'Indexing files': {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Indexing Files', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Scanning files to backup', 'swish-migrate-and-backup')
  },
  'Creating archive': {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Creating Archive', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Adding files to archive', 'swish-migrate-and-backup')
  },
  Finalizing: {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Finalizing', 'swish-migrate-and-backup'),
    detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Completing backup', 'swish-migrate-and-backup')
  }
};

/**
 * Log entry component.
 *
 * @param {Object} props        - Component props.
 * @param {string} props.stage  - Stage name (legacy) or name (pipeline).
 * @param {string} props.name   - Stage name (pipeline).
 * @param {string} props.status - Stage status.
 * @param {string} props.detail - Stage detail.
 * @return {JSX.Element} Component.
 */
const LogEntry = ({
  stage,
  name,
  status,
  detail
}) => {
  // Support both legacy (stage) and pipeline (name) formats.
  const stageName = name || stage;
  const stageInfo = STAGE_INFO[stageName] || {
    title: stageName,
    detail: ''
  };
  const getStatusClass = () => {
    switch (status) {
      case 'completed':
        return 'swish-log-completed';
      case 'in-progress':
      case 'in_progress':
        return 'swish-log-in-progress';
      case 'failed':
        return 'swish-log-failed';
      default:
        return 'swish-log-pending';
    }
  };
  const getIcon = () => {
    switch (status) {
      case 'completed':
        return '✓';
      case 'in-progress':
      case 'in_progress':
        return '●';
      case 'failed':
        return '✗';
      default:
        return '○';
    }
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: `swish-log-entry ${getStatusClass()}`
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-log-icon"
  }, getIcon()), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-log-content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-log-title"
  }, stageInfo.title), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-log-detail"
  }, detail || stageInfo.detail)));
};

/**
 * ProgressModal component.
 *
 * @param {Object}   props         - Component props.
 * @param {Object}   props.job     - Job data.
 * @param {Function} props.onClose - Close handler.
 * @return {JSX.Element|null} Component.
 */
const ProgressModal = ({
  job,
  onClose
}) => {
  const [logEntries, setLogEntries] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)([]);
  const isProcessing = job?.status === 'processing' || job?.status === 'starting';
  const isCompleted = job?.status === 'completed';
  const isFailed = job?.status === 'failed';
  const currentStage = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useMemo)(() => {
    if (!job) {
      return null;
    }
    const progress = job.progress || 0;
    const message = (job.message || '').toLowerCase();
    if (progress < 10 || message.includes('initializ')) {
      return 'init';
    }
    if (progress < 40 || message.includes('database') || message.includes('export')) {
      return 'database';
    }
    if (progress < 70 || message.includes('file') || message.includes('copying')) {
      return 'files';
    }
    if (progress < 90 || message.includes('archive') || message.includes('compress')) {
      return 'archive';
    }
    return 'upload';
  }, [job?.progress, job?.message]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useEffect)(() => {
    if (!job) {
      return;
    }

    // If job has explicit stages (from pipeline), use those.
    if (job.stages && job.stages.length > 0) {
      setLogEntries(job.stages);
      return;
    }

    // Otherwise, infer stages from progress (legacy behavior).
    const stages = ['init', 'database', 'files', 'archive', 'upload'];
    const currentIndex = stages.indexOf(currentStage);
    const entries = stages.slice(0, currentIndex + 1).map((stage, index) => {
      let status = 'pending';
      let detail = '';
      if (isFailed && index === currentIndex) {
        status = 'failed';
        detail = job.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('An error occurred', 'swish-migrate-and-backup');
      } else if (isCompleted || index < currentIndex) {
        status = 'completed';
      } else if (index === currentIndex) {
        status = 'in-progress';
        detail = job.message || '';
      }
      return {
        stage,
        status,
        detail
      };
    });
    setLogEntries(entries);
  }, [currentStage, isCompleted, isFailed, job?.message, job?.stages]);
  if (!job) {
    return null;
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal-overlay"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal swish-modal-with-log"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal-header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h2", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: `dashicons dashicons-${isCompleted ? 'yes-alt' : isFailed ? 'warning' : 'update'} ${isProcessing ? 'spin' : ''}`
  }), isCompleted && (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Backup Complete', 'swish-migrate-and-backup'), isFailed && (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Backup Failed', 'swish-migrate-and-backup'), isProcessing && (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Creating Backup', 'swish-migrate-and-backup'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal-body"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-progress-container"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: `swish-progress-bar ${isCompleted ? 'status-completed' : isFailed ? 'status-failed' : 'status-processing'}`
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-progress-fill",
    style: {
      width: `${job.progress || 0}%`
    }
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-progress-text"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "swish-progress-percent"
  }, job.progress || 0, "%"))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-backup-log-container"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h4", {
    className: "swish-backup-log-title"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Backup Progress', 'swish-migrate-and-backup')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-backup-log"
  }, logEntries.map((entry, index) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(LogEntry, {
    key: entry.stage || entry.name || index,
    stage: entry.stage,
    name: entry.name,
    status: entry.status,
    detail: entry.detail
  }))))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "swish-modal-footer"
  }, !isProcessing && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("button", {
    className: "button button-primary",
    onClick: onClose
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Close', 'swish-migrate-and-backup')), isProcessing && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", {
    className: "swish-processing-notice"
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Please wait while the backup completes. Do not close this window.', 'swish-migrate-and-backup')))));
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (ProgressModal);

/***/ },

/***/ "./src/js/components/index.js"
/*!************************************!*\
  !*** ./src/js/components/index.js ***!
  \************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   App: () => (/* reexport safe */ _App__WEBPACK_IMPORTED_MODULE_0__["default"]),
/* harmony export */   BackupList: () => (/* reexport safe */ _BackupList__WEBPACK_IMPORTED_MODULE_1__["default"]),
/* harmony export */   Dashboard: () => (/* reexport safe */ _Dashboard__WEBPACK_IMPORTED_MODULE_2__["default"]),
/* harmony export */   MigrationPanel: () => (/* reexport safe */ _MigrationPanel__WEBPACK_IMPORTED_MODULE_3__["default"]),
/* harmony export */   ProgressModal: () => (/* reexport safe */ _ProgressModal__WEBPACK_IMPORTED_MODULE_4__["default"])
/* harmony export */ });
/* harmony import */ var _App__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./App */ "./src/js/components/App.js");
/* harmony import */ var _BackupList__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./BackupList */ "./src/js/components/BackupList.js");
/* harmony import */ var _Dashboard__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./Dashboard */ "./src/js/components/Dashboard.js");
/* harmony import */ var _MigrationPanel__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./MigrationPanel */ "./src/js/components/MigrationPanel.js");
/* harmony import */ var _ProgressModal__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./ProgressModal */ "./src/js/components/ProgressModal.js");
/**
 * Components index.
 *
 * @package SwishMigrateAndBackup
 */







/***/ },

/***/ "./src/js/styles.css"
/*!***************************!*\
  !*** ./src/js/styles.css ***!
  \***************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "react"
/*!************************!*\
  !*** external "React" ***!
  \************************/
(module) {

module.exports = window["React"];

/***/ },

/***/ "@wordpress/api-fetch"
/*!**********************************!*\
  !*** external ["wp","apiFetch"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["apiFetch"];

/***/ },

/***/ "@wordpress/components"
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
(module) {

module.exports = window["wp"]["components"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/i18n"
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["i18n"];

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*************************!*\
  !*** ./src/js/index.js ***!
  \*************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./components */ "./src/js/components/index.js");
/* harmony import */ var _styles_css__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./styles.css */ "./src/js/styles.css");

/**
 * Swish Backup Dashboard Entry Point.
 *
 * @package SwishMigrateAndBackup
 */





/**
 * Initialize the dashboard app when DOM is ready.
 */
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('swish-backup-dashboard');
  if (container) {
    (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.createRoot)(container).render((0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_components__WEBPACK_IMPORTED_MODULE_2__.App, null));
  }
});
})();

/******/ })()
;
//# sourceMappingURL=index.js.map