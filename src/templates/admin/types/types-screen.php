<?php
/**
 * Competition types screen partial: the list, or the delete confirmation.
 *
 * Reads $data['mode'] ('list' or 'delete'), $data['type'] (delete only:
 * object with id and name, or null when not found), $data['rows'] (list
 * only; objects with name, categories, edit_url and delete_url, which is ''
 * when the type can't be deleted), $data['add_url'] and $data['types_url']
 * (strings).
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

if ( 'delete' === $data['mode'] ) {
	if ( ! $data['type'] ) {
		echo '<p>' . esc_html__( 'Competition type not found.', 'photo-competition-manager' ) . '</p>';
		echo wp_kses_post( $back_link );
		return;
	}

	echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
	echo '<h2>' . esc_html__( 'Delete Competition Type', 'photo-competition-manager' ) . '</h2>';
	wp_nonce_field( 'photo_competition_delete_type_' . (int) $data['type']->id, 'photo_competition_type_nonce' );
	echo '<input type="hidden" name="photo_competition_action" value="delete_type" />';
	echo '<input type="hidden" name="type_id" value="' . esc_attr( $data['type']->id ) . '" />';
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: competition type name */
			__( 'Delete the competition type %s? Members lose their tag for it. A type with competitions can\'t be deleted.', 'photo-competition-manager' ),
			$data['type']->name
		)
	) . '</p>';
	submit_button( __( 'Delete Competition Type', 'photo-competition-manager' ), 'delete' );
	echo '</form>';
	echo wp_kses_post( $back_link );
	return;
}

echo '<p class="description">' . esc_html__( 'Each competition type holds the categories, upload limits, voting and slideshow settings its new competitions start with, and its own names for the club\'s grades.', 'photo-competition-manager' ) . '</p>';

echo '<table class="widefat striped" style="max-width: 720px;">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Name', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Categories', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
echo '</tr></thead><tbody>';
foreach ( $data['rows'] as $row ) {
	echo '<tr>';
	echo '<td>' . esc_html( $row->name ) . '</td>';
	echo '<td>' . esc_html( $row->categories ) . '</td>';
	echo '<td>';
	printf( '<a href="%s">%s</a>', esc_url( $row->edit_url ), esc_html__( 'Edit', 'photo-competition-manager' ) );
	if ( '' !== $row->delete_url ) {
		echo ' | ';
		printf( '<a href="%s">%s</a>', esc_url( $row->delete_url ), esc_html__( 'Delete', 'photo-competition-manager' ) );
	}
	echo '</td>';
	echo '</tr>';
}
echo '</tbody></table>';

printf(
	'<p><a href="%s" class="button">%s</a></p>',
	esc_url( $data['add_url'] ),
	esc_html__( 'Add Competition Type', 'photo-competition-manager' )
);
