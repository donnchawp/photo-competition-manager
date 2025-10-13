<?php
/**
 * Bootstrap the Club Competitions plugin.
 *
 * @package ClubCompetitions
 */

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

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
