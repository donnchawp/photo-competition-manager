<?php
/**
 * Project-specific WordPress test configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	$env_root      = getenv( 'WP_ENV_ROOT' );
	$tests_env_dir = getenv( 'WP_ENV_TESTS_ROOT' );

	if ( ! $env_root ) {
		$home = getenv( 'HOME' );
		$dirs = glob( rtrim( $home, '/' ) . '/.wp-env/*', GLOB_ONLYDIR );

	if ( empty( $dirs ) ) {
		throw new \RuntimeException( 'wp-env directory not found. Start wp-env before running PHPUnit.' );
	}

		sort( $dirs, SORT_NATURAL );
		$env_root = array_pop( $dirs );
	}

	if ( ! $tests_env_dir ) {
		$tests_env_dir = $env_root;
	}

	$wordpress_path = rtrim( $tests_env_dir, '/' ) . '/tests-WordPress/';

	if ( ! file_exists( $wordpress_path . 'wp-settings.php' ) ) {
		$wordpress_path = rtrim( $env_root, '/' ) . '/tests-WordPress/';
	}

	if ( ! file_exists( $wordpress_path . 'wp-settings.php' ) ) {
		throw new \RuntimeException(
			'WordPress core for tests not found. Expected wp-settings.php under tests-WordPress/. Ensure wp-env is running.'
		);
	}

	define( 'ABSPATH', $wordpress_path );
}

define( 'WP_CORE_DIR', ABSPATH );

$table_prefix = 'wptests_';

define( 'DB_NAME', getenv( 'WP_ENV_TESTS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'password' );
define( 'DB_HOST', getenv( 'WP_ENV_TEST_DB_HOST' ) ?: '127.0.0.1:62159' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Club Competitions Tests' );

define( 'WP_PHP_BINARY', getenv( 'PHP_BINARY' ) ?: 'php' );
define( 'WPLANG', '' );
