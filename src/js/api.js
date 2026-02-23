/**
 * API Utilities
 *
 * Helper functions for communicating with the REST API.
 */

import apiFetch from '@wordpress/api-fetch';

const API_NAMESPACE = '/swish-backup/v1';

/**
 * Make an API request.
 *
 * @param {string} endpoint - API endpoint path.
 * @param {Object} options - Fetch options.
 * @return {Promise} API response.
 */
const request = async ( endpoint, options = {} ) => {
	const path = `${ API_NAMESPACE }${ endpoint }`;

	try {
		return await apiFetch( { path, ...options } );
	} catch ( error ) {
		// Handle WordPress API errors.
		if ( error.message ) {
			throw new Error( error.message );
		}
		throw error;
	}
};

/**
 * API methods.
 */
const api = {
	/**
	 * Get dashboard stats.
	 *
	 * @return {Promise} Stats data.
	 */
	getStats: () => request( '/stats' ),

	/**
	 * Get all backups.
	 *
	 * @return {Promise} Backups array.
	 */
	getBackups: () => request( '/backups' ),

	/**
	 * Get a single backup.
	 *
	 * @param {string} id - Backup ID.
	 * @return {Promise} Backup data.
	 */
	getBackup: ( id ) => request( `/backup/${ id }` ),

	/**
	 * Create a backup.
	 *
	 * @param {string} type - Backup type (full, database, files).
	 * @param {Object} options - Backup options.
	 * @return {Promise} Backup result.
	 */
	createBackup: ( type = 'full', options = {} ) =>
		request( '/backup', {
			method: 'POST',
			data: {
				type,
				...options,
			},
		} ),

	/**
	 * Delete a backup.
	 *
	 * @param {string} id - Backup ID.
	 * @return {Promise} Delete result.
	 */
	deleteBackup: ( id ) =>
		request( `/backup/${ id }`, {
			method: 'DELETE',
		} ),

	/**
	 * Get download URL for a backup.
	 *
	 * @param {string} id - Backup ID.
	 * @return {Promise} Download URL.
	 */
	getDownloadUrl: ( id ) => request( `/backup/${ id }/download` ),

	/**
	 * Restore a backup.
	 *
	 * @param {string} backupId - Backup ID to restore.
	 * @param {Object} options - Restore options.
	 * @return {Promise} Restore result.
	 */
	restoreBackup: ( backupId, options = {} ) =>
		request( '/restore', {
			method: 'POST',
			data: {
				backup_id: backupId,
				restore_database: true,
				restore_files: true,
				...options,
			},
		} ),

	/**
	 * Run migration.
	 *
	 * @param {Object} options - Migration options.
	 * @return {Promise} Migration result.
	 */
	runMigration: ( options ) =>
		request( '/migrate', {
			method: 'POST',
			data: options,
		} ),

	/**
	 * Run search and replace.
	 *
	 * @param {string} search - Search string.
	 * @param {string} replace - Replace string.
	 * @param {boolean} dryRun - Whether to do a dry run.
	 * @return {Promise} Search/replace result.
	 */
	searchReplace: ( search, replace, dryRun = false ) =>
		request( '/search-replace', {
			method: 'POST',
			data: {
				search,
				replace,
				dry_run: dryRun,
			},
		} ),

	/**
	 * Get job status.
	 *
	 * @param {string} jobId - Job ID.
	 * @return {Promise} Job status.
	 */
	getJobStatus: ( jobId ) => request( `/job/${ jobId }` ),

	/**
	 * Test storage connection.
	 *
	 * @param {string} adapter - Adapter ID.
	 * @return {Promise} Test result.
	 */
	testStorage: ( adapter ) =>
		request( '/storage/test', {
			method: 'POST',
			data: { adapter },
		} ),

	/**
	 * Get settings.
	 *
	 * @return {Promise} Settings data.
	 */
	getSettings: () => request( '/settings' ),

	/**
	 * Update settings.
	 *
	 * @param {Object} settings - Settings to update.
	 * @return {Promise} Update result.
	 */
	updateSettings: ( settings ) =>
		request( '/settings', {
			method: 'POST',
			data: settings,
		} ),
};

export default api;
