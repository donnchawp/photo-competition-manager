<?php
/**
 * Seed script: creates members, a competition, and uploads test images for voting.
 *
 * Usage: wp eval-file /path/to/seed-voting-data.php
 *
 * @package PhotoCompetitionManager
 */

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * 1. Resolve grades and categories from saved settings.
 * ----------------------------------------------------------------*/

$saved_settings = get_option( 'photo_comp_default_settings', '' );
$settings       = Competition_Settings::parse( $saved_settings );
$grades         = Competition_Settings::get_grades( $settings );
$categories     = Competition_Settings::get_categories( $settings );

if ( empty( $grades ) || empty( $categories ) ) {
	WP_CLI::error( 'No grades or categories configured. Save default settings first.' );
}

$grade_slugs = array_column( $grades, 'slug' );

/* ------------------------------------------------------------------
 * 2. Create 12 members with random names, evenly spread over grades.
 * ----------------------------------------------------------------*/

$first_names = array(
	'Aoife', 'Cian', 'Niamh', 'Oisin', 'Saoirse', 'Fionn',
	'Ciara', 'Roisin', 'Darragh', 'Sinead', 'Eoin', 'Maeve',
	'Conor', 'Siobhan', 'Padraig', 'Orla', 'Cathal', 'Aisling',
);

$last_names = array(
	'Murphy', 'Kelly', 'Byrne', 'Ryan', 'O\'Sullivan', 'Walsh',
	'O\'Brien', 'Doyle', 'Lynch', 'McCarthy', 'Gallagher', 'Daly',
	'O\'Neill', 'Brennan', 'Burke', 'Maguire', 'Kavanagh', 'Duffy',
);

$members_repo = new Members_Repository();
$created      = 0;

for ( $i = 0; $i < 12; $i++ ) {
	$name  = $first_names[ array_rand( $first_names ) ] . ' ' . $last_names[ array_rand( $last_names ) ];
	$email = sanitize_title( $name ) . '-' . wp_rand( 100, 999 ) . '@example.com';
	$grade = $grade_slugs[ $i % count( $grade_slugs ) ];

	$result = $members_repo->create(
		array(
			'name'      => $name,
			'email'     => $email,
			'grade'     => $grade,
			'active'    => 1,
			'committee' => 0,
		)
	);

	if ( ! is_wp_error( $result ) ) {
		++$created;
		WP_CLI::log( sprintf( '  + Member: %s (%s)', $name, $grade ) );
	} else {
		WP_CLI::warning( sprintf( 'Could not create member "%s": %s', $name, $result->get_error_message() ) );
	}
}

WP_CLI::success( sprintf( '%d members created.', $created ) );

/* ------------------------------------------------------------------
 * 3. Ensure an open competition exists.
 * ----------------------------------------------------------------*/

$competitions_repo = new Competitions_Repository();
$competition       = $competitions_repo->find_current_active();

if ( $competition && $competitions_repo->is_open( $competition ) ) {
	WP_CLI::log( sprintf( 'Using existing open competition: %s', $competition->title ) );
} else {
	$yesterday = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
	$in_5_days = gmdate( 'Y-m-d 23:59:59', strtotime( '+5 days' ) );

	$comp_settings                          = $settings;
	$comp_settings['upload']['uploads_closed'] = false;

	$comp_id = $competitions_repo->create(
		array(
			'title'     => 'Test Competition ' . gmdate( 'M Y' ),
			'slug'      => 'test-' . gmdate( 'Y-m' ),
			'open_date' => $yesterday,
			'close_date' => $in_5_days,
			'settings'  => $comp_settings,
		)
	);

	if ( is_wp_error( $comp_id ) ) {
		WP_CLI::error( 'Could not create competition: ' . $comp_id->get_error_message() );
	}

	$competition = $competitions_repo->find( $comp_id );
	WP_CLI::success( sprintf( 'Created competition: %s (open %s to %s)', $competition->title, $yesterday, $in_5_days ) );
}

