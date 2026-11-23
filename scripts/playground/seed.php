<?php
/**
 * Playground seed: the example club every blueprint boots with.
 *
 * Adds pages for the plugin's shortcodes, three members (one per default
 * grade), a past competition with published results, and a competition
 * open from today for a week. Ann and Bob have entered both; Cat hasn't
 * entered the open one, so the upload page can be tried from scratch.
 * Entries are generated test cards, labelled with the member and
 * category, so there's nothing to download or license.
 *
 * This file is the source. `make blueprints` embeds it in blueprint.json
 * and .wordpress-org/blueprints/blueprint.json, and the PR preview build
 * embeds it in that PR's blueprint. The wordpress.org copy runs against
 * the released plugin, so only use APIs the latest release has.
 *
 * @package PhotoCompetitionManager
 */

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

// The seed's entries are local files, not HTTP uploads. The filter lets
// them through for this request only.
add_filter( 'photo_competition_manager_is_uploaded_file', '__return_true' );

// The plugin only uses its own sizes, so skip WordPress's media sizes.
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Support\Competition_Settings;

$pcm_seed_fail = static function ( $result, string $what ) {
	if ( is_wp_error( $result ) || ! $result ) {
		throw new RuntimeException( 'Playground seed, ' . $what . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'failed' ) );
	}
	return $result;
};

$pcm_pages = array(
	'Upload'     => '[competition_upload]',
	'Voting'     => '[competition_voting]',
	'Results'    => '[competition_results]',
	'My Entries' => '[competition_my_entries]',
);
foreach ( $pcm_pages as $pcm_title => $pcm_shortcode ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $pcm_title,
			'post_content' => $pcm_shortcode,
		)
	);
}

$pcm_members = array();
$pcm_repo    = new Members_Repository();
// Name, email, grade, and the hue of their entries.
foreach ( array(
	array( 'Ann Beginner', 'ann@example.com', 'beginner', 20 ),
	array( 'Bob Intermediate', 'bob@example.com', 'intermediate', 200 ),
	array( 'Cat Advanced', 'cat@example.com', 'advanced', 290 ),
) as list( $pcm_name, $pcm_email, $pcm_grade, $pcm_hue ) ) {
	$pcm_members[ $pcm_name ] = array(
		'id'  => (int) $pcm_seed_fail(
			$pcm_repo->create(
				array(
					'name'  => $pcm_name,
					'email' => $pcm_email,
					'grade' => $pcm_grade,
				)
			),
			'member ' . $pcm_name
		),
		'hue' => $pcm_hue,
	);
}

$pcm_type         = ( new Competition_Types_Repository() )->all()[0];
$pcm_competitions = new Competitions_Repository();
$pcm_create       = static function ( string $title, string $slug, int $open, int $close ) use ( $pcm_competitions, $pcm_type, $pcm_seed_fail ): object {
	$id = (int) $pcm_seed_fail(
		$pcm_competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => wp_date( 'Y-m-d', $open ),
				'close_date' => wp_date( 'Y-m-d', $close ),
				'share_hash' => Competition_Settings::generate_share_hash(),
				'type_id'    => (int) $pcm_type->id,
				'settings'   => Competition_Settings::settings_for_new_competition( $pcm_type ),
			)
		),
		'competition ' . $title
	);
	return $pcm_competitions->find( $id );
};

