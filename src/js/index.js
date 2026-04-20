/**
 * Swish Backup Dashboard Entry Point.
 *
 * @package SwishMigrateAndBackup
 */

import { createRoot } from '@wordpress/element';
import { App } from './components';
import './styles.css';

/**
 * Initialize the dashboard app when DOM is ready.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'swish-backup-dashboard' );

	if ( container ) {
		createRoot( container ).render( <App /> );
	}
} );
