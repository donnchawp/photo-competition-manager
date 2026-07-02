<?php
/**
 * Voting page option card partial for the admin setup wizard page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="create_voting_page" value="1" checked />';
echo ' <strong>' . esc_html__( 'Voting Page', 'photo-competition-manager' ) . '</strong>';
echo '</label>';
echo '</p>';
echo '<p>';
echo '<label for="voting_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="voting_page_title" name="voting_page_title" value="Vote on Photos" class="regular-text" />';
echo '</p>';
echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_voting] shortcode for members to cast their votes.', 'photo-competition-manager' ) . '</p>';
echo '</div>';
