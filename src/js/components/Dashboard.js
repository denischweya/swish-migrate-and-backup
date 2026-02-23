/**
 * Dashboard Component
 *
 * Main dashboard view with backup actions and backup list.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import BackupList from './BackupList';
import MigratePanel from './MigratePanel';

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
	const [ showMigrate, setShowMigrate ] = useState( false );

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

	return (
		<div className="swish-dashboard">
			<div className="swish-dashboard-header">
				<h1>{ __( 'Swish Backup', 'swish-migrate-and-backup' ) }</h1>
				<button
					className="button button-link"
					onClick={ onOpenSettings }
				>
					<span className="dashicons dashicons-admin-settings"></span>
					{ __( 'Settings', 'swish-migrate-and-backup' ) }
				</button>
			</div>

			{/* Quick Actions */}
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
					<h3>{ __( 'Migrate Site', 'swish-migrate-and-backup' ) }</h3>
					<p>{ __( 'Search and replace URLs in the database.', 'swish-migrate-and-backup' ) }</p>
					<button
						className="button button-hero"
						onClick={ () => setShowMigrate( ! showMigrate ) }
					>
						<span className="dashicons dashicons-migrate"></span>
						{ showMigrate
							? __( 'Hide Migration', 'swish-migrate-and-backup' )
							: __( 'Start Migration', 'swish-migrate-and-backup' ) }
					</button>
				</div>
			</div>

			{/* Migration Panel */}
			{ showMigrate && (
				<MigratePanel siteUrl={ stats?.site_url } />
			) }

			{/* Stats Cards */}
			<div className="swish-stats-grid">
				<div className="swish-stat-card">
					<span className="dashicons dashicons-backup"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">{ stats?.total_backups || 0 }</span>
						<span className="swish-stat-label">{ __( 'Total Backups', 'swish-migrate-and-backup' ) }</span>
					</div>
				</div>

				<div className="swish-stat-card">
					<span className="dashicons dashicons-database"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">{ formatBytes( stats?.total_size || 0 ) }</span>
						<span className="swish-stat-label">{ __( 'Storage Used', 'swish-migrate-and-backup' ) }</span>
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
						<span className="swish-stat-label">{ __( 'Last Backup', 'swish-migrate-and-backup' ) }</span>
					</div>
				</div>

				<div className="swish-stat-card">
					<span className="dashicons dashicons-performance"></span>
					<div className="swish-stat-content">
						<span className="swish-stat-value">
							{ settings?.db_batch_size || 500 } / { settings?.file_batch_size || 100 }
						</span>
						<span className="swish-stat-label">{ __( 'Batch Size (DB/Files)', 'swish-migrate-and-backup' ) }</span>
					</div>
				</div>
			</div>

			{/* Storage Status */}
			{ stats?.storage && Object.keys( stats.storage ).length > 0 && (
				<div className="swish-storage-status">
					<h2>{ __( 'Storage Destinations', 'swish-migrate-and-backup' ) }</h2>
					<div className="swish-storage-grid">
						{ Object.entries( stats.storage ).map( ( [ id, adapter ] ) => (
							<div
								key={ id }
								className={ `swish-storage-card ${ adapter.configured ? 'configured' : '' }` }
							>
								<span className="swish-storage-icon dashicons dashicons-cloud"></span>
								<span className="swish-storage-name">{ adapter.name }</span>
								<span className={ `swish-storage-status-badge ${ adapter.configured ? 'active' : 'inactive' }` }>
									{ adapter.configured
										? __( 'Connected', 'swish-migrate-and-backup' )
										: __( 'Not Configured', 'swish-migrate-and-backup' ) }
								</span>
							</div>
						) ) }
					</div>
				</div>
			) }

			{/* Backup List */}
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
