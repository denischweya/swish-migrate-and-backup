/**
 * Main App component.
 *
 * @package SwishMigrateAndBackup
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import Dashboard from './Dashboard';
import ProgressModal from './ProgressModal';
import SettingsModal from './SettingsModal';
import {
	getStats,
	getBackups,
	createBackup,
	deleteBackup,
	getDownloadUrl,
	restoreBackup,
	getJobStatus,
	processJob,
	getSettings,
	updateSettings,
} from '../api';

/**
 * Main App component.
 *
 * @return {JSX.Element} Component.
 */
const App = () => {
	const [ stats, setStats ] = useState( null );
	const [ backups, setBackups ] = useState( [] );
	const [ settings, setSettings ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ currentJob, setCurrentJob ] = useState( null );
	const [ showProgress, setShowProgress ] = useState( false );
	const [ showSettings, setShowSettings ] = useState( false );

	useEffect( () => {
		loadDashboardData();
	}, [] );

	const loadDashboardData = async () => {
		try {
			setIsLoading( true );
			setError( null );

			const [ statsData, backupsData, settingsData ] = await Promise.all( [
				getStats(),
				getBackups(),
				getSettings(),
			] );

			setStats( statsData );
			setBackups( backupsData );
			setSettings( settingsData );
		} catch ( err ) {
			setError( err.message || 'Failed to load dashboard data' );
		} finally {
			setIsLoading( false );
		}
	};

	const handleBackup = useCallback(
		async ( type ) => {
			try {
				setShowProgress( true );
				setCurrentJob( {
					status: 'starting',
					progress: 0,
					message: 'Initializing backup...',
				} );

				const result = await createBackup( type, {
					db_batch_size: settings?.db_batch_size || 500,
					file_batch_size: settings?.file_batch_size || 100,
				} );

				if ( result.job_id ) {
					pollJobStatus( result.job_id );
				} else {
					setCurrentJob( {
						status: 'completed',
						progress: 100,
						message: 'Backup completed successfully!',
					} );
					loadDashboardData();
				}
			} catch ( err ) {
				setCurrentJob( {
					status: 'failed',
					progress: 0,
					message: err.message || 'Backup failed',
				} );
			}
		},
		[ settings ]
	);

	const pollJobStatus = useCallback( async ( jobId ) => {
		let pendingCount = 0;
		let hasTriggeredProcess = false;

		const poll = async () => {
			try {
				const jobData = await getJobStatus( jobId );

				setCurrentJob( {
					status: jobData.status,
					progress: jobData.progress,
					message: jobData.message || `Progress: ${ jobData.progress }%`,
				} );

				if ( jobData.status === 'completed' ) {
					setTimeout( () => {
						setShowProgress( false );
						loadDashboardData();
					}, 1500 );
				} else if ( jobData.status === 'failed' ) {
					// Don't continue polling on failure.
				} else if ( jobData.status === 'pending' ) {
					pendingCount++;
					// If still pending after 3 seconds, trigger the process endpoint directly.
					// This is a fallback for hosts where WP Cron doesn't trigger immediately (like WP Engine).
					if ( pendingCount >= 3 && ! hasTriggeredProcess ) {
						hasTriggeredProcess = true;
						setCurrentJob( {
							status: 'processing',
							progress: 5,
							message: 'Starting backup process...',
						} );
						// Call the process endpoint in the background - don't await it.
						// This will run the backup while we continue polling for status.
						processJob( jobId ).catch( ( processErr ) => {
							// If process fails (e.g., timeout), continue polling - the job may have started.
							console.warn( 'Process request returned:', processErr.message || 'Unknown error' );
						} );
					}
					setTimeout( poll, 1000 );
				} else {
					// Processing - continue polling.
					setTimeout( poll, 1000 );
				}
			} catch ( err ) {
				setCurrentJob( {
					status: 'failed',
					progress: 0,
					message: err.message || 'Failed to get job status',
				} );
			}
		};

		poll();
	}, [] );

	const handleDelete = useCallback( async ( backupId ) => {
		if ( ! window.confirm( 'Are you sure you want to delete this backup?' ) ) {
			return;
		}

		try {
			await deleteBackup( backupId );
			loadDashboardData();
		} catch ( err ) {
			alert( err.message || 'Failed to delete backup' );
		}
	}, [] );

	const handleDownload = useCallback( async ( backupId ) => {
		try {
			const result = await getDownloadUrl( backupId );
			if ( result.url ) {
				window.location.href = result.url;
			}
		} catch ( err ) {
			alert( err.message || 'Failed to get download URL' );
		}
	}, [] );

	const handleRestore = useCallback( async ( backupId ) => {
		if (
			! window.confirm(
				'Are you sure you want to restore this backup? This will overwrite your current site data.'
			)
		) {
			return;
		}

		try {
			setShowProgress( true );
			setCurrentJob( {
				status: 'processing',
				progress: 0,
				message: 'Restoring backup...',
			} );

			await restoreBackup( backupId );

			setCurrentJob( {
				status: 'completed',
				progress: 100,
				message: 'Restore completed successfully!',
			} );

			setTimeout( () => {
				window.location.reload();
			}, 2000 );
		} catch ( err ) {
			setCurrentJob( {
				status: 'failed',
				progress: 0,
				message: err.message || 'Restore failed',
			} );
		}
	}, [] );

	const handleSettingsSave = useCallback( async ( newSettings ) => {
		try {
			const result = await updateSettings( newSettings );
			if ( result.success ) {
				setSettings( result.settings );
			}
		} catch ( err ) {
			alert( err.message || 'Failed to update settings' );
		}
	}, [] );

	const handleCloseProgress = useCallback( () => {
		if ( currentJob?.status !== 'processing' ) {
			setShowProgress( false );
			setCurrentJob( null );
		}
	}, [ currentJob ] );

	if ( isLoading ) {
		return (
			<div className="swish-loading">
				<Spinner />
				<p>Loading dashboard...</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="swish-error">
				<p>Error: { error }</p>
				<button onClick={ loadDashboardData } className="button">
					Retry
				</button>
			</div>
		);
	}

	return (
		<div className="swish-dashboard-app">
			<Dashboard
				stats={ stats }
				backups={ backups }
				settings={ settings }
				onBackup={ handleBackup }
				onDelete={ handleDelete }
				onDownload={ handleDownload }
				onRestore={ handleRestore }
				onOpenSettings={ () => setShowSettings( true ) }
			/>

			{ showProgress && (
				<ProgressModal job={ currentJob } onClose={ handleCloseProgress } />
			) }

			{ showSettings && (
				<SettingsModal
					settings={ settings }
					onSave={ handleSettingsSave }
					onClose={ () => setShowSettings( false ) }
				/>
			) }
		</div>
	);
};

export default App;
