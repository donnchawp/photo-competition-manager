<?php
/**
 * Bootstrap the Club Competitions plugin.
 *
 * @package ClubCompetitions
 */

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

require_once __DIR__ . '/Support/Helpers.php';

spl_autoload_register(
	static function ( $class ) {
		$prefixes = array(
			'ClubCompetitions\\Admin\\'    => dirname( __DIR__ ) . '/admin/',
			'ClubCompetitions\\Frontend\\' => dirname( __DIR__ ) . '/public/',
			'ClubCompetitions\\Support\\'  => __DIR__ . '/Support/',
			'ClubCompetitions\\'           => __DIR__ . '/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );

			if ( 0 !== strncmp( $class, $prefix, $len ) ) {
				continue;
			}

			$relative_class = substr( $class, $len );
			$relative_path  = str_replace( '\\', '/', $relative_class );
			$path_parts     = explode( '/', $relative_path );
			$class_file     = array_pop( $path_parts );
			$path_prefix    = empty( $path_parts ) ? '' : implode( '/', $path_parts ) . '/';

			$candidates = array(
				$base_dir . $relative_path . '.php',
				$base_dir . $path_prefix . 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_file ) ) . '.php',
				$base_dir . $path_prefix . 'class-' . strtolower( $class_file ) . '.php',
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

use ClubCompetitions\Plugin;
use ClubCompetitions\Support\MailpitSMTP;

// Initialize Mailpit SMTP for development.
MailpitSMTP::init();

Plugin::instance()->register();
