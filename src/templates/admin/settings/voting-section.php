<?php
/**
 * Voting configuration section partial for the admin settings page.
 *
 * Reads $data['auth_mode'] (string: 'password'|'token'),
 * $data['voting_ui_type'] (string: 'buttons'|'dropdown'), $data['password']
 * (string), $data['click_to_zoom'] (bool), and $data['score_matrix_str']
 * (string, comma-separated scores).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Voting Configuration', 'photo-competition-manager' ) . '</h2>';

echo '<p>';
echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'photo-competition-manager' ) . '</label><br />';
echo '<select id="voting_auth_mode" name="voting_auth_mode">';
echo '<option value="password"' . selected( $data['auth_mode'], 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'photo-competition-manager' ) . '</option>';
echo '<option value="token"' . selected( $data['auth_mode'], 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'photo-competition-manager' ) . '</option>';
echo '</select><br />';
echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="voting_ui_type">' . esc_html__( 'Voting UI Type', 'photo-competition-manager' ) . '</label><br />';
echo '<select id="voting_ui_type" name="voting_ui_type">';
echo '<option value="buttons"' . selected( $data['voting_ui_type'], 'buttons', false ) . '>' . esc_html__( 'Horizontal Score Buttons', 'photo-competition-manager' ) . '</option>';
echo '<option value="dropdown"' . selected( $data['voting_ui_type'], 'dropdown', false ) . '>' . esc_html__( 'Dropdown', 'photo-competition-manager' ) . '</option>';
echo '</select><br />';
echo '<span class="description">' . esc_html__( 'Choose how voters select scores. Buttons offer a quick, one-click experience, while dropdowns conserve vertical space.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $data['password'] ) . '" class="regular-text" />';
echo '<span class="description">' . esc_html__( 'Voters must enter this password before submitting votes. Leave blank to disable by default. Only used when auth mode is "Password-based". Passwords are not case-sensitive.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="click_image_to_zoom">';
echo '<input type="checkbox" id="click_image_to_zoom" name="click_image_to_zoom" value="1"' . checked( $data['click_to_zoom'], true, false ) . ' />';
echo ' ' . esc_html__( 'Click image to zoom on voting form', 'photo-competition-manager' );
echo '</label><br />';
echo '<span class="description">' . esc_html__( 'When enabled, images in the voting form can be clicked to open full-size in a new tab. When disabled, images are not clickable to prevent accidental navigation. Recommended: off for touch devices.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( $data['score_matrix_str'] ) . '" class="regular-text" />';
echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'photo-competition-manager' ) . '</span>';
echo '</p>';
