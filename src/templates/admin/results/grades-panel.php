<?php
/**
 * Grades panel of a closed competition on the admin results dashboard.
 *
 * @package PhotoCompetitionManager
 *
 * $data['action_url'] string Nonced form action URL (correct_grades on this competition).
 * $data['rows']       array<int, array{member_id: int, name: string, grade: string, options: array<string, string>}> Each member with recorded entries, their recorded grade, and the grades to choose from.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<details class="photo-comp-grades-panel" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #2271b1;">';
echo '<summary style="cursor: pointer;"><strong>' . esc_html__( 'Grades in this competition', 'photo-competition-manager' ) . '</strong></summary>';
echo '<p class="description">' . esc_html__( 'The results keep the grade each member was in when they were recorded. If a member has moved grade since and is shown in the wrong one, choose the grade they entered in. Their entries in every category move to it, and the results are ranked again from the same scores. Publishing the results again after moving the close date records every member in their grade today, so check this list again afterwards.', 'photo-competition-manager' ) . '</p>';
echo '<form method="post" action="' . esc_url( $data['action_url'] ) . '">';
echo '<table class="widefat striped" style="max-width: 500px;"><tbody>';
foreach ( $data['rows'] as $row ) {
	$field_id = 'photo-comp-grade-' . (int) $row['member_id'];
	echo '<tr><td><label for="' . esc_attr( $field_id ) . '">' . esc_html( $row['name'] ) . '</label></td><td>';
	echo '<select id="' . esc_attr( $field_id ) . '" name="grades[' . (int) $row['member_id'] . ']">';
	foreach ( $row['options'] as $slug => $label ) {
		echo '<option value="' . esc_attr( (string) $slug ) . '"' . selected( (string) $slug, $row['grade'], false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></td></tr>';
}
echo '</tbody></table>';
echo '<p><button type="submit" class="button">' . esc_html__( 'Save Grades', 'photo-competition-manager' ) . '</button></p>';
echo '</form>';
echo '</details>';
