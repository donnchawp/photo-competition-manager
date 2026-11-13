<?php
/**
 * Create-competition form partial for the admin dashboard page.
 *
 * Reads $data['label_format'] (string): human-readable date format label
 * for the UI. $data['types'] (object[]): the types to choose from, empty
 * when the club has one.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" class="card" style="max-width: 720px; margin-bottom: 24px; padding: 16px;">';
echo '<h2>' . esc_html__( 'Create Competition', 'photo-competition-manager' ) . '</h2>';

wp_nonce_field( 'photo_competition_create', 'photo_competition_nonce' );
echo '<input type="hidden" name="photo_competition_action" value="create_competition" />';

echo '<p>';
echo '<label for="competition_title">' . esc_html__( 'Title', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required />';
echo '</p>';

echo '<p>';
echo '<label for="competition_slug">' . esc_html__( 'Slug (optional)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" />';
echo '</p>';

if ( $data['types'] ) {
	echo '<p>';
	echo '<label for="competition_type">' . esc_html__( 'Type', 'photo-competition-manager' ) . '</label><br />';
	echo '<select id="competition_type" name="competition_type" required>';
	echo '<option value="">' . esc_html__( 'Choose a type', 'photo-competition-manager' ) . '</option>';
	foreach ( $data['types'] as $type_row ) {
		echo '<option value="' . esc_attr( $type_row->id ) . '">' . esc_html( $type_row->name ) . '</option>';
	}
	echo '</select>';
	echo '</p>';
}

echo '<p>';
echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'photo-competition-manager' ) . ' (' . esc_html( $data['label_format'] ) . ')</label><br />';
echo '<input type="date" id="competition_open_date" name="competition_open_date" />';
echo '</p>';

echo '<p>';
echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'photo-competition-manager' ) . ' (' . esc_html( $data['label_format'] ) . ')</label><br />';
echo '<input type="date" id="competition_close_date" name="competition_close_date" />';
echo '</p>';

submit_button( __( 'Create Competition', 'photo-competition-manager' ) );

echo '</form>';
