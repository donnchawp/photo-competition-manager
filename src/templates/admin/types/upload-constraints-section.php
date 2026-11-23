<?php
/**
 * Upload constraints section partial for the competition type form.
 *
 * Reads $data['fields_html'] (string): pre-rendered
 * admin/shared/upload-constraint-fields.php markup.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Upload Constraints', 'photo-competition-manager' ) . '</h2>';

echo $data['fields_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
