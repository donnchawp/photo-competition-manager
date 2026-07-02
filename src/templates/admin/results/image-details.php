<?php
/**
 * Image details partial for the admin results dashboard page.
 *
 * @package PhotoCompetitionManager
 *
 * $data['back_url']       string "Back to Results" URL.
 * $data['image_url']      string|null Thumbnail URL, null when unavailable.
 * $data['member_name']    string Member name, or the "Unknown" fallback when no member was found.
 * $data['member_email']   string Member email; empty when absent, in which case the Email row is skipped.
 * $data['member_grade']   string Member grade; only rendered when $data['has_member'] is true.
 * $data['has_member']     bool   Whether a member record was found.
 * $data['category_label'] string Category label.
 * $data['image_number']   int    Image's random submission number.
 * $data['statistics']     array{count:int,average:float,median:float,min:float,max:float,std_dev:float} Vote statistics.
 * $data['vote_rows']      array<int, array{voter_name: string, score: float, created_at: string}> Individual votes, pre-formatted; empty renders the "no votes" message instead of a table.
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="wrap photo-competition-manager-image-details">';
echo '<h1>' . esc_html__( 'Image Details', 'photo-competition-manager' ) . '</h1>';

echo '<p><a href="' . esc_url( $data['back_url'] ) . '" class="button">&larr; ' . esc_html__( 'Back to Results', 'photo-competition-manager' ) . '</a></p>';

echo '<div style="display: grid; grid-template-columns: 300px 1fr; gap: 30px; margin: 20px 0;">';

// Left column - Image.
echo '<div>';
if ( $data['image_url'] ) {
	echo '<img src="' . esc_url( $data['image_url'] ) . '" alt="' . esc_attr__( 'Competition Image', 'photo-competition-manager' ) . '" style="max-width: 100%; height: auto; border: 1px solid #ddd;">';
}

echo '<div style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-left: 4px solid #2271b1;">';
echo '<p><strong>' . esc_html__( 'Member:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $data['member_name'] ) . '</p>';
if ( ! empty( $data['member_email'] ) ) {
	echo '<p><strong>' . esc_html__( 'Email:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $data['member_email'] ) . '</p>';
}
if ( $data['has_member'] ) {
	echo '<p><strong>' . esc_html__( 'Grade:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $data['member_grade'] ) . '</p>';
}
echo '<p><strong>' . esc_html__( 'Category:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $data['category_label'] ) . '</p>';
echo '<p><strong>' . esc_html__( 'Image #:', 'photo-competition-manager' ) . '</strong><br>' . absint( $data['image_number'] ) . '</p>';
echo '</div>';

echo '</div>';

// Right column - Statistics and votes.
echo '<div>';

echo '<h2>' . esc_html__( 'Statistics', 'photo-competition-manager' ) . '</h2>';
echo '<table class="widefat" style="margin-bottom: 30px;">';
echo '<tr><th>' . esc_html__( 'Total Votes', 'photo-competition-manager' ) . '</th><td>' . absint( $data['statistics']['count'] ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Average Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $data['statistics']['average'], 2 ) ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Median Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $data['statistics']['median'], 2 ) ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Min Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $data['statistics']['min'], 0 ) ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Max Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $data['statistics']['max'], 0 ) ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Std Deviation', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $data['statistics']['std_dev'], 2 ) ) . '</td></tr>';
echo '</table>';

echo '<h2>' . esc_html__( 'Individual Votes', 'photo-competition-manager' ) . '</h2>';
if ( ! empty( $data['vote_rows'] ) ) {
	echo '<table class="wp-list-table widefat fixed striped">';
	echo '<thead>';
	echo '<tr>';
	echo '<th>' . esc_html__( 'Voter', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Score', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Timestamp', 'photo-competition-manager' ) . '</th>';
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';

	foreach ( $data['vote_rows'] as $vote_row ) {
		echo '<tr>';
		echo '<td>' . esc_html( $vote_row['voter_name'] ) . '</td>';
		echo '<td><strong>' . esc_html( number_format( (float) $vote_row['score'], 0 ) ) . '</strong></td>';
		echo '<td>' . esc_html( $vote_row['created_at'] ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';
} else {
	echo '<p>' . esc_html__( 'No votes recorded for this image.', 'photo-competition-manager' ) . '</p>';
}

echo '</div>';

echo '</div>'; // End grid.

echo '</div>';
