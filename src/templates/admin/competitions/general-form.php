<?php
/**
 * General details form partial for the competition edit screen.
 *
 * Reads $data['competition_id'] (int), $data['title'] (string),
 * $data['slug'] (string), $data['label_format'] (string): human-readable
 * date format label for the UI. $data['open_date_value'] (string): Y-m-d
 * value for the open-date input, or ''. $data['close_date_value'] (string):
 * Y-m-d value for the close-date input, or ''. $data['types'] (object[]):
 * the types to choose from, empty when the club has one. $data['type_id']
 * (int), $data['type_name'] (string): the competition's type.
 * $data['type_locked'] (bool): whether entries stop it changing.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
wp_nonce_field( 'photo_competition_update_' . $data['competition_id'], 'photo_competition_nonce' );
echo '<input type="hidden" name="photo_competition_action" value="update_competition" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';

echo '<p>';
echo '<label for="competition_title">' . esc_html__( 'Title', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required value="' . esc_attr( $data['title'] ) . '" />';
echo '<br /><span class="description">' . esc_html__( 'Add {grade} to show each entrant their own grade\'s name, for example "{grade} 2026".', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="competition_slug">' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" value="' . esc_attr( $data['slug'] ) . '" />';
echo '</p>';

if ( $data['types'] ) {
	echo '<p>';
	echo '<label for="competition_type">' . esc_html__( 'Type', 'photo-competition-manager' ) . '</label><br />';

	if ( $data['type_locked'] ) {
		echo '<strong>' . esc_html( $data['type_name'] ) . '</strong><br />';
		echo '<span class="description">' . esc_html__( 'A competition\'s type can\'t change once it has entries.', 'photo-competition-manager' ) . '</span>';
	} else {
		echo '<select id="competition_type" name="competition_type" aria-describedby="competition_type_help" required>';
		foreach ( $data['types'] as $type_row ) {
			echo '<option value="' . esc_attr( $type_row->id ) . '"' . selected( (int) $type_row->id, $data['type_id'], false ) . '>' . esc_html( $type_row->name ) . '</option>';
		}
		echo '</select><br />';
		echo '<span class="description" id="competition_type_help">' . esc_html__( 'Changing the type replaces this competition\'s categories and its Settings tab with the new type\'s, including any voting password, quotas and slideshow timings set here.', 'photo-competition-manager' ) . '</span>';
	}

	echo '</p>';
}

echo '<p>';
echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'photo-competition-manager' ) . ' (' . esc_html( $data['label_format'] ) . ')</label><br />';
echo '<input type="date" id="competition_open_date" name="competition_open_date" value="' . esc_attr( $data['open_date_value'] ) . '" />';
echo '</p>';

echo '<p>';
echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'photo-competition-manager' ) . ' (' . esc_html( $data['label_format'] ) . ')</label><br />';
echo '<input type="date" id="competition_close_date" name="competition_close_date" value="' . esc_attr( $data['close_date_value'] ) . '" />';
echo '</p>';

submit_button( __( 'Update Competition', 'photo-competition-manager' ) );

echo '</form>';
