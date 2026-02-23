/**
 * Webpack configuration for Swish Backup.
 *
 * Extends the default @wordpress/scripts config to use our custom entry point.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( process.cwd(), 'src/js', 'index.js' ),
	},
};
