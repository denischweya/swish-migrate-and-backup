/**
 * Dashboard component.
 *
 * @package SwishMigrateAndBackup
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import BackupList from './BackupList';
import MigrationPanel from './MigrationPanel';

/**
 * Format file size.
 *
 * @param {number} bytes - Size in bytes.
 * @return {string} Formatted size.
 */
const formatSize = ( bytes ) => {
	if ( bytes === 0 ) {
		return '0 Bytes';
	}
	const k = 1024;
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	const sizes = [ 'Bytes', 'KB', 'MB', 'GB' ];
	return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[ i ];
};

/**
 * Format date.
 *
 * @param {string} dateString - Date string.
 * @return {string} Formatted date.
 */
const formatDate = ( dateString ) => {
	if ( ! dateString ) {
		return '-';
	}
	const date = new Date( dateString );
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
 * @param {Function} props.onOpenSettings - Settings handler.
 * @return {JSX.Element} Component.
 */
const Dashboard = ( {
	stats,
	backups,
	settings,
	onBackup,
	onDelete,
	onDownload,
	onRestore,
	onOpenSettings,
} ) => {
	const [ showBackupTypes, setShowBackupTypes ] = useState( false );
	const [ showMigration, setShowMigration ] = useState( false );

	return (
		<div className="swish-dashboard">
			<div className="swish-dashboard-header">
				<h1>{ __( 'Swish Backup', 'swish-migrate-and-backup' ) }</h1>
				<button className="button button-link" onClick={ onOpenSettings }>
					<span className="dashicons dashicons-admin-settings"></span>
					{ __( 'Settings', 'swish-migrate-and-backup' ) }
				</button>
			</div>

			<div className="swish-quick-actions">
				<div className="swish-action-card">
					<h3>{ __( 'Backup Now', 'swish-migrate-and-backup' ) }</h3>
					<p>{ __( 'Create a new backup of your site.', 'swish-migrate-and-backup' ) }</p>

					{ showBackupTypes ? (
						<div className="swish-backup-types">
							<button
								className="button button-primary"
								onClick={ () => {
									setShowBackupTypes( false );
									onBackup( 'full' );
								} }
							>
								{ __( 'Full Backup', 'swish-migrate-and-backup' ) }
							</button>
							<button
								className="button"
								onClick={ () => {
									setShowBackupTypes( false );
									onBackup( 'database' );
								} }
							>
								{ __( 'Database Only', 'swish-migrate-and-backup' ) }
							</button>
							<button
								className="button"
								onClick={ () => {
									setShowBackupTypes( false );
									onBackup( 'files' );
								} }
							>
								{ __( 'Files Only', 'swish-migrate-and-backup' ) }
							</button>
							<button
								className="button button-link"
								onClick={ () => setShowBackupTypes( false ) }
							>
								{ __( 'Cancel', 'swish-migrate-and-backup' ) }
							</button>
						</div>
					) : (
						<button
							className="button button-primary button-hero"
							onClick={ () => setShowBackupTypes( true ) }
						>
							<span className="dashicons dashicons-backup"></span>
							{ __( 'Create Backup', 'swish-migrate-and-backup' ) }
						</button>
					) }
				</div>

				<div className="swish-action-card">
					<h3>{ __( 'Search & Replace', 'swish-migrate-and-backup' ) }</h3>
					<p>
						{ __( 'Search and replace URLs in the database.', 'swish-migrate-and-backup' ) }
					</p>
					<button
						className="button button-hero"
						onClick={ () => setShowMigration( ! showMigration ) }
					>
						<span className="dashicons dashicons-search"></span>
						{ showMigration
							? __( 'Hide Panel', 'swish-migrate-and-backup' )
							: __( 'Open Panel', 'swish-migrate-and-backup' ) }
					</button>
				</div>

				<div className="swish-action-card">
					<h3>{ __( 'Migrate Site', 'swish-migrate-and-backup' ) }</h3>
					<p>
						{ __( 'Import a backup from another site.', 'swish-migrate-and-backup' ) }
					</p>
					<a
						className="button button-hero"
						href={ window.location.href.split( '?' )[ 0 ] + '?page=swish-backup-migration' }
					>
						<span className="dashicons dashicons-migrate"></span>
						{ __( 'Start Migration', 'swish-migrate-and-backup' ) }
					</a>
				</div>
			</div>

			{ showMigration && <MigrationPanel siteUrl={ stats?.site_url } /> }

			<div className="swish-stats-grid">
				<div className="swish-stat-card">
					<span className="dashicons dashicons-backup"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">
							{ stats?.total_backups || 0 }
						</span>
						<span className="swish-stat-label">
							{ __( 'Total Backups', 'swish-migrate-and-backup' ) }
						</span>
					</div>
				</div>

				<div className="swish-stat-card">
					<span className="dashicons dashicons-database"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">
							{ formatSize( stats?.total_size || 0 ) }
						</span>
						<span className="swish-stat-label">
							{ __( 'Storage Used', 'swish-migrate-and-backup' ) }
						</span>
					</div>
				</div>

				<div className="swish-stat-card">
					<span className="dashicons dashicons-calendar-alt"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">
							{ stats?.last_backup
								? formatDate( stats.last_backup.completed_at )
								: __( 'Never', 'swish-migrate-and-backup' ) }
						</span>
						<span className="swish-stat-label">
							{ __( 'Last Backup', 'swish-migrate-and-backup' ) }
						</span>
					</div>
				</div>

				<div className="swish-stat-card">
					<span className="dashicons dashicons-performance"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">
							{ settings?.db_batch_size || 500 } /{ ' ' }
							{ settings?.file_batch_size || 100 }
						</span>
						<span className="swish-stat-label">
							{ __( 'Batch Size (DB/Files)', 'swish-migrate-and-backup' ) }
						</span>
					</div>
				</div>
			</div>

			{ stats?.storage && Object.keys( stats.storage ).length > 0 && (
				<div className="swish-storage-status">
					<h2>{ __( 'Storage Destinations', 'swish-migrate-and-backup' ) }</h2>
					<div className="swish-storage-grid">
						{ Object.entries( stats.storage ).map(
							( [ key, storage ] ) => (
								<div
									key={ key }
									className={ `swish-storage-card ${
										storage.configured ? 'configured' : ''
									}` }
								>
									<span className="swish-storage-icon dashicons dashicons-cloud"></span>
									<span className="swish-storage-name">
										{ storage.name }
									</span>
									<span
										className={ `swish-storage-status-badge ${
											storage.configured
												? 'active'
												: 'inactive'
										}` }
									>
										{ storage.configured
											? __( 'Connected', 'swish-migrate-and-backup' )
											: __( 'Not Configured', 'swish-migrate-and-backup' ) }
									</span>
								</div>
							)
						) }
					</div>
				</div>
			) }

			<BackupList
				backups={ backups }
				onDelete={ onDelete }
				onDownload={ onDownload }
				onRestore={ onRestore }
			/>
		</div>
	);
};

export default Dashboard;
