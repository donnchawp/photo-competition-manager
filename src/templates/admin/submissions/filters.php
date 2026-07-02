<?php
/**
 * Competition/member filter form partial for the admin submissions page.
 *
 * Reads $data['competitions'] (array of competition objects), $data['competition_id']
 * (int, currently selected competition), $data['members'] (array of member objects),
 * $data['member_id'] (int, currently selected member; 0 = all).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="get" class="photo-comp-filters">';
echo '<input type="hidden" name="page" value="photo-competition-manager-submissions" />';
echo '<label for="competition_id" class="screen-reader-text">' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</label>';
echo '<select name="competition_id" id="competition_id">';
foreach ( $data['competitions'] as $competition ) {
	$label = $competition->title;
	if ( ! empty( $competition->deleted_at ) ) {
		$label .= ' ' . esc_html__( '(Archived)', 'photo-competition-manager' );
	}

	printf(
		'<option value="%1$d" %3$s>%2$s</option>',
		(int) $competition->id,
		esc_html( $label ),
		selected( $data['competition_id'], $competition->id, false )
	);
}
echo '</select> ';

echo '<label for="member_id" class="screen-reader-text">' . esc_html__( 'Member', 'photo-competition-manager' ) . '</label>';
echo '<select name="member_id" id="member_id">';
echo '<option value="0">' . esc_html__( 'All Members', 'photo-competition-manager' ) . '</option>';
foreach ( $data['members'] as $member ) {
	printf(
		'<option value="%1$d" %3$s>%2$s</option>',
		(int) $member->id,
		esc_html( $member->name ),
		selected( $data['member_id'], $member->id, false )
	);
}
echo '</select> ';

echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'photo-competition-manager' ) . '</button>';
echo '</form>';
