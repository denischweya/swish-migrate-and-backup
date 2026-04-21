/**
 * SettingsModal component.
 *
 * @package SwishMigrateAndBackup
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Format bytes to human readable string.
 *
 * @param {number} bytes - Bytes to format.
 * @return {string} Formatted string.
 */
const formatBytes = ( bytes ) => {
	if ( bytes === 0 ) return '0 B';
	const k = 1024;
	const sizes = [ 'B', 'KB', 'MB', 'GB' ];
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 1 ) ) + ' ' + sizes[ i ];
};

/**
 * FolderTree component for expandable folder selection.
 *
 * @param {Object}   props              - Component props.
 * @param {string}   props.title        - Section title.
 * @param {Array}    props.items        - Items to display.
 * @param {Array}    props.excluded     - Currently excluded items.
 * @param {Function} props.onToggle     - Toggle handler.
 * @param {boolean}  props.masterEnabled - Whether the master toggle is enabled.
 * @return {JSX.Element} Component.
 */
const FolderTree = ( { title, items, excluded, onToggle, masterEnabled } ) => {
	const [ isExpanded, setIsExpanded ] = useState( false );

	if ( ! items || items.length === 0 ) {
		return null;
	}

	const excludedCount = excluded?.length || 0;
	const totalCount = items.length;

	return (
		<div className="swish-folder-tree">
			<button
				type="button"
				className={ `swish-folder-tree-toggle ${ isExpanded ? 'expanded' : '' }` }
				onClick={ () => setIsExpanded( ! isExpanded ) }
				disabled={ ! masterEnabled }
			>
				<span className="dashicons dashicons-arrow-right-alt2"></span>
				<span className="swish-folder-tree-title">{ title }</span>
				<span className="swish-folder-tree-count">
					{ excludedCount > 0
						? `${ totalCount - excludedCount }/${ totalCount } selected`
						: `${ totalCount } items` }
				</span>
			</button>

			{ isExpanded && masterEnabled && (
				<div className="swish-folder-tree-items">
					<div className="swish-folder-tree-actions">
						<button
							type="button"
							className="button button-small"
							onClick={ () => onToggle( [] ) }
						>
							{ __( 'Select All', 'swish-migrate-and-backup' ) }
						</button>
						<button
							type="button"
							className="button button-small"
							onClick={ () => onToggle( items.map( ( i ) => i.path || i.slug ) ) }
						>
							{ __( 'Select None', 'swish-migrate-and-backup' ) }
						</button>
					</div>

					{ items.map( ( item ) => {
						const itemKey = item.path || item.slug;
						const isExcluded = excluded?.includes( itemKey );

						return (
							<div key={ itemKey } className="swish-folder-item">
								<label className={ item.active ? 'active-item' : '' }>
									<input
										type="checkbox"
										checked={ ! isExcluded }
										onChange={ () => {
											if ( isExcluded ) {
												onToggle( excluded.filter( ( e ) => e !== itemKey ) );
											} else {
												onToggle( [ ...( excluded || [] ), itemKey ] );
											}
										} }
									/>
									<span className="swish-folder-name">
										{ item.name }
										{ item.active && (
											<span className="swish-active-badge">
												{ __( 'Active', 'swish-migrate-and-backup' ) }
											</span>
										) }
									</span>
									<span className="swish-folder-size">
										{ formatBytes( item.size || 0 ) }
									</span>
								</label>

								{ /* Year folders with month subfolders */ }
								{ item.children && item.children.length > 0 && (
									<UploadYearFolder
										year={ item }
										excluded={ excluded }
										onToggle={ onToggle }
									/>
								) }
							</div>
						);
					} ) }
				</div>
			) }
		</div>
	);
};

/**
 * UploadYearFolder component for year/month folder structure.
 *
 * @param {Object}   props          - Component props.
 * @param {Object}   props.year     - Year folder data.
 * @param {Array}    props.excluded - Excluded paths.
 * @param {Function} props.onToggle - Toggle handler.
 * @return {JSX.Element} Component.
 */
