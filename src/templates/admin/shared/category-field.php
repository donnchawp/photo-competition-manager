<?php
/**
 * Single category row partial (shared by the settings and competition settings forms).
 *
 * Reads $data['index'] (int), $data['label'] (string), $data['slug']
 * (string), $data['quota'] (int|string), and $data['quota_attributes']
 * (string): the quota input's escaped min, max and step.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="category-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

echo '<p style="margin: 5px 0;">';
echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" name="categories[' . esc_attr( $data['index'] ) . '][label]" value="' . esc_attr( $data['label'] ) . '" class="regular-text" required />';
echo '</p>';

echo '<p style="margin: 5px 0;">';
echo '<label>' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" name="categories[' . esc_attr( $data['index'] ) . '][slug]" value="' . esc_attr( $data['slug'] ) . '" class="regular-text" required />';
echo '</p>';

echo '<p style="margin: 5px 0;">';
echo '<label>' . esc_html__( 'Upload Quota', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" name="categories[' . esc_attr( $data['index'] ) . '][quota]" value="' . esc_attr( $data['quota'] ) . '" ' . $data['quota_attributes'] . ' class="small-text" required />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Competition_Settings::number_attributes().
echo '</p>';

echo '<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

echo '</div>';
