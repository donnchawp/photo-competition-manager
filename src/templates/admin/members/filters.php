<?php
/**
 * Search/status/grade filter form partial for the admin members page.
 *
 * Reads $data['search'] (string), $data['status_filter'] (string),
 * $data['grade_filter'] (string), $data['grade_options'] (array<string,string>
 * of grade slug => label), $data['type_options'] (array<int,string> of type ID
 * => name; empty unless the club has several types) and $data['type_filter']
 * (a type ID, "none" or "").
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<form method="get" class="photo-comp-filters" style="margin-bottom: 15px;">';
echo '<input type="hidden" name="page" value="photo-competition-manager-members" />';

echo '<input type="search" name="s" value="' . esc_attr( $data['search'] ) . '" placeholder="' . esc_attr__( 'Search members...', 'photo-competition-manager' ) . '" style="margin-right: 10px;" />';

echo '<select name="status" style="margin-right: 10px;">';
echo '<option value="">' . esc_html__( 'All Statuses', 'photo-competition-manager' ) . '</option>';
echo '<option value="active"' . selected( $data['status_filter'], 'active', false ) . '>' . esc_html__( 'Active', 'photo-competition-manager' ) . '</option>';
echo '<option value="inactive"' . selected( $data['status_filter'], 'inactive', false ) . '>' . esc_html__( 'Inactive', 'photo-competition-manager' ) . '</option>';
echo '</select>';

echo '<select name="grade" style="margin-right: 10px;">';
echo '<option value="">' . esc_html__( 'All Grades', 'photo-competition-manager' ) . '</option>';
foreach ( $data['grade_options'] as $grade_value => $grade_label ) {
	echo '<option value="' . esc_attr( $grade_value ) . '"' . selected( $data['grade_filter'], $grade_value, false ) . '>' . esc_html( $grade_label ) . '</option>';
}
echo '</select>';

if ( $data['type_options'] ) {
	echo '<select name="type" style="margin-right: 10px;">';
	echo '<option value="">' . esc_html__( 'All Types', 'photo-competition-manager' ) . '</option>';
	foreach ( $data['type_options'] as $type_id => $type_name ) {
		echo '<option value="' . esc_attr( $type_id ) . '"' . selected( $data['type_filter'], (string) $type_id, false ) . '>' . esc_html( $type_name ) . '</option>';
	}
	echo '<option value="none"' . selected( $data['type_filter'], 'none', false ) . '>' . esc_html__( 'No type', 'photo-competition-manager' ) . '</option>';
	echo '</select>';
}

echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'photo-competition-manager' ) . '</button>';

if ( ! empty( $data['search'] ) || ! empty( $data['status_filter'] ) || ! empty( $data['grade_filter'] ) || '' !== $data['type_filter'] ) {
	echo ' <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-members' ) ) . '" class="button">' . esc_html__( 'Clear Filters', 'photo-competition-manager' ) . '</a>';
}

echo '</form>';
