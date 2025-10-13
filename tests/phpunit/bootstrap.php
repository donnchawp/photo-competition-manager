<?php
/**
 * PHPUnit bootstrap for Club Competitions.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$env_root = getenv( 'WP_ENV_ROOT' );

	if ( ! $env_root ) {
		$home = getenv( 'HOME' );
		$dirs = glob( rtrim( $home, '/' ) . '/.wp-env/*', GLOB_ONLYDIR );

		if ( ! empty( $dirs ) ) {
			sort( $dirs, SORT_NATURAL );
			$env_root = array_pop( $dirs );
		}
	}

	$phpunit_root = $env_root ? rtrim( $env_root, '/' ) . '/WordPress-PHPUnit/tests/phpunit' : '';

	if ( $phpunit_root && file_exists( $phpunit_root . '/includes/functions.php' ) ) {
		$_tests_dir = $phpunit_root;
		putenv( 'WP_TESTS_DIR=' . $_tests_dir );
	} else {
		$_tests_dir = sys_get_temp_dir() . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress tests library in {$_tests_dir}\n";
	exit( 1 );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . WP_TESTS_CONFIG_FILE_PATH );

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/club-competitions/includes/bootstrap.php';

		// Run activation to create database tables.
		\ClubCompetitions\Install\Activator::activate();
	}
);

require $_tests_dir . '/includes/bootstrap.php';
