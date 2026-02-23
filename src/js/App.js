/**
 * Main App Component
 *
 * Root component that manages global state and renders the dashboard.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import Dashboard from './components/Dashboard';
import ProgressModal from './components/ProgressModal';
import SettingsPanel from './components/SettingsPanel';
import api from './api';

const App = () => {
	// State.
	const [ stats, setStats ] = useState( null );
	const [ backups, setBackups ] = useState( [] );
	const [ settings, setSettings ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	// Progress modal state.
	const [ activeJob, setActiveJob ] = useState( null );
	const [ showProgress, setShowProgress ] = useState( false );

	// Settings panel state.
	const [ showSettings, setShowSettings ] = useState( false );

	// Load initial data.
	useEffect( () => {
		loadData();
	}, [] );

	const loadData = async () => {
		try {
			setLoading( true );
			setError( null );

			const [ statsData, backupsData, settingsData ] = await Promise.all( [
				api.getStats(),
				api.getBackups(),
				api.getSettings(),
			] );

			setStats( statsData );
			setBackups( backupsData );
			setSettings( settingsData );
		} catch ( err ) {
			setError( err.message || 'Failed to load dashboard data' );
		} finally {
			setLoading( false );
		}
	};

	// Start a backup.
	const startBackup = useCallback(
		async ( type ) => {
			try {
				setShowProgress( true );
				setActiveJob( {
					status: 'starting',
					progress: 0,
					message: 'Initializing backup...',
				} );

				const result = await api.createBackup( type, {
					db_batch_size: settings?.db_batch_size || 500,
					file_batch_size: settings?.file_batch_size || 100,
				} );

				if ( result.job_id ) {
					// Poll for progress.
					pollJobStatus( result.job_id );
				} else {
					// Backup completed immediately.
					setActiveJob( {
						status: 'completed',
						progress: 100,
						message: 'Backup completed successfully!',
					} );
					loadData();
				}
			} catch ( err ) {
				setActiveJob( {
					status: 'failed',
					progress: 0,
					message: err.message || 'Backup failed',
				} );
			}
		},
		[ settings ]
	);

	// Poll job status.
	const pollJobStatus = useCallback( async ( jobId ) => {
		const poll = async () => {
			try {
				const status = await api.getJobStatus( jobId );

				setActiveJob( {
					status: status.status,
					progress: status.progress,
					message: status.message || `Progress: ${ status.progress }%`,
				} );

				if ( status.status === 'completed' ) {
					setTimeout( () => {
						setShowProgress( false );
						loadData();
					}, 1500 );
				} else if ( status.status === 'failed' ) {
					// Keep modal open to show error.
				} else {
					// Continue polling.
					setTimeout( poll, 500 );
				}
			} catch ( err ) {
				setActiveJob( {
					status: 'failed',
					progress: 0,
					message: err.message || 'Failed to get job status',
				} );
			}
		};

		poll();
	}, [] );

	// Delete a backup.
	const deleteBackup = useCallback( async ( backupId ) => {
		if ( ! window.confirm( 'Are you sure you want to delete this backup?' ) ) {
			return;
		}

		try {
			await api.deleteBackup( backupId );
			loadData();
		} catch ( err ) {
			alert( err.message || 'Failed to delete backup' );
		}
	}, [] );

	// Download a backup.
	const downloadBackup = useCallback( async ( backupId ) => {
		try {
			const result = await api.getDownloadUrl( backupId );
			if ( result.url ) {
				window.location.href = result.url;
			}
		} catch ( err ) {
			alert( err.message || 'Failed to get download URL' );
		}
	}, [] );

	// Restore a backup.
	const restoreBackup = useCallback( async ( backupId ) => {
		if (
			! window.confirm(
				'Are you sure you want to restore this backup? This will overwrite your current site data.'
			)
		) {
			return;
		}

		try {
			setShowProgress( true );
			setActiveJob( {
				status: 'processing',
				progress: 0,
				message: 'Restoring backup...',
			} );

			await api.restoreBackup( backupId );

			setActiveJob( {
				status: 'completed',
				progress: 100,
				message: 'Restore completed successfully!',
			} );

			setTimeout( () => {
				window.location.reload();
			}, 2000 );
		} catch ( err ) {
			setActiveJob( {
				status: 'failed',
				progress: 0,
				message: err.message || 'Restore failed',
			} );
		}
	}, [] );

	// Update settings.
	const updateSettings = useCallback( async ( newSettings ) => {
		try {
			const result = await api.updateSettings( newSettings );
			if ( result.success ) {
				setSettings( result.settings );
			}
		} catch ( err ) {
			alert( err.message || 'Failed to update settings' );
		}
	}, [] );

	// Close progress modal.
	const closeProgressModal = useCallback( () => {
		if ( activeJob?.status !== 'processing' ) {
			setShowProgress( false );
			setActiveJob( null );
		}
	}, [ activeJob ] );

	// Loading state.
	if ( loading ) {
		return (
			<div className="swish-loading">
				<Spinner />
				<p>Loading dashboard...</p>
			</div>
		);
	}

	// Error state.
	if ( error ) {
		return (
			<div className="swish-error">
				<p>Error: { error }</p>
				<button onClick={ loadData } className="button">
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
				onBackup={ startBackup }
				onDelete={ deleteBackup }
				onDownload={ downloadBackup }
				onRestore={ restoreBackup }
				onOpenSettings={ () => setShowSettings( true ) }
			/>

			{ showProgress && (
				<ProgressModal
					job={ activeJob }
					onClose={ closeProgressModal }
				/>
			) }

			{ showSettings && (
				<SettingsPanel
					settings={ settings }
					onSave={ updateSettings }
					onClose={ () => setShowSettings( false ) }
				/>
			) }
		</div>
	);
};

export default App;
