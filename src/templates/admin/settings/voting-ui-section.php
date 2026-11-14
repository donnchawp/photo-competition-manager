<?php
/**
 * Voting form section partial for the admin settings page.
 *
 * Reads $data['voting_ui_type'] (string: 'buttons'|'dropdown').
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Voting Form', 'photo-competition-manager' ) . '</h2>';

echo '<p>';
echo '<label for="voting_ui_type">' . esc_html__( 'Voting UI Type', 'photo-competition-manager' ) . '</label><br />';
echo '<select id="voting_ui_type" name="voting_ui_type">';
echo '<option value="buttons"' . selected( $data['voting_ui_type'], 'buttons', false ) . '>' . esc_html__( 'Horizontal Score Buttons', 'photo-competition-manager' ) . '</option>';
echo '<option value="dropdown"' . selected( $data['voting_ui_type'], 'dropdown', false ) . '>' . esc_html__( 'Dropdown', 'photo-competition-manager' ) . '</option>';
echo '</select><br />';
echo '<span class="description">' . esc_html__( 'Choose how voters select scores. Buttons offer a quick, one-click experience, while dropdowns conserve vertical space.', 'photo-competition-manager' ) . '</span>';
echo '</p>';
