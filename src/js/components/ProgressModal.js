/**
 * ProgressModal component.
 *
 * @package SwishMigrateAndBackup
 */

import { useState, useEffect, useMemo, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Stage information mapping.
 */
const STAGE_INFO = {
	// Legacy stages.
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
	// Pipeline stages.
	'Indexing files': {
		title: __( 'Indexing Files', 'swish-migrate-and-backup' ),
		detail: __( 'Scanning files to backup', 'swish-migrate-and-backup' ),
	},
	'Creating archive': {
		title: __( 'Creating Archive', 'swish-migrate-and-backup' ),
		detail: __( 'Adding files to archive', 'swish-migrate-and-backup' ),
	},
	Finalizing: {
		title: __( 'Finalizing', 'swish-migrate-and-backup' ),
		detail: __( 'Completing backup', 'swish-migrate-and-backup' ),
	},
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
const LogEntry = ( { stage, name, status, detail } ) => {
	// Support both legacy (stage) and pipeline (name) formats.
	const stageName = name || stage;
	const stageInfo = STAGE_INFO[ stageName ] || { title: stageName, detail: '' };

	const getStatusClass = () => {
		switch ( status ) {
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
		switch ( status ) {
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

	return (
		<div className={ `swish-log-entry ${ getStatusClass() }` }>
			<span className="swish-log-icon">{ getIcon() }</span>
			<div className="swish-log-content">
				<div className="swish-log-title">{ stageInfo.title }</div>
				<div className="swish-log-detail">
					{ detail || stageInfo.detail }
				</div>
			</div>
		</div>
	);
};

/**
 * ProgressModal component.
 *
 * @param {Object}   props         - Component props.
 * @param {Object}   props.job     - Job data.
 * @param {Function} props.onClose - Close handler.
 * @return {JSX.Element|null} Component.
 */
const ProgressModal = ( { job, onClose } ) => {
	const [ logEntries, setLogEntries ] = useState( [] );

	const isProcessing =
		job?.status === 'processing' || job?.status === 'starting';
	const isCompleted = job?.status === 'completed';
	const isFailed = job?.status === 'failed';

	const currentStage = useMemo( () => {
		if ( ! job ) {
			return null;
		}

		const progress = job.progress || 0;
		const message = ( job.message || '' ).toLowerCase();

		if ( progress < 10 || message.includes( 'initializ' ) ) {
			return 'init';
		}
		if ( progress < 40 || message.includes( 'database' ) || message.includes( 'export' ) ) {
			return 'database';
		}
		if ( progress < 70 || message.includes( 'file' ) || message.includes( 'copying' ) ) {
			return 'files';
		}
		if ( progress < 90 || message.includes( 'archive' ) || message.includes( 'compress' ) ) {
			return 'archive';
		}
		return 'upload';
	}, [ job?.progress, job?.message ] );

	useEffect( () => {
		if ( ! job ) {
			return;
		}

		// If job has explicit stages (from pipeline), use those.
		if ( job.stages && job.stages.length > 0 ) {
			setLogEntries( job.stages );
			return;
		}

		// Otherwise, infer stages from progress (legacy behavior).
		const stages = [ 'init', 'database', 'files', 'archive', 'upload' ];
		const currentIndex = stages.indexOf( currentStage );

		const entries = stages.slice( 0, currentIndex + 1 ).map( ( stage, index ) => {
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

		setLogEntries( entries );
	}, [ currentStage, isCompleted, isFailed, job?.message, job?.stages ] );

	if ( ! job ) {
		return null;
	}

	return (
		<div className="swish-modal-overlay">
			<div className="swish-modal swish-modal-with-log">
				<div className="swish-modal-header">
					<h2>
						<span
							className={ `dashicons dashicons-${
								isCompleted
									? 'yes-alt'
									: isFailed
									? 'warning'
									: 'update'
							} ${ isProcessing ? 'spin' : '' }` }
						></span>
						{ isCompleted &&
							__( 'Backup Complete', 'swish-migrate-and-backup' ) }
						{ isFailed &&
							__( 'Backup Failed', 'swish-migrate-and-backup' ) }
						{ isProcessing &&
							__( 'Creating Backup', 'swish-migrate-and-backup' ) }
					</h2>
				</div>

				<div className="swish-modal-body">
					<div className="swish-progress-container">
						<div
							className={ `swish-progress-bar ${
								isCompleted
									? 'status-completed'
									: isFailed
									? 'status-failed'
									: 'status-processing'
							}` }
						>
							<div
								className="swish-progress-fill"
								style={ { width: `${ job.progress || 0 }%` } }
							></div>
						</div>
						<div className="swish-progress-text">
							<span className="swish-progress-percent">
								{ job.progress || 0 }%
							</span>
						</div>
					</div>

					<div className="swish-backup-log-container">
						<h4 className="swish-backup-log-title">
							{ __( 'Backup Progress', 'swish-migrate-and-backup' ) }
						</h4>
						<div className="swish-backup-log">
							{ logEntries.map( ( entry, index ) => (
								<LogEntry
									key={ entry.stage || entry.name || index }
									stage={ entry.stage }
									name={ entry.name }
									status={ entry.status }
									detail={ entry.detail }
								/>
							) ) }
						</div>
					</div>
				</div>

				<div className="swish-modal-footer">
					{ ! isProcessing && (
						<Fragment>
							<button
								className="button button-primary"
								onClick={ onClose }
							>
								{ __( 'Close', 'swish-migrate-and-backup' ) }
							</button>
							{ isFailed &&
								job.message &&
								job.message.includes( '2GB limit' ) && (
									<a
										href={
											window.swishBackupData?.proUrl ||
											'https://denis.swishfolio.com/swish-migrate-and-backup-pro'
										}
										className="button button-primary swish-upgrade-button"
										target="_blank"
										rel="noopener noreferrer"
									>
										{ __(
											'Upgrade to Pro',
											'swish-migrate-and-backup'
										) }
									</a>
								) }
						</Fragment>
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
