<?php
/**
 * No-submissions-found notice partial for the admin submissions page.
 *
 * Reads no $data keys.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<p>' . esc_html__( 'No submissions found for the selected filters.', 'photo-competition-manager' ) . '</p>';
