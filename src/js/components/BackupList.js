/**
 * BackupList Component
 *
 * Displays a list of backups with actions.
 */

import { __ } from '@wordpress/i18n';

const BackupList = ( { backups, onDelete, onDownload, onRestore } ) => {
	const formatBytes = ( bytes ) => {
		if ( bytes === 0 ) return '0 Bytes';
		const k = 1024;
		const sizes = [ 'Bytes', 'KB', 'MB', 'GB' ];
		const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
		return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[ i ];
	};

	const formatDate = ( dateString ) => {
		if ( ! dateString ) return '-';
		const date = new Date( dateString );
		return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
	};

	const getTypeLabel = ( type ) => {
		switch ( type ) {
			case 'full':
				return __( 'Full Backup', 'swish-migrate-and-backup' );
			case 'database':
				return __( 'Database', 'swish-migrate-and-backup' );
			case 'files':
				return __( 'Files Only', 'swish-migrate-and-backup' );
			default:
				return type;
		}
	};

	const getTypeClass = ( type ) => {
		switch ( type ) {
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

	if ( ! backups || backups.length === 0 ) {
		return (
			<div className="swish-backup-list">
				<h2>{ __( 'Recent Backups', 'swish-migrate-and-backup' ) }</h2>
				<div className="swish-empty-state">
					<span className="dashicons dashicons-backup"></span>
					<p>{ __( 'No backups yet. Create your first backup to get started.', 'swish-migrate-and-backup' ) }</p>
				</div>
			</div>
		);
	}

	return (
		<div className="swish-backup-list">
			<h2>{ __( 'Recent Backups', 'swish-migrate-and-backup' ) }</h2>
			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th className="column-filename">{ __( 'Backup', 'swish-migrate-and-backup' ) }</th>
						<th className="column-type">{ __( 'Type', 'swish-migrate-and-backup' ) }</th>
						<th className="column-size">{ __( 'Size', 'swish-migrate-and-backup' ) }</th>
						<th className="column-date">{ __( 'Created', 'swish-migrate-and-backup' ) }</th>
						<th className="column-actions">{ __( 'Actions', 'swish-migrate-and-backup' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ backups.map( ( backup ) => (
						<tr key={ backup.id }>
							<td className="column-filename">
								<strong>{ backup.filename || backup.id }</strong>
							</td>
							<td className="column-type">
								<span className={ `swish-backup-type ${ getTypeClass( backup.type ) }` }>
									{ getTypeLabel( backup.type ) }
								</span>
							</td>
							<td className="column-size">{ formatBytes( backup.size ) }</td>
							<td className="column-date">{ formatDate( backup.completed_at || backup.created_at ) }</td>
							<td className="column-actions">
								<div className="swish-action-buttons">
									<button
										className="button button-small"
										onClick={ () => onRestore( backup.id ) }
										title={ __( 'Restore', 'swish-migrate-and-backup' ) }
									>
										<span className="dashicons dashicons-backup"></span>
										{ __( 'Restore', 'swish-migrate-and-backup' ) }
									</button>
									<button
										className="button button-small"
										onClick={ () => onDownload( backup.id ) }
										title={ __( 'Download', 'swish-migrate-and-backup' ) }
									>
										<span className="dashicons dashicons-download"></span>
									</button>
									<button
										className="button button-small button-link-delete"
										onClick={ () => onDelete( backup.id ) }
										title={ __( 'Delete', 'swish-migrate-and-backup' ) }
									>
										<span className="dashicons dashicons-trash"></span>
									</button>
								</div>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

export default BackupList;
