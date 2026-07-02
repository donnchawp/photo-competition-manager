<?php
/**
 * "No images" notice partial for the admin voting controls page.
 *
 * Closes both the notice wrapper and the outer `.wrap` div opened in
 * Voting_Controller::render(), since rendering stops immediately after.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning inline">';
echo '<p>' . esc_html__( 'No images found in any category. Upload images before managing voting.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
echo '</div>';
