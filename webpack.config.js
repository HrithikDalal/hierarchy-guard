/**
 * One bundle per screen: the block editor panel, the list screen, the classic editor.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		classic: './src/classic/index.js',
	},
};
