<?php
/**
 * Notice about members tagged as both a club and a person, for the admin members page.
 *
 * Reads $data['count'] (int) and $data['url'] (string) of the list of those members.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<div class="notice notice-warning"><p>';
echo esc_html(
	sprintf(
		/* translators: %d: number of members with both a club type and a people type */
		_n(
			'%d member is tagged as both a club and a person, so they can\'t enter competitions of one of their types. Remove the types they shouldn\'t have.',
			'%d members are tagged as both a club and a person, so they can\'t enter competitions of one of their types. Remove the types they shouldn\'t have.',
			$data['count'],
			'photo-competition-manager'
		),
		$data['count']
	)
);
echo ' <a href="' . esc_url( $data['url'] ) . '">' . esc_html__( 'Show them', 'photo-competition-manager' ) . '</a>';
echo '</p></div>';
