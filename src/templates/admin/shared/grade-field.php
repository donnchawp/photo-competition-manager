<?php
/**
 * Single grade row partial for the club settings form.
 *
 * Reads $data['index'] (int), $data['label'] (string) and $data['slug'] (string).
 * The slug rides along hidden so renaming a grade keeps its members in it.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

echo '<p style="margin: 5px 0;">';
echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" name="grades[' . esc_attr( $data['index'] ) . '][label]" value="' . esc_attr( $data['label'] ) . '" class="regular-text" required />';
echo '<input type="hidden" name="grades[' . esc_attr( $data['index'] ) . '][slug]" value="' . esc_attr( $data['slug'] ) . '" />';
echo '</p>';

echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

echo '</div>';