// A 1200×800 card in the member's colour, labelled so testers can tell entries apart.
$pcm_card = static function ( string $label, int $hue, bool $mono ): array {
	$img = imagecreatetruecolor( 1200, 800 );
	for ( $y = 0; $y < 800; $y += 4 ) {
		$light = 0.35 + 0.4 * $y / 800;
		list( $r, $g, $b ) = array_map(
			static fn( $n ) => (int) round( 255 * max( 0, min( 1, $light + 0.35 * cos( deg2rad( $hue - $n ) ) ) ) ),
			array( 0, 120, 240 )
		);
		imagefilledrectangle( $img, 0, $y, 1199, $y + 3, imagecolorallocate( $img, $r, $g, $b ) );
	}
	for ( $i = 0; $i < 6; $i++ ) {
		$shade = mt_rand( 0, 255 );
		imagefilledellipse( $img, mt_rand( 0, 1200 ), mt_rand( 0, 800 ), mt_rand( 80, 320 ), mt_rand( 80, 320 ), imagecolorallocatealpha( $img, $shade, 255 - $shade, mt_rand( 0, 255 ), 60 ) );
	}
	// The built-in font is 9px wide; draw small, then scale up.
	$text = imagecreatetruecolor( 9 * strlen( $label ) + 8, 24 );
	imagefill( $text, 0, 0, imagecolorallocate( $text, 0, 0, 0 ) );
	imagestring( $text, 5, 4, 4, $label, imagecolorallocate( $text, 255, 255, 255 ) );
	$scale = min( 6, 1100 / imagesx( $text ) );
	$w     = (int) ( imagesx( $text ) * $scale );
	$h     = (int) ( 24 * $scale );
	imagecopyresized( $img, $text, (int) ( ( 1200 - $w ) / 2 ), (int) ( ( 800 - $h ) / 2 ), 0, 0, $w, $h, imagesx( $text ), 24 );
	if ( $mono ) {
		imagefilter( $img, IMG_FILTER_GRAYSCALE );
	}
	$tmp = wp_tempnam( 'pcm-seed' ) . '.jpg';
	imagejpeg( $img, $tmp, 85 );
	return array(
		'name'     => sanitize_file_name( $label ) . '.jpg',
		'tmp_name' => $tmp,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $tmp ),
	);
};

$pcm_entries = new Entries();
// Enters each member once per category; returns entry IDs by category.
$pcm_enter = static function ( object $competition, array $names ) use ( $pcm_entries, $pcm_members, $pcm_card, $pcm_seed_fail ): array {
	$by_category = array();
	foreach ( Competition_Settings::get_categories( Competition_Settings::parse( $competition->settings ) ) as $category ) {
		foreach ( $names as $name ) {
			$file = $pcm_card( strtok( $name, ' ' ) . ' - ' . $category['label'], $pcm_members[ $name ]['hue'], 'colour' !== $category['slug'] );

			$by_category[ $category['slug'] ][] = (int) $pcm_seed_fail(
				$pcm_entries->add( Actor::admin(), (int) $competition->id, $pcm_members[ $name ]['id'], $category['slug'], $file ),
				'entry for ' . $name
			);
		}
	}
	return $by_category;
};

mt_srand( 283 );

// Last month's competition: everyone entered, three voters scored, results published.
$pcm_past = $pcm_create( 'Last month', 'last-month', strtotime( '-35 days' ), strtotime( '-30 days' ) );
$pcm_votes = new Votes_Repository();
foreach ( $pcm_enter( $pcm_past, array_keys( $pcm_members ) ) as $pcm_category => $pcm_entry_ids ) {
	foreach ( array( 'Voter One', 'Voter Two', 'Voter Three' ) as $pcm_voter ) {
		$pcm_seed_fail(
			$pcm_votes->create_ballot( (int) $pcm_past->id, $pcm_category, $pcm_voter, array_combine( $pcm_entry_ids, array_map( static fn() => mt_rand( 4, 9 ), $pcm_entry_ids ) ) ),
			'ballot'
		);
	}
}
$pcm_workflow = new Competition_Workflow();
$pcm_seed_fail( $pcm_workflow->close_uploads( (int) $pcm_past->id ), 'close uploads' );
$pcm_seed_fail( $pcm_workflow->publish_results( (int) $pcm_past->id ), 'publish results' );

// This month's competition, open for a week. Cat hasn't entered yet.
$pcm_enter( $pcm_create( 'Test competition', 'test-competition', time(), strtotime( '+7 days' ) ), array( 'Ann Beginner', 'Bob Intermediate' ) );
