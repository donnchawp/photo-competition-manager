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
	define( 'PHOTO_COMPETITION_MANAGER_VERSION', '0.1.0' );
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

			$camel_case_variant = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_file ) );
			$camel_case_variant = str_replace( '_', '-', $camel_case_variant );
			$underscore_variant = strtolower( str_replace( '_', '-', $class_file ) );
			$lower_variant      = strtolower( $class_file );

			$candidates = array_unique(
				array(
					$base_dir . $relative_path . '.php',
					$base_dir . $path_prefix . 'class-' . $camel_case_variant . '.php',
					$base_dir . $path_prefix . 'class-' . $underscore_variant . '.php',
					$base_dir . $path_prefix . 'class-' . $lower_variant . '.php',
					$base_dir . $path_prefix . 'trait-' . $camel_case_variant . '.php',
					$base_dir . $path_prefix . 'trait-' . $underscore_variant . '.php',
					$base_dir . $path_prefix . 'trait-' . $lower_variant . '.php',
				)
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

// Initialize Mailpit SMTP for development.
Mailpit_SMTP::init();

Plugin::instance()->register();
