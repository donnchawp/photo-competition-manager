<?php
/**
 * Writes the three Playground blueprints from scripts/playground/seed.php.
 *
 * They differ only in where the plugin comes from. Run `make blueprints`
 * after editing the seed; CI fails if the blueprints are out of date.
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
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	// Two-space indents, like the hand-written blueprints were.
	return preg_replace_callback( '/^ +/m', static fn( $m ) => str_repeat( ' ', strlen( $m[0] ) / 2 ), $json ) . "\n";
};

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

// The workflow holds its blueprint in a YAML block scalar, between the
// `blueprint: |` line and the next line indented less than the block.
$workflow_file = $root . '/.github/workflows/pr-preview-publish.yml';
$workflow      = file_get_contents( $workflow_file );
$json          = $blueprint(
	array(
		'resource' => 'url',
		'url'      => '{{ARTIFACT_URL:photo-competition-manager}}',
	)
);
$indented      = preg_replace( '/^(?=.)/m', '        ', $json );
$updated       = preg_replace( '/^(      blueprint: \|\n)(?:(?:        .*)?\n)+/m', '$1' . addcslashes( $indented, '\\$' ), $workflow, 1, $count );
if ( 1 !== $count ) {
	fwrite( STDERR, "Couldn't find the blueprint block in $workflow_file\n" );
	exit( 1 );
}
file_put_contents( $workflow_file, $updated );
