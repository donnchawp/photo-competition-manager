<?php
/**
 * "Multiple open competitions" notice partial for the admin Competitions and
 * Voting Controls pages.
 *
 * Rendering continues after this notice; no wrapper div is closed here.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string[] $titles    Titles of the currently open competitions.
 *     @type bool     $show_link Whether to link to the Competitions page.
 * }
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning">';
echo '<p>';
printf(
	/* translators: %s: comma-separated list of open competition titles */
	esc_html__( 'More than one competition is open: %s. Voting Controls shows the categories of every open competition, so close the ones you are not running.', 'photo-competition-manager' ),
	'<strong>' . esc_html( implode( ', ', $data['titles'] ) ) . '</strong>'
);
if ( $data['show_link'] ) {
	echo ' <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager' ) ) . '">' . esc_html__( 'Go to Competitions', 'photo-competition-manager' ) . '</a>';
}
echo '</p>';
echo '</div>';
