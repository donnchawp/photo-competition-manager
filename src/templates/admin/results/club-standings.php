<?php
/**
 * Club standings partial for the admin results dashboard of an interclub competition.
 *
 * @package PhotoCompetitionManager
 *
 * $data['categories'] array<int, array{slug: string, label: string}> The competition's categories, in order.
 * $data['grades']     array<int, array{label: string, clubs: array<int, array{
 *     name: string,
 *     panels: array<string, array{total: int, position: int}|null>,
 *     total: int|null,
 *     position: int|null,
 * }>}> Each grade's clubs, from Results_Ranking::rank_clubs(). A club with no
 * total or position skipped a panel.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<h2>' . esc_html__( 'Club results', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Each panel\'s total, with its place in brackets. A club that skipped a panel gets no overall place.', 'photo-competition-manager' ) . '</p>';

foreach ( $data['grades'] as $grade ) {
	echo '<h3>' . esc_html( $grade['label'] ) . '</h3>';
	echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">';
	echo '<thead><tr>';
	echo '<th style="width: 60px;">' . esc_html__( 'Place', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Club', 'photo-competition-manager' ) . '</th>';
	foreach ( $data['categories'] as $category ) {
		echo '<th>' . esc_html( $category['label'] ) . '</th>';
	}
	echo '<th>' . esc_html__( 'Total', 'photo-competition-manager' ) . '</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ( $grade['clubs'] as $club ) {
		echo '<tr class="club-standing">';
		echo '<td><strong>' . esc_html( null === $club['position'] ? '—' : (string) $club['position'] ) . '</strong></td>';
		echo '<td>' . esc_html( $club['name'] ) . '</td>';
		foreach ( $data['categories'] as $category ) {
			$panel = $club['panels'][ $category['slug'] ] ?? null;
			echo '<td>' . ( $panel ? esc_html( number_format( $panel['total'], 0 ) . ' (' . $panel['position'] . ')' ) : '—' ) . '</td>';
		}
		echo '<td><strong>' . esc_html( null === $club['total'] ? '—' : number_format( $club['total'], 0 ) ) . '</strong></td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';
}
