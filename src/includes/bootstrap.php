<?php
/**
 * Bootstrap the Photo Competition Manager plugin.
 *
 * @package PhotoCompetitionManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin version.
if ( ! defined( 'PHOTO_COMPETITION_MANAGER_VERSION' ) ) {
	define( 'PHOTO_COMPETITION_MANAGER_VERSION', '0.3.0' );
}

// Define plugin directory and URL.
if ( ! defined( 'PHOTO_COMPETITION_MANAGER_DIR' ) ) {
	define( 'PHOTO_COMPETITION_MANAGER_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'PHOTO_COMPETITION_MANAGER_URL' ) ) {
	define( 'PHOTO_COMPETITION_MANAGER_URL', plugin_dir_url( dirname( __DIR__ ) . '/photo-competition-manager.php' ) );
}

// Load Composer autoloader if it exists.
$photo_competition_manager_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( file_exists( $photo_competition_manager_autoload ) ) {
	require_once $photo_competition_manager_autoload;
}

require_once __DIR__ . '/Support/class-helpers.php';

spl_autoload_register(
	static function ( $autoload_class ) {
		$prefixes = array(
			'PhotoCompetitionManager\\Admin\\'    => dirname( __DIR__ ) . '/admin/',
			'PhotoCompetitionManager\\Frontend\\' => dirname( __DIR__ ) . '/public/',
			'PhotoCompetitionManager\\Support\\'  => __DIR__ . '/Support/',
			'PhotoCompetitionManager\\'           => __DIR__ . '/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );

			if ( 0 !== strncmp( $autoload_class, $prefix, $len ) ) {
				continue;
			}

			$relative_class = substr( $autoload_class, $len );
			$relative_path  = str_replace( '\\', '/', $relative_class );
			$path_parts     = explode( '/', $relative_path );
			$class_file     = array_pop( $path_parts );
			$path_prefix    = empty( $path_parts ) ? '' : implode( '/', $path_parts ) . '/';

			// Class/trait files follow WordPress naming: Foo_Bar => class-foo-bar.php.
			$file_slug = strtolower( str_replace( '_', '-', $class_file ) );

			$candidates = array(
				$base_dir . $path_prefix . 'class-' . $file_slug . '.php',
				$base_dir . $path_prefix . 'trait-' . $file_slug . '.php',
			);

			foreach ( $candidates as $file ) {
				if ( file_exists( $file ) ) {
					require_once $file;
					return;
				}
			}
		}
	}
);

use PhotoCompetitionManager\Plugin;
use PhotoCompetitionManager\Support\Mailpit_SMTP;
use PhotoCompetitionManager\Support\Email_Configuration;

// Initialize Mailpit SMTP for development.
Mailpit_SMTP::init();

// Initialize global email configuration.
Email_Configuration::init();

( new Plugin() )->register();