/* ------------------------------------------------------------------
 * 4. Create a small test JPEG in memory for uploads.
 * ----------------------------------------------------------------*/

/**
 * Generate a unique coloured test JPEG in a temp file.
 *
 * @return string Path to temporary JPEG file.
 */
function photo_comp_create_test_jpeg() {
	$img = imagecreatetruecolor( 800, 600 );
	// Random pastel background so each image is visually distinct.
	$r  = wp_rand( 80, 220 );
	$g  = wp_rand( 80, 220 );
	$b  = wp_rand( 80, 220 );
	$bg = imagecolorallocate( $img, $r, $g, $b );
	imagefill( $img, 0, 0, $bg );

	// Add some visual interest - random shapes.
	for ( $i = 0; $i < 5; $i++ ) {
		$color = imagecolorallocate( $img, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) );
		imagefilledellipse( $img, wp_rand( 0, 800 ), wp_rand( 0, 600 ), wp_rand( 50, 200 ), wp_rand( 50, 200 ), $color );
	}

	$tmp = wp_tempnam( 'photo-comp-test-' ) . '.jpg';
	imagejpeg( $img, $tmp, 80 );
	imagedestroy( $img );

	return $tmp;
}

/* ------------------------------------------------------------------
 * 5. Upload one image per member per category.
 * ----------------------------------------------------------------*/

$images_repo   = new Images_Repository();
$comp_settings = Competition_Settings::parse( $competition->settings );
$upload_count  = 0;

// Get all members (including the ones we just created).
$all_members = $members_repo->all( 10000, true );

foreach ( $all_members as $member ) {
	foreach ( $categories as $cat ) {
		$slug  = $cat['slug'];
		$quota = $cat['quota'] ?? 1;

		// Check existing uploads.
		$existing = $images_repo->count_by_member_category( (int) $competition->id, (int) $member->id, $slug );
		if ( $existing >= $quota ) {
			continue;
		}

		$counter  = $existing + 1;
		$username = sanitize_title( $member->name );

		// Build the upload directory.
		$wp_upload = wp_upload_dir();
		$base_dir  = trailingslashit( $wp_upload['basedir'] ) . 'competitions';
		$comp_dir  = trailingslashit( $base_dir ) . sanitize_file_name( $competition->slug );
		$cat_dir   = trailingslashit( $comp_dir ) . sanitize_file_name( $slug );

		if ( ! file_exists( $cat_dir ) ) {
			wp_mkdir_p( $cat_dir );
			file_put_contents( trailingslashit( $cat_dir ) . 'index.php', '<?php // Silence is golden.' ); // phpcs:ignore
		}

		// Generate filename and test image.
		$filename = sprintf( '%s-%s-%d.jpg', $username, sanitize_title( $slug ), $counter );
		$tmp_file = photo_comp_create_test_jpeg();

		// Copy to slideshow location.
		copy( $tmp_file, trailingslashit( $cat_dir ) . $filename );

		// Generate thumbnail.
		$thumb_file = str_replace( '.jpg', '-thumb.jpg', $filename );
		$thumb_img  = imagecreatefromjpeg( $tmp_file );
		$thumb_resized = imagescale( $thumb_img, 400 );
		imagejpeg( $thumb_resized, trailingslashit( $cat_dir ) . $thumb_file, 80 );
		imagedestroy( $thumb_img );
		imagedestroy( $thumb_resized );

		// Clean up temp file.
		wp_delete_file( $tmp_file );

		// Create database record.
		$image_id = $images_repo->create(
			array(
				'competition_id' => (int) $competition->id,
				'member_id'      => (int) $member->id,
				'category'       => $slug,
				'filename'       => $filename,
			)
		);

		if ( ! is_wp_error( $image_id ) ) {
			++$upload_count;
		} else {
			WP_CLI::warning( sprintf( 'Failed upload for %s/%s: %s', $member->name, $slug, $image_id->get_error_message() ) );
		}
	}
}

WP_CLI::success( sprintf( '%d test images uploaded across %d categories. Ready for voting.', $upload_count, count( $categories ) ) );
