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
			$file           = $base_dir . $relative_path . '.php';

			if ( ! file_exists( $file ) ) {
				$path_parts   = explode( '/', $relative_path );
				$class_file   = array_pop( $path_parts );
				$hyphenated   = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_file ) );
				$alternate    = 'class-' . $hyphenated . '.php';
				$path_prefix  = empty( $path_parts ) ? '' : implode( '/', $path_parts ) . '/';
				$alternate_fp = $base_dir . $path_prefix . $alternate;

				if ( file_exists( $alternate_fp ) ) {
					$file = $alternate_fp;
				}
			}

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);

use ClubCompetitions\Plugin;
use ClubCompetitions\Support\MailpitSMTP;

// Initialize Mailpit SMTP for development.
MailpitSMTP::init();

Plugin::instance()->register();
