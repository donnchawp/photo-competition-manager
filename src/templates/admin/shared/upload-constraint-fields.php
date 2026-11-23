<?php
/**
 * Upload constraint fields, shared by the competition type form and the
 * competition settings form. Each caller prints its own heading.
 *
 * Reads $data['max_file_size_mb'], $data['max_width'], and
 * $data['max_height'] (all int|string), and $data['attributes']
 * (array<string, string>): each input's escaped min, max and step.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<p>';
echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" ' . $data['attributes']['max_file_size_mb'] . ' value="' . esc_attr( $data['max_file_size_mb'] ) . '" class="small-text" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Competition_Settings::number_attributes().
echo '</p>';

echo '<p class="description">' . esc_html__( 'Images aren\'t refused for being too big. Larger images are scaled down to fit these sizes for judging and voting, and the original is kept separately.', 'photo-competition-manager' ) . '</p>';

echo '<p>';
echo '<label for="max_width">' . esc_html__( 'Resize entries to fit width (pixels)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_width" name="max_width" ' . $data['attributes']['max_width'] . ' value="' . esc_attr( $data['max_width'] ) . '" class="small-text" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Competition_Settings::number_attributes().
echo '</p>';

echo '<p>';
echo '<label for="max_height">' . esc_html__( 'Resize entries to fit height (pixels)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_height" name="max_height" ' . $data['attributes']['max_height'] . ' value="' . esc_attr( $data['max_height'] ) . '" class="small-text" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Competition_Settings::number_attributes().
echo '</p>';
