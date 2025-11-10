<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Photo_Competition_Manager
 */

$photo_competition_manager_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $photo_competition_manager_tests_dir ) {
	$photo_competition_manager_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$photo_competition_manager_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $photo_competition_manager_phpunit_polyfills_path ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP_TESTS_PHPUNIT_POLYFILLS_PATH is a PHPUnit constant.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $photo_competition_manager_phpunit_polyfills_path );
}

if ( ! file_exists( "{$photo_competition_manager_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$photo_competition_manager_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$photo_competition_manager_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function photo_competition_manager_manually_load_plugin() {
	require dirname( __DIR__ ) . '/photo-competition-manager.php';
}

tests_add_filter( 'muplugins_loaded', 'photo_competition_manager_manually_load_plugin' );

// Start up the WP testing environment.
require "{$photo_competition_manager_tests_dir}/includes/bootstrap.php";
