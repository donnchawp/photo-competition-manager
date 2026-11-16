<?php
/**
 * Warning partial listing the competition's ungraded entries.
 *
 * @package PhotoCompetitionManager
 *
 * $data['members'] array<int, array{name: string, email: string}> Members without a club grade who entered.
 * $data['orphans'] array<int, array{image_number: int, category: string}> Entries whose member no longer exists.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<div class="notice notice-warning inline">';
echo '<p>' . esc_html__( 'Some entries are listed under Ungraded and aren\'t ranked with any grade, so their members get no position in the results email.', 'photo-competition-manager' ) . '</p>';

if ( ! empty( $data['members'] ) ) {
	echo '<p>' . esc_html__( 'Give these members a grade on the Members screen:', 'photo-competition-manager' ) . '</p>';
	echo '<ul>';
	foreach ( $data['members'] as $member ) {
		echo '<li>' . esc_html( $member['name'] . ' (' . $member['email'] . ')' ) . '</li>';
	}
	echo '</ul>';
}

if ( ! empty( $data['orphans'] ) ) {
	echo '<p>' . esc_html__( 'These entries belong to members who no longer exist:', 'photo-competition-manager' ) . '</p>';
	echo '<ul>';
	foreach ( $data['orphans'] as $orphan ) {
		/* translators: 1: Anonymised image identifier, 2: Category label. */
		echo '<li>' . esc_html( sprintf( __( 'Image #%1$d (%2$s)', 'photo-competition-manager' ), $orphan['image_number'], $orphan['category'] ) ) . '</li>';
	}
	echo '</ul>';
}

echo '</div>';
