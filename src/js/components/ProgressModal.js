/**
 * ProgressModal component.
 *
 * @package SwishMigrateAndBackup
 */

import { useState, useEffect, useMemo, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Parse ETA from job message.
 *
 * @param {string} message - Job message containing ETA.
 * @return {string|null} Formatted ETA or null.
 */
const parseEtaFromMessage = ( message ) => {
	if ( ! message ) {
		return null;
	}

	// Match patterns like "2m 30s remaining", "1h 15m remaining", "45s remaining"
	const etaMatch = message.match( /\((\d+[hms]\s*(?:\d+[hms]\s*)?(?:remaining|left)?[^)]*)\)/i );
	if ( etaMatch ) {
		return etaMatch[ 1 ].replace( /\s*remaining\s*/i, '' ).trim();
	}

	// Match "almost done"
	if ( message.toLowerCase().includes( 'almost done' ) ) {
		return 'almost done';
	}

	return null;
};

/**
 * Format ETA for display in the notice.
 *
 * @param {string} eta - Raw ETA string like "2m 30s".
 * @return {string} Formatted string like "approximately 2 minutes".
 */
const formatEtaForNotice = ( eta ) => {
	if ( ! eta || eta === 'almost done' ) {
		return __( 'almost done', 'swish-migrate-and-backup' );
	}

	// Parse hours, minutes, seconds
	const hours = eta.match( /(\d+)h/i );
	const minutes = eta.match( /(\d+)m/i );
	const seconds = eta.match( /(\d+)s/i );

	const h = hours ? parseInt( hours[ 1 ], 10 ) : 0;
	const m = minutes ? parseInt( minutes[ 1 ], 10 ) : 0;
	const s = seconds ? parseInt( seconds[ 1 ], 10 ) : 0;

	// Convert to a readable format
	if ( h > 0 ) {
		if ( m > 0 ) {
			return sprintf(
				__( 'approximately %1$d hour %2$d minutes', 'swish-migrate-and-backup' ),
				h,
				m
			);
		}
		return sprintf(
			__( 'approximately %d hour(s)', 'swish-migrate-and-backup' ),
			h
		);
	}

	if ( m > 0 ) {
		return sprintf(
			__( 'approximately %d minute(s)', 'swish-migrate-and-backup' ),
			m + ( s > 30 ? 1 : 0 ) // Round up if more than 30 seconds
		);
	}

	if ( s > 0 ) {
		return __( 'less than a minute', 'swish-migrate-and-backup' );
	}

	return __( 'calculating...', 'swish-migrate-and-backup' );
};

/**
 * Stage information mapping.
 */
const STAGE_INFO = {
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
 * Log entry component.
 *
 * @param {Object} props        - Component props.
 * @param {string} props.stage  - Stage name.
 * @param {string} props.status - Stage status.
 * @param {string} props.detail - Stage detail.
 * @return {JSX.Element} Component.
 */
const LogEntry = ( { stage, status, detail } ) => {
	const stageInfo = STAGE_INFO[ stage ] || { title: stage, detail: '' };

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
	}, [ currentStage, isCompleted, isFailed, job?.message ] );

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
							{ job?.message && parseEtaFromMessage( job.message ) && (
								<span className="swish-eta-notice">
									<br />
									<strong>
										{ sprintf(
											__( 'Estimated time: %s', 'swish-migrate-and-backup' ),
											formatEtaForNotice( parseEtaFromMessage( job.message ) )
										) }
									</strong>
								</span>
							) }
						</p>
					) }
				</div>
			</div>
		</div>
	);
};

export default ProgressModal;
