/**
 * MigratePanel Component
 *
 * Migration panel for search and replace operations.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';

const MigratePanel = ( { siteUrl } ) => {
	const [ searchUrl, setSearchUrl ] = useState( '' );
	const [ replaceUrl, setReplaceUrl ] = useState( siteUrl || '' );
	const [ preview, setPreview ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	const handlePreview = async () => {
		if ( ! searchUrl || ! replaceUrl ) {
			setError( __( 'Please enter both search and replace URLs.', 'swish-migrate-and-backup' ) );
			return;
		}

		setLoading( true );
		setError( null );
		setPreview( null );

		try {
			const data = await api.searchReplace( searchUrl, replaceUrl, true );
			setPreview( data );
		} catch ( err ) {
			setError( err.message || __( 'Preview failed', 'swish-migrate-and-backup' ) );
		} finally {
			setLoading( false );
		}
	};

	const handleMigrate = async () => {
		if ( ! window.confirm(
			__( 'This will permanently modify your database. Are you sure you want to continue?', 'swish-migrate-and-backup' )
		) ) {
			return;
		}

		setLoading( true );
		setError( null );
		setResult( null );

		try {
			const data = await api.searchReplace( searchUrl, replaceUrl, false );
			setResult( data );
			setPreview( null );
		} catch ( err ) {
			setError( err.message || __( 'Migration failed', 'swish-migrate-and-backup' ) );
		} finally {
			setLoading( false );
		}
	};

	return (
		<div className="swish-migrate-panel">
			<h3>{ __( 'Search and Replace URLs', 'swish-migrate-and-backup' ) }</h3>
			<p className="description">
				{ __( 'Replace old URLs with new ones across your entire database. This is useful when migrating to a new domain.', 'swish-migrate-and-backup' ) }
			</p>

			<div className="swish-migrate-form">
				<div className="swish-form-row">
					<label htmlFor="search_url">
						{ __( 'Search for (old URL)', 'swish-migrate-and-backup' ) }
					</label>
					<input
						type="url"
						id="search_url"
						className="regular-text"
						placeholder="https://old-domain.com"
						value={ searchUrl }
						onChange={ ( e ) => setSearchUrl( e.target.value ) }
					/>
				</div>

				<div className="swish-form-row">
					<label htmlFor="replace_url">
						{ __( 'Replace with (new URL)', 'swish-migrate-and-backup' ) }
					</label>
					<input
						type="url"
						id="replace_url"
						className="regular-text"
						placeholder="https://new-domain.com"
						value={ replaceUrl }
						onChange={ ( e ) => setReplaceUrl( e.target.value ) }
					/>
				</div>

				<div className="swish-form-actions">
					<button
						className="button"
						onClick={ handlePreview }
						disabled={ loading || ! searchUrl || ! replaceUrl }
					>
						{ loading
							? __( 'Loading...', 'swish-migrate-and-backup' )
							: __( 'Preview Changes', 'swish-migrate-and-backup' ) }
					</button>
					{ preview && (
						<button
							className="button button-primary"
							onClick={ handleMigrate }
							disabled={ loading }
						>
							{ __( 'Run Migration', 'swish-migrate-and-backup' ) }
						</button>
					) }
				</div>
			</div>

			{ error && (
				<div className="swish-notice swish-notice-error">
					<p>{ error }</p>
				</div>
			) }

			{ preview && (
				<div className="swish-preview-results">
					<h4>{ __( 'Preview Results', 'swish-migrate-and-backup' ) }</h4>
					<p>
						{ __( 'Found', 'swish-migrate-and-backup' ) }{ ' ' }
						<strong>{ preview.total_replacements || 0 }</strong>{ ' ' }
						{ __( 'replacements across', 'swish-migrate-and-backup' ) }{ ' ' }
						<strong>{ preview.tables_affected || 0 }</strong>{ ' ' }
						{ __( 'tables.', 'swish-migrate-and-backup' ) }
					</p>

					{ preview.details && preview.details.length > 0 && (
						<table className="widefat">
							<thead>
								<tr>
									<th>{ __( 'Table', 'swish-migrate-and-backup' ) }</th>
									<th>{ __( 'Column', 'swish-migrate-and-backup' ) }</th>
									<th>{ __( 'Replacements', 'swish-migrate-and-backup' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ preview.details.slice( 0, 10 ).map( ( item, index ) => (
									<tr key={ index }>
										<td>{ item.table }</td>
										<td>{ item.column }</td>
										<td>{ item.count }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			) }

			{ result && (
				<div className="swish-notice swish-notice-success">
					<p>
						<strong>{ __( 'Migration completed!', 'swish-migrate-and-backup' ) }</strong>{ ' ' }
						{ __( 'Replaced', 'swish-migrate-and-backup' ) }{ ' ' }
						<strong>{ result.total_replacements || 0 }</strong>{ ' ' }
						{ __( 'occurrences.', 'swish-migrate-and-backup' ) }
					</p>
				</div>
			) }
		</div>
	);
};

export default MigratePanel;
