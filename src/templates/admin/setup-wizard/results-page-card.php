<?php
/**
 * Results page option card partial for the admin setup wizard page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="create_results_page" value="1" checked />';
echo ' <strong>' . esc_html__( 'Results Page', 'photo-competition-manager' ) . '</strong>';
echo '</label>';
echo '</p>';
echo '<p>';
echo '<label for="results_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="results_page_title" name="results_page_title" value="Competition Results" class="regular-text" />';
echo '</p>';
echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="results_hide_names" value="1" checked />';
echo ' ' . esc_html__( 'Hide photographer names on results page', 'photo-competition-manager' );
echo '</label>';
echo '</p>';
echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_results] shortcode to display competition winners.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
