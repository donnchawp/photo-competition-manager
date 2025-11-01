/**
 * WordPress Scripts webpack config override.
 *
 * @package PhotoCompetitionManager
 */

const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve(process.cwd(), 'src', 'index.js'),
		'drag-drop-upload': path.resolve(process.cwd(), 'src', 'drag-drop-upload.js'),
		'submission-category': path.resolve(process.cwd(), 'src', 'submission-category.js'),
		'admin-submission-category': path.resolve(process.cwd(), 'src', 'admin-submission-category.js'),
	},
};
