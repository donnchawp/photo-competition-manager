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
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);

use ClubCompetitions\Plugin;

Plugin::instance()->register();
