<?php
/**
 * No-competitions notice partial for the admin submissions page.
 *
 * Reads no $data keys.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<p>' . esc_html__( 'No competitions available yet. Create a competition first.', 'photo-competition-manager' ) . '</p>';
