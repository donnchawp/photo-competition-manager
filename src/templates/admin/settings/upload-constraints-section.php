<?php
/**
 * Upload constraints section partial for the admin settings page.
 *
 * Reads $data['max_file_size_mb'], $data['max_width'], and
 * $data['max_height'] (all int|string).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Upload Constraints', 'photo-competition-manager' ) . '</h2>';

echo '<p>';
echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $data['max_file_size_mb'] ) . '" class="small-text" />';
echo '</p>';

echo '<p>';
echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $data['max_width'] ) . '" class="small-text" />';
echo '</p>';

echo '<p>';
echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $data['max_height'] ) . '" class="small-text" />';
echo '</p>';
