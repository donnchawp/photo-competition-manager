/**
 * Jest config: run the suites in the repository's tests/js directory.
 *
 * The preset is resolved from this directory's node_modules because
 * rootDir points at the repository root, which has no node_modules.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	preset: path.dirname(
		require.resolve( '@wordpress/jest-preset-default/jest-preset.js' )
	),
	rootDir: path.resolve( __dirname, '..' ),
	roots: [ '<rootDir>/tests/js' ],
};
