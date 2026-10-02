<?php
/**
 * "Voting open in another competition" notice partial for the admin voting
 * controls page.
 *
 * Shown when an older competition that is still open has voting open. It
 * has no tab here, and voting can't be opened in the current competition
 * until that voting is closed.
 *
 * Rendering continues after this notice; no wrapper div is closed here.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string $title Title of the competition with voting open.
 * }
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning">';
echo '<p>';
printf(
	/* translators: %s: title of the competition with voting open */
	esc_html__( 'Voting is open in %s, so it can\'t be opened here. Close that competition to close its voting.', 'photo-competition-manager' ),
	'<strong>' . esc_html( $data['title'] ) . '</strong>'
);
echo ' <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager' ) ) . '">' . esc_html__( 'Go to Competitions', 'photo-competition-manager' ) . '</a>';
echo '</p>';
echo '</div>';
