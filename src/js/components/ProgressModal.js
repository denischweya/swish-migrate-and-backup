/**
 * ProgressModal Component
 *
 * Displays backup/restore progress in a modal.
 */

import { __ } from '@wordpress/i18n';

const ProgressModal = ( { job, onClose } ) => {
	if ( ! job ) return null;

	const isProcessing = job.status === 'processing' || job.status === 'starting';
	const isCompleted = job.status === 'completed';
	const isFailed = job.status === 'failed';

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
			<div className="swish-modal">
				<div className="swish-modal-header">
					<h2>
						<span className={ `dashicons dashicons-${ getStatusIcon() } ${ isProcessing ? 'spin' : '' }` }></span>
						{ isCompleted && __( 'Operation Complete', 'swish-migrate-and-backup' ) }
						{ isFailed && __( 'Operation Failed', 'swish-migrate-and-backup' ) }
						{ isProcessing && __( 'Operation in Progress', 'swish-migrate-and-backup' ) }
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

					{/* Status Message */}
					<div className={ `swish-status-message ${ getStatusClass() }` }>
						{ job.message || __( 'Processing...', 'swish-migrate-and-backup' ) }
					</div>

					{/* Processing Animation */}
					{ isProcessing && (
						<div className="swish-processing-dots">
							<span></span>
							<span></span>
							<span></span>
						</div>
					) }
				</div>

				<div className="swish-modal-footer">
					{ ! isProcessing && (
						<button className="button button-primary" onClick={ onClose }>
							{ __( 'Close', 'swish-migrate-and-backup' ) }
						</button>
					) }
					{ isProcessing && (
						<p className="swish-processing-notice">
							{ __( 'Please wait while the operation completes. Do not close this window.', 'swish-migrate-and-backup' ) }
						</p>
					) }
				</div>
			</div>
		</div>
	);
};

export default ProgressModal;
