/**
 * One bundle per screen: the block editor panel, the list screen, the
 * classic editor, and the Redirects tab of Settings › Hierarchy Guard.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: './src/editor/index.js',
		'list-table': './src/list-table/index.js',
		classic: './src/classic/index.js',
		redirects: './src/redirects/index.js',
	},
};
