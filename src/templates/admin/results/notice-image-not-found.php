<?php
/**
 * "Image not found" notice partial for the admin results dashboard page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="wrap">';
echo '<h1>' . esc_html__( 'Image Details', 'photo-competition-manager' ) . '</h1>';
echo '<p>' . esc_html__( 'Image not found.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
