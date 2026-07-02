<?php
/**
 * Existing pages group partial for the admin setup wizard page.
 *
 * Reads $data keys: type, pages.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h3>' . esc_html( $data['type'] ) . '</h3>';
echo '<ul>';
foreach ( $data['pages'] as $found_page ) {
	echo '<li>';
	echo '<a href="' . esc_url( get_permalink( $found_page->ID ) ) . '" target="_blank">';
	echo esc_html( $found_page->post_title );
	echo '</a>';
	echo ' <span class="description">— <a href="' . esc_url( get_edit_post_link( $found_page->ID ) ) . '">Edit</a></span>';
	echo '</li>';
}
echo '</ul>';
