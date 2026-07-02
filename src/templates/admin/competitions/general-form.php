<?php
/**
 * General details form partial for the competition edit screen.
 *
 * Reads $data['competition_id'] (int), $data['title'] (string),
 * $data['slug'] (string), $data['label_format'] (string): human-readable
 * date format label for the UI. $data['open_date_value'] (string): Y-m-d
 * value for the open-date input, or ''. $data['close_date_value'] (string):
 * Y-m-d value for the close-date input, or ''.
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
echo '</p>';

echo '<p>';
echo '<label for="competition_slug">' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" value="' . esc_attr( $data['slug'] ) . '" />';
echo '</p>';

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
