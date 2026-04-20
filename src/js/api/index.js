/**
 * API functions for Swish Backup.
 *
 * @package SwishMigrateAndBackup
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Make an API request to the Swish Backup REST API.
 *
 * @param {string} endpoint - API endpoint path.
 * @param {Object} options  - Fetch options.
 * @return {Promise} API response.
 */
const apiRequest = async ( endpoint, options = {} ) => {
	const path = `/swish-backup/v1${ endpoint }`;
	try {
		return await apiFetch( { path, ...options } );
	} catch ( error ) {
		if ( error.message ) {
			throw new Error( error.message );
		}
		throw error;
	}
};

/**
 * Get dashboard stats.
 *
 * @return {Promise} Stats data.
 */
export const getStats = () => apiRequest( '/stats' );

/**
 * Get all backups.
 *
 * @return {Promise} Backups array.
 */
export const getBackups = () => apiRequest( '/backups' );

/**
 * Create a new backup.
 *
 * @param {string} type    - Backup type (full, database, files).
 * @param {Object} options - Additional options.
 * @return {Promise} Backup job data.
 */
export const createBackup = ( type = 'full', options = {} ) =>
	apiRequest( '/backup', {
		method: 'POST',
		data: { type, ...options },
	} );

/**
 * Delete a backup.
 *
 * @param {string} backupId - Backup ID.
 * @return {Promise} Deletion result.
 */
export const deleteBackup = ( backupId ) =>
	apiRequest( `/backup/${ backupId }`, { method: 'DELETE' } );

/**
 * Get backup download URL.
 *
 * @param {string} backupId - Backup ID.
 * @return {Promise} Download URL data.
 */
export const getDownloadUrl = ( backupId ) =>
	apiRequest( `/backup/${ backupId }/download` );

/**
 * Restore a backup.
 *
 * @param {string} backupId - Backup ID.
 * @param {Object} options  - Restore options.
 * @return {Promise} Restore result.
 */
export const restoreBackup = ( backupId, options = {} ) =>
	apiRequest( '/restore', {
		method: 'POST',
		data: {
			backup_id: backupId,
			restore_database: true,
			restore_files: true,
			...options,
		},
	} );

/**
 * Run search and replace.
 *
 * @param {string}  search  - Search string.
 * @param {string}  replace - Replace string.
 * @param {boolean} dryRun  - Whether to do a dry run.
 * @return {Promise} Search/replace result.
 */
export const searchReplace = ( search, replace, dryRun = false ) =>
	apiRequest( '/search-replace', {
		method: 'POST',
		data: { search, replace, dry_run: dryRun },
	} );

/**
 * Get job status.
 *
 * @param {string} jobId - Job ID.
 * @return {Promise} Job status data.
 */
export const getJobStatus = ( jobId ) => apiRequest( `/job/${ jobId }` );

/**
 * Process a pending job directly (fallback for hosts where WP Cron doesn't trigger).
 *
 * @param {string} jobId - Job ID.
 * @return {Promise} Job status data.
 */
export const processJob = ( jobId ) =>
	apiRequest( `/job/${ jobId }/process`, { method: 'POST' } );

/**
 * Get settings.
 *
 * @return {Promise} Settings data.
 */
export const getSettings = () => apiRequest( '/settings' );

/**
 * Update settings.
 *
 * @param {Object} settings - Settings to update.
 * @return {Promise} Updated settings.
 */
export const updateSettings = ( settings ) =>
	apiRequest( '/settings', {
		method: 'POST',
		data: settings,
	} );
