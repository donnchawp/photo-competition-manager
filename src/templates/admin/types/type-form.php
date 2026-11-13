<?php
/**
 * Competition type add/edit form partial.
 *
 * Reads $data['mode'] ('add' or 'edit'), $data['type'] (edit only: object
 * with id and name, or null when not found), $data['sections_html'] (string:
 * pre-rendered categories, upload, voting and slideshow sections from
 * Type_Settings_Form::render_sections()), $data['grade_rows'] (objects with
 * slug, label, the club's name for the grade, and name, this type's name for
 * it or '') and $data['types_url'] (string).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

$back_link = sprintf(
	'<p><a href="%s">%s</a></p>',
	esc_url( $data['types_url'] ),
	esc_html__( 'Back to competition types', 'photo-competition-manager' )
);

$is_edit = 'edit' === $data['mode'];

if ( $is_edit && ! $data['type'] ) {
	echo '<p>' . esc_html__( 'Competition type not found.', 'photo-competition-manager' ) . '</p>';
	echo wp_kses_post( $back_link );
	return;
}

echo '<form method="post" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';

if ( $is_edit ) {
	echo '<h2>' . esc_html__( 'Edit Competition Type', 'photo-competition-manager' ) . '</h2>';
	wp_nonce_field( 'photo_competition_type_update_' . (int) $data['type']->id, 'photo_competition_type_nonce' );
	echo '<input type="hidden" name="photo_competition_action" value="update_type" />';
	echo '<input type="hidden" name="type_id" value="' . esc_attr( $data['type']->id ) . '" />';
} else {
	echo '<h2>' . esc_html__( 'Add Competition Type', 'photo-competition-manager' ) . '</h2>';
	wp_nonce_field( 'photo_competition_type_create', 'photo_competition_type_nonce' );
	echo '<input type="hidden" name="photo_competition_action" value="create_type" />';
}

echo '<p><label for="type_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="type_name" name="type_name" class="regular-text" required value="' . esc_attr( $is_edit ? $data['type']->name : '' ) . '" /></p>';

echo $data['sections_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

echo '<h2>' . esc_html__( 'Grade Names', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'What this type calls each of the club\'s grades. Leave a name blank to use the club\'s.', 'photo-competition-manager' ) . '</p>';
echo '<table class="form-table"><tbody>';
foreach ( $data['grade_rows'] as $row ) {
	$field_id = 'grade_name_' . $row->slug;
	echo '<tr>';
	echo '<th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $row->label ) . '</label></th>';
	echo '<td><input type="text" id="' . esc_attr( $field_id ) . '" name="grade_names[' . esc_attr( $row->slug ) . ']" value="' . esc_attr( $row->name ) . '" class="regular-text" placeholder="' . esc_attr( $row->label ) . '" /></td>';
	echo '</tr>';
}
echo '</tbody></table>';

submit_button( $is_edit ? __( 'Update Competition Type', 'photo-competition-manager' ) : __( 'Add Competition Type', 'photo-competition-manager' ) );
echo '</form>';
echo wp_kses_post( $back_link );
