<?php
/**
 * Past competitions page option card partial for the admin setup wizard page.
 *
 * Reads $data['settings_url'] (string: where the archive is switched on).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="create_archive_page" value="1" />';
echo ' <strong>' . esc_html__( 'Past Competitions Page', 'photo-competition-manager' ) . '</strong>';
echo '</label>';
echo '</p>';
echo '<p>';
echo '<label for="archive_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="archive_page_title" name="archive_page_title" value="Past Competitions" class="regular-text" />';
echo '</p>';
echo '<p class="description">';
printf(
	/* translators: %s: link to the Settings screen */
	esc_html__( 'Creates a page with the [competition_archive] shortcode, listing competitions whose results are published, each at its own address. The page shows nothing until you switch it on in %s.', 'photo-competition-manager' ),
	'<a href="' . esc_url( $data['settings_url'] ) . '">' . esc_html__( 'Settings', 'photo-competition-manager' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link built from escaped parts.
);
echo '</p>';
echo '</div>';
