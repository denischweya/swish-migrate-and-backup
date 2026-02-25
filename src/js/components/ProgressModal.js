/**
 * ProgressModal Component
 *
 * Displays backup/restore progress in a modal with detailed stage tracking.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';

/**
 * Backup stage definitions.
 */
const BACKUP_STAGES = {
	init: {
		title: __( 'Initializing backup', 'swish-migrate-and-backup' ),
		detail: __( 'Preparing backup environment', 'swish-migrate-and-backup' ),
	},
	database: {
		title: __( 'Database Export', 'swish-migrate-and-backup' ),
		detail: __( 'Exporting WordPress database tables', 'swish-migrate-and-backup' ),
	},
	files: {
		title: __( 'File Backup', 'swish-migrate-and-backup' ),
		detail: __( 'Copying WordPress files', 'swish-migrate-and-backup' ),
	},
	archive: {
		title: __( 'Creating Archive', 'swish-migrate-and-backup' ),
		detail: __( 'Compressing backup files', 'swish-migrate-and-backup' ),
	},
	upload: {
		title: __( 'Finalizing', 'swish-migrate-and-backup' ),
		detail: __( 'Saving backup and cleaning up', 'swish-migrate-and-backup' ),
	},
};

/**
 * Single log entry component.
 */
const LogEntry = ( { stage, status, detail } ) => {
	const stageInfo = BACKUP_STAGES[ stage ] || { title: stage, detail: '' };

	const getStatusClass = () => {
		switch ( status ) {
			case 'completed':
				return 'swish-log-completed';
			case 'in-progress':
				return 'swish-log-in-progress';
			case 'failed':
				return 'swish-log-failed';
			default:
				return 'swish-log-pending';
		}
	};

	const getIcon = () => {
		switch ( status ) {
			case 'completed':
				return '✓';
			case 'in-progress':
				return '●';
			case 'failed':
				return '✗';
			default:
				return '○';
		}
	};

	return (
		<div className={ `swish-log-entry ${ getStatusClass() }` }>
			<span className="swish-log-icon">{ getIcon() }</span>
			<div className="swish-log-content">
				<div className="swish-log-title">{ stageInfo.title }</div>
				<div className="swish-log-detail">{ detail || stageInfo.detail }</div>
			</div>
		</div>
	);
};

const ProgressModal = ( { job, onClose } ) => {
	const [ logEntries, setLogEntries ] = useState( [] );

	const isProcessing = job?.status === 'processing' || job?.status === 'starting';
	const isCompleted = job?.status === 'completed';
	const isFailed = job?.status === 'failed';

	// Determine the current stage from progress or message.
	const currentStage = useMemo( () => {
		if ( ! job ) return null;

		const progress = job.progress || 0;
		const message = ( job.message || '' ).toLowerCase();

		if ( progress < 10 || message.includes( 'initializ' ) ) {
			return 'init';
		} else if ( progress < 40 || message.includes( 'database' ) || message.includes( 'export' ) ) {
			return 'database';
		} else if ( progress < 70 || message.includes( 'file' ) || message.includes( 'copying' ) ) {
			return 'files';
		} else if ( progress < 90 || message.includes( 'archive' ) || message.includes( 'compress' ) ) {
			return 'archive';
		} else {
			return 'upload';
		}
	}, [ job?.progress, job?.message ] );

	// Update log entries based on current stage.
	useEffect( () => {
		if ( ! job ) return;

		const stages = [ 'init', 'database', 'files', 'archive', 'upload' ];
		const currentIndex = stages.indexOf( currentStage );

		const newEntries = stages.slice( 0, currentIndex + 1 ).map( ( stage, index ) => {
			let status = 'pending';
			let detail = '';

			if ( isFailed && index === currentIndex ) {
				status = 'failed';
				detail = job.message || __( 'An error occurred', 'swish-migrate-and-backup' );
			} else if ( isCompleted || index < currentIndex ) {
				status = 'completed';
			} else if ( index === currentIndex ) {
				status = 'in-progress';
				detail = job.message || '';
			}

			return { stage, status, detail };
		} );

		setLogEntries( newEntries );
	}, [ currentStage, isCompleted, isFailed, job?.message ] );

	if ( ! job ) return null;

	const getStatusIcon = () => {
		if ( isCompleted ) return 'yes-alt';
		if ( isFailed ) return 'warning';
		return 'update';
	};

	const getStatusClass = () => {
		if ( isCompleted ) return 'status-completed';
		if ( isFailed ) return 'status-failed';
		return 'status-processing';
	};

	return (
		<div className="swish-modal-overlay">
			<div className="swish-modal swish-modal-with-log">
				<div className="swish-modal-header">
					<h2>
						<span className={ `dashicons dashicons-${ getStatusIcon() } ${ isProcessing ? 'spin' : '' }` }></span>
						{ isCompleted && __( 'Backup Complete', 'swish-migrate-and-backup' ) }
						{ isFailed && __( 'Backup Failed', 'swish-migrate-and-backup' ) }
						{ isProcessing && __( 'Creating Backup', 'swish-migrate-and-backup' ) }
					</h2>
				</div>

				<div className="swish-modal-body">
					{/* Progress Bar */}
					<div className="swish-progress-container">
						<div className={ `swish-progress-bar ${ getStatusClass() }` }>
							<div
								className="swish-progress-fill"
								style={ { width: `${ job.progress || 0 }%` } }
							></div>
						</div>
						<div className="swish-progress-text">
							<span className="swish-progress-percent">{ job.progress || 0 }%</span>
						</div>
					</div>

					{/* Backup Log */}
					<div className="swish-backup-log-container">
						<h4 className="swish-backup-log-title">
							{ __( 'Backup Progress', 'swish-migrate-and-backup' ) }
						</h4>
						<div className="swish-backup-log">
							{ logEntries.map( ( entry ) => (
								<LogEntry
									key={ entry.stage }
									stage={ entry.stage }
									status={ entry.status }
									detail={ entry.detail }
								/>
							) ) }
						</div>
					</div>
				</div>

				<div className="swish-modal-footer">
					{ ! isProcessing && (
						<>
							<button className="button button-primary" onClick={ onClose }>
								{ __( 'Close', 'swish-migrate-and-backup' ) }
							</button>
							{ isFailed && job.message && job.message.includes( '1GB limit' ) && (
								<a
									href={ window.swishBackupData?.proUrl || 'https://denis.swishfolio.com/swish-migrate-and-backup-pro' }
									className="button button-primary swish-upgrade-button"
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'Upgrade to Pro', 'swish-migrate-and-backup' ) }
								</a>
							) }
						</>
					) }
					{ isProcessing && (
						<p className="swish-processing-notice">
							{ __( 'Please wait while the backup completes. Do not close this window.', 'swish-migrate-and-backup' ) }
						</p>
					) }
				</div>
			</div>
		</div>
	);
};

export default ProgressModal;