const UploadYearFolder = ( { year, excluded, onToggle } ) => {
	const [ isExpanded, setIsExpanded ] = useState( false );

	if ( ! year.children || year.children.length === 0 ) {
		return null;
	}

	return (
		<div className="swish-year-folder">
			<button
				type="button"
				className="swish-year-toggle"
				onClick={ () => setIsExpanded( ! isExpanded ) }
			>
				<span className={ `dashicons dashicons-arrow-${ isExpanded ? 'down' : 'right' }-alt2` }></span>
				{ __( 'Show months', 'swish-migrate-and-backup' ) } ({ year.children.length })
			</button>

			{ isExpanded && (
				<div className="swish-month-folders">
					{ year.children.map( ( month ) => {
						const isExcluded = excluded?.includes( month.path );

						return (
							<label key={ month.path } className="swish-month-item">
								<input
									type="checkbox"
									checked={ ! isExcluded }
									onChange={ () => {
										if ( isExcluded ) {
											onToggle( excluded.filter( ( e ) => e !== month.path ) );
										} else {
											onToggle( [ ...( excluded || [] ), month.path ] );
										}
									} }
								/>
								<span>{ month.name }</span>
								<span className="swish-folder-size">
									{ formatBytes( month.size || 0 ) }
								</span>
							</label>
						);
					} ) }
				</div>
			) }
		</div>
	);
};

/**
 * SettingsModal component.
 *
 * @param {Object}   props            - Component props.
 * @param {Object}   props.settings   - Current settings.
 * @param {Function} props.onSave     - Save handler.
 * @param {Function} props.onClose    - Close handler.
 * @return {JSX.Element} Component.
 */
