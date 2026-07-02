<?php
/**
 * Single grade row partial for the competition settings tab.
 *
 * Reads $data['index'] (int) and $data['label'] (string).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

echo '<p style="margin: 5px 0;">';
echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" name="grades[' . esc_attr( $data['index'] ) . '][label]" value="' . esc_attr( $data['label'] ) . '" class="regular-text" required />';
echo '</p>';

echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

echo '</div>';
