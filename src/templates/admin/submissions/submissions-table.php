<?php
/**
 * Submissions table partial for the admin submissions page.
 *
 * Reads $data['competition_id'] (int) and $data['rows']: array of row
 * objects, each pre-formatted by the controller with: image_id, member_name,
 * show_upload_link, member_upload_url, category, full_url, thumb_url,
 * filename, random_number, total_score, vote_count, formatted_created_at.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" id="bulk-delete-form">';
wp_nonce_field( 'photo_competition_bulk_delete_' . $data['competition_id'], '_wpnonce' );
echo '<input type="hidden" name="action" value="bulk_delete_submissions" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';

echo '<div class="tablenav top">';
echo '<div class="alignleft actions">';
echo '<button type="submit" class="button photo-comp-bulk-delete" data-confirm="' . esc_attr( __( 'Are you sure you want to delete the selected submissions? This will permanently delete the database records, all associated votes, and all associated files (slideshow images, thumbnails, and originals). This action cannot be undone.', 'photo-competition-manager' ) ) . '" data-no-selection="' . esc_attr( __( 'Please select at least one submission to delete.', 'photo-competition-manager' ) ) . '">';
echo esc_html__( 'Delete Selected', 'photo-competition-manager' );
echo '</button>';
echo '</div>';
echo '</div>';

echo '<table class="widefat striped">';
echo '<thead><tr>';
echo '<td class="check-column"><input type="checkbox" id="cb-select-all" /></td>';
echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Category', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Image', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Filename', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Random #', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Total Score', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Votes', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Submitted', 'photo-competition-manager' ) . '</th>';
echo '</tr></thead>';
echo '<tbody>';

foreach ( $data['rows'] as $row ) {
	echo '<tr>';
	echo '<th scope="row" class="check-column"><input type="checkbox" name="image_ids[]" value="' . esc_attr( $row->image_id ) . '" /></th>';
	echo '<td>';
	echo esc_html( $row->member_name );
	// Add upload link for first submission of each member.
	if ( $row->show_upload_link ) {
		echo '<br><a href="' . esc_url( $row->member_upload_url ) . '" target="_blank" rel="noopener">';
		echo esc_html__( 'View/Edit Uploads', 'photo-competition-manager' );
		echo '</a>';
	}
	echo '</td>';

	// Category as plain text.
	echo '<td>' . esc_html( $row->category ) . '</td>';
	if ( $row->full_url ) {
		echo '<td class="photo-comp-thumbnail"><a href="' . esc_url( $row->full_url ) . '" target="_blank" rel="noopener noreferrer">';
		echo '<img src="' . esc_url( $row->thumb_url ) . '" alt="' . esc_attr( $row->filename ) . '" width="120" height="120" loading="lazy" />';
		echo '</a></td>';
	} else {
		echo '<td>' . esc_html__( 'Unavailable', 'photo-competition-manager' ) . '</td>';
	}
	echo '<td>' . esc_html( $row->filename ) . '</td>';
	echo '<td>' . esc_html( (string) $row->random_number ) . '</td>';
	echo '<td>' . esc_html( $row->total_score ) . '</td>';
	echo '<td>' . esc_html( (string) $row->vote_count ) . '</td>';
	echo '<td>' . esc_html( $row->formatted_created_at ) . '</td>';
	echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</form>';