const SettingsModal = ( { settings, onSave, onClose } ) => {
	const [ formData, setFormData ] = useState( { ...settings } );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ folders, setFolders ] = useState( null );
	const [ isLoadingFolders, setIsLoadingFolders ] = useState( false );

	// Load folder structure when modal opens.
	useEffect( () => {
		const loadFolders = async () => {
			setIsLoadingFolders( true );
			try {
				const response = await apiFetch( {
					path: '/swish-backup/v1/folders',
				} );
				setFolders( response );
			} catch ( error ) {
				console.error( 'Failed to load folders:', error );
			}
			setIsLoadingFolders( false );
		};

		loadFolders();
	}, [] );

	const updateField = ( field, value ) => {
		setFormData( ( prev ) => ( { ...prev, [ field ]: value } ) );
	};

	const handleSave = async () => {
		setIsSaving( true );
		await onSave( formData );
		setIsSaving( false );
		onClose();
	};

	return (
		<div className="swish-modal-overlay">
			<div className="swish-modal swish-settings-modal">
				<div className="swish-modal-header">
					<h2>
						<span className="dashicons dashicons-admin-settings"></span>
						{ __( 'Backup Settings', 'swish-migrate-and-backup' ) }
					</h2>
					<button className="swish-modal-close" onClick={ onClose }>
						<span className="dashicons dashicons-no-alt"></span>
					</button>
				</div>

				<div className="swish-modal-body">
					<div className="swish-settings-section">
						<h3>{ __( 'Performance Settings', 'swish-migrate-and-backup' ) }</h3>
						<p className="description">
							{ __(
								'Adjust batch sizes to optimize performance for your hosting environment. Lower values are safer for shared hosting.',
								'swish-migrate-and-backup'
							) }
						</p>

						<div className="swish-setting-row">
							<label htmlFor="db_batch_size">
								{ __( 'Database Batch Size', 'swish-migrate-and-backup' ) }
								<span className="swish-setting-hint">
									{ __( '(rows per batch: 50-2000)', 'swish-migrate-and-backup' ) }
								</span>
							</label>
							<div className="swish-range-input">
								<input
									type="range"
									id="db_batch_size"
									min="50"
									max="2000"
									step="50"
									value={ formData.db_batch_size || 500 }
									onChange={ ( e ) =>
										updateField(
											'db_batch_size',
											parseInt( e.target.value, 10 )
										)
									}
								/>
								<span className="swish-range-value">
									{ formData.db_batch_size || 500 }
								</span>
							</div>
							<div className="swish-preset-buttons">
								<button
									type="button"
									className="button button-small"
									onClick={ () => updateField( 'db_batch_size', 100 ) }
								>
									{ __( 'Slow (100)', 'swish-migrate-and-backup' ) }
								</button>
								<button
									type="button"
									className="button button-small"
									onClick={ () => updateField( 'db_batch_size', 500 ) }
								>
									{ __( 'Balanced (500)', 'swish-migrate-and-backup' ) }
								</button>
								<button
									type="button"
									className="button button-small"
									onClick={ () => updateField( 'db_batch_size', 1000 ) }
								>
									{ __( 'Fast (1000)', 'swish-migrate-and-backup' ) }
								</button>
							</div>
						</div>

						<div className="swish-setting-row">
							<label htmlFor="file_batch_size">
								{ __( 'File Batch Size', 'swish-migrate-and-backup' ) }
								<span className="swish-setting-hint">
									{ __( '(files per batch: 25-500)', 'swish-migrate-and-backup' ) }
								</span>
							</label>
							<div className="swish-range-input">
								<input
									type="range"
									id="file_batch_size"
									min="25"
									max="500"
									step="25"
									value={ formData.file_batch_size || 100 }
									onChange={ ( e ) =>
										updateField(
											'file_batch_size',
											parseInt( e.target.value, 10 )
										)
									}
								/>
								<span className="swish-range-value">
									{ formData.file_batch_size || 100 }
								</span>
							</div>
							<div className="swish-preset-buttons">
								<button
									type="button"
									className="button button-small"
									onClick={ () => updateField( 'file_batch_size', 50 ) }
								>
									{ __( 'Slow (50)', 'swish-migrate-and-backup' ) }
								</button>
								<button
									type="button"
									className="button button-small"
									onClick={ () => updateField( 'file_batch_size', 100 ) }
								>
									{ __( 'Balanced (100)', 'swish-migrate-and-backup' ) }
								</button>
								<button
									type="button"
									className="button button-small"
									onClick={ () => updateField( 'file_batch_size', 250 ) }
								>
									{ __( 'Fast (250)', 'swish-migrate-and-backup' ) }
								</button>
							</div>
						</div>
					</div>

					<div className="swish-settings-section">
						<h3>{ __( 'Archive Format', 'swish-migrate-and-backup' ) }</h3>
						<p className="description">
							{ __(
								'Choose the archive format for backups.',
								'swish-migrate-and-backup'
							) }
						</p>
						<div className="swish-setting-row">
							<select
								id="archive_format"
								value={ formData.archive_format || 'auto' }
								onChange={ ( e ) =>
									updateField( 'archive_format', e.target.value )
								}
								className="swish-select-field"
							>
								<option value="auto">
									{ __( 'Auto (recommended)', 'swish-migrate-and-backup' ) }
								</option>
								<option value="zip">
									{ __( 'ZIP - Better for shared hosting, chunked processing', 'swish-migrate-and-backup' ) }
								</option>
								<option value="tar" disabled={ ! settings.tar_available }>
									{ __( 'TAR.GZ - Faster for large sites', 'swish-migrate-and-backup' ) }
									{ ! settings.tar_available && ' (' + __( 'not available', 'swish-migrate-and-backup' ) + ')' }
								</option>
							</select>
							<p className="description swish-archive-format-hint">
								{ formData.archive_format === 'zip' &&
									__( 'ZIP supports chunked processing and timeout recovery.', 'swish-migrate-and-backup' ) }
								{ formData.archive_format === 'tar' &&
									__( 'TAR.GZ uses system tar command for better performance.', 'swish-migrate-and-backup' ) }
								{ ( ! formData.archive_format || formData.archive_format === 'auto' ) &&
									__( 'Auto mode selects the best format based on your server environment.', 'swish-migrate-and-backup' ) }
							</p>
						</div>
					</div>

					<div className="swish-settings-section">
						<h3>{ __( 'Backup Contents', 'swish-migrate-and-backup' ) }</h3>
						<p className="description">
							{ __( 'Select what to include in backups. Click on each section to choose specific items.', 'swish-migrate-and-backup' ) }
						</p>

						<div className="swish-backup-contents">
							{ /* Database */ }
							<div className="swish-content-item">
								<label className="swish-content-toggle">
									<input
										type="checkbox"
										checked={ formData.backup_database !== false }
										onChange={ ( e ) =>
											updateField( 'backup_database', e.target.checked )
										}
									/>
									<span className="dashicons dashicons-database"></span>
									{ __( 'Database', 'swish-migrate-and-backup' ) }
								</label>
							</div>

							{ /* Plugins */ }
							<div className="swish-content-item">
								<label className="swish-content-toggle">
									<input
										type="checkbox"
										checked={ formData.backup_plugins !== false }
										onChange={ ( e ) =>
											updateField( 'backup_plugins', e.target.checked )
										}
									/>
									<span className="dashicons dashicons-admin-plugins"></span>
									{ __( 'Plugins', 'swish-migrate-and-backup' ) }
								</label>

								{ isLoadingFolders ? (
									<div className="swish-loading-folders">
										<span className="spinner is-active"></span>
									</div>
								) : (
									<FolderTree
										title={ __( 'Select plugins to include', 'swish-migrate-and-backup' ) }
										items={ folders?.plugins || [] }
										excluded={ formData.exclude_plugins || [] }
										onToggle={ ( newExcluded ) =>
											updateField( 'exclude_plugins', newExcluded )
										}
										masterEnabled={ formData.backup_plugins !== false }
									/>
								) }
							</div>

							{ /* Themes */ }
							<div className="swish-content-item">
								<label className="swish-content-toggle">
									<input
										type="checkbox"
										checked={ formData.backup_themes !== false }
										onChange={ ( e ) =>
											updateField( 'backup_themes', e.target.checked )
										}
									/>
									<span className="dashicons dashicons-admin-appearance"></span>
									{ __( 'Themes', 'swish-migrate-and-backup' ) }
								</label>

								{ isLoadingFolders ? (
									<div className="swish-loading-folders">
										<span className="spinner is-active"></span>
									</div>
								) : (
									<FolderTree
										title={ __( 'Select themes to include', 'swish-migrate-and-backup' ) }
										items={ folders?.themes || [] }
										excluded={ formData.exclude_themes || [] }
										onToggle={ ( newExcluded ) =>
											updateField( 'exclude_themes', newExcluded )
										}
										masterEnabled={ formData.backup_themes !== false }
									/>
								) }
							</div>

							{ /* Uploads */ }
							<div className="swish-content-item">
								<label className="swish-content-toggle">
									<input
										type="checkbox"
										checked={ formData.backup_uploads !== false }
										onChange={ ( e ) =>
											updateField( 'backup_uploads', e.target.checked )
										}
									/>
									<span className="dashicons dashicons-admin-media"></span>
									{ __( 'Uploads', 'swish-migrate-and-backup' ) }
								</label>

								{ isLoadingFolders ? (
									<div className="swish-loading-folders">
										<span className="spinner is-active"></span>
									</div>
								) : (
									<FolderTree
										title={ __( 'Select upload folders to include', 'swish-migrate-and-backup' ) }
										items={ folders?.uploads || [] }
										excluded={ formData.exclude_uploads || [] }
										onToggle={ ( newExcluded ) =>
											updateField( 'exclude_uploads', newExcluded )
										}
										masterEnabled={ formData.backup_uploads !== false }
									/>
								) }
							</div>

							{ /* Core Files */ }
							<div className="swish-content-item">
								<label className="swish-content-toggle">
									<input
										type="checkbox"
										checked={ formData.backup_core_files === true }
										onChange={ ( e ) =>
											updateField( 'backup_core_files', e.target.checked )
										}
									/>
									<span className="dashicons dashicons-wordpress"></span>
									{ __( 'WordPress Core Files', 'swish-migrate-and-backup' ) }
								</label>
								<p className="description swish-core-hint">
									{ __(
										'Excludes wp-admin, wp-includes by default.',
										'swish-migrate-and-backup'
									) }
								</p>
							</div>
						</div>
					</div>

					<div className="swish-settings-section">
						<h3>{ __( 'Hosting Presets', 'swish-migrate-and-backup' ) }</h3>
						<p className="description">
							{ __(
								'Quick presets for common hosting environments.',
								'swish-migrate-and-backup'
							) }
						</p>
						<div className="swish-preset-buttons hosting-presets">
							<button
								type="button"
								className="button"
								onClick={ () => {
									updateField( 'db_batch_size', 100 );
									updateField( 'file_batch_size', 50 );
								} }
							>
								{ __( 'Shared Hosting', 'swish-migrate-and-backup' ) }
							</button>
							<button
								type="button"
								className="button"
								onClick={ () => {
									updateField( 'db_batch_size', 500 );
									updateField( 'file_batch_size', 100 );
								} }
							>
								{ __( 'VPS / Managed', 'swish-migrate-and-backup' ) }
							</button>
							<button
								type="button"
								className="button"
								onClick={ () => {
									updateField( 'db_batch_size', 1000 );
									updateField( 'file_batch_size', 250 );
								} }
							>
								{ __( 'Dedicated Server', 'swish-migrate-and-backup' ) }
							</button>
						</div>
					</div>
				</div>

				<div className="swish-modal-footer">
					<button
						className="button"
						onClick={ onClose }
						disabled={ isSaving }
					>
						{ __( 'Cancel', 'swish-migrate-and-backup' ) }
					</button>
					<button
						className="button button-primary"
						onClick={ handleSave }
						disabled={ isSaving }
					>
						{ isSaving
							? __( 'Saving...', 'swish-migrate-and-backup' )
							: __( 'Save Settings', 'swish-migrate-and-backup' ) }
					</button>
				</div>
			</div>
		</div>
	);
};

export default SettingsModal;
