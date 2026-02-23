/**
 * Swish Backup React Dashboard
 *
 * Main entry point for the React admin dashboard.
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

// Mount the React app when DOM is ready.
document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'swish-backup-dashboard' );

	if ( container ) {
		const root = createRoot( container );
		root.render( <App /> );
	}
} );
