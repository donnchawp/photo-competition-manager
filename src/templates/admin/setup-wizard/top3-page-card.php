<?php
/**
 * Top 3 page option card partial for the admin setup wizard page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="create_top3_page" value="1" checked />';
echo ' <strong>' . esc_html__( 'Top 3 Page', 'photo-competition-manager' ) . '</strong>';
echo '</label>';
echo '</p>';
echo '<p>';
echo '<label for="top3_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="top3_page_title" name="top3_page_title" value="Top 3 Winners" class="regular-text" />';
echo '</p>';
echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_top3] shortcode to display the top 3 winners in each category and grade.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
