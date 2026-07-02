<?php
/**
 * Member-not-found notice partial for the admin members edit-member page.
 *
 * Reads $data['members_url'] (string).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found. Return to the list to continue.', 'photo-competition-manager' ) . '</p></div>';
printf(
	'<p><a class="button" href="%s">%s</a></p>',
	esc_url( $data['members_url'] ),
	esc_html__( 'Back to members', 'photo-competition-manager' )
);
