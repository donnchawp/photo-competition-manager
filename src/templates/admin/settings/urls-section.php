<?php
/**
 * URLs section partial for the admin settings page.
 *
 * Reads $data['upload_page_url'], $data['voting_page_url'],
 * $data['results_page_url'], and $data['top3_page_url'] (all string).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'URLs', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Default pages used in upload and voting notifications.', 'photo-competition-manager' ) . '</p>';

echo '<p>';
echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $data['upload_page_url'] ) . '" class="regular-text" placeholder="https://example.com/upload" />';
echo '<br /><span class="description">' . esc_html__( 'Members receive this link when requesting upload tokens.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $data['voting_page_url'] ) . '" class="regular-text" placeholder="https://example.com/vote" />';
echo '<br /><span class="description">' . esc_html__( 'Voters receive this link in voting invitation emails.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="results_page_url">' . esc_html__( 'Results Page URL', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="url" id="results_page_url" name="results_page_url" value="' . esc_attr( $data['results_page_url'] ) . '" class="regular-text" placeholder="https://example.com/results" />';
echo '<br /><span class="description">' . esc_html__( 'Page displaying full competition results with all entries and scores.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="top3_page_url">' . esc_html__( 'Top 3 Page URL', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="url" id="top3_page_url" name="top3_page_url" value="' . esc_attr( $data['top3_page_url'] ) . '" class="regular-text" placeholder="https://example.com/top3" />';
echo '<br /><span class="description">' . esc_html__( 'Page displaying top 3 winners in a featured format.', 'photo-competition-manager' ) . '</span>';
echo '</p>';
