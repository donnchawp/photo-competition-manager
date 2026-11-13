<?php
/**
 * Notice about active members with no competition type, for the admin members page.
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
		/* translators: %d: number of active members with no competition type */
		_n(
			'%d active member has no type, so they get no links for competitions open to all.',
			'%d active members have no type, so they get no links for competitions open to all.',
			$data['count'],
			'photo-competition-manager'
		),
		$data['count']
	)
);
echo ' <a href="' . esc_url( $data['url'] ) . '">' . esc_html__( 'Show them', 'photo-competition-manager' ) . '</a>';
echo '</p></div>';
