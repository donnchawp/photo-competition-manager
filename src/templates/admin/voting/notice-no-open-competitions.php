<?php
/**
 * "No open competitions" notice partial for the admin voting controls page.
 *
 * Closes both the notice wrapper and the outer `.wrap` div opened in
 * Voting_Controller::render(), since rendering stops immediately after.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning inline">';
echo '<p>' . esc_html__( 'No open competitions found. Create a competition with open and close dates to enable voting controls.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
echo '</div>';
