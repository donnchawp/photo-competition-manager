<?php
/**
 * "Entrants haven't paid" warning partial for the admin voting controls page.
 * It doesn't stop voting: what happens to someone who hasn't paid is the
 * organiser's decision.
 *
 * Rendering continues after this notice; no wrapper div is closed here.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string[] $names        Names of the entrants who haven't paid.
 *     @type string   $entrants_url The competition's Entrants tab.
 * }
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

$count = count( $data['names'] );

echo '<div class="notice notice-warning photo-comp-unpaid-notice">';
echo '<p>';
echo esc_html(
	sprintf(
		/* translators: 1: Number of entrants, 2: Comma-separated entrant names */
		_n( '%1$d entrant hasn\'t paid the entry fee: %2$s.', '%1$d entrants haven\'t paid the entry fee: %2$s.', $count, 'photo-competition-manager' ),
		$count,
		implode( ', ', $data['names'] )
	)
);
echo ' <a href="' . esc_url( $data['entrants_url'] ) . '">' . esc_html__( 'Check entrants', 'photo-competition-manager' ) . '</a>';
echo '</p>';
echo '</div>';
