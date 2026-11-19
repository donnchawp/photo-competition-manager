<?php
/**
 * "Needs judges" notice partial for the admin Judges, Competitions and
 * Voting Controls pages: one line per judged competition with no judges.
 *
 * Rendering continues after this notice; no wrapper div is closed here.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type array<int, array{title: string, url: string}> $competitions Each competition's title and the link to its judges section.
 * }
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<div class="notice notice-warning">';
foreach ( $data['competitions'] as $competition ) {
	echo '<p>';
	printf(
		/* translators: %s: competition title */
		esc_html__( '%s is scored by judges but has no judges. Add them before voting starts.', 'photo-competition-manager' ),
		'<strong>' . esc_html( $competition['title'] ) . '</strong>'
	);
	echo ' <a href="' . esc_url( $competition['url'] ) . '">' . esc_html__( 'Choose judges', 'photo-competition-manager' ) . '</a>';
	echo '</p>';
}
echo '</div>';
