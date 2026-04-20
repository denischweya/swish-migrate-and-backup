/**
 * SettingsModal component.
 *
 * @package SwishMigrateAndBackup
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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
						<h3>{ __( 'Backup Contents', 'swish-migrate-and-backup' ) }</h3>
						<div className="swish-checkbox-group">
							<label>
								<input
									type="checkbox"
									checked={ formData.backup_database !== false }
									onChange={ ( e ) =>
										updateField( 'backup_database', e.target.checked )
									}
								/>
								{ __( 'Database', 'swish-migrate-and-backup' ) }
							</label>
							<label>
								<input
									type="checkbox"
									checked={ formData.backup_plugins !== false }
									onChange={ ( e ) =>
										updateField( 'backup_plugins', e.target.checked )
									}
								/>
								{ __( 'Plugins', 'swish-migrate-and-backup' ) }
							</label>
							<label>
								<input
									type="checkbox"
									checked={ formData.backup_themes !== false }
									onChange={ ( e ) =>
										updateField( 'backup_themes', e.target.checked )
									}
								/>
								{ __( 'Themes', 'swish-migrate-and-backup' ) }
							</label>
							<label>
								<input
									type="checkbox"
									checked={ formData.backup_uploads !== false }
									onChange={ ( e ) =>
										updateField( 'backup_uploads', e.target.checked )
									}
								/>
								{ __( 'Uploads', 'swish-migrate-and-backup' ) }
							</label>
						</div>
						<div className="swish-checkbox-group swish-core-files-option">
							<label>
								<input
									type="checkbox"
									checked={ formData.backup_core_files === true }
									onChange={ ( e ) =>
										updateField( 'backup_core_files', e.target.checked )
									}
								/>
								{ __( 'WordPress Core Files', 'swish-migrate-and-backup' ) }
							</label>
							<p className="description swish-core-files-hint">
								{ __(
									'Excludes wp-admin, wp-includes, and core root files by default. The target site already has WordPress installed.',
									'swish-migrate-and-backup'
								) }
							</p>
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
