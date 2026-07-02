<?php
/**
 * "Competition not found" notice partial for the admin results dashboard page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="wrap">';
echo '<h1>' . esc_html__( 'Results Dashboard', 'photo-competition-manager' ) . '</h1>';
echo '<p>' . esc_html__( 'Competition not found.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
