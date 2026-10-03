<?php
/**
 * Warning partial listing the members whose entries are ungraded.
 *
 * @package PhotoCompetitionManager
 *
 * $data['members'] array<int, array{name: string|null, image_number: int}> One row per
 *                  member with ungraded entries; name is null when the member no longer exists.
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning inline">';
echo '<p>' . esc_html__( 'These entries are listed under Ungraded because their member has no grade from the club\'s list, so they aren\'t ranked with any grade. Give each member a grade on the Members screen:', 'photo-competition-manager' ) . '</p>';
echo '<ul>';

foreach ( $data['members'] as $member ) {
	if ( null !== $member['name'] ) {
		echo '<li>' . esc_html( $member['name'] ) . '</li>';
	} else {
		/* translators: %d: Anonymised image identifier. */
		echo '<li>' . esc_html( sprintf( __( 'Image #%d: a member who no longer exists', 'photo-competition-manager' ), $member['image_number'] ) ) . '</li>';
	}
}

echo '</ul>';
echo '</div>';
