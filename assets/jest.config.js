/**
 * Jest config: run the suites in the repository's tests/js directory.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	roots: [ '<rootDir>/../tests/js' ],
	// The suites live outside assets/, so point them at its packages.
	modulePaths: [ '<rootDir>/node_modules' ],
};
