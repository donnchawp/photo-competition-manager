<?php
/**
 * Writes the Playground blueprints from scripts/playground/seed.php.
 *
 * With no argument it writes blueprint.json and the wordpress.org Live
 * Preview blueprint: run `make blueprints` after editing the seed, and CI
 * fails if they're out of date. With a path it writes only the PR preview
 * blueprint there, which the PR Preview build bundles with its zip.
 *
 * @package PhotoCompetitionManager
 */

$root = dirname( __DIR__, 2 );
$seed = file_get_contents( __DIR__ . '/seed.php' );

$blueprint = static function ( array $plugin ) use ( $seed ): string {
	$data = array(
		'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
		'landingPage'       => '/wp-admin/admin.php?page=photo-competition-manager',
		'preferredVersions' => array(
			'php' => '8.3',
			'wp'  => 'latest',
		),
		'steps'             => array(
			array(
				'step'     => 'login',
				'username' => 'admin',
			),
			array(
				'step'       => 'installPlugin',
				'pluginData' => $plugin,
				'options'    => array( 'activate' => true ),
			),
			array(
				'step' => 'runPHP',
				'code' => $seed,
			),
		),
	);
	return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
};

if ( isset( $argv[1] ) ) {
	file_put_contents(
		$argv[1],
		$blueprint(
			array(
				'resource' => 'url',
				'url'      => '{{ARTIFACT_URL:photo-competition-manager}}',
			)
		)
	);
	exit;
}

file_put_contents(
	$root . '/blueprint.json',
	$blueprint(
		array(
			'resource' => 'url',
			'url'      => 'https://github.com/donnchawp/photo-competition-manager/releases/download/ci-artifacts/main-photo-competition-manager.zip',
		)
	)
);

file_put_contents(
	$root . '/.wordpress-org/blueprints/blueprint.json',
	$blueprint(
		array(
			'resource' => 'wordpress.org/plugins',
			'slug'     => 'photo-competition-manager',
		)
	)
);
