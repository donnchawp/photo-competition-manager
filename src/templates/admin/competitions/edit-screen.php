<?php
/**
 * Edit-competition screen partial for the admin competitions page.
 *
 * Reads $data['found'] (bool): whether the requested competition exists.
 * $data['dashboard_url'] (string): URL back to the competitions dashboard.
 * $data['tabs_html'] (string, only present when found): pre-rendered tab
 * strip markup. $data['form_html'] (string, only present when found):
 * pre-rendered general/settings form markup.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="wrap">';
echo '<h1>' . esc_html__( 'Edit Competition', 'photo-competition-manager' ) . '</h1>';

if ( ! $data['found'] ) {
	echo '<p>' . esc_html__( 'Competition not found. Return to the list and try again.', 'photo-competition-manager' ) . '</p>';
	printf(
		'<a class="button" href="%s">%s</a>',
		esc_url( $data['dashboard_url'] ),
		esc_html__( 'Back to competitions', 'photo-competition-manager' )
	);
	echo '</div>';
	return;
}

echo $data['tabs_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
echo $data['form_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

printf(
	'<p><a href="%s">%s</a></p>',
	esc_url( $data['dashboard_url'] ),
	esc_html__( 'Back to competitions', 'photo-competition-manager' )
);

echo '</div>';
