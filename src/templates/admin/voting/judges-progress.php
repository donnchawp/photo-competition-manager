<?php
/**
 * Judges partial for the admin voting controls page.
 *
 * Says the competition is scored by external judges, and whether each one
 * has scored the open category as of this page load, never the scores
 * themselves. The judges are in the room, so it doesn't refresh itself; it's
 * there to check before closing voting, which doesn't wait for missing ballots.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type array<int, array{name: string, submitted: bool}> $judges Judges assigned to the competition.
 * }
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

// The heading sits inside .inside, as on the page's other cards, which gives it the card's padding.
echo '<div class="postbox photo-comp-judges-progress">';
echo '<div class="inside">';
echo '<h2>' . esc_html__( 'Judges', 'photo-competition-manager' ) . '</h2>';
echo '<p>' . esc_html__( 'Judging is by external judges. Before you close voting, check every judge\'s scores have arrived. Reload the page to update this list.', 'photo-competition-manager' ) . '</p>';
echo '<table class="widefat striped"><tbody>';
foreach ( $data['judges'] as $judge ) {
	echo '<tr>';
	echo '<td>' . esc_html( $judge['name'] ) . '</td>';
	echo '<td>' . ( $judge['submitted'] ? esc_html__( 'Scored', 'photo-competition-manager' ) : esc_html__( 'Waiting', 'photo-competition-manager' ) ) . '</td>';
	echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</div>';
